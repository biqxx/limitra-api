<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiErrorResponse;
use App\Models\Product\Category;
use App\Models\Product\Product;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class EnsureAgeVerified
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $product = $request->route('product');
        if ($product instanceof Product) {
            $isAgeGated = (bool) ($product->is_age_gated || $product->category?->is_age_gated || $product->category?->age_gated);
            if (! $isAgeGated) {
                return $next($request);
            }
        }

        $category = $request->route('category');
        if ($category instanceof Category) {
            $isAgeGated = (bool) ($category->is_age_gated || $category->age_gated);
            if (! $isAgeGated) {
                return $next($request);
            }
        }

        /** @var User|null $user */
        $user = $request->user('api');

        if ($user instanceof User && $user->is_age_verified) {
            return $next($request);
        }

        $guestToken = $request->header('X-Guest-Token', $request->query('guest_token'));
        if ($guestToken && Cache::has("age_grant:{$guestToken}")) {
            return $next($request);
        }

        return ApiErrorResponse::make(
            'AGE_VERIFICATION_REQUIRED',
            'Age verification required to access this resource.',
            403,
        );
    }
}
