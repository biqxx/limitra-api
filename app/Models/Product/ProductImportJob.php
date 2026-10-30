<?php

namespace App\Models\Product;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductImportJob extends Model
{
    protected $fillable = [
        'user_id',
        'file_name',
        'status',
        'dry_run',
        'total_rows',
        'processed_rows',
        'successful_rows',
        'failed_rows',
        'errors',
    ];

    protected function casts(): array
    {
        return [
            'dry_run' => 'boolean',
            'errors' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
