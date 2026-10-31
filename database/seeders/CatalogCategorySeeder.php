<?php

namespace Database\Seeders;

use App\Models\Product\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CatalogCategorySeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->taxonomy() as $position => $definition) {
            $category = Category::query()->firstOrCreate(
                ['slug' => $definition['slug']],
                [
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'icon' => $definition['icon'],
                    'sort_order' => ($position + 1) * 10,
                    'age_gated' => $definition['age_gated'] ?? false,
                    'active' => true,
                ],
            );

            foreach ($definition['subcategories'] as $subcategoryPosition => $subcategoryName) {
                Category::query()->firstOrCreate(
                    ['slug' => $definition['slug'].'-'.Str::slug($subcategoryName)],
                    [
                        'parent_id' => $category->id,
                        'name' => $subcategoryName,
                        'sort_order' => $subcategoryPosition + 1,
                        'age_gated' => $definition['age_gated'] ?? false,
                        'active' => true,
                    ],
                );
            }
        }
    }

    /**
     * @return array<int, array{slug: string, name: string, description: string, icon: string, age_gated?: bool, subcategories: array<int, string>}>
     */
    private function taxonomy(): array
    {
        return [
            ['slug' => 'phones', 'name' => 'Phones & Tablets', 'description' => 'Phones, tablets and everyday mobile accessories.', 'icon' => 'phone', 'subcategories' => ['Smartphones', 'Tablets', 'Phone Cases', 'Screen Protectors', 'Chargers & Cables', 'Power Banks', 'Earbuds & Headphones', 'Smartwatches', 'Memory Cards', 'Tablet Accessories']],
            ['slug' => 'computing', 'name' => 'Computers & Accessories', 'description' => 'Computers, storage, networking and workstation accessories.', 'icon' => 'grid', 'subcategories' => ['Laptops', 'Mini Laptops', 'Laptop Bags', 'Keyboards & Mice', 'Webcams', 'USB Hubs', 'Flash Drives', 'External Drives & SSD', 'Wi-Fi Routers', 'Laptop Stands', 'Printer Accessories']],
            ['slug' => 'electronics', 'name' => 'Electronics & Gadgets', 'description' => 'Audio, cameras, gaming, smart-home and portable electronics.', 'icon' => 'spark', 'subcategories' => ['Wireless Earbuds', 'Bluetooth Speakers', 'Smartwatches', 'Fitness Trackers', 'Cameras', 'Camera Accessories', 'Gaming Accessories', 'Streaming Devices', 'Smart Home', 'Portable Fans', 'Rechargeable Lamps', 'Adapters & Cables']],
            ['slug' => 'mens', 'name' => 'Men’s Fashion', 'description' => 'Clothing, footwear and accessories for men.', 'icon' => 'shirt', 'subcategories' => ['Shirts', 'T-Shirts', 'Polo Shirts', 'Jeans', 'Trousers', 'Shorts', 'Jackets', 'Shoes', 'Sneakers', 'Sandals', 'Watches', 'Belts', 'Sunglasses', 'Wallets', 'Caps & Hats']],
            ['slug' => 'womens', 'name' => 'Women’s Fashion', 'description' => 'Clothing, footwear, bags and accessories for women.', 'icon' => 'shirt', 'subcategories' => ['Dresses', 'Tops', 'Jeans', 'Skirts', 'Two-Piece Sets', 'Jackets', 'Heels', 'Sneakers', 'Sandals', 'Handbags', 'Watches', 'Sunglasses', 'Jewellery', 'Hair Accessories']],
            ['slug' => 'beauty', 'name' => 'Beauty & Personal Care', 'description' => 'Makeup, skincare, hair care and grooming essentials.', 'icon' => 'makeup', 'subcategories' => ['Makeup', 'Lipstick', 'Eye Makeup', 'Makeup Brushes', 'Skincare', 'Cleansers', 'Serums', 'Sunscreen', 'Hair Care', 'Wigs & Extensions', 'Hair Tools', 'Grooming & Shaving', 'Body Care']],
            ['slug' => 'fragrance', 'name' => 'Fragrance', 'description' => 'Fragrances, perfume oils, sprays and gift sets.', 'icon' => 'spark', 'subcategories' => ['Men’s Fragrance', 'Women’s Fragrance', 'Unisex Fragrance', 'Perfume Oils', 'Body Sprays', 'Mini & Travel Size', 'Gift Sets']],
            ['slug' => 'home', 'name' => 'Home & Kitchen Essentials', 'description' => 'Useful products for the kitchen, bedroom, bathroom and home.', 'icon' => 'store', 'subcategories' => ['Kitchen Tools', 'Storage Containers', 'Water Bottles', 'Lunch Boxes', 'Cutlery', 'Coffee & Tea', 'Home Decor', 'Bedsheets', 'Towels', 'Cleaning Tools', 'Bathroom Accessories', 'Closet Organizers', 'LED Lights']],
            ['slug' => 'kids', 'name' => 'Baby, Kids & Toys', 'description' => 'Clothing, footwear, feeding, school and play essentials for children.', 'icon' => 'gift', 'subcategories' => ['Baby Clothing', 'Kids Clothing', 'Baby Shoes', 'Kids Shoes', 'Feeding Accessories', 'Baby Bags', 'Toys', 'Educational Toys', 'School Bags', 'Kids Watches']],
            ['slug' => 'auto', 'name' => 'Automobile Accessories', 'description' => 'Practical accessories, electronics and care products for vehicles.', 'icon' => 'truck', 'subcategories' => ['Car Phone Holders', 'Car Chargers', 'Air Fresheners', 'Seat Accessories', 'Steering Wheel Covers', 'Car Lights', 'Tyre Gauges', 'Cleaning Accessories', 'Dash Cameras', 'Jump Starters']],
            ['slug' => 'sports', 'name' => 'Sports & Fitness', 'description' => 'Fitness, gym, running and sports accessories.', 'icon' => 'spark', 'subcategories' => ['Gym Gloves', 'Resistance Bands', 'Fitness Trackers', 'Yoga Mats', 'Water Bottles', 'Sports Bags', 'Waist Trainers', 'Jump Ropes', 'Football Accessories', 'Running Accessories']],
            ['slug' => 'pets', 'name' => 'Pets', 'description' => 'Everyday pet care, comfort, grooming and travel accessories.', 'icon' => 'heart', 'subcategories' => ['Pet Collars', 'Pet Leashes', 'Pet Clothing', 'Pet Toys', 'Grooming Tools', 'Pet Bowls', 'Pet Beds', 'Travel Accessories']],
            ['slug' => 'adult', 'name' => 'Wellness & Intimacy', 'description' => 'Private wellness and intimacy products for adults.', 'icon' => 'heart', 'age_gated' => true, 'subcategories' => ['Wellness', 'Couples', 'Massagers', 'Accessories']],
        ];
    }
}
