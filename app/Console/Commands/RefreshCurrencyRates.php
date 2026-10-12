<?php

namespace App\Console\Commands;

use App\Services\Currency\CurrencyExchangeService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('currency:refresh-rates {--currency=* : Currency codes to refresh instead of the configured defaults}')]
#[Description('Refresh the daily foreign-currency market rates used for naira pricing')]
class RefreshCurrencyRates extends Command
{
    public function handle(CurrencyExchangeService $exchangeRates): int
    {
        $requestedCurrencies = $this->option('currency');
        $currencies = $requestedCurrencies !== []
            ? $requestedCurrencies
            : config('services.exchange_rates.currencies', ['USD']);
        $failed = false;

        foreach (array_unique(array_map('strtoupper', $currencies)) as $currency) {
            try {
                $rate = $exchangeRates->marketRateToNaira($currency, forceRefresh: true);
                $this->info(sprintf('%s/NGN market rate refreshed: %s', $currency, $rate));
            } catch (Throwable $exception) {
                $failed = true;
                $this->error(sprintf('%s/NGN refresh failed: %s', $currency, $exception->getMessage()));
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
