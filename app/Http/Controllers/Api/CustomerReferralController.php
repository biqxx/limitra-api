<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Referral\IndexCustomerReferralsRequest;
use App\Http\Requests\Referral\IndexReferralInvitationsRequest;
use App\Http\Requests\Referral\ResolveReferralRequest;
use App\Http\Requests\Referral\StoreReferralInvitationRequest;
use App\Http\Requests\Referral\StoreReferralShareEventRequest;
use App\Http\Resources\CustomerReferralResource;
use App\Http\Resources\ReferralInvitationResource;
use App\Models\Referral\CustomerReferral;
use App\Models\Referral\CustomerReferralInvitation;
use App\Services\Payment\WalletBalanceService;
use App\Services\Referral\CustomerReferralService;
use App\Services\Referral\ReferralInvitationService;
use App\Services\Settings\BusinessSettingsService;
use Illuminate\Http\JsonResponse;

class CustomerReferralController extends BaseController
{
    public function storeInvitation(
        StoreReferralInvitationRequest $request,
        ReferralInvitationService $invitations,
    ): JsonResponse {
        $invitation = $invitations->create($request->user('api'), $request->validated());

        return $this->success(new ReferralInvitationResource($invitation), 'Referral invitation accepted.', 201);
    }

    public function invitations(IndexReferralInvitationsRequest $request): JsonResponse
    {
        $data = $request->validated();
        $page = CustomerReferralInvitation::query()
            ->where('referrer_id', $request->user('api')->id)
            ->when($data['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->latest('id')
            ->paginate($data['per_page'] ?? 20);

        return $this->success([
            'items' => ReferralInvitationResource::collection($page->getCollection()),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function shareEvent(
        StoreReferralShareEventRequest $request,
        ReferralInvitationService $invitations,
    ): JsonResponse {
        return $this->success(
            $invitations->recordShare($request->user('api'), $request->validated(), $request),
            'Referral share event accepted.',
            202,
        );
    }

    public function resolve(ResolveReferralRequest $request, CustomerReferralService $referrals): JsonResponse
    {
        return $this->success(
            $referrals->resolve($request->validated('code'), $request->validated('session_id'), $request),
            'Referral resolved.',
        );
    }

    public function me(
        IndexCustomerReferralsRequest $request,
        CustomerReferralService $referrals,
        WalletBalanceService $wallets,
        BusinessSettingsService $settings,
    ): JsonResponse {
        $user = $request->user('api');
        $enabled = (bool) $settings->value('referrals.enabled');
        $code = $enabled ? $referrals->codeFor($user) : null;
        $data = $request->validated();
        $page = CustomerReferral::query()
            ->where('referrer_id', $user->id)
            ->when($data['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->with('referredUser:id,username')
            ->latest('id')
            ->paginate($data['per_page'] ?? 20);
        $counts = CustomerReferral::query()
            ->where('referrer_id', $user->id)
            ->selectRaw('status, COUNT(*) AS aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');
        $wallet = $wallets->forUser($user);
        $rewardMinor = (int) $settings->value('referrals.reward_amount_minor');

        return $this->success([
            'enabled' => $enabled,
            'code' => $code?->code,
            'share_url' => $code ? rtrim((string) config('app.frontend_url'), '/').'/ref/'.$code->code : null,
            'reward' => [
                'amount' => $wallets->money($rewardMinor),
                'amount_minor' => $rewardMinor,
                'currency' => $settings->value('referrals.currency'),
            ],
            'lim_cash_balance' => $wallet['balances']['lim_cash'],
            'lim_cash_balance_minor' => $wallet['balances']['lim_cash_minor'],
            'counts' => [
                'total' => $counts->sum(),
                'pending' => (int) ($counts['pending'] ?? 0),
                'qualified' => (int) ($counts['qualified'] ?? 0),
                'rewarded' => (int) ($counts['rewarded'] ?? 0),
                'reversed' => (int) ($counts['reversed'] ?? 0),
                'rejected' => (int) ($counts['rejected'] ?? 0),
            ],
            'total_earned' => $wallets->money((int) CustomerReferral::query()
                ->where('referrer_id', $user->id)
                ->where('status', 'rewarded')
                ->sum('reward_amount_minor')),
            'items' => CustomerReferralResource::collection($page->getCollection()),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
            ],
        ]);
    }
}
