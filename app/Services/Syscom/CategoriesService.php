<?php

namespace App\Services\Syscom;

use App\Services\Syscom\DTOs\CategoryDto;
use Illuminate\Support\Facades\Cache;

class CategoriesService
{
    public function __construct(protected SyscomHttpClient $client) {}

    /**
     * Devuelve todas las categorías de Syscom como arreglos, cacheadas por el TTL configurado.
     *
     * @return array Lista de categorías (id, nivel, nombre).
     */
    public function getCategories(): array
    {
        $ttl = (int) config('syscom.cache.categories_ttl', 86400);
        $cacheKey = 'syscom:categories:all';

        return Cache::remember($cacheKey, $ttl, function (): array {
            $data = $this->client->get('categorias');

            return array_map(
                static fn (array $c) => CategoryDto::fromArray($c)->toArray(),
                $data
            );
        });
    }

    /**
     * Devuelve una categoría de Syscom por su ID, cacheada por el TTL configurado.
     *
     * @param  int  $id  ID de la categoría en Syscom.
     * @return array|null Categoría encontrada o null si la API falla o no existe.
     */
    public function getCategoryById(int $id): ?array
    {
        $ttl = (int) config('syscom.cache.categories_ttl', 86400);
        $cacheKey = "syscom:categories:{$id}";

        return Cache::remember($cacheKey, $ttl, function () use ($id): ?array {
            try {
                $data = $this->client->get("categorias/{$id}");
            } catch (\Throwable) {
                return null;
            }

            return $data ? CategoryDto::fromArray($data)->toArray() : null;
        });
    }
}
