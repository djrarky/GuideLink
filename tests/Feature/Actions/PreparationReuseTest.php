<?php

namespace AppLocalPlugins\GuideLink\Tests\Feature\Actions;

require_once dirname(__DIR__).'/PluginFeatureTestCase.php';

use App\Models\Channel;
use AppLocalPlugins\GuideLink\Actions\SyncPlaylistAction;
use AppLocalPlugins\GuideLink\Services\ChannelMatcher;
use AppLocalPlugins\GuideLink\Services\GuideLinkServices;
use AppLocalPlugins\GuideLink\Services\SourceCacheService;
use AppLocalPlugins\GuideLink\Services\SourceRefreshService;
use AppLocalPlugins\GuideLink\Support\CountryRulesRepository;
use AppLocalPlugins\GuideLink\Tests\Feature\PluginFeatureTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;

class PreparationReuseTest extends PluginFeatureTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Http::preventStrayRequests();
        Storage::fake('local');
    }

    public function test_sync_reads_its_source_once_and_a_later_sync_reads_the_replacement(): void
    {
        [$context, $playlist] = $this->context(applyPluginSchema: true);
        $config = $this->cacheConfig(['provider_source' => 'viewersvault']);
        $cache = $this->countedCache();
        $cache->writeLastGood($config, $this->source(), []);
        $this->assignProfile($context, $playlist, [
            ...$config, 'source_mode' => 'scraper_cache', 'guide_epg_id' => $this->guideEpgId($context),
        ]);
        $services = Mockery::mock(GuideLinkServices::class)->makePartial();
        $services->shouldReceive('sourceCache')->andReturn($cache);
        $action = new SyncPlaylistAction($services);

        $result = $action->handle(['playlist_id' => $playlist->id], $context);
        $this->assertTrue($result->success, $result->summary);
        $this->assertSame(1, $cache->reads);
        $this->assertSame(1, $result->data['source_cache']['channel_count']);
        $this->assertArrayNotHasKey('snapshot', $result->data['source_cache']);

        $cache->writeLastGood($config, [...$this->source(), [
            'number' => 102, 'name' => 'BBC Two', 'category' => 'Entertainment', 'aliases' => [],
        ]], []);
        $again = $action->handle(['playlist_id' => $playlist->id], $context);
        $this->assertTrue($again->success, $again->summary);
        $this->assertSame(2, $cache->reads);
        $this->assertSame(2, $again->data['source_cache']['channel_count']);
        Http::assertNothingSent();
    }

    public function test_fresh_source_refresh_reads_and_validates_one_snapshot(): void
    {
        [$context] = $this->context();
        $cache = $this->countedCache();
        $config = $this->cacheConfig(['provider_source' => 'viewersvault', 'region' => '1']);
        $cache->writeLastGood($config, $this->source(), ['fetched_at' => now()->toIso8601String()]);
        $result = (new SourceRefreshService(new CountryRulesRepository, $cache))->refresh($config, $context);
        $this->assertSame(1, $cache->reads);
        $this->assertSame($this->source(), $result['entries']);
        $this->assertTrue($result['summary']['source']['from_cache']);
        Http::assertNothingSent();
    }

    public function test_guide_alias_preparation_is_reused_but_observes_current_source_and_guide_data(): void
    {
        [$context] = $this->context();
        $countries = $this->countedRules();
        $cache = new SourceCacheService;
        $payload = $this->cacheConfig();
        $cache->writeLastGood($payload, $this->source(), []);
        $services = Mockery::mock(GuideLinkServices::class)->makePartial();
        $services->shouldReceive('countryRules')->andReturn($countries);
        $services->shouldReceive('sourceCache')->andReturn($cache);
        $loader = $services->parsedSourceLoader();
        $map = [101 => 'bbc.one'];
        $aliases = [101 => ['BBC One', 'The Beeb']];
        $first = $loader->entries($payload, $context, $map, $aliases);
        $calls = $countries->normalizations;
        $this->assertGreaterThan(0, $calls);
        $this->assertSame($first, $services->parsedSourceLoader()->entries($payload, $context, $map, $aliases));
        $this->assertSame($calls, $countries->normalizations);

        $changed = $loader->entries($payload, $context, $map, [101 => ['BBC One', 'New guide alias']]);
        $this->assertContains('New guide alias', $changed[0]['aliases']);
        $this->assertNotContains('The Beeb', $changed[0]['aliases']);
        $this->assertGreaterThan($calls, $countries->normalizations);

        $cache->writeLastGood($payload, [[...$this->source()[0], 'aliases' => ['New source alias']]], []);
        $changed = $loader->entries($payload, $context, $map, $aliases);
        $this->assertContains('New source alias', $changed[0]['aliases']);
        $this->assertSame([false], array_column($loader->entries($payload, $context, []), 'guide_valid'));
    }

    public function test_matching_indexes_reuse_work_without_sharing_changed_inputs_or_mutations(): void
    {
        $countries = $this->countedRules();
        $rules = $countries->resolve([], []);
        $services = Mockery::mock(GuideLinkServices::class)->makePartial();
        $services->shouldReceive('countryRules')->andReturn($countries);
        $matcher = $services->channelMatcher($services->countryRules());
        $thresholds = $matcher->thresholds([], []);
        $entries = [
            [...$this->source()[0], 'stream_id' => 'bbc.one', 'guide_valid' => true],
            ['number' => 102, 'name' => 'BBC One London', 'category' => 'Regional', 'aliases' => []],
            ['number' => 103, 'name' => 'Guide Extra', 'category' => '', 'aliases' => [], 'guide_only' => true],
        ];
        $channel = (new Channel)->forceFill(['name' => 'BBC One HD', 'title' => 'BBC One HD']);
        $first = $matcher->sourceIndex($entries, $rules, $thresholds);
        $calls = $countries->normalizations;
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame($first, $services->channelMatcher($services->countryRules())->sourceIndex($entries, $rules, $thresholds));
        }
        $this->assertSame($calls, $countries->normalizations);
        $expected = (new ChannelMatcher(new CountryRulesRepository))->match($channel, $first, $thresholds);
        $this->assertSame($expected, $matcher->match($channel, $matcher->sourceIndex($entries, $rules, $thresholds), $thresholds));
        $mutated = $first;
        $mutated['by_name'] = [];
        $this->assertSame($first, $matcher->sourceIndex($entries, $rules, $thresholds));

        foreach ([
            [[...$entries, ['number' => 104, 'name' => 'New Source', 'category' => '', 'aliases' => []]], $rules, $thresholds],
            [$entries, [...$rules, 'noise_pattern' => '~BBC~'], $thresholds],
            [$entries, $rules, [...$thresholds, 'min_alias_words' => 100, 'min_alias_chars' => 100]],
        ] as [$source, $country, $settings]) {
            $expected = (new ChannelMatcher(new CountryRulesRepository))->sourceIndex($source, $country, $settings);
            $this->assertSame($expected, $matcher->sourceIndex($source, $country, $settings));
        }
        $this->assertSame($first, $matcher->sourceIndex($entries, $rules, $thresholds));
    }

    public function test_matching_normalizes_each_distinct_name_once_across_guide_fallbacks(): void
    {
        $countries = $this->countedRules();
        $rules = $countries->resolve([], []);
        $matcher = new ChannelMatcher($countries);
        $thresholds = $matcher->thresholds([], []);
        $index = $matcher->sourceIndex([
            ['number' => 101, 'name' => 'Discovery Channel', 'category' => '', 'aliases' => []],
            ['number' => 102, 'name' => 'BBC One', 'category' => '', 'aliases' => [],
                'guide_only' => true, 'profile_aliases' => ['THE BEEB' => [7 => 'Provider names']]],
        ], $rules, $thresholds);
        foreach (['BBC One', 'THE BEEB', 'THE BEEB / Alternate label'] as $name) {
            $countries->normalizationInputs = [];
            $channel = (new Channel)->forceFill(['title' => $name, 'name' => $name]);
            $result = $matcher->match($channel, $index, $thresholds);
            $this->assertSame('auto_apply', $result['decision'], $name);
            $this->assertSame(102, $result['source']['number']);
            $this->assertStringStartsWith('guide:', $result['matched_on']);
            $this->assertSame(1, array_count_values($countries->normalizationInputs)[$name]);
        }

        $countries->normalizationInputs = [];
        $result = $matcher->match((new Channel)->forceFill(['title' => 'Discovery Channel', 'name' => 'BBC One']), $index, $thresholds);
        $this->assertSame(101, $result['source']['number']);
        $this->assertSame(['Discovery Channel', 'BBC One'], $countries->normalizationInputs);
    }

    private function countedCache(): SourceCacheService
    {
        return new class extends SourceCacheService
        {
            public int $reads = 0;

            public function readLastGood(array $config): array
            {
                $this->reads++;

                return parent::readLastGood($config);
            }
        };
    }

    private function countedRules(): CountryRulesRepository
    {
        return new class extends CountryRulesRepository
        {
            public int $normalizations = 0;

            public array $normalizationInputs = [];

            public function normalize(string $value, ?array $rules = null): string
            {
                $this->normalizations++;
                $this->normalizationInputs[] = $value;

                return parent::normalize($value, $rules);
            }
        };
    }

    private function source(): array
    {
        return [['number' => 101, 'name' => 'BBC One', 'category' => 'Entertainment', 'aliases' => []]];
    }
}
