<?php

namespace AppLocalPlugins\GuideLink\Scrapers\Backends;

use AppLocalPlugins\GuideLink\Scrapers\Contracts\ScraperClient;
use Illuminate\Support\Facades\Http;

class OloStepScraper implements ScraperClient
{
    public function fetch(string $url, string $format, string $apiKey, int $timeoutSeconds): string
    {
        $response = Http::timeout($timeoutSeconds)
            ->withToken($apiKey)
            ->acceptJson()
            ->post('https://api.olostep.com/v1/scrapes', [
                'url_to_scrape' => $url,
                'formats' => [$format],
            ])
            ->throw()
            ->json();

        if (! is_array($response)) {
            throw new \RuntimeException('OloStep returned an unexpected response.');
        }

        $result = $response['result'] ?? [];
        if (! is_array($result)) {
            throw new \RuntimeException('OloStep result payload was not an object.');
        }

        return (string) ($result["{$format}_content"] ?? '');
    }
}
