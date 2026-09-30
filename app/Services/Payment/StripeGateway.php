<?php

namespace App\Services\Payment;

use App\Models\Order;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class StripeGateway implements PaymentGateway
{
    /**
     * Identificador del gateway de Stripe.
     *
     * @return string 'stripe'.
     */
    public function name(): string
    {
        return 'stripe';
    }

    /**
     * Crea un PaymentIntent en Stripe para la orden y devuelve el resultado con `client_secret` como `redirectUrl`.
     *
     * @param  Order  $order  Orden a cobrar.
     * @param  array  $options  Opciones (no utilizadas, se conserva por la interface).
     * @return PaymentResult Pendiente en éxito; `failed` si la clave no está configurada o la API rechaza.
     */
    public function charge(Order $order, array $options = []): PaymentResult
    {
        $secret = (string) config('payment.methods.stripe.secret_key');
        if ($secret === '') {
            return PaymentResult::failed('Stripe secret key no configurada.');
        }

        $currency = (string) config('payment.methods.stripe.currency', 'mxn');
        $amount = (int) round(((float) $order->total) * 100);

        try {
            $response = Http::withToken($secret)
                ->asForm()
                ->post('https://api.stripe.com/v1/payment_intents', [
                    'amount' => $amount,
                    'currency' => $currency,
                    'description' => "Orden {$order->number}",
                    'metadata' => [
                        'order_number' => $order->number,
                        'order_id' => (string) $order->id,
                    ],
                ]);

            if ($response->failed()) {
                return PaymentResult::failed(
                    'Stripe rechazó la solicitud: '.$response->body(),
                    $response->json() ?? [],
                );
            }

            $data = $response->json();

            return new PaymentResult(
                success: true,
                status: 'pending',
                paymentIntentId: (string) ($data['id'] ?? 'pi_'.Str::random(24)),
                redirectUrl: $data['client_secret'] ?? null,
                raw: $data,
            );
        } catch (\Throwable $e) {
            Log::error('Stripe charge failed', [
                'order' => $order->number,
                'error' => $e->getMessage(),
            ]);

            return PaymentResult::failed('Error de comunicación con Stripe: '.$e->getMessage());
        }
    }

    /**
     * Recupera el estado actual de un PaymentIntent en Stripe.
     *
     * @param  string  $paymentIntentId  ID del PaymentIntent.
     * @return PaymentResult Estado actual; `failed` si la clave no está configurada o la API falla.
     */
    public function retrieve(string $paymentIntentId): PaymentResult
    {
        $secret = (string) config('payment.methods.stripe.secret_key');
        if ($secret === '') {
            return PaymentResult::failed('Stripe secret key no configurada.');
        }

        try {
            $response = Http::withToken($secret)->get("https://api.stripe.com/v1/payment_intents/{$paymentIntentId}");

            if ($response->failed()) {
                return PaymentResult::failed('Stripe retrieve failed', $response->json() ?? []);
            }

            $data = $response->json();
            $status = (string) ($data['status'] ?? 'unknown');

            return new PaymentResult(
                success: in_array($status, ['succeeded', 'processing', 'requires_capture'], true),
                status: $status,
                paymentIntentId: $paymentIntentId,
                raw: $data,
            );
        } catch (\Throwable $e) {
            return PaymentResult::failed('Stripe retrieve error: '.$e->getMessage());
        }
    }

    /**
     * Procesa un webhook de Stripe: valida la firma HMAC si hay secreto y devuelve el evento normalizado.
     *
     * @param  array  $payload  Cuerpo del webhook.
     * @param  string|null  $signature  Firma esperada en SHA-256 HMAC.
     * @return WebhookEvent Evento con tipo, paymentIntentId y status.
     */
    public function handleWebhook(array $payload, ?string $signature = null): WebhookEvent
    {
        $secret = (string) config('payment.methods.stripe.webhook_secret');
        $type = (string) ($payload['type'] ?? 'unknown');

        $intent = $payload['data']['object'] ?? $payload;
        $paymentIntentId = (string) ($intent['id'] ?? $intent['payment_intent'] ?? '');
        $status = (string) ($intent['status'] ?? 'unknown');

        if ($secret !== '' && is_string($signature)) {
            $expected = hash_hmac('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE), $secret);
            if (! hash_equals($expected, $signature)) {
                Log::warning('Stripe webhook signature mismatch');
            }
        }

        return new WebhookEvent(
            type: $type,
            paymentIntentId: $paymentIntentId,
            status: $status,
            raw: $payload,
        );
    }
}
