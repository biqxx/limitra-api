<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

/**
 * OpenAPI endpoint documentation for Celebrity Store routes that share
 * Microstore controllers via route defaults (`storeType = 'celebrity'`).
 */
class CelebrityStoreSpec
{
    #[OA\Get(
        path: '/celebrity-stores',
        tags: ['Celebrity Stores'],
        summary: 'List active celebrity stores',
        parameters: [
            new OA\Parameter(name: 'featured', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 16)),
        ],
        responses: [new OA\Response(response: 200, description: 'Paginated celebrity stores', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    )]
    public function publicIndex(): void {}

    #[OA\Get(
        path: '/celebrity-stores/{slug}',
        tags: ['Celebrity Stores'],
        summary: 'Get public celebrity store by slug',
        parameters: [new OA\Parameter(name: 'slug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 200, description: 'Celebrity store details', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    )]
    public function publicShow(): void {}

    #[OA\Get(
        path: '/celebrity-stores/{slug}/products',
        tags: ['Celebrity Stores'],
        summary: 'List curated products in a celebrity store',
        parameters: [
            new OA\Parameter(name: 'slug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20)),
        ],
        responses: [new OA\Response(response: 200, description: 'Paginated celebrity store products', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    )]
    public function publicProducts(): void {}

    #[OA\Post(
        path: '/celebrity-stores/{slug}/clicks',
        tags: ['Celebrity Stores'],
        summary: 'Track a celebrity store or product click',
        parameters: [new OA\Parameter(name: 'slug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 201, description: 'Click tracked', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    )]
    public function publicTrackClick(): void {}

    #[OA\Get(
        path: '/celebrity-stores/{slug}/chat',
        tags: ['Celebrity Store Chat'],
        summary: 'Get or start customer chat with a celebrity store owner',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'slug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 200, description: 'Conversation retrieved', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    )]
    public function customerChatShow(): void {}

    #[OA\Post(
        path: '/celebrity-stores/{slug}/chat/messages',
        tags: ['Celebrity Store Chat'],
        summary: 'Send message to celebrity store owner',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'slug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 201, description: 'Message sent', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    )]
    public function customerChatSend(): void {}

    #[OA\Get(
        path: '/my-celebrity-store',
        tags: ['My Celebrity Store'],
        summary: 'Get authenticated owner celebrity store',
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Owner celebrity store details', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    )]
    public function ownerShow(): void {}

    #[OA\Post(
        path: '/my-celebrity-store',
        tags: ['My Celebrity Store'],
        summary: 'Create celebrity store and assign celebrity_store_owner role',
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 201, description: 'Celebrity store created', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    )]
    public function ownerStore(): void {}

    #[OA\Patch(
        path: '/my-celebrity-store',
        tags: ['My Celebrity Store'],
        summary: 'Update authenticated owner celebrity store',
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Celebrity store updated', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    )]
    public function ownerUpdate(): void {}

    #[OA\Post(
        path: '/my-celebrity-store/products',
        tags: ['My Celebrity Store'],
        summary: 'Add product to celebrity store',
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 201, description: 'Product added', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    )]
    public function ownerAddProduct(): void {}

    #[OA\Patch(
        path: '/my-celebrity-store/products/{product}',
        tags: ['My Celebrity Store'],
        summary: 'Update curated celebrity store product',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'product', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Product updated', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    )]
    public function ownerUpdateProduct(): void {}

    #[OA\Delete(
        path: '/my-celebrity-store/products/{product}',
        tags: ['My Celebrity Store'],
        summary: 'Remove curated product from celebrity store',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'product', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Product removed', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    )]
    public function ownerRemoveProduct(): void {}

    #[OA\Get(
        path: '/my-celebrity-store/analytics',
        tags: ['My Celebrity Store'],
        summary: 'Get celebrity store analytics, order status breakdown, and top performing products',
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Analytics retrieved', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    )]
    public function ownerAnalytics(): void {}

    #[OA\Get(
        path: '/my-celebrity-store/orders',
        tags: ['My Celebrity Store'],
        summary: 'List celebrity store orders listed by product items',
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Product-first celebrity store orders', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    )]
    public function ownerOrders(): void {}

    #[OA\Patch(
        path: '/my-celebrity-store/orders/bulk-status',
        tags: ['My Celebrity Store'],
        summary: 'Bulk update status for celebrity store orders',
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Orders updated', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    )]
    public function ownerBulkOrderStatus(): void {}

    #[OA\Get(
        path: '/my-celebrity-store/inventory',
        tags: ['My Celebrity Store'],
        summary: 'Get celebrity store curated product inventory',
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Inventory retrieved', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    )]
    public function ownerInventory(): void {}

    #[OA\Get(
        path: '/my-celebrity-store/customers',
        tags: ['My Celebrity Store'],
        summary: 'List celebrity store customers',
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Customers retrieved', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    )]
    public function ownerCustomers(): void {}

    #[OA\Get(
        path: '/my-celebrity-store/reviews',
        tags: ['My Celebrity Store'],
        summary: 'List reviews for celebrity store products',
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Reviews retrieved', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    )]
    public function ownerReviews(): void {}

    #[OA\Get(
        path: '/my-celebrity-store/earnings',
        tags: ['My Celebrity Store'],
        summary: 'Get celebrity store commission earnings summary',
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Earnings retrieved', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    )]
    public function ownerEarnings(): void {}

    #[OA\Get(
        path: '/my-celebrity-store/withdrawals',
        tags: ['My Celebrity Store'],
        summary: 'List celebrity store withdrawal requests',
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Withdrawals retrieved', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    )]
    public function ownerWithdrawals(): void {}

    #[OA\Post(
        path: '/my-celebrity-store/withdrawals',
        tags: ['My Celebrity Store'],
        summary: 'Request a celebrity store payout withdrawal',
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 201, description: 'Withdrawal submitted', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    )]
    public function ownerRequestWithdrawal(): void {}

    #[OA\Get(
        path: '/my-celebrity-store/transactions',
        tags: ['My Celebrity Store'],
        summary: 'List celebrity store financial transactions',
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Transactions retrieved', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    )]
    public function ownerTransactions(): void {}

    #[OA\Get(
        path: '/my-celebrity-store/chats',
        tags: ['My Celebrity Store'],
        summary: 'List customer chat conversations for celebrity store owner',
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Conversations retrieved', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    )]
    public function ownerChats(): void {}

    #[OA\Get(
        path: '/my-celebrity-store/chats/{conversation}',
        tags: ['My Celebrity Store'],
        summary: 'Read a celebrity store customer conversation',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'conversation', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Conversation retrieved', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    )]
    public function ownerChatShow(): void {}

    #[OA\Post(
        path: '/my-celebrity-store/chats/{conversation}/messages',
        tags: ['My Celebrity Store'],
        summary: 'Reply to a celebrity store customer conversation',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'conversation', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 201, description: 'Reply sent', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    )]
    public function ownerChatReply(): void {}
}
