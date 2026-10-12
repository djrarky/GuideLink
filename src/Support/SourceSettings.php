<?php

namespace AppLocalPlugins\GuideLink\Support;

final class SourceSettings
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $settings
     * @return array{country: string, provider: string, provider_source: string, region: string}
     */
    public static function identity(array $payload, array $settings = []): array
    {
        $defaultCountry = ProfileDefaults::string('country');
        $provider = strtolower(Options::string($payload, $settings, 'provider', ProfileDefaults::string('provider')));
        $source = strtolower(Options::string($payload, $settings, 'provider_source', ProfileDefaults::string('provider_source')));
        $region = Options::string($payload, $settings, 'region', ProfileDefaults::string('region'));

        return [
            'country' => self::country(Options::string($payload, $settings, 'country', $defaultCountry), $defaultCountry),
            'provider' => $provider !== '' ? $provider : ProfileDefaults::string('provider'),
            'provider_source' => $source !== '' ? $source : ProfileDefaults::string('provider_source'),
            'region' => $region !== '' ? $region : ProfileDefaults::string('region'),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public static function runtimeCache(array $payload, array $settings = []): array
    {
        return self::cache($payload, $settings, includeForceRefresh: true);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function profileCache(array $payload): array
    {
        return self::cache($payload, [], includeForceRefresh: false);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private static function cache(
        array $payload,
        array $settings = [],
        bool $includeForceRefresh = true,
    ): array {
        $scraper = strtolower(Options::string($payload, $settings, 'scraping_api', ProfileDefaults::string('scraping_api')));

        $config = [
            ...self::identity($payload, $settings),
            'scraping_api' => $scraper !== '' ? $scraper : ProfileDefaults::string('scraping_api'),
            'scraping_api_key' => Options::string($payload, $settings, 'scraping_api_key', ProfileDefaults::string('scraping_api_key')),
            'scraping_timeout_seconds' => max(1, Options::number($payload, $settings, 'scraping_timeout_seconds', ProfileDefaults::int('scraping_timeout_seconds'))),
            'source_cache_days' => max(0, Options::number($payload, $settings, 'source_cache_days', ProfileDefaults::int('source_cache_days'))),
            'min_channels' => max(1, Options::number($payload, $settings, 'min_channels', ProfileDefaults::int('min_channels'))),
            'max_drop_percent' => max(0, Options::number($payload, $settings, 'max_drop_percent', ProfileDefaults::int('max_drop_percent'))),
            'max_retries' => max(1, Options::number($payload, $settings, 'max_retries', ProfileDefaults::int('max_retries'))),
        ];

        if ($includeForceRefresh) {
            $config['force_refresh'] = Options::bool($payload, $settings, 'force_refresh', false);
        }

        return $config;
    }

    private static function country(string $country, string $default): string
    {
        return preg_replace('/[^a-z0-9_-]/', '', strtolower($country)) ?: $default;
    }
}
