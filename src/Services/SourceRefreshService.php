<?php

namespace AppLocalPlugins\GuideLink\Services;

use App\Plugins\Support\PluginExecutionContext;
use AppLocalPlugins\GuideLink\Scrapers\Contracts\ScraperClient;
use AppLocalPlugins\GuideLink\Scrapers\ScraperRegistry;
use AppLocalPlugins\GuideLink\Sources\Contracts\ProviderSource;
use AppLocalPlugins\GuideLink\Sources\Contracts\SourceRequest;
use AppLocalPlugins\GuideLink\Sources\SourceRegistry;
use AppLocalPlugins\GuideLink\Support\CountryRulesRepository;
use Throwable;

/**
 * Fetch and validate registered sources, preserving the last-good parsed cache.
 */
class SourceRefreshService
{
    private readonly SourceRegistry $sources;

    public function __construct(
        private readonly CountryRulesRepository $countryRules,
        private readonly SourceCacheService $cache,
        private readonly ScraperRegistry $scrapers = new ScraperRegistry,
        ?SourceRegistry $sources = null,
    ) {
        $this->sources = $sources ?? new SourceRegistry($this->countryRules);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{summary: array<string, mixed>, entries: array<int, array<string, mixed>>}
     */
    public function refresh(array $payload, PluginExecutionContext $context): array
    {
        $config = $this->cache->config($payload, []);
        $rules = $this->countryRules->resolve($payload, []);
        $request = SourceRequest::fromConfig($config);
        $request->assertSupported();

        if ($this->scrapers->requiresApiKey((string) $config['scraping_api']) && $config['scraping_api_key'] === '') {
            throw new \RuntimeException("Scraper API key is required for [{$config['scraping_api']}].");
        }

        if ($config['force_refresh'] === false && $this->cache->isFresh($config)) {
            try {
                $cached = $this->cache->readLastGood($config);
                $context->info('Using fresh parsed source cache.', [
                    'source_cache_path' => $cached['path'],
                    'channel_count' => count($cached['entries']),
                    'fetched_at' => $cached['metadata']['fetched_at'] ?? null,
                ]);

                return $this->cachedResult($cached, staleFallback: false);
            } catch (Throwable $exception) {
                $context->warning('Fresh source cache could not be read; attempting a live refresh.', [
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $source = $this->sources->provider($request->provider);
        $definition = $source->definition($request->source);
        $scraper = $this->scrapers->make((string) $config['scraping_api']);

        try {
            return $this->refreshFromSource($config, $rules, $request, $source, $definition, $scraper, $context);
        } catch (Throwable $exception) {
            try {
                $cached = $this->cache->readLastGood($config);
            } catch (Throwable) {
                throw $exception;
            }

            $context->warning('Source refresh failed; continuing with the parsed last-good cache.', [
                'source_cache_path' => $cached['path'],
                'channel_count' => count($cached['entries']),
                'error' => $exception->getMessage(),
            ]);

            return $this->cachedResult($cached, staleFallback: true, error: $exception->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $rules
     * @param  array<string, string>  $definition
     * @return array{summary: array<string, mixed>, entries: array<int, array<string, mixed>>}
     */
    private function refreshFromSource(
        array $config,
        array $rules,
        SourceRequest $request,
        ProviderSource $source,
        array $definition,
        ScraperClient $scraper,
        PluginExecutionContext $context,
    ): array {
        $context->checkpoint(15, 'Fetching source region mapping.', [
            'provider' => $request->provider,
            'provider_source' => $request->source,
            'region' => $request->region,
        ], log: true);

        $regionRaw = empty($definition['region_url']) ? '' : $this->cache->fetchFresh(
            $scraper, $context, $config, $definition['region_url'], $definition['region_format'] ?? 'html',
        );
        $region = $source->resolveRegion($request->source, $regionRaw, $request->region,
            function (string $url) use ($scraper, $context, $config, &$regionRaw): string {
                $content = $this->cache->fetchFresh($scraper, $context, $config, $url, 'html');
                $regionRaw .= "\0".$content;

                return $content;
            });

        $context->checkpoint(40, 'Fetching channel source page.', [
            'provider' => $request->provider,
            'provider_source' => $request->source,
            'region_name' => $region['name'] ?? '',
        ], log: true);

        $channelRaw = $this->cache->fetchFresh($scraper, $context, $config, $definition['url'], $definition['format']);
        $entries = $source->parseChannels($channelRaw, $region, $rules, $request->source);
        $previousCount = $this->cache->previousCount($config);

        $this->validate($entries, $previousCount, $config);

        $metadata = [
            'country' => $request->country,
            'provider' => $request->provider,
            'provider_source' => $request->source,
            'region' => $request->region,
            'region_name' => $region['name'] ?? '',
            'scraper' => $config['scraping_api'],
            'fetched_at' => now()->toIso8601String(),
            'validated_at' => now()->toIso8601String(),
            'channel_count' => count($entries),
            'previous_channel_count' => $previousCount,
            'source_checksum' => hash('sha256', $regionRaw."\0".$channelRaw),
            'source_version' => 1,
        ];
        $path = $this->cache->writeLastGood($config, $entries, $metadata);

        return [
            'summary' => [
                'source' => [
                    ...$metadata,
                    'from_cache' => false,
                    'stale_fallback' => false,
                    'last_good_cache_path' => $path,
                ],
            ],
            'entries' => $entries,
        ];
    }

    /**
     * @param  array{metadata: array<string, mixed>, entries: array<int, array<string, mixed>>, path: string}  $cached
     * @return array{summary: array<string, mixed>, entries: array<int, array<string, mixed>>}
     */
    private function cachedResult(array $cached, bool $staleFallback, ?string $error = null): array
    {
        $source = [
            ...$cached['metadata'],
            'channel_count' => count($cached['entries']),
            'from_cache' => true,
            'stale_fallback' => $staleFallback,
            'last_good_cache_path' => $cached['path'],
        ];

        if ($error !== null) {
            $source['refresh_error'] = $error;
        }

        return [
            'summary' => ['source' => $source],
            'entries' => $cached['entries'],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @param  array<string, mixed>  $config
     */
    private function validate(array $entries, int $previousCount, array $config): void
    {
        $count = count($entries);
        if ($count < (int) $config['min_channels']) {
            throw new \RuntimeException("Source parsed only {$count} channel(s); minimum is {$config['min_channels']}.");
        }

        if ($previousCount > 0 && $count < $previousCount) {
            $drop = (($previousCount - $count) / $previousCount) * 100;
            if ($drop > (int) $config['max_drop_percent']) {
                throw new \RuntimeException('Source channel count dropped '.round($drop)."% ({$previousCount} to {$count}); refusing to replace last-good cache.");
            }
        }
    }
}
