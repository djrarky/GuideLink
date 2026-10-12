<?php

namespace AppLocalPlugins\GuideLink\Actions;

use App\Plugins\Support\PluginActionResult;
use App\Plugins\Support\PluginExecutionContext;
use AppLocalPlugins\GuideLink\Support\Options;
use AppLocalPlugins\GuideLink\Support\ProfileDefaults;

class AvailabilityReportAction extends GuideLinkAction
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload, PluginExecutionContext $context): PluginActionResult
    {
        return $this->services->playlistBatchRunner()->runResolved(
            $payload,
            $context,
            'availability',
            'Availability report',
            fn (array $runPayload): PluginActionResult => $this->handleSingle($runPayload, $context),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleSingle(array $payload, PluginExecutionContext $context): PluginActionResult
    {
        $countryRules = $this->services->countryRules();
        $playlistResolver = $this->services->playlistResolver();
        $channelRepository = $this->services->channelRepository($countryRules);
        $parsedSourceLoader = $this->services->parsedSourceLoader();
        $guideMaps = $this->services->guideMaps();
        $matcher = $this->services->channelMatcher($countryRules);
        $analyzer = $this->services->availabilityAnalyzer($channelRepository, $matcher);
        $runResults = $this->services->runResults();

        $context->checkpoint(5, 'Preparing availability report.', log: true);

        $playlist = $playlistResolver->target($payload, $context);
        $rules = $countryRules->resolve($payload, []);
        $includeScrubberLogs = Options::bool($payload, [], 'include_scrubber_logs', ProfileDefaults::bool('include_scrubber_logs'));

        $context->checkpoint(20, 'Loading live channels and scrubber history.', [
            'playlist_id' => $playlist->id,
        ], log: true);

        $channels = $channelRepository->liveChannels($playlist, true);
        $scrubberChannelIds = $channelRepository->scrubberChannelIds($playlist, $includeScrubberLogs);

        $context->checkpoint(45, 'Classifying channel availability.', [
            'channels' => $channels->count(),
            'scrubber_channels' => count($scrubberChannelIds),
        ]);

        $channelRows = $analyzer->channelRows($playlist, $channels, $scrubberChannelIds);
        $summary = [
            ...$analyzer->summary($playlist, $channelRows),
            'source_coverage' => null,
        ];

        $sourceCoverageRows = null;
        $guide = $guideMaps->resolve($payload, $context);
        $sourceEntries = $parsedSourceLoader->entries($payload, $context, $guide['map'], $guide['aliases']);

        if ($sourceEntries !== []) {
            $context->checkpoint(60, 'Checking canonical scraper source coverage.');

            $sourceCoverageRows = $analyzer->sourceCoverageRows(
                $playlist,
                $sourceEntries,
                $channels,
                $scrubberChannelIds,
                $matcher->thresholds($payload, []),
                $rules,
            );
            $summary['source_coverage'] = $analyzer->sourceCoverageSummary($sourceCoverageRows);
        }

        if (($summary['availability']['disabled_no_fallback'] ?? 0) > 0) {
            $context->warning('Disabled live channels without enabled failover coverage were found.', [
                'channels' => $summary['availability']['disabled_no_fallback'],
            ]);
        }

        $context->checkpoint(75, 'Saving availability run results.');

        $resultDetails = $runResults->replaceAvailability(
            $context,
            (int) $playlist->id,
            $channelRows,
            $sourceCoverageRows,
        );

        $context->checkpoint(100, 'Availability report completed.', [
            'availability' => $summary['availability'],
            'source_coverage' => $summary['source_coverage'],
            'run_results' => $resultDetails,
        ], log: true);

        return PluginActionResult::success(
            "Availability report completed for {$playlist->name}: {$summary['availability']['enabled']} enabled, {$summary['availability']['disabled']} disabled.",
            [
                ...$summary,
                'totals' => $summary['availability'],
                'run_results' => $resultDetails,
                'progress' => 100,
            ],
        );
    }
}
