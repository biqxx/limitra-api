<?php

namespace App\Http\Controllers\Api\Affiliate;

use App\Http\Controllers\Api\BaseController;
use App\Services\Affiliate\AffiliateAttributionService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AffiliateTrackingController extends BaseController
{
    public function __construct(
        private readonly AffiliateAttributionService $attributionService
    ) {}

    /**
     * Resolve public tracked referral link and redirect with attribution cookie.
     */
    public function resolve(Request $request, string $slug): Response
    {
        $result = $this->attributionService->resolveTrackingClick($slug, $request);

        if ($request->wantsJson()) {
            $response = $this->success([
                'destination_url' => $result['destination'],
            ], 'Affiliate link resolved.');

            if ($result['cookie']) {
                $response->withCookie($result['cookie']);
            }

            return $response;
        }

        $redirect = redirect($result['destination']);
        if ($result['cookie']) {
            $redirect->withCookie($result['cookie']);
        }

        return $redirect;
    }
}
