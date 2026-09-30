<?php

namespace App\Services\Currency;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExchangeRateService
{
    public function __construct(
        protected string $sourceUrl,
        protected int $cacheTtlSeconds,
        protected float $fallbackRate,
        protected int $timeoutSeconds = 5,
    ) {}

    /**
     * Devuelve la tasa USD→MXN, cacheada por el TTL configurado; si la API falla se usa la tasa de respaldo.
     *
     * @return float Tasa de cambio actual o de fallback.
     */
    public function getUsdToMxnRate(): float
    {
        $cacheKey = 'currency:usd_to_mxn';

        return Cache::remember($cacheKey, $this->cacheTtlSeconds, function (): float {
            return $this->fetchRate();
        });
    }

    /**
     * Convierte un monto en USD a MXN aplicando la tasa cacheada.
     *
     * @param  float  $amount  Monto en dólares.
     * @return float Equivalente en pesos mexicanos.
     */
    public function convertUsdToMxn(float $amount): float
    {
        return $amount * $this->getUsdToMxnRate();
    }

    /**
     * Invalida el caché de la tasa y la recarga desde la fuente.
     *
     * @return float Tasa recién obtenida.
     */
    public function refresh(): float
    {
        Cache::forget('currency:usd_to_mxn');

        return $this->getUsdToMxnRate();
    }

    /**
     * Llama a la API externa de tipos de cambio y devuelve la tasa MXN o la de fallback ante cualquier fallo.
     *
     * @return float Tasa MXN válida o fallback si la respuesta falla.
     */
    protected function fetchRate(): float
    {
        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->connectTimeout($this->timeoutSeconds)
                ->get($this->sourceUrl);

            if (! $response->successful()) {
                Log::warning('Exchange rate API non-success', [
                    'url' => $this->sourceUrl,
                    'status' => $response->status(),
                ]);

                return $this->fallbackRate;
            }

            $payload = $response->json();
            $rate = $this->extractRate($payload);

            if ($rate === null || $rate <= 0) {
                Log::warning('Exchange rate API returned invalid rate', [
                    'url' => $this->sourceUrl,
                    'payload' => $payload,
                ]);

                return $this->fallbackRate;
            }

            return $rate;
        } catch (Throwable $e) {
            Log::warning('Exchange rate API request failed', [
                'url' => $this->sourceUrl,
                'error' => $e->getMessage(),
            ]);

            return $this->fallbackRate;
        }
    }

    /**
     * Extrae el valor de la tasa MXN soportando tres formatos comunes de respuesta de la API.
     *
     * @param  mixed  $payload  Cuerpo JSON decodificado de la respuesta.
     * @return float|null Tasa encontrada, o null si no se pudo extraer.
     */
    protected function extractRate(mixed $payload): ?float
    {
        if (! is_array($payload)) {
            return null;
        }

        if (isset($payload['rates']['MXN']) && is_numeric($payload['rates']['MXN'])) {
            return (float) $payload['rates']['MXN'];
        }

        if (isset($payload['conversion_rates']['MXN']) && is_numeric($payload['conversion_rates']['MXN'])) {
            return (float) $payload['conversion_rates']['MXN'];
        }

        if (isset($payload['rates'][0]) && is_array($payload['rates'][0])) {
            foreach ($payload['rates'] as $entry) {
                if (isset($entry['code'], $entry['value']) && $entry['code'] === 'MXN') {
                    return (float) $entry['value'];
                }
            }
        }

        return null;
    }
}
