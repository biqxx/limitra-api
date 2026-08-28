<?php

namespace Database\Seeders;

use App\Models\Commerce\DeliveryMethod;
use App\Models\Commerce\DeliveryZone;
use Illuminate\Database\Seeder;

class DeliverySeeder extends Seeder
{
    public function run(): void
    {
        $lagos = DeliveryZone::updateOrCreate(['name' => 'Lagos'], [
            'country' => 'NG', 'states' => ['Lagos'], 'priority' => 100, 'active' => true,
        ]);
        $nationwide = DeliveryZone::updateOrCreate(['name' => 'Nigeria Nationwide'], [
            'country' => 'NG', 'states' => null, 'priority' => 1, 'active' => true,
        ]);
        $standard = DeliveryMethod::updateOrCreate(['code' => 'standard'], [
            'name' => 'Standard Delivery', 'type' => 'standard', 'active' => true,
        ]);
        $express = DeliveryMethod::updateOrCreate(['code' => 'express'], [
            'name' => 'Express Delivery', 'type' => 'express', 'active' => true,
        ]);

        $lagos->methods()->syncWithoutDetaching([
            $standard->id => ['fee' => 2500, 'free_shipping_threshold' => 150000, 'estimated_days_min' => 2, 'estimated_days_max' => 4, 'active' => true],
            $express->id => ['fee' => 5000, 'estimated_days_min' => 1, 'estimated_days_max' => 2, 'active' => true],
        ]);
        $nationwide->methods()->syncWithoutDetaching([
            $standard->id => ['fee' => 5000, 'free_shipping_threshold' => 250000, 'estimated_days_min' => 5, 'estimated_days_max' => 8, 'active' => true],
        ]);
    }
}
