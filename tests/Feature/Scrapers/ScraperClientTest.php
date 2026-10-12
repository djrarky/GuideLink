<?php

namespace AppLocalPlugins\GuideLink\Tests\Feature\Scrapers;

require_once dirname(__DIR__).'/PluginFeatureTestCase.php';

use AppLocalPlugins\GuideLink\Scrapers\Backends\DirectHttpScraper;
use AppLocalPlugins\GuideLink\Scrapers\Backends\OloStepScraper;
use AppLocalPlugins\GuideLink\Scrapers\Backends\ScrapeDoScraper;
use AppLocalPlugins\GuideLink\Tests\Feature\PluginFeatureTestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ScraperClientTest extends PluginFeatureTestCase
{
    public function test_direct_http_scraper_returns_response_body(): void
    {
        Http::fake([
            'https://example.test/source' => Http::response('source body', 200),
        ]);

        $body = (new DirectHttpScraper)->fetch('https://example.test/source', 'html', '', 5);

        $this->assertSame('source body', $body);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://example.test/source');
    }

    public function test_direct_http_scraper_throws_for_http_failures(): void
    {
        Http::fake([
            'https://example.test/source' => Http::response('upstream failed', 500),
        ]);

        $this->expectException(RequestException::class);

        (new DirectHttpScraper)->fetch('https://example.test/source', 'html', '', 5);
    }

    public function test_olostep_scraper_extracts_requested_format_content(): void
    {
        Http::fake([
            'https://api.olostep.com/v1/scrapes' => Http::response([
                'result' => [
                    'html_content' => '<main>source</main>',
                ],
            ], 200),
        ]);

        $body = (new OloStepScraper)->fetch('https://example.test/source', 'html', 'secret-key', 5);

        $this->assertSame('<main>source</main>', $body);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.olostep.com/v1/scrapes'
            && $request['url_to_scrape'] === 'https://example.test/source'
            && $request['formats'] === ['html']);
    }

    public function test_olostep_scraper_rejects_unexpected_result_payload(): void
    {
        Http::fake([
            'https://api.olostep.com/v1/scrapes' => Http::response([
                'result' => 'unexpected',
            ], 200),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OloStep result payload was not an object.');

        (new OloStepScraper)->fetch('https://example.test/source', 'html', 'secret-key', 5);
    }

    public function test_olostep_scraper_throws_for_http_failures(): void
    {
        Http::fake([
            'https://api.olostep.com/v1/scrapes' => Http::response(['error' => 'upstream failed'], 500),
        ]);

        $this->expectException(RequestException::class);

        (new OloStepScraper)->fetch('https://example.test/source', 'html', 'secret-key', 5);
    }

    public function test_scrapedo_scraper_returns_response_body(): void
    {
        Http::fake([
            'https://api.scrape.do/*' => Http::response('rendered body', 200),
        ]);

        $body = (new ScrapeDoScraper)->fetch('https://example.test/source', 'html', 'secret-key', 5);

        $this->assertSame('rendered body', $body);
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.scrape.do/')
            && $request['token'] === 'secret-key'
            && $request['url'] === 'https://example.test/source'
            && $request['render'] === 'true');
    }

    public function test_scrapedo_scraper_throws_for_http_failures(): void
    {
        Http::fake([
            'https://api.scrape.do/*' => Http::response('upstream failed', 500),
        ]);

        $this->expectException(RequestException::class);

        (new ScrapeDoScraper)->fetch('https://example.test/source', 'html', 'secret-key', 5);
    }
}
