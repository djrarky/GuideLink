<?php

namespace AppLocalPlugins\GuideLink\Sources\Contracts;

interface ProviderSource
{
    /**
     * @return array{url: string, format: string, region_url?: string, region_format?: string}
     */
    public function definition(string $sourceName): array;

    /**
     * Sources without a separate region feed receive empty content and may return [].
     *
     * @return array<string, mixed>
     */
    public function resolveRegion(string $sourceName, string $content, int|string|null $regionValue, ?callable $fetch = null): array;

    /**
     * @param  array<string, mixed>  $region
     * @param  array<string, mixed>  $rules
     * @return array<int, array{number: int, name: string, category: string, aliases: array<int, string>, stream_id: string, guide_valid: bool}>
     */
    public function parseChannels(string $content, array $region, array $rules, ?string $sourceName = null): array;
}
