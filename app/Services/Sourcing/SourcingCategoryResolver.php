<?php

namespace App\Services\Sourcing;

use App\Models\Product\Category;

class SourcingCategoryResolver
{
    public function fallback(): Category
    {
        return Category::query()->firstOrCreate(
            ['slug' => 'uncategorized'],
            [
                'name' => 'Uncategorized',
                'description' => 'Supplier products awaiting an administrator category review.',
                'sort_order' => 32767,
                'active' => false,
            ],
        );
    }
}
