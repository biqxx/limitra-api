<?php

namespace App\Console\Commands;

use App\Models\Content\BlogPost;
use App\Models\Content\ContentEntry;
use Illuminate\Console\Command;

class PublishScheduledContentCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'content:publish-scheduled';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Publish scheduled blog posts and content entries whose publish date has arrived.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $now = now();

        $postsCount = 0;
        BlogPost::query()
            ->where('status', 'scheduled')
            ->where(function ($query) use ($now): void {
                $query->where('scheduled_at', '<=', $now)
                    ->orWhere(function ($q) use ($now): void {
                        $q->whereNotNull('published_at')->where('published_at', '<=', $now);
                    });
            })
            ->chunkById(100, function ($posts) use (&$postsCount, $now): void {
                foreach ($posts as $post) {
                    $publishTimestamp = $post->scheduled_at ?? $post->published_at ?? $now;
                    $post->update([
                        'status' => 'published',
                        'published_at' => $publishTimestamp,
                    ]);
                    $postsCount++;
                }
            });

        $entriesCount = 0;
        ContentEntry::query()
            ->where('status', 'scheduled')
            ->where(function ($query) use ($now): void {
                $query->where('scheduled_at', '<=', $now)
                    ->orWhere(function ($q) use ($now): void {
                        $q->whereNotNull('published_at')->where('published_at', '<=', $now);
                    });
            })
            ->chunkById(100, function ($entries) use (&$entriesCount, $now): void {
                foreach ($entries as $entry) {
                    $publishTimestamp = $entry->scheduled_at ?? $entry->published_at ?? $now;
                    $entry->update([
                        'status' => 'published',
                        'published_at' => $publishTimestamp,
                    ]);
                    $entriesCount++;
                }
            });

        $this->info("Published {$postsCount} scheduled blog posts and {$entriesCount} scheduled content entries.");

        return self::SUCCESS;
    }
}
