<?php

namespace AppLocalPlugins\GuideLink\Actions;

use App\Models\Channel;
use App\Plugins\Support\PluginActionResult;
use App\Plugins\Support\PluginExecutionContext;
use AppLocalPlugins\GuideLink\Support\Options;
use AppLocalPlugins\GuideLink\Support\ProfileDefaults;
use AppLocalPlugins\GuideLink\Support\RunProgress;

class RepairAvailabilityAction extends GuideLinkAction
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
            $context->dryRun ? 'Repair availability report' : 'Repair availability apply',
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
        $matcher = $this->services->channelMatcher($countryRules);
        $analyzer = $this->services->availabilityAnalyzer($channelRepository, $matcher);
        $runResults = $this->services->runResults();
        $mode = (string) ($payload['repair_mode'] ?? ProfileDefaults::string('repair_mode'));
        $includeScrubberLogs = Options::bool($payload, [], 'include_scrubber_logs', ProfileDefaults::bool('include_scrubber_logs'));

        $context->checkpoint(5, 'Preparing availability repair report.', log: true);

        $playlist = $playlistResolver->target($payload, $context);
        $channels = $channelRepository->liveChannels($playlist, true);
        $scrubberChannelIds = $channelRepository->scrubberChannelIds($playlist, $includeScrubberLogs);

        $context->checkpoint(45, 'Classifying repair candidates.', [
            'playlist_id' => $playlist->id,
            'channels' => $channels->count(),
            'repair_mode' => $mode,
        ]);

        $channelRows = $analyzer->channelRows($playlist, $channels, $scrubberChannelIds);
        $repairRows = $this->repairRows($channelRows, $mode);
        $applied = 0;

        if (! $context->dryRun && $mode === 'reenable_master') {
            $context->checkpoint(70, 'Re-enabling disabled primaries with enabled failover coverage.', [
                'candidates' => count($repairRows),
            ], log: true);

            $applied = $this->reenableMasters($repairRows, $context);
            $repairRows = array_map(
                fn (array $row): array => [
                    ...$row,
                    'repair_applied' => $row['status'] === 'primary_disabled_with_enabled_fallback' ? '1' : $row['repair_applied'],
                ],
                $repairRows,
            );
        } elseif (! $context->dryRun && $mode !== 'report_only') {
            $context->warning('Repair mode is not write-enabled yet; generated report only.', [
                'repair_mode' => $mode,
            ]);
        }

        $summary = [
            ...$analyzer->summary($playlist, $channelRows),
            'repair' => [
                'mode' => $mode,
                'dry_run' => $context->dryRun,
                'candidates' => count($repairRows),
                'reenable_master_candidates' => count(array_filter($repairRows, fn (array $row): bool => $row['status'] === 'primary_disabled_with_enabled_fallback')),
                'scrubber_disabled_no_fallback' => count(array_filter($repairRows, fn (array $row): bool => $row['status'] === 'scrubber_disabled_no_fallback')),
                'disabled_no_fallback' => count(array_filter($repairRows, fn (array $row): bool => $row['status'] === 'disabled_no_fallback')),
                'applied' => $applied,
            ],
        ];

        $context->checkpoint(90, 'Saving repair run results.');

        $resultDetails = $runResults->replaceRows(
            $context,
            (int) $playlist->id,
            $repairRows,
            'repair',
        );

        $context->checkpoint(100, 'Availability repair report completed.', [
            'repair' => $summary['repair'],
            'run_results' => $resultDetails,
        ], log: true);

        return PluginActionResult::success(
            $context->dryRun
                ? "Repair report completed for {$playlist->name}."
                : "Repair action completed for {$playlist->name}: {$applied} channel(s) updated.",
            [
                ...$summary,
                'totals' => $summary['repair'],
                'run_results' => $resultDetails,
                'progress' => 100,
            ],
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $channelRows
     * @return array<int, array<string, mixed>>
     */
    private function repairRows(array $channelRows, string $mode): array
    {
        $repairableStatuses = [
            'primary_disabled_with_enabled_fallback',
            'scrubber_disabled_no_fallback',
            'disabled_no_fallback',
        ];

        return array_values(array_map(
            fn (array $row): array => [
                ...$row,
                'repair_mode' => $mode,
                'repair_action' => $this->repairAction((string) $row['status'], $mode),
                'repair_applied' => '0',
            ],
            array_filter($channelRows, fn (array $row): bool => in_array($row['status'], $repairableStatuses, true)),
        ));
    }

    private function repairAction(string $status, string $mode): string
    {
        return match (true) {
            $mode === 'reenable_master' && $status === 'primary_disabled_with_enabled_fallback' => 'reenable_master',
            $status === 'primary_disabled_with_enabled_fallback' => 'review_disabled_primary',
            $status === 'scrubber_disabled_no_fallback' => 'review_scrubber_disablement',
            default => 'review_disabled_channel',
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $repairRows
     */
    private function reenableMasters(array $repairRows, PluginExecutionContext $context): int
    {
        $updated = 0;
        $total = count($repairRows);

        foreach ($repairRows as $index => $row) {
            if ($context->cancellationRequested()) {
                return $updated;
            }

            if (RunProgress::shouldHeartbeat($index, $total)) {
                $context->heartbeat('Repair apply: checking '.($index + 1).' of '.$total.' candidate row(s).', null, [
                    'repair_apply' => [
                        'position' => $index + 1,
                        'total' => $total,
                        'updated' => $updated,
                    ],
                ]);
            }

            if ($row['status'] !== 'primary_disabled_with_enabled_fallback') {
                continue;
            }

            Channel::query()
                ->whereKey((int) $row['channel_id'])
                ->firstOrFail()
                ->forceFill(['enabled' => true])
                ->save();

            $updated++;
        }

        return $updated;
    }
}
