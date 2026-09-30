<?php

namespace App\Services\Cart;

use App\Models\Cart;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CartResolver
{
    public const COOKIE_NAME = 'cart_token';

    public const HEADER_NAME = 'X-Cart-Token';

    public const COOKIE_MINUTES = 60 * 24 * 30;

    /**
     * Resuelve el carrito para el request: usa el del usuario autenticado o, en su defecto, el del invitado.
     *
     * @param  Request  $request  Request HTTP entrante.
     * @return Cart Carrito abierto (existente o recién creado).
     */
    public function resolve(Request $request): Cart
    {
        $user = $request->user();

        if ($user instanceof User) {
            return $this->resolveForUser($user);
        }

        return $this->resolveForGuest($request);
    }

    /**
     * Devuelve el carrito abierto del usuario o crea uno nuevo en MXN si no existe.
     *
     * @param  User  $user  Usuario autenticado.
     * @return Cart Carrito abierto del usuario.
     */
    public function resolveForUser(User $user): Cart
    {
        $cart = Cart::where('user_id', $user->id)
            ->where('status', 'open')
            ->latest('id')
            ->first();

        if ($cart === null) {
            $cart = Cart::create([
                'user_id' => $user->id,
                'session_token' => null,
                'status' => 'open',
                'currency' => 'MXN',
            ]);
        }

        return $cart;
    }

    /**
     * Devuelve el carrito abierto de invitado asociado al token, o crea uno nuevo si falta o es inválido.
     *
     * @param  Request  $request  Request HTTP entrante.
     * @return Cart Carrito abierto de invitado.
     */
    public function resolveForGuest(Request $request): Cart
    {
        $token = $this->extractToken($request);

        if (! is_string($token) || strlen($token) < 32) {
            $token = Str::random(64);
        }

        $cart = Cart::where('session_token', $token)
            ->whereNull('user_id')
            ->where('status', 'open')
            ->latest('id')
            ->first();

        if ($cart === null) {
            $cart = Cart::create([
                'user_id' => null,
                'session_token' => $token,
                'status' => 'open',
                'currency' => 'MXN',
            ]);
        }

        return $cart;
    }

    /**
     * Extrae un token de carrito válido desde el header `X-Cart-Token` o desde la cookie `cart_token`.
     *
     * @param  Request  $request  Request HTTP entrante.
     * @return string|null Token crudo si pasa validación, o null si no hay ninguno utilizable.
     */
    protected function extractToken(Request $request): ?string
    {
        $header = $request->headers->get(self::HEADER_NAME);
        if (is_string($header) && $header !== '' && $this->looksLikeRawToken($header)) {
            return $header;
        }

        $cookie = $request->cookie(self::COOKIE_NAME);
        if (is_string($cookie) && $cookie !== '' && $this->looksLikeRawToken($cookie)) {
            return $cookie;
        }

        return null;
    }

    /**
     * Verifica que un string tenga forma de token crudo (32-128 caracteres alfanuméricos).
     *
     * @param  string  $value  Valor a validar.
     * @return bool true si cumple longitud y patrón.
     */
    protected function looksLikeRawToken(string $value): bool
    {
        if (strlen($value) < 32 || strlen($value) > 128) {
            return false;
        }

        return (bool) preg_match('/^[A-Za-z0-9]+$/', $value);
    }

    /**
     * Encola la cookie `cart_token` en la respuesta si el carrito es de invitado y no coincide con la cookie/header actual.
     *
     * @param  Request  $request  Request HTTP entrante.
     * @param  Cart  $cart  Carrito a reflejar en la cookie.
     */
    public function attachCookie(Request $request, Cart $cart): void
    {
        if ($cart->user_id !== null) {
            return;
        }

        if (! is_string($cart->session_token)) {
            return;
        }

        if ($request->cookie(self::COOKIE_NAME) === $cart->session_token) {
            return;
        }

        if ($request->headers->get(self::HEADER_NAME) === $cart->session_token) {
            return;
        }

        cookie()->queue(
            self::COOKIE_NAME,
            $cart->session_token,
            self::COOKIE_MINUTES,
            '/',
            null,
            false,
            true,
        );
    }

    /**
     * Fusiona el carrito de invitado dentro del carrito del usuario; suma cantidades si el producto ya existe.
     *
     * @param  Cart  $guestCart  Carrito de invitado origen.
     * @param  User  $user  Usuario destino.
     * @return Cart Carrito del usuario ya fusionado y con totales recalculados.
     */
    public function transferGuestToUser(Cart $guestCart, User $user): Cart
    {
        $userCart = $this->resolveForUser($user);

        foreach ($guestCart->items()->get() as $guestItem) {
            $existing = $userCart->items()
                ->where('producto_id', $guestItem->producto_id)
                ->first();

            if ($existing === null) {
                $guestItem->update(['cart_id' => $userCart->id]);

                continue;
            }

            $existing->qty = (int) $existing->qty + (int) $guestItem->qty;
            $existing->line_total = round(((float) $existing->unit_price) * $existing->qty, 2);
            $existing->save();
            $guestItem->delete();
        }

        $guestCart->update(['status' => 'abandoned']);
        $userCart->refresh()->recomputeTotals();

        return $userCart->fresh('items');
    }
}
