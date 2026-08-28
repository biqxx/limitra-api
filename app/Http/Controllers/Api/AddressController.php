<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\AddressResource;
use App\Models\Address\Address;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

class AddressController extends BaseController
{
    // ═══════════════════════════════════════════════════════════════════════
    //  Standard apiResource methods
    // ═══════════════════════════════════════════════════════════════════════

    #[OA\Get(
        path: '/addresses',
        tags: ['Addresses'],
        summary: 'List addresses',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'type', in: 'query', required: false, description: 'Filter by address type', schema: new OA\Schema(type: 'string', enum: ['billing', 'delivery'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Address list', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $addresses = Address::where('user_id', auth('api')->id())
            ->when($request->type, fn ($q) => $q->where('type', $request->type))
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->get();

        return $this->success(AddressResource::collection($addresses));
    }

    #[OA\Post(
        path: '/addresses',
        tags: ['Addresses'],
        summary: 'Create address',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['type', 'address_line_1', 'city', 'state', 'postal_code'],
                properties: [
                    new OA\Property(property: 'type', type: 'string', enum: ['billing', 'delivery']),
                    new OA\Property(property: 'address_line_1', type: 'string', maxLength: 255),
                    new OA\Property(property: 'address_line_2', type: 'string', maxLength: 255, nullable: true),
                    new OA\Property(property: 'city', type: 'string', maxLength: 100),
                    new OA\Property(property: 'landmark', type: 'string', maxLength: 255, nullable: true),
                    new OA\Property(property: 'state', type: 'string', maxLength: 100),
                    new OA\Property(property: 'country', type: 'string', maxLength: 100, nullable: true),
                    new OA\Property(property: 'postal_code', type: 'string', maxLength: 20),
                    new OA\Property(property: 'is_default', type: 'boolean', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Address created', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:billing,delivery'],
            'label' => ['nullable', 'string', 'max:50'],
            'recipient_name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'string', 'max:20'],
            'line1' => ['required', 'string', 'max:255'],
            'line2' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:100'],
            'landmark' => ['nullable', 'string', 'max:255'],
            'state' => ['required', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'size:2'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'is_default' => ['nullable', 'boolean'],
        ]);

        $userId = auth('api')->id();

        $address = DB::transaction(function () use ($data, $userId) {
            if (! empty($data['is_default'])) {
                $this->demotePreviousDefault($userId, $data['type']);
            }

            return Address::create(array_merge($data, ['user_id' => $userId]));
        });

        return $this->success(new AddressResource($address), 'Address created.', 201);
    }

    #[OA\Get(
        path: '/addresses/{address}',
        tags: ['Addresses'],
        summary: 'Get address',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'address', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Address details', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function show(Address $address): JsonResponse
    {
        $this->authorize('view', $address);

        return $this->success(new AddressResource($address));
    }

    #[OA\Put(
        path: '/addresses/{address}',
        tags: ['Addresses'],
        summary: 'Update address',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'address', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'type', type: 'string', enum: ['billing', 'delivery']),
                    new OA\Property(property: 'address_line_1', type: 'string', maxLength: 255),
                    new OA\Property(property: 'address_line_2', type: 'string', maxLength: 255, nullable: true),
                    new OA\Property(property: 'city', type: 'string', maxLength: 100),
                    new OA\Property(property: 'landmark', type: 'string', maxLength: 255, nullable: true),
                    new OA\Property(property: 'state', type: 'string', maxLength: 100),
                    new OA\Property(property: 'country', type: 'string', maxLength: 100, nullable: true),
                    new OA\Property(property: 'postal_code', type: 'string', maxLength: 20),
                    new OA\Property(property: 'is_default', type: 'boolean', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Address updated', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function update(Request $request, Address $address): JsonResponse
    {
        $this->authorize('update', $address);

        $data = $request->validate([
            'type' => ['sometimes', 'required', 'in:billing,delivery'],
            'label' => ['nullable', 'string', 'max:50'],
            'recipient_name' => ['sometimes', 'required', 'string', 'max:150'],
            'phone' => ['sometimes', 'required', 'string', 'max:20'],
            'line1' => ['sometimes', 'required', 'string', 'max:255'],
            'line2' => ['nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'required', 'string', 'max:100'],
            'landmark' => ['nullable', 'string', 'max:255'],
            'state' => ['sometimes', 'required', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'size:2'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'is_default' => ['nullable', 'boolean'],
        ]);

        DB::transaction(function () use ($data, $address): void {
            if (! empty($data['is_default'])) {
                $this->demotePreviousDefault($address->user_id, $data['type'] ?? $address->type, $address->id);
            }
            $address->update($data);
        });

        return $this->success(new AddressResource($address), 'Address updated.');
    }

    #[OA\Delete(
        path: '/addresses/{address}',
        tags: ['Addresses'],
        summary: 'Delete address',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'address', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Address deleted', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 422, description: 'Cannot delete default address', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function destroy(Address $address): JsonResponse
    {
        $this->authorize('delete', $address);

        if ($address->is_default) {
            return $this->error(
                'Cannot delete a default address. Set another address as default first.',
                422
            );
        }

        $address->delete();

        return $this->success(message: 'Address deleted.');
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Named action endpoints
    // ═══════════════════════════════════════════════════════════════════════

    #[OA\Patch(
        path: '/addresses/{address}/set-default',
        tags: ['Addresses'],
        summary: 'Set address as default',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'address', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Default address updated', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function setDefault(Address $address): JsonResponse
    {
        $this->authorize('update', $address);

        DB::transaction(function () use ($address): void {
            $this->demotePreviousDefault($address->user_id, $address->type, $address->id);
            $address->update(['is_default' => true]);
        });

        return $this->success(new AddressResource($address), 'Default address updated.');
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Private helpers
    // ═══════════════════════════════════════════════════════════════════════

    private function demotePreviousDefault(int $userId, string $type, int $excludeId = 0): void
    {
        Address::where('user_id', $userId)
            ->where('type', $type)
            ->where('is_default', true)
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->update(['is_default' => false]);
    }
}
