<?php

namespace App\Services\Payment;

use App\Models\Order;
use Illuminate\Support\Str;

class NullGateway implements PaymentGateway
{
    /**
     * Identificador del gateway nulo (usado en pruebas/desarrollo).
     *
     * @return string 'null'.
     */
    public function name(): string
    {
        return 'null';
    }

    /**
     * Simula un cobro devolviendo un PaymentResult pendiente con un intent aleatorio.
     *
     * @param  Order  $order  Orden a cobrar (no se usa).
     * @param  array  $options  Opciones (ignoradas).
     * @return PaymentResult Resultado pendiente con `paymentIntentId` autogenerado.
     */
    public function charge(Order $order, array $options = []): PaymentResult
    {
        $intent = 'pi_null_'.Str::random(24);

        return new PaymentResult(
            success: true,
            status: 'pending',
            paymentIntentId: $intent,
            redirectUrl: null,
            raw: ['gateway' => 'null', 'auto_complete' => (bool) config('payment.auto_complete', true)],
        );
    }

    /**
     * Simula la consulta de un intento y siempre lo devuelve como exitoso.
     *
     * @param  string  $paymentIntentId  ID del intento.
     * @return PaymentResult Resultado exitoso.
     */
    public function retrieve(string $paymentIntentId): PaymentResult
    {
        return PaymentResult::succeeded($paymentIntentId, ['gateway' => 'null']);
    }

    /**
     * Construye un WebhookEvent a partir del payload recibido, con valores por defecto seguros.
     *
     * @param  array  $payload  Cuerpo del webhook.
     * @param  string|null  $signature  Firma (ignorada).
     * @return WebhookEvent Evento normalizado.
     */
    public function handleWebhook(array $payload, ?string $signature = null): WebhookEvent
    {
        $object = $payload['data']['object'] ?? $payload;

        return WebhookEvent::fromArray([
            'type' => $payload['type'] ?? 'null.test',
            'payment_intent_id' => (string) ($object['id'] ?? $object['payment_intent'] ?? $payload['payment_intent_id'] ?? ''),
            'status' => (string) ($object['status'] ?? $payload['status'] ?? 'succeeded'),
            'raw' => $payload,
        ]);
    }
}
