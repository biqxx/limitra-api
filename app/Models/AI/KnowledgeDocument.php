<?php

namespace App\Models\AI;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class KnowledgeDocument extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_INDEXED = 'indexed';

    public const STATUS_ARCHIVED = 'archived';

    protected $table = 'ai_knowledge_documents';

    protected $fillable = [
        'title',
        'slug',
        'category',
        'version',
        'content',
        'status',
        'token_count',
        'indexed_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'token_count' => 'integer',
            'indexed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function isIndexed(): bool
    {
        return $this->status === self::STATUS_INDEXED;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', '!=', self::STATUS_ARCHIVED);
    }
}
