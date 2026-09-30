<?php

namespace App\Services\Payment;

use App\Models\Order;

class PaymentResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $status,
        public readonly ?string $paymentIntentId = null,
        public readonly ?string $redirectUrl = null,
        public readonly ?string $message = null,
        public readonly array $raw = [],
    ) {}

    /**
     * Fabrica un resultado pendiente (éxito=true, status='pending') listo para devolver al caller.
     *
     * @param  Order|null  $order  Orden asociada (opcional, no se persiste).
     * @param  string  $paymentIntentId  ID del intento de pago.
     * @param  string|null  $redirectUrl  URL de redirección opcional.
     * @param  array  $raw  Datos crudos devueltos por el gateway.
     * @return self Instancia en estado pendiente.
     */
    public static function pending(?Order $order = null, string $paymentIntentId = '', ?string $redirectUrl = null, array $raw = []): self
    {
        return new self(
            success: true,
            status: 'pending',
            paymentIntentId: $paymentIntentId,
            redirectUrl: $redirectUrl,
            raw: $raw,
        );
    }

    /**
     * Fabrica un resultado exitoso (success=true, status='succeeded').
     *
     * @param  string  $paymentIntentId  ID del intento de pago.
     * @param  array  $raw  Datos crudos devueltos por el gateway.
     * @return self Instancia exitosa.
     */
    public static function succeeded(string $paymentIntentId, array $raw = []): self
    {
        return new self(
            success: true,
            status: 'succeeded',
            paymentIntentId: $paymentIntentId,
            raw: $raw,
        );
    }

    /**
     * Fabrica un resultado fallido (success=false, status='failed') con un mensaje de error.
     *
     * @param  string  $message  Mensaje descriptivo del fallo.
     * @param  array  $raw  Datos crudos devueltos por el gateway.
     * @return self Instancia fallida.
     */
    public static function failed(string $message, array $raw = []): self
    {
        return new self(
            success: false,
            status: 'failed',
            message: $message,
            raw: $raw,
        );
    }

    /**
     * Serializa el resultado a un arreglo apto para respuestas JSON.
     *
     * @return array Arreglo con `success`, `status`, `payment_intent_id`, `redirect_url` y `message`.
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'status' => $this->status,
            'payment_intent_id' => $this->paymentIntentId,
            'redirect_url' => $this->redirectUrl,
            'message' => $this->message,
        ];
    }
}
