<?php

namespace Tests\Feature;

use App\AI\AgentService;
use App\AI\Contracts\AgentDriver;
use App\AI\Data\AgentResponse;
use App\Jobs\InboundMessageJob;
use App\Models\AI\ConversationMessage;
use App\Models\Social\InboundSocialMessage;
use App\Social\Channels\MetaChannel;
use App\Social\Channels\XChannel;
use App\Social\Contracts\SocialChannel;
use App\Social\Data\InboundMessage;
use App\Social\InboundMessageReceiver;
use App\Social\SocialChannelManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SocialMessageIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_message_is_persisted_and_queued_once(): void
    {
        Queue::fake();
        $receiver = app(InboundMessageReceiver::class);
        $message = $this->message();

        $this->assertTrue($receiver->accept($message));
        $this->assertFalse($receiver->accept($message));

        $this->assertDatabaseCount('inbound_social_messages', 1);
        $record = InboundSocialMessage::firstOrFail();
        $this->assertSame('wamid.duplicate-safe', $record->provider_message_id);
        $this->assertSame('Can you help?', $record->message);
        Queue::assertPushed(InboundMessageJob::class, 1);
    }

    public function test_unknown_social_contact_remains_unlinked_and_job_is_idempotent(): void
    {
        Queue::fake();
        app(InboundMessageReceiver::class)->accept($this->message());
        $record = InboundSocialMessage::firstOrFail();
        $driver = new StaticResponseDriver;
        $channel = new RecordingSocialChannel;
        $job = new InboundMessageJob($record->id);

        $job->handle(new SocialChannelManager([$channel]), new AgentService($driver, []));
        $job->handle(new SocialChannelManager([$channel]), new AgentService($driver, []));

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseHas('social_accounts', [
            'platform' => 'whatsapp',
            'platform_sender_id' => '2348000000000',
            'user_id' => null,
        ]);
        $this->assertDatabaseHas('conversations', ['user_id' => null]);
        $this->assertDatabaseHas('inbound_social_messages', [
            'id' => $record->id,
            'status' => 'processed',
            'attempts' => 1,
        ]);
        $this->assertSame(1, $driver->calls);
        $this->assertCount(1, $channel->messages);
        $this->assertSame(2, ConversationMessage::count());
    }

    public function test_channels_require_and_preserve_provider_message_ids(): void
    {
        $metaMessage = (new MetaChannel)->parseInboundPayload([
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'metadata' => ['phone_number_id' => 'phone-1'],
                        'messages' => [[
                            'id' => 'wamid.123',
                            'from' => 'sender-1',
                            'type' => 'text',
                            'text' => ['body' => 'Hello'],
                            'timestamp' => '1700000000',
                        ]],
                    ],
                ]],
            ]],
        ]);
        $xMessage = (new XChannel)->parseInboundPayload([
            'direct_message_events' => [[
                'id' => 'x-event-123',
                'type' => 'message_create',
                'created_timestamp' => '1700000000000',
                'message_create' => [
                    'sender_id' => 'sender-2',
                    'target' => ['recipient_id' => 'bot-1'],
                    'message_data' => ['text' => 'Hello from X'],
                ],
            ]],
        ]);

        $this->assertSame('wamid.123', $metaMessage?->providerMessageId);
        $this->assertSame('x-event-123', $xMessage?->providerMessageId);
        $this->assertNull((new MetaChannel)->parseInboundPayload([
            'object' => 'whatsapp_business_account',
            'entry' => [['changes' => [['value' => [
                'messages' => [[
                    'from' => 'sender-1',
                    'type' => 'text',
                    'text' => ['body' => 'Missing ID'],
                ]],
            ]]]]],
        ]));
    }

    public function test_failed_delivery_reuses_the_generated_reply_on_retry(): void
    {
        Queue::fake();
        app(InboundMessageReceiver::class)->accept($this->message());
        $record = InboundSocialMessage::firstOrFail();
        $driver = new StaticResponseDriver;
        $channel = new FlakySocialChannel;
        $job = new InboundMessageJob($record->id);

        try {
            $job->handle(new SocialChannelManager([$channel]), new AgentService($driver, []));
            $this->fail('The first delivery should fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Temporary provider failure', $exception->getMessage());
        }

        $this->assertDatabaseHas('inbound_social_messages', [
            'id' => $record->id,
            'status' => 'failed',
            'attempts' => 1,
        ]);

        $job->handle(new SocialChannelManager([$channel]), new AgentService($driver, []));

        $this->assertSame(1, $driver->calls);
        $this->assertSame(2, $channel->attempts);
        $this->assertSame(2, ConversationMessage::count());
        $this->assertDatabaseHas('inbound_social_messages', [
            'id' => $record->id,
            'status' => 'processed',
            'attempts' => 2,
        ]);
    }

    private function message(): InboundMessage
    {
        return new InboundMessage(
            platform: 'whatsapp',
            providerMessageId: 'wamid.duplicate-safe',
            platformSenderId: '2348000000000',
            platformRecipientId: 'phone-number-id',
            message: 'Can you help?',
            username: 'Prospective Customer',
            timestamp: 1700000000,
        );
    }
}

class StaticResponseDriver implements AgentDriver
{
    public int $calls = 0;

    public function complete(
        array $messages,
        array $toolDefinitions,
        string $systemContext = '',
        bool $highReasoning = false,
    ): AgentResponse {
        $this->calls++;

        return new AgentResponse('How can I help?', [], 'end_turn');
    }
}

class RecordingSocialChannel implements SocialChannel
{
    public array $messages = [];

    public function handles(string $platform): bool
    {
        return true;
    }

    public function sendTextMessage(string $platform, string $recipientId, string $message): void
    {
        $this->messages[] = compact('platform', 'recipientId', 'message');
    }
}

class FlakySocialChannel implements SocialChannel
{
    public int $attempts = 0;

    public function handles(string $platform): bool
    {
        return true;
    }

    public function sendTextMessage(string $platform, string $recipientId, string $message): void
    {
        $this->attempts++;

        if ($this->attempts === 1) {
            throw new \RuntimeException('Temporary provider failure');
        }
    }
}
