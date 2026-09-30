<?php

namespace App\Services\Syscom;

use App\Services\Currency\ExchangeRateService;
use App\Services\Syscom\DTOs\ProductsPageDto;
use Illuminate\Support\Facades\Cache;

class ProductsService
{
    public function __construct(
        protected SyscomHttpClient $client,
        protected ExchangeRateService $exchange,
    ) {}

    /**
     * Lista productos de Syscom con caché, aplicando comisión, conversión a MXN y tipo de cambio.
     *
     * @param  array  $params  Parámetros de búsqueda (categoria, marca, stock, busqueda, pagina, orden, max, precio_min, precio_max).
     * @return array Página de productos con totales y tipo de cambio.
     */
    public function getProducts(array $params): array
    {
        $query = $this->parseParams($params);
        $cacheKey = 'syscom:products:'.md5(json_encode($query, JSON_UNESCAPED_UNICODE));
        $ttl = (int) config('syscom.cache.products_ttl', 600);

        $page = Cache::remember($cacheKey, $ttl, function () use ($query): array {
            $data = $this->client->get('productos', $query);

            return ProductsPageDto::fromArray($data)->toArray();
        });

        $page['productos'] = $this->addMxnCurrencyToProducts(
            $this->addCommissionToProducts($this->convertPricesToMxn($page['productos']))
        );
        $page['tipo_cambio'] = $this->exchange->getUsdToMxnRate();

        return $page;
    }

    /**
     * Devuelve un producto de Syscom por ID, con precios convertidos a MXN y comisión aplicada.
     *
     * @param  int  $id  ID del producto en Syscom.
     * @return array|null Producto enriquecido o null si no existe o la API falla.
     */
    public function getProductById(int $id): ?array
    {
        $cacheKey = "syscom:product:{$id}";
        $ttl = (int) config('syscom.cache.product_ttl', 600);

        $product = Cache::remember($cacheKey, $ttl, function () use ($id): ?array {
            try {
                $data = $this->client->get("productos/{$id}");
            } catch (\Throwable) {
                return null;
            }

            return $data ?: null;
        });

        if ($product === null) {
            return null;
        }

        $converted = $this->convertPricesToMxn([$product]);

        return $this->addMxnCurrencyToProducts($this->addCommissionToProducts($converted))[0] ?? $product;
    }

    /**
     * Devuelve las marcas disponibles, opcionalmente filtradas por categoría.
     *
     * @param  int|null  $categoriaId  ID de categoría para filtrar; null devuelve todas.
     * @return array Lista de marcas (vacía si la API falla).
     */
    public function getBrands(?int $categoriaId = null): array
    {
        $cacheKey = 'syscom:brands'.($categoriaId ? ":cat:{$categoriaId}" : ':all');
        $ttl = (int) config('syscom.cache.brands_ttl', 86400);

        return Cache::remember($cacheKey, $ttl, function () use ($categoriaId): array {
            $query = $categoriaId ? ['categoria' => $categoriaId] : [];
            try {
                $data = $this->client->get('marcas', $query);

                return is_array($data) ? $data : [];
            } catch (\Throwable) {
                return [];
            }
        });
    }

    /**
     * Devuelve el ID de la categoría destacada para mostrar en portada (lista curada con fallback al primero).
     *
     * @return int|null ID de la categoría preferida o de la primera disponible, o null si no hay categorías.
     */
    public function getFeaturedCategoryId(): ?int
    {
        $cats = app(CategoriesService::class)->getCategories();
        if ($cats === []) {
            return null;
        }
        $preferredNames = ['Redes', 'Videovigilancia', 'Energía', 'Cableado Estructurado', 'Automatización'];
        foreach ($preferredNames as $name) {
            foreach ($cats as $c) {
                if (strcasecmp($c['nombre'] ?? '', $name) === 0) {
                    return (int) $c['id'];
                }
            }
        }

        return (int) $cats[0]['id'];
    }

    /**
     * Normaliza y sanitiza los parámetros de búsqueda aceptados por el API de Syscom.
     *
     * @param  array  $params  Parámetros crudos del request.
     * @return array Arreglo con solo las claves válidas y tipos seguros para enviar como query string.
     */
    protected function parseParams(array $params): array
    {
        $query = [];

        if (isset($params['categoria']) && is_numeric($params['categoria'])) {
            $query['categoria'] = (int) $params['categoria'];
        }
        if (isset($params['marca']) && $params['marca'] !== '') {
            $query['marca'] = $params['marca'];
        }
        if (isset($params['stock']) && $params['stock'] !== '') {
            $query['stock'] = $params['stock'];
        }
        if (isset($params['busqueda']) && $params['busqueda'] !== '') {
            $query['busqueda'] = $params['busqueda'];
        }
        if (isset($params['pagina']) && is_numeric($params['pagina'])) {
            $query['pagina'] = max(1, (int) $params['pagina']);
        }
        if (isset($params['orden']) && $params['orden'] !== '') {
            $query['orden'] = $params['orden'];
        }
        if (isset($params['max']) && is_numeric($params['max'])) {
            $query['max'] = max(1, min(200, (int) $params['max']));
        }
        if (isset($params['precio_min']) && is_numeric($params['precio_min'])) {
            $query['precio_min'] = (float) $params['precio_min'];
        }
        if (isset($params['precio_max']) && is_numeric($params['precio_max'])) {
            $query['precio_max'] = (float) $params['precio_max'];
        }

        return $query;
    }

