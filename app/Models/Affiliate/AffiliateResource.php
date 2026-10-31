<?php

namespace App\Models\Affiliate;

use Illuminate\Database\Eloquent\Model;

class AffiliateResource extends Model
{
    protected $fillable = [
        'title',
        'description',
        'category',
        'file_path',
        'mime_type',
        'file_size',
        'is_published',
        'downloads_count',
    ];

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'file_size' => 'integer',
            'downloads_count' => 'integer',
        ];
    }
}
