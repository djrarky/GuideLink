<?php

namespace AppLocalPlugins\GuideLink\Services;

use AppLocalPlugins\GuideLink\Support\CountryRulesRepository;

/**
 * Factory owned by one action instance (including its playlist batch).
 * Plugin dispatch creates a fresh action; this must not be a process singleton.
 * Rules are parsed once per country. Guides, sources and matching reuse only
 * their last derived result, comparing complete inputs after current data reads.
 * Database writers and persistent source-cache accessors remain independent.
 */
class GuideLinkServices
{
    private ?CountryRulesRepository $rules = null;

    private ?GuideMapService $guides = null;

    private ?ParsedSourceLoader $sources = null;

    private ?ChannelMatcher $matcher = null;

    private ?CountryRulesRepository $matcherRules = null;

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
        if ($this->matcherRules !== $countryRules) {
            $this->matcherRules = $countryRules;
            $this->matcher = new ChannelMatcher($countryRules);
        }

        return $this->matcher;
    }

    public function channelRepository(): ChannelRepository
    {
        return new ChannelRepository;
    }

    public function channelWriter(): ChannelWriter
    {
        return new ChannelWriter;
    }

    public function channelQuarantine(): ChannelQuarantineService
    {
        return new ChannelQuarantineService;
    }

    public function matchedChannelRecovery(): MatchedChannelRecoveryService
    {
        return new MatchedChannelRecoveryService;
    }

    public function countryRules(): CountryRulesRepository
    {
        return $this->rules ??= new CountryRulesRepository;
    }

    public function groupSync(CountryRulesRepository $countryRules): GroupSyncService
    {
        return new GroupSyncService($countryRules);
    }

    public function guideMaps(): GuideMapService
    {
        return $this->guides ??= new GuideMapService;
    }

    public function parsedSourceLoader(?SourceCacheService $cache = null): ParsedSourceLoader
    {
        if ($cache !== null) {
            return new ParsedSourceLoader($cache, $this->countryRules());
        }

        return $this->sources ??= new ParsedSourceLoader($this->sourceCache(), $this->countryRules());
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

    public function epgMappingSetup(): EpgMappingSetupService
    {
        return new EpgMappingSetupService;
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

    public function writePlans(): WritePlanBuilder
    {
        return new WritePlanBuilder;
    }
}