    /**
     * Aplica el porcentaje de comisión configurado a cada precio y al campo `precio` de los productos.
     *
     * @param  array  $products  Lista de productos con `precios` (USD antes de conversión).
     * @return array Misma lista con precios incrementados (mutados por referencia).
     */
    public function addCommissionToProducts(array $products): array
    {
        $commissionRate = (float) config('syscom.commission_rate', 0.1);
        $multiplier = 1 + $commissionRate;

        foreach ($products as &$product) {
            $precios = $product['precios'] ?? null;
            if (is_array($precios)) {
                foreach (['precio_1', 'precio_descuento', 'precio_especial', 'precio_lista'] as $field) {
                    if (isset($precios[$field]) && is_numeric($precios[$field]) && (float) $precios[$field] > 0) {
                        $precios[$field] = round((float) $precios[$field] * $multiplier, 2);
                    }
                }
                $product['precios'] = $precios;
            }

            $product['precio'] = round($this->effectivePrice($product) * $multiplier, 2);
        }
        unset($product);

        return $products;
    }

    /**
     * Convierte los precios USD de cada producto a MXN usando la tasa actual, preservando el original en `precios_usd`.
     *
     * @param  array  $products  Lista de productos con precios en USD.
     * @return array Misma lista con precios en MXN y respaldo USD en `precios_usd`.
     */
    public function convertPricesToMxn(array $products): array
    {
        $rate = $this->exchange->getUsdToMxnRate();

        foreach ($products as &$product) {
            $precios = $product['precios'] ?? null;
            if (is_array($precios)) {
                $preciosUsd = [];
                foreach (['precio_1', 'precio_descuento', 'precio_especial', 'precio_lista'] as $field) {
                    if (isset($precios[$field]) && is_numeric($precios[$field])) {
                        $value = (float) $precios[$field];
                        $preciosUsd[$field] = round($value, 2);
                        if ($value > 0) {
                            $precios[$field] = round($value * $rate, 2);
                        }
                    }
                }
                $product['precios'] = $precios;
                $product['precios_usd'] = $preciosUsd;
            }

            $product['moneda_origen'] = 'USD';
        }
        unset($product);

        return $products;
    }

    /**
     * Agrega a cada producto los campos formateados `precio_mxn`, `precio_usd`, `precio_usd_formatted` y `moneda`.
     *
     * @param  array  $products  Lista de productos con precios ya en MXN.
     * @return array Misma lista con los campos de visualización agregados.
     */
    public function addMxnCurrencyToProducts(array $products): array
    {
        foreach ($products as &$product) {
            $base = isset($product['precio']) && is_numeric($product['precio'])
                ? (float) $product['precio']
                : $this->effectivePrice($product);

            $product['precio_mxn'] = '$'.number_format($base, 2).' MXN';
            $product['moneda'] = 'MXN';

            $usdBase = $this->usdEffectivePrice($product);
            $product['precio_usd'] = round($usdBase, 2);
            $product['precio_usd_formatted'] = '$'.number_format($usdBase, 2).' USD';
        }
        unset($product);

        return $products;
    }

    /**
     * Devuelve el precio efectivo en USD usando prioridad especial > descuento > precio_1 > lista.
     *
     * @param  array  $product  Producto con `precios_usd` o `precios`.
     * @return float Precio efectivo en USD.
     */
    protected function usdEffectivePrice(array $product): float
    {
        $precios = $product['precios_usd'] ?? $product['precios'] ?? [];

        $especial = (float) ($precios['precio_especial'] ?? 0);
        if ($especial > 0) {
            return $especial;
        }
        $descuento = (float) ($precios['precio_descuento'] ?? 0);
        if ($descuento > 0) {
            return $descuento;
        }
        $uno = (float) ($precios['precio_1'] ?? 0);
        if ($uno > 0) {
            return $uno;
        }

        return (float) ($precios['precio_lista'] ?? 0);
    }

    /**
     * Devuelve el precio efectivo (en la moneda actual de `precios`) usando prioridad especial > descuento > precio_1 > lista.
     *
     * @param  array  $product  Producto con clave `precios`.
     * @return float Precio efectivo del producto.
     */
    protected function effectivePrice(array $product): float
    {
        $precios = $product['precios'] ?? [];

        $especial = (float) ($precios['precio_especial'] ?? 0);
        if ($especial > 0) {
            return $especial;
        }
        $descuento = (float) ($precios['precio_descuento'] ?? 0);
        if ($descuento > 0) {
            return $descuento;
        }
        $uno = (float) ($precios['precio_1'] ?? 0);
        if ($uno > 0) {
            return $uno;
        }

        return (float) ($precios['precio_lista'] ?? 0);
    }
}
