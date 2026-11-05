<?php

namespace App\Http\Controllers\Api\Content;

use App\Http\Controllers\Api\BaseController;
use App\Models\Content\NewsletterSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class NewsletterController extends BaseController
{
    public function subscribe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'source' => ['nullable', 'string', 'max:50'],
        ]);

        $email = strtolower(trim($validated['email']));

        $subscription = NewsletterSubscription::query()->where('email', $email)->first();

        if ($subscription) {
            if ($subscription->status !== 'subscribed') {
                $subscription->update([
                    'status' => 'subscribed',
                    'consented_at' => now(),
                    'unsubscribed_at' => null,
                ]);
            }
        } else {
            NewsletterSubscription::create([
                'email' => $email,
                'source' => $validated['source'] ?? 'footer',
                'status' => 'subscribed',
                'token' => Str::random(40),
                'consented_at' => now(),
            ]);
        }

        return $this->success(
            ['status' => 'subscribed'],
            'Thank you for subscribing to our newsletter.',
            202
        );
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'email'],
        ]);

        if (empty($validated['token']) && empty($validated['email'])) {
            return $this->error('Token or email is required.', 422);
        }

        $subscription = NewsletterSubscription::query()
            ->when(! empty($validated['token']), fn ($q) => $q->where('token', $validated['token']))
            ->when(! empty($validated['email']), fn ($q) => $q->where('email', strtolower(trim($validated['email']))))
            ->first();

        if ($subscription) {
            $subscription->update([
                'status' => 'unsubscribed',
                'unsubscribed_at' => now(),
            ]);
        }

        return $this->success(null, 'You have been successfully unsubscribed from the newsletter.');
    }

    public function verify(Request $request): JsonResponse
    {
        $token = (string) $request->input('token', $request->query('token', ''));

        if ($token === '') {
            return $this->error('Unsubscribe token is required.', 422);
        }

        $subscription = NewsletterSubscription::query()
            ->where('token', $token)
            ->first();

        if (! $subscription) {
            return $this->error('Subscription not found.', 404);
        }

        $subscription->update([
            'status' => 'unsubscribed',
            'unsubscribed_at' => now(),
        ]);

        return $this->success([
            'email' => $subscription->email,
            'status' => 'unsubscribed',
        ], 'You have been successfully unsubscribed from the newsletter.');
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'in:subscribed,unsubscribed'],
            'q' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = NewsletterSubscription::query()->latest('id');

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['q'])) {
            $term = $validated['q'];
            $query->where('email', 'like', "%{$term}%");
        }

        $paginator = $query->paginate($validated['per_page'] ?? 20);

        return $this->success([
            'items' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }

    public function destroy(NewsletterSubscription $subscription): JsonResponse
    {
        $subscription->delete();

        return $this->success(null, 'Newsletter subscription deleted.');
    }
}
