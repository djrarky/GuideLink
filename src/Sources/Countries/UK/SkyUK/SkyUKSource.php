<?php

namespace AppLocalPlugins\GuideLink\Sources\Countries\UK\SkyUK;

use AppLocalPlugins\GuideLink\Sources\Contracts\ChannelSource;
use AppLocalPlugins\GuideLink\Sources\Contracts\ProviderSource;
use AppLocalPlugins\GuideLink\Sources\Countries\UK\SkyUK\Support\RegionParser;
use AppLocalPlugins\GuideLink\Sources\SourceRegistry;
use AppLocalPlugins\GuideLink\Support\ProfileDefaults;

class SkyUKSource implements ProviderSource
{
    private const PROVIDER = 'sky';

    /** @var array<string, ChannelSource> */
    private array $channelSources = [];

    public function __construct(private readonly SourceRegistry $sources) {}

    /**
     * @return array{url: string, format: string, region_url: string, region_format: string}
     */
    public function definition(string $sourceName): array
    {
        return $this->source($sourceName)->definition();
    }

    /**
     * @return array<string, mixed>
     */
    public function resolveRegion(string $sourceName, string $content, int|string|null $regionValue, ?callable $fetch = null): array
    {
        return (new RegionParser($sourceName))->resolve($content, $regionValue, $fetch);
    }

    /**
     * @param  array<string, mixed>  $region
     * @param  array<string, mixed>  $rules
     * @return array<int, array{number: int, name: string, category: string, aliases: array<int, string>, stream_id: string, guide_valid: bool}>
     */
    public function parseChannels(string $content, array $region, array $rules, ?string $sourceName = null): array
    {
        return $this->source($sourceName ?? ProfileDefaults::string('provider_source'))->parseChannels($content, $region, $rules);
    }

    private function source(string $sourceName): ChannelSource
    {
        $sourceName = strtolower($sourceName);

        return $this->channelSources[$sourceName]
            ??= $this->sources->source(self::PROVIDER, $sourceName);
    }
}
