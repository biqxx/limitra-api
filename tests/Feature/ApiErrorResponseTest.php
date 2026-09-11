<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class ApiErrorResponseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/api/v1/__test/errors/unauthenticated', function (): never {
            throw new AuthenticationException;
        });
        Route::get('/api/v1/__test/errors/forbidden', function (): never {
            throw new AuthorizationException;
        });
        Route::get('/api/v1/__test/errors/model-not-found', function (): never {
            throw (new ModelNotFoundException)->setModel(User::class, [999999]);
        });
        Route::post('/api/v1/__test/errors/validation', function (Request $request): array {
            return $request->validate(['name' => ['required', 'string']]);
        });
        Route::get('/api/v1/__test/errors/throttled', fn (): array => ['ok' => true])
            ->middleware('throttle:1,1');
        Route::get('/api/v1/__test/errors/server', function (): never {
            throw new RuntimeException('Secret provider credential must never be exposed.');
        });
    }

    public function test_authentication_and_authorization_errors_use_the_stable_envelope(): void
    {
        $this->getJson('/api/v1/__test/errors/unauthenticated')
            ->assertUnauthorized()
            ->assertExactJson([
                'success' => false,
                'message' => 'Unauthenticated.',
                'code' => 'UNAUTHENTICATED',
                'errors' => null,
            ]);

        $this->getJson('/api/v1/__test/errors/forbidden')
            ->assertForbidden()
            ->assertExactJson([
                'success' => false,
                'message' => 'This action is unauthorized.',
                'code' => 'FORBIDDEN',
                'errors' => null,
            ]);
    }

    public function test_missing_routes_models_and_invalid_methods_use_stable_codes(): void
    {
        $this->getJson('/api/v1/__test/errors/model-not-found')
            ->assertNotFound()
            ->assertJsonPath('code', 'RESOURCE_NOT_FOUND')
            ->assertJsonPath('message', 'Resource not found.');

        $this->getJson('/api/v1/__test/errors/unknown')
            ->assertNotFound()
            ->assertJsonPath('code', 'RESOURCE_NOT_FOUND');

        $this->postJson('/api/v1/__test/errors/server')
            ->assertStatus(405)
            ->assertJsonPath('code', 'METHOD_NOT_ALLOWED');
    }

    public function test_validation_errors_preserve_the_errors_object_and_add_a_stable_code(): void
    {
        $this->postJson('/api/v1/__test/errors/validation')
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonValidationErrors('name');
    }

    public function test_throttled_requests_preserve_retry_headers_and_use_a_stable_code(): void
    {
        $this->getJson('/api/v1/__test/errors/throttled')->assertOk();

        $this->getJson('/api/v1/__test/errors/throttled')
            ->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertJsonPath('code', 'TOO_MANY_REQUESTS');
    }

    public function test_unexpected_api_errors_hide_internal_details_even_without_accept_header(): void
    {
        $this->get('/api/v1/__test/errors/server')
            ->assertInternalServerError()
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson([
                'success' => false,
                'message' => 'Server error.',
                'code' => 'SERVER_ERROR',
                'errors' => null,
            ])
            ->assertDontSee('Secret provider credential');
    }
}
