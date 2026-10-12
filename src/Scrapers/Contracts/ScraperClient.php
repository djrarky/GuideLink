<?php

namespace AppLocalPlugins\GuideLink\Scrapers\Contracts;

interface ScraperClient
{
    public function fetch(string $url, string $format, string $apiKey, int $timeoutSeconds): string;
}
