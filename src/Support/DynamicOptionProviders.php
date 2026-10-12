<?php

namespace AppLocalPlugins\GuideLink\Support;

use App\Plugins\Support\PluginSelectOptionsContext;
use AppLocalPlugins\GuideLink\Scrapers\ScraperRegistry;
use AppLocalPlugins\GuideLink\Services\BundledAliasService;
use AppLocalPlugins\GuideLink\Services\ProfileAliasResolver;
use AppLocalPlugins\GuideLink\Sources\SourceRegistry;

final class DynamicOptionProviders
{
    public const PROVIDERS = 'guidelink_providers';

    public const COUNTRIES = 'guidelink_countries';

    public const SOURCES = 'guidelink_sources';

    public const SCRAPERS = 'guidelink_scrapers';

    /**
     * @return array<string, string>
     */
    public static function options(string $provider, PluginSelectOptionsContext $context): array
    {
        $country = strtolower(trim((string) $context->value(
            'settings.country',
            $context->value('country', ProfileDefaults::string('country')),
        )));

        $sourceProvider = strtolower(trim((string) $context->value(
            'settings.provider',
            $context->value('provider', ProfileDefaults::string('provider')),
        ))) ?: ProfileDefaults::string('provider');

        return match ($provider) {
            'guidelink_bundled_sets' => (new BundledAliasService)->options(),
            'guidelink_custom_alias_sets' => ProfileAliasResolver::customSetOptions($context),
            'guidelink_alias_targets' => ProfileAliasResolver::targetOptions($context),
            self::PROVIDERS => SourceRegistry::providerOptions($country !== '' ? $country : null),
            self::COUNTRIES => SourceRegistry::countryOptions(),
            self::SOURCES => SourceRegistry::supportsProvider($sourceProvider)
                ? SourceRegistry::sourceOptions($sourceProvider, $country !== '' ? $country : null)
                : [],
            self::SCRAPERS => ScraperRegistry::options(),
            default => [],
        };
    }

    /**
     * @return array<int, array{key: string, value: string, label: string, provider: string, depends_on?: array<int, string>}>
     */
    public static function manifestRequirements(): array
    {
        return [
            [
                'key' => 'id',
                'value' => 'settings.provider',
                'label' => 'provider field',
                'provider' => self::PROVIDERS,
                'depends_on' => ['settings.country'],
            ],
            [
                'key' => 'id',
                'value' => 'settings.country',
                'label' => 'country field',
                'provider' => self::COUNTRIES,
            ],
            [
                'key' => 'id',
                'value' => 'settings.provider_source',
                'label' => 'provider source field',
                'provider' => self::SOURCES,
                'depends_on' => ['settings.country', 'settings.provider'],
            ],
            [
                'key' => 'name',
                'value' => 'settings.provider_source',
                'label' => 'provider source column',
                'provider' => self::SOURCES,
                'depends_on' => ['settings.country', 'settings.provider'],
            ],
            [
                'key' => 'id',
                'value' => 'settings.scraping_api',
                'label' => 'scraping API field',
                'provider' => self::SCRAPERS,
            ],
        ];
    }
}
