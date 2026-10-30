<?php

namespace App\Models\Content;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CareerJob extends Model
{
    protected $fillable = [
        'slug',
        'title',
        'department',
        'location',
        'type',
        'description',
        'requirements',
        'status',
    ];

    public function applications(): HasMany
    {
        return $this->hasMany(CareerApplication::class, 'career_job_id');
    }
}
