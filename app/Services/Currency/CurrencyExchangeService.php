<?php

namespace App\Services\Currency;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use UnexpectedValueException;

class CurrencyExchangeService
{
    private const TARGET_CURRENCY = 'NGN';

    /** @return array{source_currency: string, target_currency: string, source_amount: float, market_rate: float, naira_markup: float, effective_rate: float, converted_amount: float} */
    public function quoteToNaira(float $amount, string $sourceCurrency): array
    {
        if ($amount <= 0) {
            throw new UnexpectedValueException('The amount to convert must be greater than zero.');
        }

        $currency = $this->normaliseCurrency($sourceCurrency);

        if ($currency === self::TARGET_CURRENCY) {
            return [
                'source_currency' => $currency,
                'target_currency' => self::TARGET_CURRENCY,
                'source_amount' => $amount,
                'market_rate' => 1.0,
                'naira_markup' => 0.0,
                'effective_rate' => 1.0,
                'converted_amount' => round($amount, 2),
            ];
        }

        $marketRate = $this->marketRateToNaira($currency);
        $markup = (float) config('services.exchange_rates.naira_markup', 100);
        $effectiveRate = $marketRate + $markup;

        return [
            'source_currency' => $currency,
            'target_currency' => self::TARGET_CURRENCY,
            'source_amount' => $amount,
            'market_rate' => $marketRate,
            'naira_markup' => $markup,
            'effective_rate' => $effectiveRate,
            'converted_amount' => round($amount * $effectiveRate, 2),
        ];
    }

    public function marketRateToNaira(string $sourceCurrency, bool $forceRefresh = false): float
    {
        $currency = $this->normaliseCurrency($sourceCurrency);

        if ($currency === self::TARGET_CURRENCY) {
            return 1.0;
        }

        $cacheKey = sprintf('currency-rates:%s:%s:%s', $currency, self::TARGET_CURRENCY, now()->format('Y-m-d'));

        if (! $forceRefresh && Cache::has($cacheKey)) {
            return (float) Cache::get($cacheKey);
        }

        $rate = $this->fetchMarketRate($currency);
        Cache::put($cacheKey, $rate, now()->addDays(2));

        return $rate;
    }

    private function fetchMarketRate(string $sourceCurrency): float
    {
        $baseUrl = rtrim((string) config('services.exchange_rates.base_url'), '/');

        if ($baseUrl === '') {
            throw new RuntimeException('The exchange-rate service is not configured.');
        }

        try {
            $response = Http::baseUrl($baseUrl)
                ->acceptJson()
                ->timeout((int) config('services.exchange_rates.timeout', 10))
                ->retry((int) config('services.exchange_rates.retries', 2), 200, throw: false)
                ->get('latest/'.$sourceCurrency);
        } catch (ConnectionException) {
            throw new RuntimeException('The exchange-rate service is unavailable.');
        }

        $rate = $response->successful() && $response->json('result') === 'success'
            ? $response->json('rates.'.self::TARGET_CURRENCY)
            : null;

        if (! is_numeric($rate) || (float) $rate <= 0) {
            throw new RuntimeException('The exchange-rate service did not return a valid NGN rate.');
        }

        return (float) $rate;
    }

    private function normaliseCurrency(string $currency): string
    {
        $currency = strtoupper(trim($currency));

        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new UnexpectedValueException('A valid three-letter currency code is required.');
        }

        return $currency;
    }
}
