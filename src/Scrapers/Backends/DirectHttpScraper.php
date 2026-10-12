<?php

namespace AppLocalPlugins\GuideLink\Scrapers\Backends;

use AppLocalPlugins\GuideLink\Scrapers\Contracts\ScraperClient;
use Illuminate\Support\Facades\Http;

class DirectHttpScraper implements ScraperClient
{
    public function fetch(string $url, string $format, string $apiKey, int $timeoutSeconds): string
    {
        return Http::timeout($timeoutSeconds)
            ->get($url)
            ->throw()
            ->body();
    }
}
