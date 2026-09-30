<?php

namespace App\Services\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use App\Services\Syscom\ProductsService;
use App\Services\Syscom\WishlistService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class CartService
{
    public function __construct(
        protected CartResolver $resolver,
        protected PricingService $pricing,
        protected ProductsService $products,
        protected WishlistService $wishlist,
    ) {}

    /**
     * Agrega un producto al carrito (o incrementa su cantidad si ya existe) y lo sincroniza con la wishlist de Syscom.
     *
     * @param  Cart  $cart  Carrito destino.
     * @param  int  $productoId  ID de producto Syscom.
     * @param  int  $qty  Cantidad a sumar (mínimo 1).
     * @param  float|null  $unitPriceHint  Precio unitario opcional; si falta se toma del producto.
     * @return CartItem Item creado o actualizado.
     */
    public function add(Cart $cart, int $productoId, int $qty = 1, ?float $unitPriceHint = null): CartItem
    {
        $qty = max(1, $qty);

        return DB::transaction(function () use ($cart, $productoId, $qty, $unitPriceHint) {
            $product = $this->products->getProductById($productoId);

            $unit = $unitPriceHint;
            $titulo = $cart->items->firstWhere('producto_id', $productoId)?->titulo;
            $modelo = $cart->items->firstWhere('producto_id', $productoId)?->modelo;
            $marca = $cart->items->firstWhere('producto_id', $productoId)?->marca;
            $img = $cart->items->firstWhere('producto_id', $productoId)?->img_portada;
            $sku = $cart->items->firstWhere('producto_id', $productoId)?->sku;

            if (is_array($product)) {
                $precios = $product['precios'] ?? [];
                $unit = $unit ?? (float) (
                    ($precios['precio_especial'] ?? 0)
                    ?: ($precios['precio_descuento'] ?? 0)
                    ?: ($precios['precio_1'] ?? 0)
                    ?: 0.0
                );
                $titulo = $titulo ?? (string) ($product['titulo'] ?? '');
                $modelo = $modelo ?? ($product['modelo'] ?? null);
                $marca = $marca ?? ($product['marca'] ?? null);
                $img = $img ?? ($product['img_portada'] ?? null);
            }

            $item = CartItem::firstOrNew([
                'cart_id' => $cart->id,
                'producto_id' => $productoId,
            ]);

            $item->fill([
                'sku' => $sku ?? ($item->sku ?? null),
                'titulo' => $titulo ?? 'Producto Syscom',
                'modelo' => $modelo,
                'marca' => $marca,
                'img_portada' => $img,
                'unit_price' => (float) ($unit ?? 0.0),
                'qty' => (int) $item->qty + $qty,
                'snapshot' => $item->snapshot ?: ($product ?: null),
            ]);
            $item->save();

            $this->wishlist->addItem($cart, $productoId);
            $cart->refresh()->recomputeTotals();
            $cart->update(['last_synced_at' => now()]);

            return $item;
        });
    }

    /**
     * Actualiza la cantidad de un item del carrito; si llega a 0 lo elimina.
     *
     * @param  Cart  $cart  Carrito al que pertenece el item.
     * @param  int  $itemId  ID del CartItem.
     * @param  int  $qty  Nueva cantidad (valores negativos se tratan como 0).
     * @return CartItem Item actualizado o, si qty=0, el item eliminado.
     */
    public function updateQty(Cart $cart, int $itemId, int $qty): CartItem
    {
        $item = $cart->items()->whereKey($itemId)->firstOrFail();
        $newQty = max(0, $qty);

        if ($newQty === 0) {
            return $this->remove($cart, $itemId);
        }

        DB::transaction(function () use ($item, $newQty) {
            $item->qty = $newQty;
            $item->line_total = round(((float) $item->unit_price) * $newQty, 2);
            $item->save();
        });

        $cart->refresh()->recomputeTotals();

        return $item->fresh();
    }

    /**
     * Elimina un item del carrito y lo quita también de la wishlist Syscom.
     *
     * @param  Cart  $cart  Carrito al que pertenece el item.
     * @param  int  $itemId  ID del CartItem a eliminar.
     * @return CartItem Item eliminado (modelo sin persistir).
     */
    public function remove(Cart $cart, int $itemId): CartItem
    {
        $item = $cart->items()->whereKey($itemId)->firstOrFail();
        $productoId = (int) $item->producto_id;

        DB::transaction(function () use ($item) {
            $item->delete();
        });

        $this->wishlist->removeItem($cart, $productoId);
        $cart->refresh()->recomputeTotals();

        return $item;
    }

    /**
     * Vacía todos los items del carrito y recalcula totales.
     *
     * @param  Cart  $cart  Carrito a vaciar.
     * @return Cart Carrito recargado sin items.
     */
    public function clear(Cart $cart): Cart
    {
        DB::transaction(function () use ($cart) {
            $cart->items()->delete();
        });

        $cart->recomputeTotals();

        return $cart->fresh('items');
    }

    /**
     * Sincroniza el carrito local con la wishlist remota de Syscom: agrega los faltantes y elimina los que ya no están.
     *
     * @param  Cart  $cart  Carrito a sincronizar.
     * @return Cart Carrito recargado tras la sincronización.
     */
    public function syncWithSyscom(Cart $cart): Cart
    {
        try {
            $remote = $this->wishlist->fetchItems($cart);

            if ($remote === []) {
                return $cart->fresh('items');
            }

            $remoteIds = collect($remote)
                ->map(fn ($r) => (int) ($r['producto_id'] ?? $r['id'] ?? 0))
                ->filter(fn ($id) => $id > 0)
                ->all();

            $localItems = $cart->items()->get();
            $localIds = $localItems->pluck('producto_id')->map(fn ($v) => (int) $v)->all();

            $toAdd = array_diff($remoteIds, $localIds);
            $toRemove = array_diff($localIds, $remoteIds);

            foreach ($toAdd as $productoId) {
                $this->add($cart, (int) $productoId, 1);
            }

            foreach ($toRemove as $productoId) {
                $item = $localItems->firstWhere('producto_id', (int) $productoId);
                if ($item !== null) {
                    $item->delete();
                }
            }

            $cart->update(['last_synced_at' => now()]);
            $cart->refresh()->recomputeTotals();
        } catch (Throwable $e) {
            Log::error('Cart sync with Syscom failed', [
                'cart_id' => $cart->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $cart->fresh('items');
    }
}
