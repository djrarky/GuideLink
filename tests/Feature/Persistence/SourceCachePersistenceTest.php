<?php

namespace AppLocalPlugins\GuideLink\Tests\Feature\Persistence;

require_once dirname(__DIR__).'/PluginFeatureTestCase.php';

use AppLocalPlugins\GuideLink\Scrapers\Contracts\ScraperClient;
use AppLocalPlugins\GuideLink\Scrapers\ScraperRegistry;
use AppLocalPlugins\GuideLink\Services\SourceCacheService;
use AppLocalPlugins\GuideLink\Services\SourceRefreshService;
use AppLocalPlugins\GuideLink\Support\CountryRulesRepository;
use AppLocalPlugins\GuideLink\Support\ProfileSettings;
use AppLocalPlugins\GuideLink\Tests\Feature\PluginFeatureTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class SourceCachePersistenceTest extends PluginFeatureTestCase
{
    use RefreshDatabase;

    public function test_parsed_cache_is_persistent_and_raw_responses_are_not_written(): void
    {
        Storage::fake('local');
        $cache = new SourceCacheService;
        $config = $this->cacheConfig();
        $entries = [[
            'number' => 101,
            'name' => 'Synthetic One',
            'category' => 'UK| TEST',
            'aliases' => ['Test One'],
        ]];

        $path = $cache->writeLastGood($config, $entries, [
            'fetched_at' => now()->toIso8601String(),
            'channel_count' => 1,
        ]);

        $stored = $cache->readLastGood($config);

        $this->assertSame($entries, $stored['entries']);
        $this->assertSame($path, $stored['path']);
        $this->assertTrue($cache->isFresh($config));
        $this->assertSame([$path], Storage::disk('local')->allFiles('plugin-data/guidelink'));
        $this->assertStringNotContainsString('/raw/', $path);
    }

    public function test_failed_fetch_retries_without_persisting_raw_content(): void
    {
        Storage::fake('local');
        [$context] = $this->context();
        $scraper = new class implements ScraperClient
        {
            public function fetch(string $url, string $format, string $apiKey, int $timeoutSeconds): string
            {
                throw new RuntimeException('synthetic fetch failure');
            }
        };

        try {
            (new SourceCacheService)->fetchFresh(
                $scraper,
                $context,
                $this->cacheConfig(['max_retries' => 2]),
                'https://example.invalid/source',
                'html',
            );
            $this->fail('Expected the synthetic source fetch to fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('after 2 attempt(s)', $exception->getMessage());
        }

        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(2, $context->run->logs()->where('level', 'warning')->count());
    }

    public function test_scraper_api_key_comes_from_profile_and_ignores_environment(): void
    {
        putenv('GUIDELINK_SCRAPER_API_KEY=env-fallback-key');
        $_ENV['GUIDELINK_SCRAPER_API_KEY'] = 'env-fallback-key';
        $_SERVER['GUIDELINK_SCRAPER_API_KEY'] = 'env-fallback-key';

        try {
            $cache = new SourceCacheService;
            foreach (['', 'profile-key'] as $key) {
                $profile = ProfileSettings::fromPayload($this->cacheConfig(['scraping_api_key' => $key]));
                $this->assertSame($key, $profile['scraping_api_key']);
                $this->assertSame($key, $cache->config($profile, [])['scraping_api_key']);
            }
            $this->assertSame('', $cache->config([], [])['scraping_api_key']);
        } finally {
            putenv('GUIDELINK_SCRAPER_API_KEY');
            unset($_ENV['GUIDELINK_SCRAPER_API_KEY'], $_SERVER['GUIDELINK_SCRAPER_API_KEY']);
        }
    }

    public function test_scraper_errors_redact_credentials_from_logs_and_exceptions(): void
    {
        [$context] = $this->context();
        $key = 'private+key/with space';
        $scraper = new class implements ScraperClient
        {
            public function fetch(string $url, string $format, string $apiKey, int $timeoutSeconds): string
            {
                throw new RuntimeException('Request failed: token='.rawurlencode($apiKey).' form='.urlencode($apiKey).' response='.$apiKey);
            }
        };

        try {
            (new SourceCacheService)->fetchFresh($scraper, $context, $this->cacheConfig([
                'scraping_api_key' => $key, 'max_retries' => 2,
            ]), 'https://example.invalid/source', 'html');
            $this->fail('Expected a source fetch failure.');
        } catch (RuntimeException $exception) {
            $this->assertNull($exception->getPrevious());
            $this->assertStringContainsString('[redacted]', $exception->getMessage());
            $output = $exception->getMessage().$context->run->logs()->get()->toJson();
            foreach ([$key, rawurlencode($key), urlencode($key)] as $secret) {
                $this->assertStringNotContainsString($secret, $output);
            }
        }
        $this->assertSame(2, $context->run->logs()->where('level', 'warning')->count());
    }

    public function test_parsed_cache_paths_include_country(): void
    {
        $cache = new SourceCacheService;

        $ukPath = $cache->parsedPath($this->cacheConfig([
            'country' => 'uk',
            'provider' => 'sky',
            'provider_source' => 'viewersvault',
            'region' => '1',
        ]));
        $iePath = $cache->parsedPath($this->cacheConfig([
            'country' => 'ie',
            'provider' => 'sky',
            'provider_source' => 'viewersvault',
            'region' => '1',
        ]));

        $this->assertStringContainsString('sky_uk_viewersvault_region_1', $ukPath);
        $this->assertStringContainsString('sky_ie_viewersvault_region_1', $iePath);
        $this->assertNotSame($ukPath, $iePath);
    }

    public function test_refresh_rejects_unsupported_provider_country_source_before_fetch(): void
    {
        Storage::fake('local');
        [$context] = $this->context();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported source combination [sky/uk/missing].');

        (new SourceRefreshService(new CountryRulesRepository, new SourceCacheService))
            ->refresh($this->cacheConfig([
                'provider' => 'sky',
                'country' => 'uk',
                'provider_source' => 'missing',
                'force_refresh' => true,
            ]), $context);
    }

    public function test_failed_refresh_uses_the_parsed_last_good_cache(): void
    {
        Storage::fake('local');
        [$context] = $this->context();
        $cache = new SourceCacheService;
        $payload = $this->cacheConfig([
            'provider_source' => 'viewersvault',
            'region' => '1',
            'force_refresh' => true,
        ]);
        $entries = [[
            'number' => 101,
            'name' => 'Last Good Channel',
            'category' => 'Entertainment',
            'aliases' => [],
        ]];
        $cache->writeLastGood($payload, $entries, [
            'provider' => 'sky',
            'provider_source' => 'viewersvault',
            'region' => '1',
            'fetched_at' => now()->subDay()->toIso8601String(),
            'channel_count' => 1,
        ]);
        $scrapers = new class extends ScraperRegistry
        {
            public function make(string $name): ScraperClient
            {
                return new class implements ScraperClient
                {
                    public function fetch(string $url, string $format, string $apiKey, int $timeoutSeconds): string
                    {
                        throw new RuntimeException('synthetic upstream outage');
                    }
                };
            }
        };

        $result = (new SourceRefreshService(new CountryRulesRepository, $cache, $scrapers))
            ->refresh($payload, $context);

        $this->assertSame($entries, $result['entries']);
        $this->assertTrue($result['summary']['source']['from_cache']);
        $this->assertTrue($result['summary']['source']['stale_fallback']);
        $this->assertStringContainsString('synthetic upstream outage', $result['summary']['source']['refresh_error']);
        $this->assertCount(1, Storage::disk('local')->allFiles('plugin-data/guidelink'));
    }
}
