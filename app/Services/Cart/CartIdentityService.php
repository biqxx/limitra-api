<?php

namespace App\Services\Cart;

use App\Models\Cart\Cart;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CartIdentityService
{
    private ?string $issuedToken = null;

    public function active(Request $request, bool $create = true): ?Cart
    {
        if ($user = auth('api')->user()) {
            return $create ? Cart::activeForUser($user->id) : Cart::where('user_id', $user->id)->active()->first();
        }

        $token = $this->token($request);
        $cart = $token ? Cart::activeForGuestToken($token) : null;

        if (! $cart && $create) {
            $this->issuedToken = Str::random(64);
            $cart = Cart::create([
                'guest_token_hash' => hash('sha256', $this->issuedToken),
                'guest_expires_at' => now()->addDays(30),
                'status' => 'active',
            ]);
        }

        return $cart;
    }

    public function authorize(Request $request, Cart $cart): void
    {
        $active = $this->active($request, false);
        if (! $active || ! $active->is($cart)) {
            throw new AuthorizationException;
        }
    }

    public function token(Request $request): ?string
    {
        return $request->header('X-Cart-Token') ?: $request->cookie('limitra_cart_token');
    }

    public function attachToken(JsonResponse $response): JsonResponse
    {
        if (! $this->issuedToken) {
            return $response;
        }

        $secure = app()->environment('production');
        $response->headers->set('X-Cart-Token', $this->issuedToken);
        $response->withCookie(cookie(
            'limitra_cart_token',
            $this->issuedToken,
            60 * 24 * 30,
            '/',
            null,
            $secure,
            true,
            false,
            'lax'
        ));

        return $response;
    }
}
