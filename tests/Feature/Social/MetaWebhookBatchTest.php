<?php

namespace Tests\Feature\Social;

use App\Jobs\InboundMessageJob;
use App\Models\Social\InboundSocialMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MetaWebhookBatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_meta_webhook_processes_all_batched_whatsapp_messages(): void
    {
        Queue::fake();

        $appSecret = 'test_meta_app_secret_123';
        config(['meta.app_secret' => $appSecret]);

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [
                [
                    'id' => 'WHATSAPP_ENTRY_1',
                    'changes' => [
                        [
                            'field' => 'messages',
                            'value' => [
                                'messaging_product' => 'whatsapp',
                                'metadata' => [
                                    'display_phone_number' => '15550234567',
                                    'phone_number_id' => 'PHONE_REC_001',
                                ],
                                'contacts' => [
                                    ['profile' => ['name' => 'Alice'], 'wa_id' => '2348000000001'],
                                ],
                                'messages' => [
                                    [
                                        'from' => '2348000000001',
                                        'id' => 'wamid.BATCH_MSG_001',
                                        'timestamp' => '1700000001',
                                        'type' => 'text',
                                        'text' => ['body' => 'First batched message'],
                                    ],
                                    [
                                        'from' => '2348000000001',
                                        'id' => 'wamid.BATCH_MSG_002',
                                        'timestamp' => '1700000002',
                                        'type' => 'text',
                                        'text' => ['body' => 'Second batched message'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                [
                    'id' => 'WHATSAPP_ENTRY_2',
                    'changes' => [
                        [
                            'field' => 'messages',
                            'value' => [
                                'messaging_product' => 'whatsapp',
                                'metadata' => [
                                    'display_phone_number' => '15550234567',
                                    'phone_number_id' => 'PHONE_REC_001',
                                ],
                                'contacts' => [
                                    ['profile' => ['name' => 'Bob'], 'wa_id' => '2348000000002'],
                                ],
                                'messages' => [
                                    [
                                        'from' => '2348000000002',
                                        'id' => 'wamid.BATCH_MSG_003',
                                        'timestamp' => '1700000003',
                                        'type' => 'text',
                                        'text' => ['body' => 'Third batched message from Bob'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $rawPayload = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = 'sha256='.hash_hmac('sha256', $rawPayload, $appSecret);

        $response = $this->call(
            'POST',
            '/api/v1/webhook/meta',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => $signature,
            ],
            $rawPayload
        );

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'OK');

        // All 3 messages must be persisted
        $this->assertDatabaseCount('inbound_social_messages', 3);
        $this->assertDatabaseHas('inbound_social_messages', ['provider_message_id' => 'wamid.BATCH_MSG_001']);
        $this->assertDatabaseHas('inbound_social_messages', ['provider_message_id' => 'wamid.BATCH_MSG_002']);
        $this->assertDatabaseHas('inbound_social_messages', ['provider_message_id' => 'wamid.BATCH_MSG_003']);

        $msg1 = InboundSocialMessage::where('provider_message_id', 'wamid.BATCH_MSG_001')->firstOrFail();
        $this->assertSame('First batched message', $msg1->message);

        $msg2 = InboundSocialMessage::where('provider_message_id', 'wamid.BATCH_MSG_002')->firstOrFail();
        $this->assertSame('Second batched message', $msg2->message);

        $msg3 = InboundSocialMessage::where('provider_message_id', 'wamid.BATCH_MSG_003')->firstOrFail();
        $this->assertSame('Third batched message from Bob', $msg3->message);

        Queue::assertPushed(InboundMessageJob::class, 3);
    }

    public function test_meta_webhook_processes_batched_instagram_messages(): void
    {
        Queue::fake();

        $appSecret = 'test_meta_app_secret_123';
        config(['meta.app_secret' => $appSecret]);

        $payload = [
            'object' => 'instagram',
            'entry' => [
                [
                    'id' => 'IG_PAGE_1',
                    'time' => 1700000000,
                    'messaging' => [
                        [
                            'sender' => ['id' => 'IG_USER_1'],
                            'recipient' => ['id' => 'IG_PAGE_1'],
                            'timestamp' => 1700000010,
                            'message' => [
                                'mid' => 'mid.IG_MSG_001',
                                'text' => 'Instagram msg 1',
                            ],
                        ],
                        [
                            'sender' => ['id' => 'IG_USER_2'],
                            'recipient' => ['id' => 'IG_PAGE_1'],
                            'timestamp' => 1700000020,
                            'message' => [
                                'mid' => 'mid.IG_MSG_002',
                                'text' => 'Instagram msg 2',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $rawPayload = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = 'sha256='.hash_hmac('sha256', $rawPayload, $appSecret);

        $response = $this->call(
            'POST',
            '/api/v1/webhook/meta',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => $signature,
            ],
            $rawPayload
        );

        $response->assertOk();
        $this->assertDatabaseCount('inbound_social_messages', 2);
        Queue::assertPushed(InboundMessageJob::class, 2);
    }
}
