<?php

namespace AppLocalPlugins\GuideLink\Actions;

use App\Models\Channel;
use App\Models\Playlist;
use App\Plugins\Support\PluginActionResult;
use App\Plugins\Support\PluginExecutionContext;
use AppLocalPlugins\GuideLink\Services\ChannelQuarantineService;
use AppLocalPlugins\GuideLink\Support\Options;
use AppLocalPlugins\GuideLink\Support\ProfileDefaults;
use AppLocalPlugins\GuideLink\Support\RunProgress;
use Illuminate\Support\Facades\DB;

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
        $quarantined = DB::table(ChannelQuarantineService::TABLE)
            ->where('extension_plugin_id', $context->plugin->id)
            ->where('playlist_id', $playlist->id)
            ->pluck('channel_id')->flip()->all();
        $channelRows = array_map(
            fn (array $row): array => ! $row['enabled'] && isset($quarantined[$row['channel_id']])
                ? [...$row, 'status' => 'quarantined']
                : $row,
            $channelRows,
        );
        $repairRows = $this->repairRows($channelRows, $mode);
        $applied = 0;

        if (! $context->dryRun && $mode === 'reenable_master') {
            $context->checkpoint(70, 'Re-enabling disabled primaries with enabled failover coverage.', [
                'candidates' => count($repairRows),
            ], log: true);

            $repairRows = $this->reenableMasters($repairRows, $playlist, $context);
            $applied = count(array_filter($repairRows, fn (array $row): bool => $row['repair_applied'] === '1'));
        } elseif (! $context->dryRun && $mode !== 'report_only') {
            $context->warning('Repair mode is not write-enabled yet; generated report only.', [
                'repair_mode' => $mode,
            ]);
        }

        $cancelled = $context->cancellationRequested();
        $summary = [
            ...$analyzer->summary($playlist, $channelRows),
            'repair' => [
                'mode' => $mode,
                'dry_run' => $context->dryRun,
                'candidates' => count($repairRows),
                'reenable_master_candidates' => count(array_filter($repairRows, fn (array $row): bool => $row['status'] === 'primary_disabled_with_enabled_fallback')),
                'scrubber_disabled_no_fallback' => count(array_filter($repairRows, fn (array $row): bool => $row['status'] === 'scrubber_disabled_no_fallback')),
                'disabled_no_fallback' => count(array_filter($repairRows, fn (array $row): bool => $row['status'] === 'disabled_no_fallback')),
                'quarantined' => count(array_filter($repairRows, fn (array $row): bool => $row['status'] === 'quarantined')),
                'applied' => $applied,
                'failed' => count(array_filter($repairRows, fn (array $row): bool => $row['repair_error'] !== '')),
                'skipped' => count(array_filter($repairRows, fn (array $row): bool => $row['repair_skip_reason'] !== '' && $row['repair_skip_reason'] !== 'cancelled')),
                'cancelled' => $cancelled,
            ],
        ];

        $context->checkpoint(90, 'Saving repair run results.');

        $resultDetails = $runResults->replaceRows(
            $context,
            (int) $playlist->id,
            $repairRows,
            'repair',
        );

        $context->checkpoint(100, $cancelled ? 'Availability repair cancelled; partial results saved.' : 'Availability repair report completed.', [
            'repair' => $summary['repair'],
            'run_results' => $resultDetails,
        ], log: true);

        $data = [
            ...$summary,
            'totals' => $summary['repair'],
            'run_results' => $resultDetails,
            'progress' => 100,
        ];

        if ($cancelled) {
            return PluginActionResult::cancelled(
                "Repair action cancelled for {$playlist->name}: {$applied} channel(s) updated; partial results saved.",
                $data,
            );
        }

        if ($summary['repair']['failed'] > 0) {
            return PluginActionResult::failure('Availability repair had write failures; successful repairs and failure details were saved.', $data);
        }

        return PluginActionResult::success(
            $context->dryRun
                ? "Repair report completed for {$playlist->name}."
                : "Repair action completed for {$playlist->name}: {$applied} channel(s) updated.",
            $data,
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
            'quarantined',
        ];

        return array_values(array_map(
            fn (array $row): array => [
                ...$row,
                'repair_mode' => $mode,
                'repair_action' => $this->repairAction((string) $row['status'], $mode),
                'repair_applied' => '0',
                'repair_skip_reason' => '',
                'repair_error' => '',
            ],
            array_filter($channelRows, fn (array $row): bool => in_array($row['status'], $repairableStatuses, true)),
        ));
    }

    private function repairAction(string $status, string $mode): string
    {
        return match (true) {
            $status === 'quarantined' => 'keep_disabled',
            $mode === 'reenable_master' && $status === 'primary_disabled_with_enabled_fallback' => 'reenable_master',
            $status === 'primary_disabled_with_enabled_fallback' => 'review_disabled_primary',
            $status === 'scrubber_disabled_no_fallback' => 'review_scrubber_disablement',
            default => 'review_disabled_channel',
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $repairRows
     * @return array<int, array<string, mixed>>
     */
    private function reenableMasters(array $repairRows, Playlist $playlist, PluginExecutionContext $context): array
    {
        $updated = 0;
        $total = count($repairRows);

        foreach ($repairRows as $index => $row) {
            if ($context->cancellationRequested()) {
                break;
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

            try {
                $reason = DB::transaction(function () use ($row, $playlist, $context): string {
                    // Lock in the same order as quarantine writes: channel, then quarantine state.
                    $channel = Channel::query()
                        ->where('playlist_id', $playlist->id)
                        ->where('user_id', $playlist->user_id)
                        ->where('is_vod', false)
                        ->where('uuid', $row['channel_uuid'])
                        ->whereKey((int) $row['channel_id'])
                        ->lockForUpdate()->first();

                    if ($context->cancellationRequested()) {
                        return 'cancelled';
                    }
                    if (! $channel) {
                        return 'channel_unavailable';
                    }
                    if (DB::table(ChannelQuarantineService::TABLE)
                        ->where('extension_plugin_id', $context->plugin->id)
                        ->where('playlist_id', $playlist->id)
                        ->where('channel_id', $channel->id)
                        ->lockForUpdate()->first()) {
                        return 'quarantined';
                    }
                    if ($channel->enabled) {
                        return 'already_enabled';
                    }
                    if (! $channel->failovers()->whereHas('channelFailover', fn ($query) => $query
                        ->where('enabled', true)->where('is_vod', false)->where('user_id', $playlist->user_id))->exists()) {
                        return 'no_enabled_failover';
                    }

                    $channel->forceFill(['enabled' => true])->save();

                    return '';
                });
            } catch (\Throwable $exception) {
                $repairRows[$index]['repair_skip_reason'] = 'write_failed';
                $repairRows[$index]['repair_error'] = $exception->getMessage();

                continue;
            }

            $repairRows[$index]['repair_skip_reason'] = $reason;
            if ($reason === '') {
                $repairRows[$index]['repair_applied'] = '1';
                $updated++;
            }
            if ($reason === 'cancelled') {
                break;
            }
        }

        if ($context->cancellationRequested()) {
            foreach ($repairRows as &$row) {
                if ($row['repair_action'] === 'reenable_master' && $row['repair_applied'] === '0' && $row['repair_skip_reason'] === '') {
                    $row['repair_skip_reason'] = 'cancelled';
                }
            }
        }

        return $repairRows;
    }
}
