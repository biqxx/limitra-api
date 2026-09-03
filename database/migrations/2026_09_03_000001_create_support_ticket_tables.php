<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('category', 60);
            $table->string('subject', 200);
            $table->string('status', 30)->default('open');
            $table->string('priority', 20)->default('normal');
            $table->string('contact_name', 160);
            $table->string('contact_email');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('first_response_due_at');
            $table->timestamp('resolution_due_at');
            $table->timestamp('first_responded_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('last_message_at');
            $table->timestamps();

            $table->index(['user_id', 'status', 'last_message_at']);
            $table->index(['status', 'priority', 'last_message_at']);
            $table->index(['assigned_to', 'status', 'last_message_at']);
            $table->index(['status', 'first_response_due_at']);
            $table->index(['status', 'resolution_due_at']);
        });

        Schema::create('support_ticket_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('sender_type', 20);
            $table->text('message');
            $table->timestamps();

            $table->index(['support_ticket_id', 'created_at']);
        });

        $now = now();
        DB::table('business_settings')->insert([
            $this->setting(
                'support.allowed_categories',
                'Support ticket categories',
                'Categories customers may select when opening a support ticket.',
                'array',
                ['order', 'payment', 'delivery', 'return_refund', 'product', 'account', 'technical', 'other'],
                ['min_items' => 1, 'distinct' => true, 'item_type' => 'string'],
                true,
                $now,
            ),
            $this->setting(
                'support.guest_tickets_enabled',
                'Guest support tickets',
                'Whether unauthenticated customers may open support tickets.',
                'boolean',
                true,
                null,
                true,
                $now,
            ),
            $this->setting(
                'support.first_response_minutes',
                'Support first-response target',
                'Minutes in which support should first respond to a new ticket.',
                'integer',
                60,
                ['min' => 5, 'max' => 10080],
                false,
                $now,
            ),
            $this->setting(
                'support.resolution_minutes',
                'Support resolution target',
                'Minutes in which support should resolve a new ticket.',
                'integer',
                2880,
                ['min' => 30, 'max' => 43200],
                false,
                $now,
            ),
        ]);
        Cache::forget('business_settings.values.v1');
    }

    public function down(): void
    {
        DB::table('business_settings')->whereIn('key', [
            'support.allowed_categories',
            'support.guest_tickets_enabled',
            'support.first_response_minutes',
            'support.resolution_minutes',
        ])->delete();
        Cache::forget('business_settings.values.v1');

        Schema::dropIfExists('support_ticket_messages');
        Schema::dropIfExists('support_tickets');
    }

    private function setting(
        string $key,
        string $label,
        string $description,
        string $type,
        mixed $value,
        ?array $constraints,
        bool $isPublic,
        mixed $now,
    ): array {
        return [
            'key' => $key,
            'group' => 'support',
            'label' => $label,
            'description' => $description,
            'type' => $type,
            'value' => json_encode($value, JSON_THROW_ON_ERROR),
            'constraints' => $constraints === null ? null : json_encode($constraints, JSON_THROW_ON_ERROR),
            'is_public' => $isPublic,
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
};
