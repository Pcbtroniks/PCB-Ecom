<?php

namespace App\Services\Payment;

use App\Models\Order;

class TransferGateway implements PaymentGateway
{
    /**
     * Identificador del gateway de transferencia bancaria.
     *
     * @return string 'transfer'.
     */
    public function name(): string
    {
        return 'transfer';
    }

    /**
     * Genera las instrucciones de transferencia (CLABE, banco, referencia) como resultado pendiente de pago.
     *
     * @param  Order  $order  Orden a pagar.
     * @param  array  $options  Opciones (no utilizadas).
     * @return PaymentResult Resultado en estado `awaiting_transfer` con los datos bancarios en `message` y `raw`.
     */
    public function charge(Order $order, array $options = []): PaymentResult
    {
        $clabe = (string) config('payment.methods.transfer.clabe');
        $bank = (string) config('payment.methods.transfer.bank');
        $prefix = (string) config('payment.methods.transfer.reference_prefix', 'PCB-');

        return new PaymentResult(
            success: true,
            status: 'awaiting_transfer',
            paymentIntentId: 'transfer_'.$order->number,
            redirectUrl: null,
            message: "Transferir a {$bank} CLABE {$clabe} con referencia {$prefix}{$order->number}",
            raw: [
                'gateway' => 'transfer',
                'clabe' => $clabe,
                'bank' => $bank,
                'reference' => $prefix.$order->number,
                'amount' => (float) $order->total,
                'currency' => $order->currency,
            ],
        );
    }

    /**
     * Devuelve siempre el estado `awaiting_transfer`; la confirmación real llega por webhook.
     *
     * @param  string  $paymentIntentId  ID del intento.
     * @return PaymentResult Resultado en espera de transferencia.
     */
    public function retrieve(string $paymentIntentId): PaymentResult
    {
        return new PaymentResult(
            success: true,
            status: 'awaiting_transfer',
            paymentIntentId: $paymentIntentId,
        );
    }

    /**
     * Construye un WebhookEvent de tipo `transfer.received` con valores por defecto.
     *
     * @param  array  $payload  Cuerpo del webhook.
     * @param  string|null  $signature  Firma (ignorada).
     * @return WebhookEvent Evento de transferencia recibida.
     */
    public function handleWebhook(array $payload, ?string $signature = null): WebhookEvent
    {
        return new WebhookEvent(
            type: 'transfer.received',
            paymentIntentId: (string) ($payload['payment_intent_id'] ?? ''),
            status: (string) ($payload['status'] ?? 'succeeded'),
            raw: $payload,
        );
    }
}
