<?php

namespace AppLocalPlugins\GuideLink\Sources;

use AppLocalPlugins\GuideLink\Sources\Contracts\ChannelSource;
use AppLocalPlugins\GuideLink\Sources\Contracts\ProviderSource;
use AppLocalPlugins\GuideLink\Sources\Countries\UK\SkyUK\SkyUKSource;
use AppLocalPlugins\GuideLink\Sources\Countries\UK\SkyUK\Sources\TvChannelListsSource;
use AppLocalPlugins\GuideLink\Sources\Countries\UK\SkyUK\Sources\ViewersVaultSource;
use AppLocalPlugins\GuideLink\Support\CountryRulesRepository;

class SourceRegistry
{
    /**
     * @var array<string, array{label: string, adapter: class-string<ProviderSource>, sources: array<string, array{label: string, source: class-string<ChannelSource>}>}>
     */
    private const PROVIDERS = [
        'sky' => [
            'label' => 'Sky',
            'adapter' => SkyUKSource::class,
            'sources' => [
                'viewersvault' => [
                    'label' => 'ViewersVault',
                    'source' => ViewersVaultSource::class,
                ],
                'tvchannellists' => [
                    'label' => 'TV Channel Lists',
                    'source' => TvChannelListsSource::class,
                ],
            ],
        ],
    ];

    public function __construct(private readonly CountryRulesRepository $countryRules) {}

    /**
     * @return array<string, array{label: string, adapter: class-string<ProviderSource>, sources: array<string, array{label: string, source: class-string<ChannelSource>}>}>
     */
    public static function definitions(): array
    {
        return self::PROVIDERS;
    }

    /**
     * @return array<string, string>
     */
    public static function providerOptions(?string $country = null): array
    {
        $country = self::normalizedOptionalKey($country);
        $options = array_map(fn (array $definition): string => $definition['label'], self::PROVIDERS);
        if ($country === null) {
            return $options;
        }
        $providers = array_column(array_filter(
            CountrySourceSupport::packs(),
            fn (array $pack): bool => $pack['country'] === $country,
        ), 'provider');

        return array_intersect_key($options, array_fill_keys($providers, true));
    }

    /**
     * @return array<string, string>
     */
    public static function countryOptions(?string $provider = null): array
    {
        $countryOptions = CountryRulesRepository::options();
        $provider = self::normalizedOptionalKey($provider);
        if ($provider === null) {
            return $countryOptions;
        }

        return array_intersect_key($countryOptions, array_fill_keys(self::providerCountries($provider), true));
    }

    /**
     * @return array<string, string>
     */
    public static function sourceOptions(string $provider, ?string $country = null): array
    {
        $country = self::normalizedOptionalKey($country);
        $sources = self::providerDefinition($provider)['sources'];
        $supported = [];
        if ($country !== null) {
            foreach (CountrySourceSupport::packs() as $pack) {
                if ($pack['country'] === $country && $pack['provider'] === strtolower($provider)) {
                    $supported = [...$supported, ...$pack['sources']];
                }
            }
        }

        if ($country !== null) {
            $sources = array_intersect_key($sources, array_fill_keys($supported, true));
        }

        return array_map(fn (array $definition): string => $definition['label'], $sources);
    }

    /**
     * @return array<int, string>
     */
    public static function providerCountries(string $provider): array
    {
        self::providerDefinition($provider);

        return CountrySourceSupport::supportedCountries($provider);
    }

    /**
     * @return array<int, string>
     */
    public static function sourceCountries(string $provider, string $sourceName): array
    {
        self::sourceDefinition($provider, $sourceName);

        return CountrySourceSupport::supportedCountries($provider, $sourceName);
    }

    private static function normalizedOptionalKey(?string $value): ?string
    {
        $value = strtolower(trim((string) $value));

        return $value !== '' ? $value : null;
    }

    public static function supportsProvider(string $provider): bool
    {
        return isset(self::PROVIDERS[strtolower($provider)]);
    }

    public static function supportsCountry(string $provider, string $country, ?string $sourceName = null): bool
    {
        try {
            $countries = $sourceName === null
                ? self::providerCountries($provider)
                : self::sourceCountries($provider, $sourceName);
        } catch (\RuntimeException) {
            return false;
        }

        return in_array(strtolower($country), $countries, true);
    }

    public function provider(string $provider): ProviderSource
    {
        $definition = self::providerDefinition($provider);
        $class = $definition['adapter'];
        $adapter = new $class($this);

        if (! $adapter instanceof ProviderSource) {
            throw new \RuntimeException("Source provider adapter [{$class}] must implement ProviderSource.");
        }

        return $adapter;
    }

    public function source(string $provider, string $sourceName): ChannelSource
    {
        $definition = self::sourceDefinition($provider, $sourceName);
        $class = $definition['source'];
        $source = new $class($this->countryRules);

        if (! $source instanceof ChannelSource) {
            throw new \RuntimeException("Channel source [{$class}] must implement ChannelSource.");
        }

        return $source;
    }

    /**
     * @return array{label: string, adapter: class-string<ProviderSource>, sources: array<string, array{label: string, source: class-string<ChannelSource>}>}
     */
    private static function providerDefinition(string $provider): array
    {
        $provider = strtolower($provider);

        return self::PROVIDERS[$provider]
            ?? throw new \RuntimeException("Unsupported source provider [{$provider}].");
    }

    /**
     * @return array{label: string, source: class-string<ChannelSource>}
     */
    private static function sourceDefinition(string $provider, string $sourceName): array
    {
        $sourceName = strtolower($sourceName);
        $sources = self::providerDefinition($provider)['sources'];

        return $sources[$sourceName]
            ?? throw new \RuntimeException("Unsupported {$provider} source [{$sourceName}].");
    }
}
