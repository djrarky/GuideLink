<?php

namespace AppLocalPlugins\GuideLink\Scrapers;

use AppLocalPlugins\GuideLink\Scrapers\Backends\DirectHttpScraper;
use AppLocalPlugins\GuideLink\Scrapers\Backends\OloStepScraper;
use AppLocalPlugins\GuideLink\Scrapers\Backends\ScrapeDoScraper;
use AppLocalPlugins\GuideLink\Scrapers\Contracts\ScraperClient;

class ScraperRegistry
{
    /**
     * @var array<string, array{label: string, client: class-string<ScraperClient>, requires_api_key: bool}>
     */
    private const SCRAPERS = [
        'direct_http' => [
            'label' => 'Direct HTTP',
            'client' => DirectHttpScraper::class,
            'requires_api_key' => false,
        ],
        'olostep' => [
            'label' => 'OloStep',
            'client' => OloStepScraper::class,
            'requires_api_key' => true,
        ],
        'scrapedo' => [
            'label' => 'Scrape.do',
            'client' => ScrapeDoScraper::class,
            'requires_api_key' => true,
        ],
    ];

    /**
     * @return array<string, array{label: string, client: class-string<ScraperClient>, requires_api_key: bool}>
     */
    public static function definitions(): array
    {
        return self::SCRAPERS;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_map(fn (array $definition): string => $definition['label'], self::SCRAPERS);
    }

    public function requiresApiKey(string $name): bool
    {
        return self::definition($name)['requires_api_key'];
    }

    public function make(string $name): ScraperClient
    {
        $definition = self::definition($name);
        $class = $definition['client'];
        $client = new $class;

        if (! $client instanceof ScraperClient) {
            throw new \RuntimeException("Scraper client [{$class}] must implement ScraperClient.");
        }

        return $client;
    }

    /**
     * @return array{label: string, client: class-string<ScraperClient>, requires_api_key: bool}
     */
    private static function definition(string $name): array
    {
        $name = strtolower($name);

        return self::SCRAPERS[$name]
            ?? throw new \RuntimeException("Unsupported scraper backend [{$name}].");
    }
}
