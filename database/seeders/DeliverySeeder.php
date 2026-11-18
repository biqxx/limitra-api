<?php

namespace Database\Seeders;

use App\Models\Commerce\DeliveryMethod;
use App\Models\Commerce\DeliveryZone;
use Illuminate\Database\Seeder;

class DeliverySeeder extends Seeder
{
    public function run(): void
    {
        $nigeria = DeliveryZone::updateOrCreate(['name' => 'Nigeria (Red Star Express)'], [
            'country' => 'NG',
            'states' => null,
            'cities' => null,
            'priority' => 100,
            'active' => true,
        ]);

        $redstar = DeliveryMethod::updateOrCreate(['code' => 'redstar'], [
            'name' => 'Red Star Express Delivery',
            'type' => 'standard',
            'description' => 'Doorstep and express centre delivery powered by Red Star Logistics.',
            'active' => true,
        ]);

        $nigeria->methods()->syncWithoutDetaching([
            $redstar->id => [
                'fee' => 0,
                'estimated_days_min' => 1,
                'estimated_days_max' => 5,
                'active' => true,
            ],
        ]);
    }
}
