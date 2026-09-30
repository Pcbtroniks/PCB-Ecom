<?php

namespace App\Services\Payment;

use App\Models\Order;

interface PaymentGateway
{
    /**
     * Identificador legible del gateway (ej. 'stripe', 'transfer', 'null').
     *
     * @return string Nombre corto del gateway.
     */
    public function name(): string;

    /**
     * Inicia el cobro para una orden y devuelve el resultado del intento.
     *
     * @param  Order  $order  Orden a cobrar.
     * @param  array  $options  Datos adicionales (método, metadata, etc.).
     * @return PaymentResult Resultado del intento de cobro.
     */
    public function charge(Order $order, array $options = []): PaymentResult;

    /**
     * Consulta el estado actual de un intento de pago en el proveedor.
     *
     * @param  string  $paymentIntentId  ID del intento de pago.
     * @return PaymentResult Estado actual del intento.
     */
    public function retrieve(string $paymentIntentId): PaymentResult;

    /**
     * Procesa un payload entrante del proveedor y lo convierte en un evento normalizado.
     *
     * @param  array  $payload  Cuerpo del webhook.
     * @param  string|null  $signature  Firma opcional para validación.
     * @return WebhookEvent Evento normalizado.
     */
    public function handleWebhook(array $payload, ?string $signature = null): WebhookEvent;
}
