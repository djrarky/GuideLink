<?php

namespace AppLocalPlugins\GuideLink\Sources\Contracts;

interface ChannelSource
{
    /**
     * @return array{url: string, format: string, region_url?: string, region_format?: string}
     */
    public function definition(): array;

    /**
     * @param  array<string, mixed>  $region
     * @param  array<string, mixed>  $rules
     * @return array<int, array{number: int, name: string, category: string, aliases: array<int, string>, stream_id: string, guide_valid: bool}>
     */
    public function parseChannels(string $content, array $region, array $rules): array;
}
