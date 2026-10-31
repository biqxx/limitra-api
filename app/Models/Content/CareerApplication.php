<?php

namespace App\Models\Content;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CareerApplication extends Model
{
    protected $fillable = [
        'career_job_id',
        'name',
        'email',
        'phone',
        'cv_url',
        'cv_path',
        'cover_letter',
        'portfolio_url',
        'status',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(CareerJob::class, 'career_job_id');
    }
}
