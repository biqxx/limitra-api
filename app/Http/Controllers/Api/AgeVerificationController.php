<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class AgeVerificationController extends BaseController
{
    public function verify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'birth_date' => ['required_without:confirmed', 'nullable', 'date', 'before:today'],
            'confirmed' => ['required_without:birth_date', 'nullable', 'boolean'],
            'guest_token' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        if (! empty($validated['birth_date'])) {
            $age = Carbon::parse($validated['birth_date'])->age;
            if ($age < 18) {
                return $this->error(
                    'You must be at least 18 years of age to access age-restricted content.',
                    403,
                    null,
                    'AGE_VERIFICATION_FAILED'
                );
            }
        } elseif (empty($validated['confirmed'])) {
            return $this->error(
                'Age confirmation must be accepted.',
                422,
                'VALIDATION_FAILED'
            );
        }

        /** @var User|null $user */
        $user = $request->user('api');
        $now = now();

        if ($user) {
            $user->forceFill([
                'is_age_verified' => true,
                'age_verified_at' => $now,
            ])->save();

            if (! empty($validated['birth_date']) && $user->profile) {
                $user->profile->update([
                    'birthday' => $validated['birth_date'],
                ]);
            }
        } else {
            $guestToken = $validated['guest_token'] ?? $request->header('X-Guest-Token');
            if ($guestToken) {
                Cache::put("age_grant:{$guestToken}", true, now()->addDays(30));
            }
        }

        return $this->success([
            'is_age_verified' => true,
            'verified_at' => $now->toIso8601String(),
        ], 'Age verification completed successfully.');
    }
}
