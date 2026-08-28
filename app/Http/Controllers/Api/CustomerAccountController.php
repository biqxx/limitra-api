<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\OrderResource;
use App\Models\User\AuthSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class CustomerAccountController extends BaseController
{
    public function dashboard(): JsonResponse
    {
        $user = auth('api')->user();
        $recentOrders = $user->orders()->with(['items.product', 'shippingAddress'])->latest()->limit(5)->get();

        return $this->success([
            'counts' => [
                'orders' => $user->orders()->count(),
                'favorites' => $user->favorites()->count(),
                'cart_items' => $user->cartItems()->count(),
                'addresses' => $user->addresses()->count(),
            ],
            'recent_orders' => OrderResource::collection($recentOrders),
            'wallet' => $user->account ? [
                'balance' => $user->account->balance,
                'currency' => $user->account->currency,
            ] : ['balance' => '0.00', 'currency' => 'NGN'],
        ]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password:api'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);
        $user = auth('api')->user();
        $user->update(['password' => $data['password']]);
        $currentSessionId = auth('api')->payload()->get('sid');
        $user->authSessions()
            ->when($currentSessionId, fn ($query) => $query->whereKeyNot($currentSessionId))
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        return response()->json(null, 204);
    }

    public function sessions(): JsonResponse
    {
        $currentSessionId = auth('api')->payload()->get('sid');
        $sessions = auth('api')->user()->authSessions()
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->latest('last_used_at')
            ->get()
            ->map(fn (AuthSession $session) => [
                'id' => $session->id,
                'device_name' => $session->device_name,
                'ip_address' => $session->ip_address,
                'user_agent' => $session->user_agent,
                'last_used_at' => $session->last_used_at,
                'expires_at' => $session->expires_at,
                'current' => $session->id === $currentSessionId,
            ]);

        return $this->success($sessions);
    }

    public function revokeSession(Request $request, AuthSession $session): JsonResponse
    {
        $request->validate(['current_password' => ['required', 'current_password:api']]);
        abort_unless($session->user_id === auth('api')->id(), 403);
        $session->update(['revoked_at' => now()]);

        return $this->success(null, 'Session revoked.');
    }
}
