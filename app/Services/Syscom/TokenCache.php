<?php

namespace App\Services\Syscom;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class TokenCache
{
    public function __construct(
        protected string $cacheKey,
        protected int $safetyMarginSeconds = 60,
    ) {}

    /**
     * Devuelve el token OAuth cacheado, o lo obtiene y cachea si está vencido o ausente.
     *
     * @return string Token Bearer válido.
     */
    public function get(): string
    {
        $token = Cache::get($this->cacheKey);

        if (is_string($token) && $token !== '') {
            return $token;
        }

        return $this->refresh();
    }

    /**
     * Invalida el token cacheado para forzar un refresh en el próximo `get()`.
     */
    public function forget(): void
    {
        Cache::forget($this->cacheKey);
    }

    /**
     * Solicita un nuevo token vía OAuth client_credentials y lo guarda en caché con margen de seguridad.
     *
     * @return string Token recién emitido.
     *
     * @throws RuntimeException Si las credenciales no están configuradas o la respuesta es inválida.
     */
    protected function refresh(): string
    {
        $oauthUrl = (string) config('syscom.oauth_url');
        $clientId = (string) config('syscom.client_id');
        $clientSecret = (string) config('syscom.client_secret');

        if ($clientId === '' || $clientSecret === '') {
            throw new RuntimeException('Syscom credentials are not configured.');
        }

        $response = Http::asForm()
            ->timeout((int) config('syscom.http.timeout', 15))
            ->post($oauthUrl, [
                'grant_type' => 'client_credentials',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
            ]);

        if ($response->failed()) {
            Log::error('Syscom OAuth failure', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new RuntimeException(
                'Syscom OAuth failed with status '.$response->status()
            );
        }

        $payload = $response->json();
        $token = $payload['access_token'] ?? null;
        $expiresIn = (int) ($payload['expires_in'] ?? 0);

        if (! is_string($token) || $token === '' || $expiresIn <= 0) {
            throw new RuntimeException('Syscom OAuth response is malformed.');
        }

        $ttl = max(60, $expiresIn - $this->safetyMarginSeconds);
        Cache::put($this->cacheKey, $token, $ttl);

        return $token;
    }
}
