<?php

namespace App\Services\Payment;

class WebhookEvent
{
    public function __construct(
        public readonly string $type,
        public readonly string $paymentIntentId,
        public readonly string $status,
        public readonly array $raw = [],
    ) {}

    /**
     * Construye un WebhookEvent a partir de un arreglo asociativo, con valores por defecto seguros.
     *
     * @param  array  $data  Datos del evento con claves `type`, `payment_intent_id` (o `id`) y `status`.
     * @return self Evento normalizado con el payload crudo preservado en `raw`.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            type: (string) ($data['type'] ?? 'unknown'),
            paymentIntentId: (string) ($data['payment_intent_id'] ?? $data['id'] ?? ''),
            status: (string) ($data['status'] ?? 'unknown'),
            raw: $data,
        );
    }
}
