<?php

namespace AppLocalPlugins\GuideLink\Tests\Feature\Sources;

require_once dirname(__DIR__).'/PluginFeatureTestCase.php';

use AppLocalPlugins\GuideLink\Services\SourceCacheService;
use AppLocalPlugins\GuideLink\Services\SourceRefreshService;
use AppLocalPlugins\GuideLink\Sources\Countries\UK\SkyUK\Support\RegionParser;
use AppLocalPlugins\GuideLink\Support\CountryRulesRepository;
use AppLocalPlugins\GuideLink\Tests\Feature\PluginFeatureTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class RegionRefreshTest extends PluginFeatureTestCase
{
    use RefreshDatabase;

    public function test_current_region_tables_resolve_inline_and_linked_regions_by_number_and_name(): void
    {
        $parser = new RegionParser('viewersvault');
        $index = $this->fixture('viewersvault-index');
        $calls = [];
        $fetch = function (string $url) use (&$calls): string {
            $calls[] = $url;

            return $this->fixture('viewersvault-granada');
        };
        $this->assertSame('London', $parser->resolve($index, 1, $fetch)['name']);
        $this->assertSame('London', $parser->resolve($index, 'London', $fetch)['bbc_one']);
        $this->assertSame([], $calls);
        foreach ([7, 'Granada'] as $selection) {
            $region = $parser->resolve($index, $selection, $fetch);
            $this->assertSame(7, $region['num']);
            $this->assertSame('England', $region['country']);
            $this->assertSame('North West', $region['bbc_one']);
            $this->assertSame('Granada', $region['itv']);
        }
        $this->assertSame(array_fill(0, 2, 'https://viewersvault.com/Template:Region7'), $calls);
    }

    public function test_tv_channel_lists_summary_handles_wrapped_country_headings(): void
    {
        $html = <<<'HTML'
        <div class="mw-heading"><h2>Wales</h2></div>
        <table class="wikitable"><tr><th>Region #</th><th>Sky region</th><th>BBC One</th><th>ITV1 HD</th></tr>
        <tr><td>32</td><td>Wales</td><td>Wales</td><td>Wales</td></tr></table>
        HTML;
        $region = (new RegionParser('tvchannellists'))->resolve($html, 32);
        $this->assertSame('Wales', $region['country']);
        $this->assertSame([], (new RegionParser('viewersvault'))->parse($html));
    }

    public function test_region_key_and_channel_matrix_preserve_regional_feeds_and_country(): void
    {
        $parser = new RegionParser('viewersvault');
        $html = $this->fixture('viewersvault-matrix');
        foreach ([7, 'Granada', 'North West'] as $selection) {
            $region = $parser->resolve($html, $selection);
            $this->assertSame(7, $region['num']);
            $this->assertSame('North West', $region['bbc_one']);
            $this->assertSame('Granada', $region['itv']);
            $this->assertSame('England', $region['country']);
            $this->assertSame('Network', $region['itv_plus1']);
            $this->assertSame('Network', $region['ch4_plus1']);
        }
        foreach ([32 => 'Wales', 33 => 'Northern Ireland', 35 => 'Scotland', 36 => 'Scotland', 50 => 'Ireland'] as $number => $country) {
            $this->assertSame($country, $parser->resolve($html, $number)['country']);
        }
        $this->assertSame('STV', $parser->resolve($html, 35)['itv']);
        $this->assertSame('Border Scotland', $parser->resolve($html, 36)['itv']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Sky region [70] was not found');
        $parser->resolve($html, 70);
    }

    public function test_matrix_region_key_without_country_evidence_cannot_refresh_a_lineup(): void
    {
        $html = '<table class="wikitable"><tr><th>Region number</th><th>Region name</th></tr><tr><td>7</td><td>Granada</td></tr></table>';
        $this->assertSame([], (new RegionParser('viewersvault'))->parse($html));
    }

    public function test_ambiguous_matrix_country_cannot_choose_an_arbitrary_region(): void
    {
        $html = str_replace('103(Region 32)', '103(Regions 7, 32)', $this->fixture('viewersvault-matrix'));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Sky region [7] was not found');
        (new RegionParser('viewersvault'))->resolve($html, 7);
    }

    public function test_matrix_refresh_uses_current_region_page_without_fetching_old_templates(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        [$context] = $this->context();
        $pages = [
            'https://viewersvault.com/Region_mapping_on_Sky_(United_Kingdom_and_Ireland)' => $this->fixture('viewersvault-matrix'),
            'https://viewersvault.com/List_of_channels_on_Sky_(United_Kingdom_and_Ireland)' => file_get_contents(dirname(__DIR__, 2).'/Fixtures/Sources/Countries/UK/SkyUK/Sources/ViewersVault/basic.html'),
        ];
        Http::fake($pages);
        $config = $this->cacheConfig(['provider_source' => 'viewersvault', 'region' => '7']);
        $cache = new SourceCacheService;
        $result = (new SourceRefreshService(new CountryRulesRepository, $cache))->refresh($config, $context);
        $this->assertSame('Granada', $result['summary']['source']['region_name']);
        $this->assertFalse($result['summary']['source']['from_cache']);
        $this->assertSame(2, $result['summary']['source']['channel_count']);
        Http::assertSentCount(2);
    }

    public function test_region_links_cannot_redirect_the_scraper_to_an_arbitrary_host(): void
    {
        $html = str_replace('/Template:Region7', 'https://example.invalid/Template:Region7', $this->fixture('viewersvault-index'));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Sky region [7] was not found');
        (new RegionParser('viewersvault'))->resolve($html, 7, function (): never {
            $this->fail('An untrusted link must not be fetched.');
        });
    }

    public function test_linked_region_must_contain_the_requested_mapping(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Sky region [7] was not found');
        (new RegionParser('viewersvault'))->resolve($this->fixture('viewersvault-index'), 7,
            fn (): string => $this->fixture('viewersvault-index'));
    }

    public function test_refresh_fetches_linked_region_through_the_configured_scraper_and_saves_only_parsed_data(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        [$context] = $this->context();
        $pages = [
            'https://viewersvault.com/Region_mapping_on_Sky_(United_Kingdom_and_Ireland)' => $this->fixture('viewersvault-index'),
            'https://viewersvault.com/Template:Region7' => $this->fixture('viewersvault-granada'),
            'https://viewersvault.com/List_of_channels_on_Sky_(United_Kingdom_and_Ireland)' => file_get_contents(dirname(__DIR__, 2).'/Fixtures/Sources/Countries/UK/SkyUK/Sources/ViewersVault/basic.html'),
        ];
        $fetched = [];
        Http::fake(['api.scrape.do/*' => function ($request) use ($pages, &$fetched) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
            $this->assertSame('fixture-key', $query['token']);
            $this->assertArrayHasKey($query['url'], $pages);
            $fetched[] = $query['url'];

            return Http::response($pages[$query['url']]);
        }]);
        $config = $this->cacheConfig(['provider_source' => 'viewersvault', 'region' => '7', 'scraping_api' => 'scrapedo', 'scraping_api_key' => 'fixture-key']);
        $cache = new SourceCacheService;
        $result = (new SourceRefreshService(new CountryRulesRepository, $cache))->refresh($config, $context);
        $this->assertSame(array_keys($pages), $fetched);
        $this->assertSame('Granada', $result['summary']['source']['region_name']);
        $this->assertSame(2, $result['summary']['source']['channel_count']);
        $this->assertFalse($result['summary']['source']['stale_fallback']);
        $this->assertSame([$cache->parsedPath($config)], Storage::disk('local')->allFiles());
        $this->assertStringNotContainsString('fixture-key', Storage::disk('local')->get($cache->parsedPath($config)));
    }

    private function fixture(string $name): string
    {
        return file_get_contents(dirname(__DIR__, 2).'/Fixtures/Regions/'.$name.'.html');
    }
}
