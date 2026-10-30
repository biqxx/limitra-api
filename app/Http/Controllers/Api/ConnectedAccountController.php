<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\ConnectedAccountResource;
use App\Models\Social\SocialAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConnectedAccountController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $accounts = $request->user('api')->socialAccounts()->latest('id')->get();

        return $this->success(
            ConnectedAccountResource::collection($accounts),
            'Connected accounts retrieved successfully.'
        );
    }

    public function destroy(Request $request, string $platform): JsonResponse
    {
        /** @var SocialAccount|null $account */
        $account = $request->user('api')->socialAccounts()
            ->where('platform', strtolower($platform))
            ->first();

        if (! $account) {
            return $this->error('Connected account not found.', 404);
        }

        if ($account->conversations()->exists()) {
            $account->update(['user_id' => null]);
        } else {
            $account->delete();
        }

        return $this->success(null, "Account {$platform} disconnected successfully.");
    }
}
