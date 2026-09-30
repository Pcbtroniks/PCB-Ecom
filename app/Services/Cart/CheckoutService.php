<?php

namespace App\Services\Cart;

use App\Models\Cart;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\Payment\PaymentGateway;
use App\Services\Syscom\CartCheckoutService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CheckoutService
{
    public function __construct(
        protected PricingService $pricing,
        protected CartCheckoutService $syscom,
        protected PaymentGateway $gateway,
    ) {}

    /**
     * Recalcula los totales del carrito para mostrar una vista previa del checkout.
     *
     * @param  Cart  $cart  Carrito a previsualizar.
     * @return Cart Carrito con subtotal, envío, impuesto y total actualizados.
     */
    public function preview(Cart $cart): Cart
    {
        return $this->pricing->recompute($cart);
    }

    /**
     * Confirma el checkout: crea la orden en Syscom, persiste Order/OrderItems, intenta el cobro y vacía el carrito.
     *
     * @param  Cart  $cart  Carrito origen.
     * @param  array  $data  Datos de checkout (direcciones, método de pago, notas).
     * @param  User|null  $user  Usuario autenticado (opcional si el carrito ya lo tiene).
     * @param  string|null  $idempotencyKey  Clave para deduplicar; si falta se genera una.
     * @return Order Orden creada (o la existente si la clave ya se usó).
     *
     * @throws \RuntimeException Si el carrito está vacío.
     */
    public function confirm(Cart $cart, array $data, ?User $user = null, ?string $idempotencyKey = null): Order
    {
        $idempotencyKey ??= 'ck_'.Str::random(24);

        if ($existing = $this->findIdempotent($idempotencyKey)) {
            return $existing;
        }

        if ($cart->items()->count() === 0) {
            throw new \RuntimeException('El carrito está vacío.');
        }

        return DB::transaction(function () use ($cart, $data, $user, $idempotencyKey) {
            $cart = $this->pricing->recompute($cart);
            $cart->update(['status' => 'checkout']);

            $syscomCartId = $this->syscom->createOrder($cart, $data);
            $syscomResponse = null;
            if ($syscomCartId !== null) {
                $syscomResponse = $this->syscom->confirmOrder($syscomCartId);
            }

            $order = Order::create([
                'number' => $this->generateOrderNumber(),
                'user_id' => $cart->user_id ?? $user?->id,
                'cart_id' => $cart->id,
                'syscom_order_id' => $syscomCartId,
                'status' => 'pending',
                'shipping_address' => $data['shipping_address'] ?? [],
                'billing_address' => $data['billing_address'] ?? $data['shipping_address'] ?? [],
                'currency' => $cart->currency,
                'subtotal' => $cart->subtotal,
                'shipping' => $cart->shipping,
                'tax' => $cart->tax,
                'total' => $cart->total,
                'payment_method' => $data['payment_method'] ?? $this->gateway->name(),
                'notes' => $data['notes'] ?? null,
                'syscom_response' => $syscomResponse,
                'placed_at' => now(),
                'meta' => ['idempotency_key' => $idempotencyKey],
                'idempotency_key' => $idempotencyKey,
            ]);

            foreach ($cart->items()->get() as $item) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'producto_id' => $item->producto_id,
                    'sku' => $item->sku,
                    'titulo' => $item->titulo,
                    'modelo' => $item->modelo,
                    'marca' => $item->marca,
                    'qty' => $item->qty,
                    'unit_price' => $item->unit_price,
                    'line_total' => $item->line_total,
                    'snapshot' => $item->snapshot,
                ]);
            }

            $this->chargePayment($order, $data);

            $cart->update(['status' => 'converted']);
            $cart->items()->delete();

            return $order->fresh(['items']);
        });
    }

    /**
     * Marca la orden como pagada y ajusta el `payment_status` si quedó en estado pagado/procesando.
     *
     * @param  Order  $order  Orden a actualizar.
     * @param  string  $paymentIntentId  ID del intento de pago.
     * @param  string|null  $status  Estado de pago opcional.
     * @return Order Orden recargada.
     */
    public function markPaid(Order $order, string $paymentIntentId, ?string $status = null): Order
    {
        $order->markPaid($paymentIntentId, $status);

        if (in_array($order->status, ['paid', 'processing'], true)) {
            $order->update(['payment_status' => $status ?? 'succeeded']);
        }

        return $order->fresh();
    }

    /**
     * Busca la orden más reciente asociada a una clave de idempotencia.
     *
     * @param  string  $key  Clave de idempotencia (vacía devuelve null).
     * @return Order|null Orden encontrada o null.
     */
    public function findIdempotent(string $key): ?Order
    {
        if ($key === '') {
            return null;
        }

        return Order::query()
            ->where('idempotency_key', $key)
            ->latest()
            ->first();
    }

    /**
     * Intenta cobrar la orden vía el gateway configurado y actualiza sus datos de pago.
     *
     * @param  Order  $order  Orden a cobrar.
     * @param  array  $data  Datos del checkout reenviados al gateway.
     */
    protected function chargePayment(Order $order, array $data): void
    {
        try {
            $result = $this->gateway->charge($order, $data);

            $order->update([
                'payment_intent_id' => $result->paymentIntentId,
                'payment_status' => $result->status,
            ]);

            $shouldAutoComplete = $this->gateway->name() === 'null'
                && (bool) config('payment.auto_complete', true);

            if ($result->status === 'succeeded' || $shouldAutoComplete) {
                $order->update(['status' => 'paid', 'paid_at' => now()]);
            }
        } catch (\Throwable $e) {
            Log::error('Checkout charge failed', [
                'order' => $order->number,
                'error' => $e->getMessage(),
            ]);
            $order->update(['payment_status' => 'failed', 'notes' => trim(($order->notes ?? '')."\n[payment] ".$e->getMessage())]);
        }
    }

    /**
     * Genera un número de orden único con el formato `PCB-AAAA-XXXXXX`.
     *
     * @return string Número de orden garantizado único.
     */
    protected function generateOrderNumber(): string
    {
        $year = now()->format('Y');
        do {
            $random = strtoupper(Str::random(6));
            $number = "PCB-{$year}-{$random}";
        } while (Order::where('number', $number)->exists());

        return $number;
    }
}
