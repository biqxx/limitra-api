<?php

namespace App\Models\Microstore;

use App\Models\Product\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Microstore extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'slug',
        'tagline',
        'bio',
        'logo_url',
        'banner_url',
        'theme_color',
        'status',
        'moderation_status',
        'moderation_notes',
        'moderated_by',
        'moderated_at',
        'is_featured',
        'views_count',
        'orders_count',
        'total_revenue',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'is_featured' => 'boolean',
            'views_count' => 'integer',
            'orders_count' => 'integer',
            'total_revenue' => 'decimal:2',
            'settings' => 'array',
            'moderated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function moderatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by');
    }

    public function curatedProducts(): HasMany
    {
        return $this->hasMany(MicrostoreProduct::class)->orderByDesc('is_pinned')->orderBy('sort_order')->orderByDesc('id');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'microstore_products')
            ->withPivot(['custom_title', 'custom_description', 'sort_order', 'is_pinned', 'created_at'])
            ->withTimestamps();
    }

    public function events(): HasMany
    {
        return $this->hasMany(MicrostoreEvent::class);
    }
}
