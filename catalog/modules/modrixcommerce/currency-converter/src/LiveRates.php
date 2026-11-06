<?php

namespace EcoModular\CurrencyConverter;

use App\Core\Extensions\ModuleSettingsSchema;
use App\Core\Settings\Currencies;
use App\Core\Settings\StoreSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Latest exchange rates for the store currency. One unit of the store
 * currency buys the stored number of units of each other currency.
 */
final class LiveRates
{
    public const CACHE = 'ecomodular.currency-converter.rates';

    private const ENDPOINT = 'https://open.er-api.com/v6/latest/';

    public function __construct(
        private readonly ModuleSettingsSchema $settings,
        private readonly StoreSettings $store,
    ) {}

    /**
     * Rates for the currencies the merchant listed, refreshing when stale.
     *
     * @return array<string, string>
     */
    public function forDisplay(): array
    {
        $base = $this->store->currency();
        $cached = $this->cached($base);
        $rates = $cached !== null && $this->fresh($cached) ? $cached['rates'] : $this->refresh();

        return array_intersect_key($rates, array_flip($this->codes()));
    }

    /**
     * @return array<string, string>
     */
    public function refresh(): array
    {
        $base = $this->store->currency();

        try {
            $response = Http::connectTimeout(2)->timeout(5)->acceptJson()->get(self::ENDPOINT.$base);
            $body = $response->json();
            if (! $response->ok() || ! is_array($body) || ($body['result'] ?? null) !== 'success' || ! is_array($body['rates'] ?? null)) {
                return $this->remembered($base);
            }

            $rates = [];
            foreach ($body['rates'] as $code => $rate) {
                $code = strtoupper((string) $code);
                $formatted = $this->format($rate);
                if ($code !== $base && isset(Currencies::ALL[$code]) && $formatted !== null) {
                    $rates[$code] = $formatted;
                }
            }

            Cache::forever(self::CACHE, [
                'base' => $base,
                'fetched_at' => now()->getTimestamp(),
                'rates' => $rates,
            ]);

            return $rates;
        } catch (Throwable) {
            return $this->remembered($base);
        }
    }

    /**
     * @return list<string>
     */
    public function codes(): array
    {
        $raw = $this->settings->of(CurrencyConverterServiceProvider::MODULE, 'currencies', 'USD,AED,EUR,GBP,SAR');
        if (! is_string($raw)) {
            return [];
        }

        $codes = [];
        foreach (preg_split('/[\s,]+/', strtoupper($raw)) ?: [] as $code) {
            if (isset(Currencies::ALL[$code])) {
                $codes[] = $code;
            }
        }

        return array_values(array_unique($codes));
    }

    public function refreshHours(): int
    {
        $hours = $this->settings->of(CurrencyConverterServiceProvider::MODULE, 'refresh_hours', 6);

        return is_int($hours) && $hours >= 1 ? min($hours, 24) : 6;
    }

    /**
     * @param  array{base: string, fetched_at: int, rates: array<string, string>}  $cached
     */
    private function fresh(array $cached): bool
    {
        return $cached['fetched_at'] >= now()->subHours($this->refreshHours())->getTimestamp();
    }

    /**
     * @return array{base: string, fetched_at: int, rates: array<string, string>}|null
     */
    private function cached(string $base): ?array
    {
        $cached = Cache::get(self::CACHE);
        if (! is_array($cached) || ($cached['base'] ?? null) !== $base || ! is_int($cached['fetched_at'] ?? null) || ! is_array($cached['rates'] ?? null)) {
            return null;
        }

        return $cached;
    }

    /**
     * @return array<string, string>
     */
    private function remembered(string $base): array
    {
        $cached = $this->cached($base);

        return $cached === null ? [] : $cached['rates'];
    }

    private function format(mixed $rate): ?string
    {
        if (! is_int($rate) && ! is_float($rate) && ! (is_string($rate) && is_numeric($rate))) {
            return null;
        }

        $plain = rtrim(rtrim(sprintf('%.8F', (float) $rate), '0'), '.');
        if (! preg_match('/^\d{1,9}(\.\d{1,8})?$/', $plain)) {
            return null;
        }

        return $plain;
    }
}
