<?php

namespace App\Http\Middleware;

use App\Jobs\RecordAnalyticsEvent;
use App\Services\Analytics\AnalyticsTrackingService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrackAnalytics
{
    public function handle(Request $request, Closure $next): Response
    {
        $sessionId = AnalyticsTrackingService::resolveSessionId($request);
        $userId = $request->user()?->id;

        $request->attributes->set('analytics_session_id', $sessionId);

        $response = $next($request);
        $response->headers->set('X-Session-ID', $sessionId);

        dispatch(RecordAnalyticsEvent::pageView($request, $sessionId, $userId));

        return $response;
    }
}
