<?php

namespace Tests\Feature;

use App\Http\Middleware\TrackAnalytics;
use App\Models\Cart\Cart;
use App\Models\Order\Order;
use App\Models\Product\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RedStarLogisticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'jwt.secret' => 'test-secret-with-at-least-thirty-two-characters',
            'services.redstar.base_url' => 'http://redstar.test',
            'services.redstar.api_key' => 'test-redstar-key',
            'services.redstar.sender_city' => 'LOS',
            'services.redstar.sender_town_id' => 1,
        ]);

        Cache::flush();
        $this->withoutMiddleware(TrackAnalytics::class);
    }

    public function test_redstar_location_endpoints_are_cached(): void
    {
        Http::fake([
            'http://redstar.test/api/Operations/Cities' => Http::response([
                ['id' => 1, 'abbr' => 'LOS', 'name' => 'Lagos'],
                ['id' => 2, 'abbr' => 'ABV', 'name' => 'Abuja'],
            ]),
            'http://redstar.test/api/Operations/DeliveryTowns/LOS' => Http::response([
                ['id' => 10, 'cityId' => 1, 'abbr' => 'IKE', 'name' => 'Ikeja'],
            ]),
            'http://redstar.test/api/Operations/DropOffLocations/LOS' => Http::response([
                ['name' => 'Ikeja Express Centre', 'address' => 'Allen Avenue', 'abbr' => 'IKE'],
            ]),
        ]);

        $this->getJson('/api/v1/logistics/redstar/cities')
            ->assertOk()
            ->assertJsonPath('data.0.abbr', 'LOS');

        // Second call hits cache without another HTTP request.
        $this->getJson('/api/v1/logistics/redstar/cities')
            ->assertOk()
            ->assertJsonPath('data.1.abbr', 'ABV');

        $this->getJson('/api/v1/logistics/redstar/cities/LOS/towns')
            ->assertOk()
            ->assertJsonPath('data.0.id', 10);

        $this->getJson('/api/v1/logistics/redstar/cities/LOS/drop-off-locations')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Ikeja Express Centre');

        Http::assertSentCount(3);
    }

    public function test_delivery_address_requires_valid_redstar_location_and_caches_delivery_fee(): void
    {
        Http::fake([
            'http://redstar.test/api/Operations/Cities' => Http::response([
                ['id' => 1, 'abbr' => 'LOS', 'name' => 'Lagos'],
            ]),
            'http://redstar.test/api/Operations/DeliveryTowns/LOS' => Http::response([
                ['id' => 10, 'cityId' => 1, 'abbr' => 'IKE', 'name' => 'Ikeja'],
            ]),
            'http://redstar.test/api/Operations/DeliveryFee' => Http::response([
                'totalAmount' => 4500,
            ]),
        ]);

        $user = User::factory()->create();

        // Invalid Red Star town is rejected.
        $this->actingAs($user, 'api')
            ->postJson('/api/v1/addresses', [
                'type' => 'delivery',
                'recipient_name' => 'Jane Doe',
                'phone' => '08012345678',
                'line1' => '12 Allen Ave',
                'redstar_city_code' => 'LOS',
                'redstar_town_id' => 999,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('redstar_town_id');

        // Valid Red Star city & town succeeds and auto-fills state & city.
        $addressResponse = $this->actingAs($user, 'api')
            ->postJson('/api/v1/addresses', [
                'type' => 'delivery',
                'recipient_name' => 'Jane Doe',
                'phone' => '08012345678',
                'line1' => '12 Allen Ave',
                'redstar_city_code' => 'LOS',
                'redstar_town_id' => 10,
            ])
            ->assertCreated()
            ->assertJsonPath('data.state', 'Lagos')
            ->assertJsonPath('data.city', 'Ikeja')
            ->assertJsonPath('data.redstar_city_code', 'LOS')
            ->assertJsonPath('data.redstar_town_id', 10);

        $addressId = $addressResponse->json('data.id');
        $product = Product::factory()->create([
            'price' => 25000,
            'weight_kg' => 2.5,
            'status' => 'active',
            'stock' => 10,
        ]);

        $cart = Cart::activeForUser($user->id);
        $cart->items()->create([
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price_at_addition' => 25000,
        ]);

        $this->actingAs($user, 'api')
            ->getJson("/api/v1/delivery/options?cart_id={$cart->id}&address_id={$addressId}")
            ->assertOk()
            ->assertJsonPath('data.0.code', 'redstar')
            ->assertJsonPath('data.0.fee', '4500.00')
            ->assertJsonPath('data.0.weight_kg', 2.5);

        // Second delivery options call uses cached DeliveryFee.
        $this->actingAs($user, 'api')
            ->getJson("/api/v1/delivery/options?cart_id={$cart->id}&address_id={$addressId}")
            ->assertOk()
            ->assertJsonPath('data.0.fee', '4500.00');

        Http::assertSentCount(3);
    }

    public function test_admin_can_dispatch_order_to_redstar_and_fetch_waybill(): void
    {
        Http::fake([
            'http://redstar.test/api/Operations/PickupRequest' => Http::response([
                'waybillNumber' => 'RSW-998877',
            ]),
            'http://redstar.test/api/Operations/PrintWaybill*' => Http::response([
                'waybillNumber' => 'RSW-998877',
                'pdfUrl' => 'https://redstar.test/waybills/RSW-998877.pdf',
            ]),
        ]);

        $admin = User::factory()->admin()->create();
        $customer = User::factory()->create();

        $order = Order::create([
            'user_id' => $customer->id,
            'number' => 'LMT-TEST-1001',
            'currency' => 'NGN',
            'subtotal' => 25000,
            'discount_total' => 0,
            'credit_total' => 0,
            'shipping_total' => 4500,
            'grand_total' => 29500,
            'total_amount' => 29500,
            'status' => 'confirmed',
            'payment_status' => 'paid',
            'fulfilment_status' => 'unfulfilled',
            'payment_method' => 'card',
            'contact_email' => $customer->email,
            'delivery_method' => 'redstar',
            'shipping_address' => [
                'recipient_name' => 'Jane Doe',
                'phone' => '08012345678',
                'line1' => '12 Allen Ave',
                'city' => 'Ikeja',
                'state' => 'Lagos',
                'country' => 'NG',
                'redstar_city_code' => 'LOS',
                'redstar_town_id' => 10,
            ],
        ]);

        $this->actingAs($admin, 'api')
            ->postJson("/api/v1/admin/orders/{$order->id}/ship/redstar", [])
            ->assertOk()
            ->assertJsonPath('data.status', 'shipped')
            ->assertJsonPath('data.shipment.courier', 'redstar')
            ->assertJsonPath('data.shipment.tracking_number', 'RSW-998877');

        $this->actingAs($admin, 'api')
            ->getJson("/api/v1/admin/orders/{$order->id}/waybill")
            ->assertOk()
            ->assertJsonPath('data.waybillNumber', 'RSW-998877');
    }
}
