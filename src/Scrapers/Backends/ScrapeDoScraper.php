<?php

namespace AppLocalPlugins\GuideLink\Scrapers\Backends;

use AppLocalPlugins\GuideLink\Scrapers\Contracts\ScraperClient;
use Illuminate\Support\Facades\Http;

class ScrapeDoScraper implements ScraperClient
{
    public function fetch(string $url, string $format, string $apiKey, int $timeoutSeconds): string
    {
        return Http::timeout($timeoutSeconds)
            ->get('https://api.scrape.do/', [
                'token' => $apiKey,
                'url' => $url,
                'super' => 'true',
                'geoCode' => 'GB',
                'render' => 'true',
                'blockResources' => 'false',
            ])
            ->throw()
            ->body();
    }
}
