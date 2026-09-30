<?php

namespace App\Services\Syscom;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class SyscomHttpClient
{
    protected ?string $cachedToken = null;

    public function __construct(
        protected TokenCache $tokenCache,
        protected string $baseUrl,
    ) {}

    /**
     * Ejecuta una petición GET al API de Syscom.
     *
     * @param  string  $path  Endpoint relativo (ej. `productos`).
     * @param  array  $query  Parámetros de query string.
     * @return array Cuerpo JSON decodificado.
     */
    public function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, query: $query);
    }

    /**
     * Ejecuta una petición POST al API de Syscom con cuerpo JSON.
     *
     * @param  string  $path  Endpoint relativo.
     * @param  array  $json  Cuerpo a enviar como JSON.
     * @return array Cuerpo JSON decodificado.
     */
    public function post(string $path, array $json = []): array
    {
        return $this->request('POST', $path, json: $json);
    }

    /**
     * Ejecuta una petición PUT al API de Syscom con cuerpo JSON.
     *
     * @param  string  $path  Endpoint relativo.
     * @param  array  $json  Cuerpo a enviar como JSON.
     * @return array Cuerpo JSON decodificado.
     */
    public function put(string $path, array $json = []): array
    {
        return $this->request('PUT', $path, json: $json);
    }

    /**
     * Ejecuta una petición DELETE al API de Syscom.
     *
     * @param  string  $path  Endpoint relativo.
     * @param  array  $query  Parámetros de query string.
     * @return array Cuerpo JSON decodificado.
     */
    public function delete(string $path, array $query = []): array
    {
        return $this->request('DELETE', $path, query: $query);
    }

    /**
     * Lógica común: envía la petición con token, maneja 401 (refresca token), 429 (espera Retry-After) y reintenta con backoff.
     *
     * @param  string  $method  Verbo HTTP en mayúsculas.
     * @param  string  $path  Endpoint relativo.
     * @param  array  $query  Parámetros de query string.
     * @param  array  $json  Cuerpo JSON (solo para POST/PUT).
     * @return array Cuerpo JSON decodificado.
     *
     * @throws RuntimeException Si se agotan los reintentos o la API devuelve un error no recuperable.
     */
    protected function request(string $method, string $path, array $query = [], array $json = []): array
    {
        $url = $this->buildUrl($path);
        $attempts = 0;
        $maxAttempts = max(1, (int) config('syscom.http.retry_times', 3));
        $sleepMs = max(0, (int) config('syscom.http.retry_sleep_ms', 200));
        $lastError = null;

        while ($attempts < $maxAttempts) {
            $attempts++;
            $token = $this->getToken();

            try {
                /** @var Response $response */
                $response = $this->buildRequest($token, $json)
                    ->{$this->httpMethod($method)}($url, $query);

                if ($response->status() === 401) {
                    $this->tokenCache->forget();
                    $this->cachedToken = null;

                    continue;
                }

                if ($response->status() === 429) {
                    $retryAfter = (int) ($response->header('Retry-After') ?? 1);
                    Log::warning('Syscom rate limited', [
                        'url' => $url,
                        'retry_after' => $retryAfter,
                        'attempt' => $attempts,
                    ]);
                    $this->sleepBackoff($retryAfter * 1000, $attempts);

                    continue;
                }

                if ($response->serverError() && $attempts < $maxAttempts) {
                    $this->sleepBackoff($sleepMs, $attempts);

                    continue;
                }

                if ($response->failed()) {
                    throw new RuntimeException(sprintf(
                        'Syscom %s %s failed [%d]: %s',
                        $method,
                        $path,
                        $response->status(),
                        $response->body()
                    ));
                }

                return $response->json() ?? [];
            } catch (RequestException $e) {
                $lastError = $e;
                if ($attempts < $maxAttempts) {
                    $this->sleepBackoff($sleepMs, $attempts);

                    continue;
                }
                break;
            } catch (Throwable $e) {
                $lastError = $e;
                if ($attempts < $maxAttempts) {
                    $this->sleepBackoff($sleepMs, $attempts);

                    continue;
                }
                break;
            }
        }

        Log::error('Syscom request exhausted retries', [
            'method' => $method,
            'path' => $path,
            'error' => $lastError?->getMessage(),
        ]);

        throw new RuntimeException(
            'Syscom request failed after '.$maxAttempts.' attempts: '.($lastError?->getMessage() ?? 'unknown'),
            previous: $lastError instanceof Throwable ? $lastError : null,
        );
    }

    /**
     * Devuelve el token de acceso cacheado en memoria, solicitándolo al TokenCache si aún no se cargó.
     *
     * @return string Token Bearer listo para enviar en `Authorization`.
     */
    protected function getToken(): string
    {
        if (is_string($this->cachedToken) && $this->cachedToken !== '') {
            return $this->cachedToken;
        }

        return $this->cachedToken = $this->tokenCache->get();
    }

    /**
     * Construye el cliente HTTP con token Bearer, cabeceras JSON y timeouts; añade el cuerpo si se proporciona.
     *
     * @param  string  $token  Token de acceso.
     * @param  array  $json  Cuerpo JSON a serializar (vacío = sin body).
     * @return PendingRequest Cliente listo para ejecutar el verbo HTTP.
     */
    protected function buildRequest(string $token, array $json): PendingRequest
    {
        $request = Http::withToken($token)
            ->acceptJson()
            ->timeout((int) config('syscom.http.timeout', 15))
            ->connectTimeout((int) config('syscom.http.connect_timeout', 5));

        if ($json !== []) {
            $request = $request->withBody(
                json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'application/json'
            );
        }

        return $request;
    }

    /**
     * Une la URL base y el path en una sola URL absoluta, normalizando las barras.
     *
     * @param  string  $path  Endpoint relativo.
     * @return string URL completa sin slash duplicado.
     */
    protected function buildUrl(string $path): string
    {
        $base = rtrim($this->baseUrl, '/');
        $path = ltrim($path, '/');

        return $base.'/'.$path;
    }

    /**
     * Mapea un verbo HTTP en mayúsculas al nombre de método correspondiente en el HTTP client.
     *
     * @param  string  $method  Verbo HTTP (GET, POST, PUT, DELETE, PATCH).
     * @return string Nombre del método del cliente HTTP en minúsculas.
     */
    protected function httpMethod(string $method): string
    {
        return match ($method) {
            'GET' => 'get',
            'POST' => 'post',
            'PUT' => 'put',
            'DELETE' => 'delete',
            'PATCH' => 'patch',
            default => strtolower($method),
        };
    }

    /**
     * Duerme el proceso aplicando backoff exponencial con jitter aleatorio entre intentos.
     *
     * @param  int  $baseMs  Milisegundos base del retardo.
     * @param  int  $attempt  Número de intento actual (1-based).
     */
    protected function sleepBackoff(int $baseMs, int $attempt): void
    {
        $jitter = random_int(0, max(1, (int) ($baseMs / 2)));
        $delay = (int) (($baseMs * (2 ** ($attempt - 1))) + $jitter);
        usleep($delay * 1000);
    }
}
