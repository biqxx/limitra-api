<?php

namespace Tests\Unit;

use App\Services\Currency\CurrencyExchangeService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class ExchangeRateServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config([
            'services.exchange_rates.base_url' => 'https://rates.test/v6',
            'services.exchange_rates.naira_markup' => 100,
            'services.exchange_rates.retries' => 1,
        ]);
    }

    public function test_it_adds_the_naira_markup_and_caches_the_daily_market_rate(): void
    {
        Http::fake([
            'https://rates.test/v6/latest/USD' => Http::response([
                'result' => 'success',
                'base_code' => 'USD',
                'rates' => ['NGN' => 1500],
            ]),
        ]);

        $service = app(CurrencyExchangeService::class);
        $firstQuote = $service->quoteToNaira(2, 'usd');
        $secondQuote = $service->quoteToNaira(1, 'USD');

        $this->assertSame(1500.0, $firstQuote['market_rate']);
        $this->assertSame(100.0, $firstQuote['naira_markup']);
        $this->assertSame(1600.0, $firstQuote['effective_rate']);
        $this->assertSame(3200.0, $firstQuote['converted_amount']);
        $this->assertSame(1600.0, $secondQuote['converted_amount']);
        Http::assertSentCount(1);
    }

    public function test_ngn_amounts_are_not_given_the_exchange_rate_markup(): void
    {
        $quote = app(CurrencyExchangeService::class)->quoteToNaira(1250.55, 'NGN');

        $this->assertSame(1.0, $quote['effective_rate']);
        $this->assertSame(1250.55, $quote['converted_amount']);
        Http::assertNothingSent();
    }

    public function test_it_rejects_an_invalid_provider_rate(): void
    {
        Http::fake([
            'https://rates.test/v6/latest/USD' => Http::response([
                'result' => 'error',
                'rates' => [],
            ], 503),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('did not return a valid NGN rate');

        app(CurrencyExchangeService::class)->quoteToNaira(1, 'USD');
    }

    public function test_daily_rate_refresh_is_scheduled(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => $event->description === 'currency:daily-rate-refresh');

        $this->assertNotNull($event);
        $this->assertSame('10 0 * * *', $event->expression);
    }
}
