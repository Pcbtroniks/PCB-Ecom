<?php

namespace App\Services\Syscom;

use App\Models\Cart;
use Illuminate\Support\Facades\Log;
use Throwable;

class WishlistService
{
    public function __construct(protected SyscomHttpClient $client) {}

    /**
     * Indica si la integración con la wishlist de Syscom está habilitada por configuración.
     *
     * @return bool true si `syscom.wishlist.enabled` es verdadero.
     */
    public function isEnabled(): bool
    {
        return (bool) config('syscom.wishlist.enabled', true);
    }

    /**
     * Asegura que el carrito tenga un wishlist_id en Syscom, creándolo si hace falta, y lo persiste en el carrito.
     *
     * @param  Cart  $cart  Carrito al que asociar la wishlist.
     * @return string|null ID remoto de la wishlist o null si falla.
     */
    public function ensureWishlist(Cart $cart): ?string
    {
        if (! $this->isEnabled()) {
            return $cart->syscom_wishlist_id;
        }

        if (! empty($cart->syscom_wishlist_id)) {
            return $cart->syscom_wishlist_id;
        }

        try {
            $response = $this->client->post('wishlist', [
                'producto_id' => null,
                'origen' => 'pcbecom',
            ]);

            $wishlistId = $response['id'] ?? $response['wishlist_id'] ?? null;

            if (is_string($wishlistId) && $wishlistId !== '') {
                $cart->syscom_wishlist_id = $wishlistId;
                $cart->save();

                return $wishlistId;
            }
        } catch (Throwable $e) {
            Log::warning('Syscom wishlist ensure failed', [
                'cart_id' => $cart->id,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Agrega un producto a la wishlist remota de Syscom asociada al carrito (creándola si hace falta).
     *
     * @param  Cart  $cart  Carrito asociado a la wishlist.
     * @param  int  $productoId  ID de producto Syscom.
     * @return bool true si la API respondió con éxito.
     */
    public function addItem(Cart $cart, int $productoId): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        $wishlistId = $this->ensureWishlist($cart);
        if ($wishlistId === null) {
            return false;
        }

        try {
            $this->client->post("wishlist/{$wishlistId}/items", [
                'producto_id' => $productoId,
            ]);

            return true;
        } catch (Throwable $e) {
            Log::warning('Syscom wishlist add item failed', [
                'cart_id' => $cart->id,
                'wishlist_id' => $wishlistId,
                'producto_id' => $productoId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Quita un producto de la wishlist remota de Syscom asociada al carrito.
     *
     * @param  Cart  $cart  Carrito asociado a la wishlist.
     * @param  int  $productoId  ID de producto Syscom.
     * @return bool true si la API respondió con éxito.
     */
    public function removeItem(Cart $cart, int $productoId): bool
    {
        if (! $this->isEnabled() || empty($cart->syscom_wishlist_id)) {
            return false;
        }

        try {
            $this->client->delete("wishlist/{$cart->syscom_wishlist_id}/items/{$productoId}");

            return true;
        } catch (Throwable $e) {
            Log::warning('Syscom wishlist remove item failed', [
                'cart_id' => $cart->id,
                'producto_id' => $productoId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Devuelve los items de la wishlist remota de Syscom asociada al carrito.
     *
     * @param  Cart  $cart  Carrito asociado a la wishlist.
     * @return array Items remotos (vacío si falla o no hay wishlist).
     */
    public function fetchItems(Cart $cart): array
    {
        if (! $this->isEnabled() || empty($cart->syscom_wishlist_id)) {
            return [];
        }

        try {
            $response = $this->client->get("wishlist/{$cart->syscom_wishlist_id}/items");

            return is_array($response) ? $response : [];
        } catch (Throwable $e) {
            Log::warning('Syscom wishlist fetch items failed', [
                'cart_id' => $cart->id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }
}
