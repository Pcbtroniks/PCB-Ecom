<?php

namespace App\Services\Syscom;

use App\Models\Cart;
use Illuminate\Support\Facades\Log;
use Throwable;

class CartCheckoutService
{
    public function __construct(protected SyscomHttpClient $client) {}

    /**
     * Indica si la integración de checkout con Syscom está habilitada por configuración.
     *
     * @return bool true si `syscom.cart_checkout.enabled` es verdadero.
     */
    public function isEnabled(): bool
    {
        return (bool) config('syscom.cart_checkout.enabled', true);
    }

    /**
     * Crea la orden/carrito en Syscom y devuelve el ID remoto, o null si está deshabilitado o falla.
     *
     * @param  Cart  $cart  Carrito origen.
     * @param  array  $payload  Campos extra a fusionar en el payload enviado.
     * @return string|null ID de la orden en Syscom o null.
     */
    public function createOrder(Cart $cart, array $payload = []): ?string
    {
        if (! $this->isEnabled()) {
            return null;
        }

        try {
            $response = $this->client->post('carrito', $this->buildPayload($cart, $payload));

            return (string) ($response['id'] ?? $response['order_id'] ?? null) ?: null;
        } catch (Throwable $e) {
            Log::warning('Syscom cart create order failed', [
                'cart_id' => $cart->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Confirma una orden/carrito previamente creado en Syscom.
     *
     * @param  string  $syscomCartId  ID remoto del carrito/orden.
     * @return array|null Respuesta de Syscom o null si falla o está deshabilitado.
     */
    public function confirmOrder(string $syscomCartId): ?array
    {
        if (! $this->isEnabled() || $syscomCartId === '') {
            return null;
        }

        try {
            return $this->client->post("carrito/{$syscomCartId}/confirmar");
        } catch (Throwable $e) {
            Log::warning('Syscom cart confirm failed', [
                'syscom_cart_id' => $syscomCartId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Consulta el estado de un pedido en Syscom.
     *
     * @param  string  $syscomOrderId  ID remoto del pedido.
     * @return array|null Datos del pedido o null si falla o está deshabilitado.
     */
    public function fetchStatus(string $syscomOrderId): ?array
    {
        if (! $this->isEnabled() || $syscomOrderId === '') {
            return null;
        }

        try {
            return $this->client->get("pedidos/{$syscomOrderId}");
        } catch (Throwable $e) {
            Log::warning('Syscom order status fetch failed', [
                'syscom_order_id' => $syscomOrderId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Construye el payload que se envía a Syscom con líneas, totales y datos del cliente.
     *
     * @param  Cart  $cart  Carrito fuente.
     * @param  array  $overrides  Campos adicionales a fusionar sobre el payload base.
     * @return array Payload listo para enviar a Syscom.
     */
    protected function buildPayload(Cart $cart, array $overrides): array
    {
        $cart->loadMissing('items');

        $lines = $cart->items->map(fn ($item) => [
            'producto_id' => (int) $item->producto_id,
            'cantidad' => (int) $item->qty,
            'precio_unitario' => (float) $item->unit_price,
        ])->all();

        return array_merge([
            'origen' => 'pcbecom',
            'moneda' => $cart->currency,
            'lineas' => $lines,
            'subtotal' => (float) $cart->subtotal,
            'envio' => (float) $cart->shipping,
            'impuestos' => (float) $cart->tax,
            'total' => (float) $cart->total,
            'cliente' => $cart->user_id ? ['id' => $cart->user_id] : null,
        ], $overrides);
    }
}
