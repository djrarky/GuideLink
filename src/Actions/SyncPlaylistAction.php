<?php

namespace AppLocalPlugins\GuideLink\Actions;

use App\Plugins\Support\PluginActionResult;
use App\Plugins\Support\PluginExecutionContext;
use AppLocalPlugins\GuideLink\Support\GroupIdentity;
use AppLocalPlugins\GuideLink\Support\Options;
use AppLocalPlugins\GuideLink\Support\ProfileDefaults;
use AppLocalPlugins\GuideLink\Support\ProfileSettings;
use AppLocalPlugins\GuideLink\Support\RunCancelled;
use AppLocalPlugins\GuideLink\Support\RunProgress;
use Illuminate\Support\Facades\DB;

/**
 * Coordinate one playlist at a time: prepare, plan, apply, then report.
 * See docs/MAINTAINING.md for commit boundaries and partial-result handling.
 *
 * @phpstan-import-type SourceEntry from \AppLocalPlugins\GuideLink\Services\ParsedSourceLoader
 * @phpstan-import-type SourceSnapshot from \AppLocalPlugins\GuideLink\Services\SourceCacheService
 */
class SyncPlaylistAction extends GuideLinkAction
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload, PluginExecutionContext $context): PluginActionResult
    {
        return $this->services->playlistBatchRunner()->runResolved(
            $payload,
            $context,
            'sync',
            $context->dryRun ? 'Sync playlist preview' : 'Sync playlist apply',
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
        $channelRepository = $this->services->channelRepository();
        $parsedSourceLoader = $this->services->parsedSourceLoader();
        $guideMaps = $this->services->guideMaps();
        $scopeFilter = $this->services->scopeFilter($countryRules);
        $matcher = $this->services->channelMatcher($countryRules);
        $groupSync = $this->services->groupSync($countryRules);
        $writePlans = $this->services->writePlans();
        $channelWriter = $this->services->channelWriter();
        $quarantine = $this->services->channelQuarantine();
        $logoSource = ProfileSettings::logoSource($payload);
        $runResults = $this->services->runResults();

        $context->checkpoint(5, $context->dryRun ? 'Preparing sync playlist preview.' : 'Preparing sync playlist apply run.', log: true);

        // Resolve and validate inputs before planning any native changes.
        // A first run may populate source cache, including during preview.
        $playlist = $playlistResolver->target($payload, $context);
        $rules = $countryRules->resolve($payload, []);
        $guide = $guideMaps->resolve($payload, $context);
        $autoMerge = $this->services->autoMergeSetup();
        $scrubber = $this->services->scrubberSetup();
        $playlistSetup = $this->services->playlistSetup();
        $playlistConfig = $playlistSetup->desiredConfig($playlist, $payload, $context);
        $autoMergeConfig = $autoMerge->desiredConfig($playlist, $payload, $context);
        $scrubberConfig = $scrubber->desiredConfig($playlist, $payload, $context);
        $preparedSource = $this->prepareSourceCache($payload, $context);
        $sourceCache = $preparedSource['summary'] ?? null;
        $sourceEntries = $parsedSourceLoader->entries($payload, $context, $guide['map'], $guide['aliases'], $preparedSource['snapshot'] ?? null);
        unset($preparedSource);

        if ($sourceEntries === []) {
            return PluginActionResult::failure(
                'Sync needs a populated source before it can plan edits.',
                [
                    'playlist_id' => $playlist->id,
                    'source_cache' => $sourceCache,
                ],
            );
        }

        $context->checkpoint(20, 'Loading target channels and scope rules.', [
            'playlist_id' => $playlist->id,
            'source_total' => count($sourceEntries),
            'guide_epg_id' => $guide['epg']['id'],
            'guide_channels' => count($guide['map']),
        ], log: true);

        $channels = $channelRepository->liveChannels($playlist, true, includeFailovers: false);
        $scopePayload = $this->scopePayload($payload, $sourceEntries);
        $scope = $scopeFilter->split($playlist, $channels, $scopePayload, [], $rules);
        $groupsByName = $channelRepository->groupsByName($playlist);
        $groupPlan = $groupSync->plan($sourceEntries, $groupsByName, $payload);
        $preservedGroupKeys = array_fill_keys(array_column(array_filter(
            $groupPlan, fn (array $row): bool => $row['action'] === 'preserve',
        ), 'group_key'), true);

        $context->checkpoint(45, 'Matching target channels to the configured source.', [
            'total_live_channels' => $channels->count(),
            'total_enabled_live_channels' => $channels->where('enabled', true)->count(),
            'in_scope_channels' => $scope['included']->count(),
            'excluded_channels' => count($scope['excluded']),
        ]);

        $matchRows = $matcher->rows(
            $playlist,
            $scope['included'],
            $sourceEntries,
            $matcher->thresholds($payload, []),
            $rules,
            $context,
        );
        $writePlan = $writePlans->rows($matchRows, $groupsByName, $logoSource, $preservedGroupKeys);
        // Quarantine follows matching decisions, including unchanged channels;
        // an empty metadata diff does not mean a channel is out of scope.
        $decisions = [...$matchRows, ...$scope['excluded']];
        $quarantinePlan = $quarantine->plan(
            $playlist, $channels, $decisions,
            Options::bool($payload, [], 'disable_other_groups', ProfileDefaults::bool('disable_other_groups')),
            $context,
        );
        $quarantineResult = null;
        $recoveryResult = null;
        $groupDisablePlan = $groupSync->planDisabling(
            $playlist, $channels, $decisions, $writePlan, $groupPlan, $payload,
        );
        $groupDisableResult = null;
        $groupResult = null;
        $writeResult = null;

        if ($context->cancellationRequested()) {
            return PluginActionResult::cancelled('Sync cancelled before applying native settings or channel metadata.');
        }

        $context->checkpoint(60, $context->dryRun
            ? 'Previewing native playlist layout, auto-merge and scrubber settings.'
            : 'Applying native playlist layout, auto-merge and scrubber settings.', log: true);

        // Native configuration and its audit rows commit together. Later group,
        // channel and quarantine writes have their own transaction boundaries.
        $nativeSettings = DB::transaction(function () use ($playlist, $autoMerge, $scrubber, $playlistSetup, $autoMergeConfig, $scrubberConfig, $playlistConfig, $context, $runResults): array {
            if (! $context->dryRun) {
                $playlist = $playlist->newQuery()->where('user_id', $playlist->user_id)->whereKey($playlist->id)->lockForUpdate()->firstOrFail();
            }
            RunProgress::checkCancellation($context);
            $rows = [
                'playlist_layout' => $playlistSetup->apply($playlist, $playlistConfig, $context->dryRun, deferSave: true),
                'auto_merge' => $autoMerge->apply($playlist, $autoMergeConfig, $context->dryRun, deferSave: true),
                'scrubber' => $scrubber->apply($playlist, $scrubberConfig, $context->dryRun),
            ];
            // Commit both sets of playlist attributes together so native update hooks
            // observe the complete configuration and run only once.
            if (! $context->dryRun && $playlist->isDirty()) {
                $playlist->save();
            }
            $runResults->replaceRows($context, (int) $playlist->id, [$rows['auto_merge']], 'auto_merge_audit');
            $runResults->replaceRows($context, (int) $playlist->id, [$rows['scrubber']], 'scrubber_audit');
            $runResults->replaceRows($context, (int) $playlist->id, [
                $rows['playlist_layout'], ...$rows['playlist_layout']['details']['checks'],
            ], 'playlist_layout_audit');

            return $rows;
        });

        if ($nativeSettings['playlist_layout']['details']['warnings'] > 0) {
            $context->warning('Playlist automation may override GuideLink layout. Review the playlist layout audit for the specific rules to adjust.', [
                'playlist_id' => (int) $playlist->id,
                'warnings' => $nativeSettings['playlist_layout']['details']['warnings'],
            ]);
        }

        if (! $context->dryRun) {
            $groupActionCounts = array_count_values(array_column($groupPlan, 'action'));
            $context->checkpoint(70, 'Ensuring managed live groups exist.', [
                'groups_to_create' => $groupActionCounts['create'] ?? 0,
                'groups_to_update' => ($groupActionCounts['update_sort'] ?? 0) + ($groupActionCounts['protect'] ?? 0) + ($groupActionCounts['restore'] ?? 0),
            ], log: true);

            $groupResult = $groupSync->apply($playlist, $groupPlan, $context);
            if ($groupResult['aborted']) {
                $summary = $writePlans->summary($playlist, $matchRows, $writePlan, $sourceEntries, $scope['excluded'], true, $groupPlan, $groupResult);
                $resultDetails = $runResults->replaceSync(
                    $context,
                    (int) $playlist->id,
                    $matchRows,
                    $scope['excluded'],
                    $writePlan,
                    $quarantinePlan,
                    $groupDisablePlan,
                );

                $data = [
                    ...$summary,
                    'totals' => $this->totals($summary),
                    'source_cache' => $sourceCache,
                    'native_settings' => $nativeSettings,
                    'run_results' => $resultDetails,
                    'progress' => 70,
                ];

                return $context->cancellationRequested()
                    ? PluginActionResult::cancelled('Sync cancelled during group setup; channel metadata writes were aborted.', $data)
                    : PluginActionResult::failure('Group setup failed; channel metadata writes were aborted.', $data);
            }

            // Existing and restored groups already have their final IDs in the preview plan.
            if (isset($groupActionCounts['create'])) {
                $writePlan = $writePlans->rows($matchRows, $groupResult['groups_by_name'], $logoSource, $preservedGroupKeys);
            }

            $context->checkpoint(82, 'Applying diff-only channel metadata writes.', [
                'write_plan_channels' => count($writePlan),
            ], log: true);

            $writeResult = $channelWriter->apply($writePlan, $context);
            // Apply policy only after complete metadata writes. Quarantine,
            // matched recovery and group cleanup each depend on the prior stage.
            if (! $writeResult['aborted'] && $writeResult['channels_failed'] === 0
                && ! $context->cancellationRequested()) {
                $quarantineResult = $quarantine->apply($playlist, $quarantinePlan, $context, $writePlan);
                $writeResult['unmanaged_channels_disabled'] = $quarantineResult['disabled'];
                $writeResult['channels_failed'] += $quarantineResult['failed'];
                $writeResult['errors'] = [...$writeResult['errors'], ...$quarantineResult['errors']];
                $writeResult['aborted'] = $quarantineResult['aborted'];
                if (! $quarantineResult['aborted'] && $quarantineResult['failed'] === 0) {
                    $recoveryResult = $this->services->matchedChannelRecovery()->apply($playlist, $matchRows, $writePlan, $context);
                    $writeResult['channels_failed'] += $recoveryResult['failed'];
                    $writeResult['errors'] = [...$writeResult['errors'], ...$recoveryResult['errors']];
                    $writeResult['aborted'] = $recoveryResult['aborted'];
                }
                if ($recoveryResult !== null && ! $recoveryResult['aborted'] && $recoveryResult['failed'] === 0) {
                    $groupDisableResult = $groupSync->applyDisabling($playlist, $groupDisablePlan, $context);
                    $groupResult['groups_failed'] += $groupDisableResult['failed'];
                    $groupResult['errors'] = [...$groupResult['errors'], ...$groupDisableResult['errors']];
                    $writeResult['aborted'] = $groupDisableResult['aborted'];
                }
            }
            $writeResult['aborted'] = $writeResult['aborted'] || $context->cancellationRequested();
        }

        $summary = $writePlans->summary(
            $playlist,
            $matchRows,
            $writePlan,
            $sourceEntries,
            $scope['excluded'],
            ! $context->dryRun,
            $groupPlan,
            $groupResult,
            $writeResult,
        );

        $context->checkpoint(90, 'Saving sync run results.', [
            'write_plan_channels' => $summary['writes']['write_plan_channels'],
            'needs_review' => $summary['matching']['needs_review'],
        ]);

        $resultDetails = $runResults->replaceSync(
            $context,
            (int) $playlist->id,
            $matchRows,
            $scope['excluded'],
            $writePlan,
            $quarantinePlan,
            $groupDisablePlan,
        );
        $summary['writes']['groups_to_disable'] = count($groupDisablePlan);
        $summary['writes']['groups_disabled'] = $groupDisableResult['disabled'] ?? 0;
        if ($context->dryRun) {
            $recoveryResult = $this->services->matchedChannelRecovery()->apply($playlist, $matchRows, $writePlan, $context);
        }

        $epgMappingError = null;
        if (! $summary['writes']['aborted'] && $summary['writes']['channels_failed'] === 0 && $summary['writes']['groups_failed'] === 0) {
            try {
                $nativeSettings['epg_mapping'] = $this->services->epgMappingSetup()->apply($playlist, $guide['epg'], $logoSource === 'epg', $context);
                $runResults->replaceRows($context, (int) $playlist->id, [$nativeSettings['epg_mapping']], 'epg_mapping_audit');
            } catch (RunCancelled) {
                $summary['writes']['aborted'] = true;
            } catch (\Throwable $exception) {
                $epgMappingError = $exception->getMessage();
                $context->warning('Native EPG mapping setup did not complete.', ['error' => $epgMappingError]);
            }
        }

        $data = [
            ...$summary,
            'totals' => $this->totals($summary),
            'source_cache' => $sourceCache,
            'native_settings' => $nativeSettings,
            'epg_mapping_error' => $epgMappingError,
            'matched_recovery' => $recoveryResult,
            'quarantine' => [
                'planned' => count($quarantinePlan),
                'to_disable' => count(array_filter($quarantinePlan, fn (array $row): bool => $row['action'] === 'quarantine' && $row['current']['enabled'])),
                'result' => $quarantineResult,
            ],
            'run_results' => $resultDetails,
            'sync_preview' => $context->dryRun,
            'writes_enabled' => ! $context->dryRun,
            'progress' => 90,
        ];

        if ($summary['writes']['aborted']) {
            return PluginActionResult::cancelled('Sync cancelled; changes already applied have been retained.', $data);
        }
        if ($summary['writes']['channels_failed'] > 0 || $summary['writes']['groups_failed'] > 0) {
            return PluginActionResult::failure('Sync had write failures; inspect the channel, quarantine, recovery and group results. Successful changes were retained.', $data);
        }
        if ($epgMappingError !== null) {
            return PluginActionResult::failure('Channel sync completed, but native EPG mapping setup failed: '.$epgMappingError, $data);
        }

        $context->checkpoint(100, $context->dryRun ? 'Sync playlist preview completed.' : 'Sync playlist apply completed.', [
            'matching' => $summary['matching'],
            'writes' => $summary['writes'],
            'run_results' => $resultDetails,
        ], log: true);

        $epgStatus = $nativeSettings['epg_mapping']['status'] ?? 'ok';
        $epgMessage = in_array($epgStatus, ['queued', 'processing'], true)
            ? ' Native EPG mapping is '.$epgStatus.'; see EPG Maps for completion.'
            : '';

        return PluginActionResult::success(
            $context->dryRun
                ? 'Sync preview completed without changing native settings or channel metadata.'
                : "Sync completed: {$summary['writes']['channels_written']} metadata update(s), {$summary['writes']['unmanaged_channels_disabled']} unconfirmed channel(s) disabled, {$recoveryResult['enabled']} primary stream(s) enabled, {$recoveryResult['disabled']} duplicate or unavailable entry/entries disabled, and {$summary['writes']['groups_disabled']} provider group(s) disabled.".$epgMessage,
            [...$data, 'progress' => 100],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<SourceEntry>  $sourceEntries
     * @return array<string, mixed>
     */
    private function scopePayload(array $payload, array $sourceEntries): array
    {
        unset($payload['_managed_group_keys']);
        if (! Options::bool($payload, [], 'include_managed_groups', ProfileDefaults::bool('include_managed_groups'))) {
            return $payload;
        }

        $prefixes = Options::list($payload, [], 'include_group_prefixes', ProfileDefaults::list('include_group_prefixes'));
        if ($prefixes === []) {
            return $payload;
        }

        $categories = [];
        foreach ($sourceEntries as $entry) {
            $category = (string) $entry['category'];
            if ($category !== '') {
                $categories[GroupIdentity::key($category)] = true;
            }
        }

        return [
            ...$payload,
            '_managed_group_keys' => array_keys($categories),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{summary: array<string, mixed>, snapshot: SourceSnapshot}|null
     */
    private function prepareSourceCache(array $payload, PluginExecutionContext $context): ?array
    {
        $sourceMode = strtolower(Options::string($payload, [], 'source_mode', ProfileDefaults::SOURCE_MODE_SCRAPER_CACHE));
        if ($sourceMode !== ProfileDefaults::SOURCE_MODE_SCRAPER_CACHE) {
            return null;
        }

        $cache = $this->services->sourceCache();
        $config = $cache->config($payload, []);

        try {
            $lastGood = $cache->readLastGood($config);

            return ['snapshot' => $lastGood, 'summary' => [
                'status' => 'ready',
                'source_cache_path' => $lastGood['path'],
                'channel_count' => count($lastGood['entries']),
                'fetched_at' => $lastGood['metadata']['fetched_at'] ?? null,
            ]];
        } catch (\RuntimeException $exception) {
            if (! str_contains($exception->getMessage(), 'No last-good source cache exists')) {
                throw $exception;
            }

            $context->checkpoint(12, 'Preparing source cache for first sync.', [
                'provider' => $config['provider'],
                'provider_source' => $config['provider_source'],
                'region' => $config['region'],
            ], log: true);

            $result = $this->services
                ->sourceRefresh(cache: $cache)
                ->refresh([...$payload, 'force_refresh' => false], $context);
            $source = is_array($result['summary']['source'] ?? null) ? $result['summary']['source'] : [];

            $context->info('Source cache prepared for sync.', [
                'source_cache_path' => $source['last_good_cache_path'] ?? null,
                'channel_count' => $source['channel_count'] ?? 0,
            ]);

            return ['snapshot' => [
                'entries' => $result['entries'],
                'metadata' => $source,
                'path' => $source['last_good_cache_path'],
            ], 'summary' => [
                'status' => 'prepared',
                'source_cache_path' => $source['last_good_cache_path'] ?? null,
                'channel_count' => (int) ($source['channel_count'] ?? 0),
                'fetched_at' => $source['fetched_at'] ?? null,
                'stale_fallback' => (bool) ($source['stale_fallback'] ?? false),
            ]];
        }
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, int|bool>
     */
    private function totals(array $summary): array
    {
        return [
            'in_scope' => (int) ($summary['matching']['in_scope_channels'] ?? 0),
            'matched' => (int) (($summary['matching']['fingerprint'] ?? 0) + ($summary['matching']['auto_apply'] ?? 0)),
            'needs_review' => (int) ($summary['matching']['needs_review'] ?? 0),
            'unmatched' => (int) ($summary['matching']['unmatched'] ?? 0),
            'excluded' => (int) ($summary['matching']['excluded_channels'] ?? 0),
            'channels_written' => (int) ($summary['writes']['channels_written'] ?? 0),
        ];
    }
}
