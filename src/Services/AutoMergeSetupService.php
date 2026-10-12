<?php

namespace AppLocalPlugins\GuideLink\Services;

use App\Jobs\MergeChannels;
use App\Listeners\SyncListener;
use App\Models\Channel;
use App\Models\Playlist;
use App\Plugins\Support\PluginExecutionContext;
use AppLocalPlugins\GuideLink\Support\BuildsSetupReports;
use AppLocalPlugins\GuideLink\Support\Options;
use AppLocalPlugins\GuideLink\Support\ProfileDefaults;
use AppLocalPlugins\GuideLink\Support\ProfileSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AutoMergeSetupService
{
    use BuildsSetupReports;

    /**
     * @var array<int, string>
     */
    private const REQUIRED_WARNING_TITLES = [
        'Channel mergeability column',
    ];

    /**
     * @return array<int, string>
     */
    public function requiredWarningTitles(): array
    {
        return self::REQUIRED_WARNING_TITLES;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     preferred_playlist_id:int,
     *     failover_playlist_ids:array<int, int>,
     *     playlist_ids:array<int, int>,
     *     deactivate_failover:bool,
     *     merge_key:string,
     *     new_channels_only:bool,
     *     force_complete_remerge:bool,
     *     check_resolution:bool,
     *     prefer_catchup_as_primary:bool,
     *     exclude_disabled_groups:bool
     * }
     */
    public function desiredConfig(Playlist $playlist, array $payload, PluginExecutionContext $context): array
    {
        $preferredPlaylistId = Options::nullableInt($payload['auto_merge_preferred_playlist_id'] ?? null)
            ?? (int) $playlist->id;
        $failoverPlaylistIds = $this->integerList($payload['auto_merge_failover_playlist_ids'] ?? ProfileDefaults::list('auto_merge_failover_playlist_ids'));
        $failoverPlaylistIds = array_values(array_filter(
            array_unique($failoverPlaylistIds),
            fn (int $id): bool => $id !== (int) $playlist->id,
        ));

        $this->assertPlaylistsAvailable([$preferredPlaylistId, ...$failoverPlaylistIds], $context);

        $settings = ProfileSettings::nativeOptions($payload);

        return [
            'preferred_playlist_id' => $preferredPlaylistId,
            'failover_playlist_ids' => $failoverPlaylistIds,
            'playlist_ids' => array_values(array_unique([(int) $playlist->id, $preferredPlaylistId, ...$failoverPlaylistIds])),
            'deactivate_failover' => $settings['auto_merge_deactivate_failover'],
            'merge_key' => $settings['auto_merge_merge_key'],
            'new_channels_only' => $settings['auto_merge_new_channels_only'],
            'force_complete_remerge' => $settings['auto_merge_force_complete_remerge'],
            'check_resolution' => $settings['auto_merge_check_resolution'],
            'prefer_catchup_as_primary' => $settings['auto_merge_prefer_catchup_as_primary'],
            'exclude_disabled_groups' => $settings['auto_merge_exclude_disabled_groups'],
            'scrubber_aware_master_selection' => $settings['auto_merge_scrubber_aware_master_selection'],
        ];
    }

    /**
     * @param  array<string, mixed>  $desired
     * @return array<int, array<string, mixed>>
     */
    public function auditRows(Playlist $playlist, array $desired): array
    {
        $actualConfig = is_array($playlist->auto_merge_config) ? $playlist->auto_merge_config : [];

        return [
            $this->checkRow(
                'auto_merge_channels_enabled',
                'Auto-merge enabled',
                (bool) $playlist->auto_merge_channels_enabled,
                true,
                'Enable native auto-merge after playlist sync.',
            ),
            $this->checkRow(
                'auto_merge_deactivate_failover',
                'Deactivate native failover channels',
                (bool) $playlist->auto_merge_deactivate_failover,
                $desired['deactivate_failover'],
                'Deactivate failover channels so only the selected master remains visible.',
            ),
            $this->checkRow(
                'preferred_playlist_id',
                'Preferred playlist',
                Options::nullableInt($actualConfig['preferred_playlist_id'] ?? null),
                $desired['preferred_playlist_id'],
                'Set the preferred playlist used for native master selection.',
            ),
            $this->checkRow(
                'failover_playlists',
                'Failover playlists',
                $this->integerList($actualConfig['failover_playlists'] ?? []),
                $desired['failover_playlist_ids'],
                'Configure the playlists that native auto-merge should use as failover sources.',
            ),
            $this->checkRow(
                'merge_key',
                'Merge key',
                (string) ($actualConfig['merge_key'] ?? 'stream_id'),
                $desired['merge_key'],
                'Use stream IDs for live-channel failover matching.',
            ),
            $this->checkRow(
                'new_channels_only',
                'Merge only new channels',
                (bool) ($actualConfig['new_channels_only'] ?? true),
                $desired['new_channels_only'],
                'Disable new-only mode when GuideLink should be able to rebuild all failovers.',
            ),
            $this->checkRow(
                'force_complete_remerge',
                'Force complete re-merge',
                (bool) ($actualConfig['force_complete_remerge'] ?? false),
                $desired['force_complete_remerge'],
                'Enable complete re-merge when GuideLink is rebuilding native failovers.',
            ),
            $this->checkRow(
                'check_resolution',
                'Prioritize by resolution',
                (bool) ($actualConfig['check_resolution'] ?? false),
                $desired['check_resolution'],
                'Keep resolution priority aligned with the GuideLink profile.',
            ),
            $this->checkRow(
                'prefer_catchup_as_primary',
                'Prefer catch-up as primary',
                (bool) ($actualConfig['prefer_catchup_as_primary'] ?? false),
                $desired['prefer_catchup_as_primary'],
                'Keep catch-up preference aligned with the GuideLink profile.',
            ),
            $this->checkRow(
                'exclude_disabled_groups',
                'Exclude disabled groups from master selection',
                (bool) ($actualConfig['exclude_disabled_groups'] ?? false),
                $desired['exclude_disabled_groups'],
                'Do not select channels from disabled groups as native masters.',
            ),
            $this->checkRow(
                'scrubber_aware_master_selection',
                'Scrubber-aware master selection',
                (bool) ($actualConfig['scrubber_aware_master_selection'] ?? false),
                $desired['scrubber_aware_master_selection'],
                'Exclude channels confirmed dead by the scrubber from primary selection.',
            ),
            ...$this->channelRows($desired['playlist_ids'], $desired['merge_key']),
        ];
    }

    /**
     * @param  array<string, mixed>  $desired
     * @return array<string, mixed>
     */
    public function apply(Playlist $playlist, array $desired, bool $dryRun): array
    {
        $before = $this->nativeState($playlist);
        $config = $this->targetNativeConfig(is_array($playlist->auto_merge_config) ? $playlist->auto_merge_config : [], $desired);
        $after = [
            'auto_merge_channels_enabled' => true,
            'auto_merge_deactivate_failover' => (bool) $desired['deactivate_failover'],
            'auto_merge_config' => $config,
        ];
        $changes = $this->changedFields($before, $after);

        if (! $dryRun && $changes !== []) {
            $playlist->forceFill($after)->save();
            $playlist->refresh();
        }

        return $this->row(
            $changes === [] ? 'ok' : ($dryRun ? 'change_required' : 'applied'),
            'Native auto-merge setup',
            match (true) {
                $changes === [] => 'Native auto-merge settings already match the desired GuideLink setup.',
                $dryRun => 'Use Run Sync to apply the profile\'s native auto-merge settings.',
                default => 'Native auto-merge settings were updated on the playlist.',
            },
            [
                'playlist_id' => (int) $playlist->id,
                'dry_run' => $dryRun,
                'changed_fields' => array_keys($changes),
                'changes' => $changes,
                'before' => $before,
                'desired' => $after,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function rebuildFailovers(Playlist $playlist, PluginExecutionContext $context, bool $dryRun): array
    {
        $playlist = $this->freshPlaylist($playlist);
        if (! (bool) $playlist->auto_merge_channels_enabled) {
            throw new \RuntimeException('Native auto-merge is disabled. Use Run Sync to apply the profile settings before rebuilding failovers.');
        }

        $before = $this->failoverSnapshot($playlist);
        if ($dryRun) {
            return $this->row('change_required', 'Native failover rebuild', 'Run rebuild failovers to invoke m3u-editor native auto-merge.', [
                'playlist_id' => (int) $playlist->id,
                'dry_run' => true,
                'before' => $before,
            ]);
        }

        $job = SyncListener::getMergeJob($playlist);
        if (! $job instanceof MergeChannels) {
            throw new \RuntimeException('m3u-editor did not build a native MergeChannels job for this playlist.');
        }

        $context->heartbeat('Running m3u-editor native MergeChannels job.', null, [
            'native_auto_merge' => [
                'playlist_id' => (int) $playlist->id,
                'stage' => 'running',
            ],
        ]);

        // MergeChannels owns native failover rows; GuideLink only invokes it and records before/after totals.
        Channel::withoutEvents(fn (): mixed => $job->handle());

        $after = $this->failoverSnapshot($this->freshPlaylist($playlist));

        return $this->row('rebuilt', 'Native failover rebuild', 'm3u-editor native MergeChannels rebuilt failover relationships.', [
            'playlist_id' => (int) $playlist->id,
            'dry_run' => false,
            'before' => $before,
            'after' => $after,
            'delta' => [
                'failover_rows' => $after['failover_rows'] - $before['failover_rows'],
                'enabled_channels' => $after['enabled_channels'] - $before['enabled_channels'],
                'disabled_channels' => $after['disabled_channels'] - $before['disabled_channels'],
                'scrubber_dead_channels' => $after['scrubber_dead_channels'] - $before['scrubber_dead_channels'],
            ],
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, int>
     */
    public function summary(array $rows): array
    {
        return $this->summaryFor($rows, ['ok', 'change_required', 'warning', 'applied', 'rebuilt', 'info']);
    }

    /**
     * @param  array<string, mixed>  $desired
     * @return array<string, mixed>
     */
    private function targetNativeConfig(array $currentConfig, array $desired): array
    {
        return [
            ...$currentConfig,
            'preferred_playlist_id' => $desired['preferred_playlist_id'],
            'failover_playlists' => array_map(
                fn (int $id): array => ['playlist_failover_id' => $id],
                $desired['failover_playlist_ids'],
            ),
            'merge_key' => $desired['merge_key'],
            'new_channels_only' => $desired['new_channels_only'],
            'force_complete_remerge' => $desired['force_complete_remerge'],
            'check_resolution' => $desired['check_resolution'],
            'prefer_catchup_as_primary' => $desired['prefer_catchup_as_primary'],
            'exclude_disabled_groups' => $desired['exclude_disabled_groups'],
            'scrubber_aware_master_selection' => $desired['scrubber_aware_master_selection'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function nativeState(Playlist $playlist): array
    {
        return [
            'auto_merge_channels_enabled' => (bool) $playlist->auto_merge_channels_enabled,
            'auto_merge_deactivate_failover' => (bool) $playlist->auto_merge_deactivate_failover,
            'auto_merge_config' => is_array($playlist->auto_merge_config) ? $playlist->auto_merge_config : [],
        ];
    }

    private function freshPlaylist(Playlist $playlist): Playlist
    {
        $fresh = $playlist->fresh();
        if (! $fresh instanceof Playlist) {
            throw new \RuntimeException('Playlist disappeared before native failover rebuild could run.');
        }

        return $fresh;
    }

    /**
     * @return array<string, int|bool|array<int, int>>
     */
    private function failoverSnapshot(Playlist $playlist): array
    {
        $playlistIds = $this->configuredPlaylistIds($playlist);
        $channels = Channel::query()
            ->where('user_id', $playlist->user_id)
            ->whereIn('playlist_id', $playlistIds);

        $failoverRows = DB::table('channel_failovers')
            ->where('user_id', $playlist->user_id)
            ->where(function ($query) use ($channels): void {
                $query->whereIn('channel_id', (clone $channels)->select('id'))
                    ->orWhereIn('channel_failover_id', (clone $channels)->select('id'));
            })
            ->count();

        $hasScrubberState = Schema::hasColumn('channels', 'last_scrubber_live');
        $counts = $channels->toBase()
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('SUM(CASE WHEN enabled = true THEN 1 ELSE 0 END) AS enabled_count')
            ->selectRaw('SUM(CASE WHEN enabled = false THEN 1 ELSE 0 END) AS disabled_count')
            ->selectRaw($hasScrubberState
                ? 'SUM(CASE WHEN last_scrubber_live = false THEN 1 ELSE 0 END) AS dead_count'
                : '0 AS dead_count')
            ->first();

        return [
            'playlist_ids' => $playlistIds,
            'channels' => (int) $counts->total,
            'enabled_channels' => (int) $counts->enabled_count,
            'disabled_channels' => (int) $counts->disabled_count,
            'scrubber_dead_channels' => (int) $counts->dead_count,
            'failover_rows' => $failoverRows,
            'has_scrubber_state' => $hasScrubberState,
        ];
    }

    /**
     * @return array<int, int>
     */
    private function configuredPlaylistIds(Playlist $playlist): array
    {
        $config = is_array($playlist->auto_merge_config) ? $playlist->auto_merge_config : [];
        $preferred = Options::nullableInt($config['preferred_playlist_id'] ?? null);
        $failovers = $this->integerList($config['failover_playlists'] ?? []);

        return array_values(array_unique(array_filter([
            (int) $playlist->id,
            $preferred,
            ...$failovers,
        ])));
    }

    /**
     * @param  array<int, int>  $playlistIds
     * @return array<int, array<string, mixed>>
     */
    private function channelRows(array $playlistIds, string $mergeKey): array
    {
        if (! Schema::hasColumn('channels', 'can_merge')) {
            return [
                $this->row(
                    'warning',
                    'Channel mergeability column',
                    'The host channels table does not expose can_merge; install the current m3u-editor host before applying native auto-merge setup.',
                ),
            ];
        }

        $query = Channel::query()
            ->whereIn('playlist_id', $playlistIds);

        if ($mergeKey === 'tmdb_id') {
            $query->where('is_vod', true);
        } else {
            $query->where(function ($query): void {
                $query->where('is_vod', false)
                    ->orWhereNull('is_vod');
            });
        }

        $nonMergeable = (clone $query)
            ->where('can_merge', false)
            ->count();

        $channels = (clone $query)
            ->where('can_merge', true)
            ->get($mergeKey === 'tmdb_id' ? ['tmdb_id'] : ['stream_id', 'stream_id_custom']);

        $keys = [];
        foreach ($channels as $channel) {
            $key = $this->mergeKey($channel, $mergeKey);
            if ($key === '') {
                continue;
            }

            $keys[$key] = ($keys[$key] ?? 0) + 1;
        }

        $candidateGroups = array_filter($keys, fn (int $count): bool => $count > 1);

        return [
            $this->row(
                $nonMergeable > 0 ? 'warning' : 'ok',
                'Channels marked non-mergeable',
                $nonMergeable > 0
                    ? 'Some channels in the configured playlist set have can_merge disabled and cannot participate in native auto-merge.'
                    : 'All channels in the configured playlist set are mergeable.',
                [
                    'playlist_ids' => $playlistIds,
                    'non_mergeable_channels' => $nonMergeable,
                ],
            ),
            $this->row(
                count($candidateGroups) > 0 ? 'ok' : 'warning',
                'Duplicate merge keys',
                count($candidateGroups) > 0
                    ? 'Native auto-merge has duplicate merge keys to rebuild into failovers.'
                    : 'No duplicate merge keys were found in the configured playlist set.',
                [
                    'playlist_ids' => $playlistIds,
                    'merge_key' => $mergeKey,
                    'duplicate_key_groups' => count($candidateGroups),
                    'duplicate_channel_count' => array_sum($candidateGroups),
                ],
            ),
        ];
    }

    /**
     * @param  array<int, int>  $playlistIds
     */
    private function assertPlaylistsAvailable(array $playlistIds, PluginExecutionContext $context): void
    {
        $ids = array_values(array_unique(array_filter($playlistIds)));
        if ($ids === []) {
            return;
        }

        $available = (new PlaylistResolver)->availableTo($context)
            ->whereIn('id', $ids)
            ->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $missing = array_values(array_diff($ids, $available));
        if ($missing !== []) {
            throw new \RuntimeException('Auto-merge setup references unavailable playlist id(s): '.implode(', ', $missing));
        }
    }

    /**
     * @return array<int, int>
     */
    private function integerList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\r\n,]+/', $value) ?: [];
        }

        if (! is_array($value)) {
            return [];
        }

        $ids = [];
        foreach ($value as $item) {
            $id = is_array($item)
                ? Options::nullableInt($item['playlist_failover_id'] ?? $item['id'] ?? null)
                : Options::nullableInt($item);

            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    private function mergeKey(Channel $channel, string $mergeKey): string
    {
        if ($mergeKey === 'tmdb_id') {
            return trim((string) ($channel->tmdb_id ?? ''));
        }

        return trim((string) (($channel->stream_id_custom ?: $channel->stream_id) ?? ''));
    }
}
