<?php

namespace AppLocalPlugins\GuideLink\Tests\Feature\Persistence;

require_once dirname(__DIR__).'/PluginFeatureTestCase.php';

use App\Models\Channel;
use AppLocalPlugins\GuideLink\Scrapers\Backends\DirectHttpScraper;
use AppLocalPlugins\GuideLink\Services\SourceCacheService;
use AppLocalPlugins\GuideLink\Services\SourceRefreshService;
use AppLocalPlugins\GuideLink\Sources\Contracts\ProviderSource;
use AppLocalPlugins\GuideLink\Sources\SourceRegistry;
use AppLocalPlugins\GuideLink\Support\CountryRulesRepository;
use AppLocalPlugins\GuideLink\Tests\Feature\PluginFeatureTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;

class SourceCacheSafetyTest extends PluginFeatureTestCase
{
    use RefreshDatabase;

    public function test_a_shared_cache_cannot_bypass_the_current_profiles_minimum_channel_count(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response('unavailable', 503)]);
        [$context] = $this->context();
        $cache = new SourceCacheService;
        $config = $this->cacheConfig(['provider_source' => 'viewersvault']);
        $cache->writeLastGood($config, $this->entries(), ['fetched_at' => now()->toIso8601String()]);
        $strict = [...$config, 'min_channels' => 200];
        $this->assertFalse($cache->isFresh($strict));
        $this->expectException(\RuntimeException::class);
        (new SourceRefreshService(new CountryRulesRepository, $cache))->refresh($strict, $context);
    }

    public function test_copying_an_old_cache_does_not_make_its_data_fresh_again(): void
    {
        Storage::fake('local');
        $cache = new SourceCacheService;
        $config = $this->cacheConfig();
        $cache->writeLastGood($config, $this->entries(), ['fetched_at' => now()->subDays(10)->toIso8601String()]);
        $this->assertFalse($cache->isFresh($config));
        $cache->writeLastGood($config, $this->entries(), ['fetched_at' => now()->addDay()->toIso8601String()]);
        $this->assertFalse($cache->isFresh($config));
    }

    public function test_undersized_cache_stops_sync_before_native_settings_or_quarantine_writes(): void
    {
        Storage::fake('local');
        Bus::fake();
        Http::preventStrayRequests();
        [$context, $playlist] = $this->context(applyPluginSchema: true, dryRun: false);
        $config = $this->cacheConfig(['provider_source' => 'viewersvault']);
        (new SourceCacheService)->writeLastGood($config, $this->entries(), []);
        $this->assignProfile($context, $playlist, [
            ...$config, 'source_mode' => 'scraper_cache', 'min_channels' => 200,
            'guide_epg_id' => $this->guideEpgId($context), 'disable_other_groups' => true,
        ]);
        $channel = Channel::factory()->createQuietly([
            'playlist_id' => $playlist->id, 'user_id' => $context->user->id,
            'name' => 'Unmatched channel', 'enabled' => true, 'can_merge' => true, 'is_vod' => false,
        ]);
        $before = $channel->refresh()->getAttributes();
        $playlistBefore = $playlist->refresh()->getAttributes();
        $result = $this->runGuideLinkAction('apply_sync_playlist', ['playlist_id' => $playlist->id], $context);
        $this->assertFalse($result->success);
        $this->assertStringContainsString('minimum of 200', $result->summary);
        $this->assertSame($before, $channel->refresh()->getAttributes());
        $this->assertSame($playlistBefore, $playlist->refresh()->getAttributes());
        Http::assertNothingSent();
    }

    public function test_corrupt_cache_is_never_considered_fresh_for_setup(): void
    {
        Storage::fake('local');
        $cache = new SourceCacheService;
        $config = $this->cacheConfig();
        Storage::disk('local')->put($cache->parsedPath($config), '{corrupt JSON');
        $this->assertFalse($cache->isFresh($config));
    }

    public function test_cache_publication_errors_remove_the_temporary_file(): void
    {
        Storage::fake('local');
        $cache = new SourceCacheService;
        $config = $this->cacheConfig();
        Storage::disk('local')->makeDirectory($cache->parsedPath($config));
        try {
            $cache->writeLastGood($config, $this->entries(), []);
            $this->fail('A cache file cannot replace a directory.');
        } catch (\ErrorException|\RuntimeException $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_rate_limited_scrapers_are_not_immediately_retried(): void
    {
        [$context] = $this->context();
        Http::fake(['*' => Http::response('Rate limit exceeded', 429, ['Retry-After' => '3600'])]);
        try {
            (new SourceCacheService)->fetchFresh(new DirectHttpScraper, $context,
                $this->cacheConfig(['max_retries' => 3]), 'https://example.invalid/source', 'html');
            $this->fail('Expected the rate-limit failure.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('after 1 attempt(s)', $exception->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_a_slow_refresh_does_not_overwrite_a_cache_published_while_it_was_fetching(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response('older fetched page', 200)]);
        [$context] = $this->context();
        $cache = new SourceCacheService;
        $config = $this->cacheConfig(['provider_source' => 'viewersvault', 'force_refresh' => true]);
        $latest = [['number' => 101, 'name' => 'Newer channel listing', 'category' => 'Entertainment', 'aliases' => []]];
        $provider = Mockery::mock(ProviderSource::class);
        $provider->shouldReceive('definition')->once()->andReturn(['url' => 'https://example.invalid/source', 'format' => 'html']);
        $provider->shouldReceive('resolveRegion')->once()->andReturn(['name' => 'Test']);
        $provider->shouldReceive('parseChannels')->once()->andReturnUsing(function () use ($cache, $config, $latest) {
            // Another worker completes after this fetch began but before it saves.
            $cache->writeLastGood($config, $latest, ['fetched_at' => now()->toIso8601String()]);

            return $this->entries();
        });
        $sources = Mockery::mock(SourceRegistry::class);
        $sources->shouldReceive('provider')->once()->andReturn($provider);
        $result = (new SourceRefreshService(new CountryRulesRepository, $cache, sources: $sources))->refresh($config, $context);
        $this->assertSame($latest, $cache->readLastGood($config)['entries']);
        $this->assertSame($latest, $result['entries']);
        $this->assertTrue($result['summary']['source']['from_cache']);
        $this->assertFalse($result['summary']['source']['stale_fallback']);
    }

    private function entries(): array
    {
        return [['number' => 101, 'name' => 'BBC One', 'category' => 'Entertainment', 'aliases' => []]];
    }
}
