<?php

namespace AppLocalPlugins\GuideLink\Services;

use AppLocalPlugins\GuideLink\Support\CountryRulesRepository;

class GuideLinkServices
{
    public function autoMergeSetup(): AutoMergeSetupService
    {
        return new AutoMergeSetupService;
    }

    public function availabilityAnalyzer(ChannelRepository $channels, ChannelMatcher $matcher): AvailabilityAnalyzer
    {
        return new AvailabilityAnalyzer($channels, $matcher);
    }

    public function channelMatcher(CountryRulesRepository $countryRules): ChannelMatcher
    {
        return new ChannelMatcher($countryRules);
    }

    public function channelRepository(CountryRulesRepository $countryRules): ChannelRepository
    {
        return new ChannelRepository($countryRules);
    }

    public function channelWriter(): ChannelWriter
    {
        return new ChannelWriter;
    }

    public function channelQuarantine(): ChannelQuarantineService
    {
        return new ChannelQuarantineService;
    }

    public function countryRules(): CountryRulesRepository
    {
        return new CountryRulesRepository;
    }

    public function groupSync(CountryRulesRepository $countryRules): GroupSyncService
    {
        return new GroupSyncService($countryRules);
    }

    public function guideMaps(): GuideMapService
    {
        return new GuideMapService;
    }

    public function parsedSourceLoader(?SourceCacheService $cache = null): ParsedSourceLoader
    {
        return new ParsedSourceLoader($cache ?? $this->sourceCache());
    }

    public function playlistBatchRunner(): PlaylistBatchRunner
    {
        return new PlaylistBatchRunner;
    }

    public function playlistResolver(): PlaylistResolver
    {
        return new PlaylistResolver;
    }

    public function playlistSetup(): PlaylistSetupService
    {
        return new PlaylistSetupService;
    }

    public function profileRepository(): ProfileRepository
    {
        return new ProfileRepository;
    }

    public function runResults(): RunResultRepository
    {
        return new RunResultRepository;
    }

    public function scopeFilter(CountryRulesRepository $countryRules): ScopeFilter
    {
        return new ScopeFilter($countryRules);
    }

    public function setupChecklist(): SetupChecklistService
    {
        return new SetupChecklistService($this->sourceCache());
    }

    public function scrubberSetup(): ScrubberSetupService
    {
        return new ScrubberSetupService;
    }

    public function sourceCache(): SourceCacheService
    {
        return new SourceCacheService;
    }

    public function sourceRefresh(
        ?CountryRulesRepository $countryRules = null,
        ?SourceCacheService $cache = null,
    ): SourceRefreshService {
        return new SourceRefreshService(
            $countryRules ?? $this->countryRules(),
            $cache ?? $this->sourceCache(),
        );
    }

    public function writePlans(CountryRulesRepository $countryRules): WritePlanBuilder
    {
        return new WritePlanBuilder($countryRules);
    }
}
