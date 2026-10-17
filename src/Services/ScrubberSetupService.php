<?php

namespace AppLocalPlugins\GuideLink\Services;

use App\Models\Channel;
use App\Models\ChannelScrubber;
use App\Models\ChannelScrubberLog;
use App\Models\Playlist;
use App\Plugins\Support\PluginExecutionContext;
use AppLocalPlugins\GuideLink\Support\BuildsSetupReports;
use AppLocalPlugins\GuideLink\Support\Options;
use AppLocalPlugins\GuideLink\Support\ProfileDefaults;
use AppLocalPlugins\GuideLink\Support\ProfileSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

class ScrubberSetupService
{
    use BuildsSetupReports;

    private const MANAGED_NAME_PREFIX = 'GuideLink - ';

    /**
     * @var array<string, string>
     */
    private const REQUIRED_TABLES = [
        'channel_scrubbers' => 'Native scrubber table',
        'channel_scrubber_logs' => 'Native scrubber logs table',
        'channel_scrubber_log_channels' => 'Native scrubber channel log table',
    ];

    /**
     * @var array<string, string>
     */
    private const REQUIRED_CHANNEL_COLUMNS = [
        'last_scrubbed_at' => 'Last scrubbed timestamp',
        'last_scrubber_live' => 'Last scrubber live state',
    ];

    /**
     * @var array<string, string>
     */
    private const REQUIRED_SCRUBBER_COLUMNS = [
        'disable_dead' => 'Dead-channel disabling',
        'scan_all' => 'Disabled-channel scanning',
        'enable_live' => 'Direct live-channel re-enable',
        'check_method' => 'Probe method',
        'probe_timeout' => 'Probe timeout',
        'protect_failover_channels' => 'Failover re-enable protection',
        'rebuild_failovers_after_scan' => 'Post-scan failover rebuild',
    ];

    /**
     * @var array<string, array{title:string, recommendation:string}>
     */
    private const SETTING_ROWS = [
        'recurring' => [
            'title' => 'Scan recurrence',
            'recommendation' => 'Run this scrubber after playlist sync.',
        ],
        'disable_dead' => [
            'title' => 'Dead-channel disabling',
            'recommendation' => 'Disable channels that fail native scrubber probing.',
        ],
        'scan_all' => [
            'title' => 'Disabled-channel scanning',
            'recommendation' => 'Scan disabled channels so native failover recovery can observe streams that become live again.',
        ],
        'enable_live' => [
            'title' => 'Direct live-channel re-enable',
            'recommendation' => 'Keep direct re-enable disabled; native auto-merge handles primary and failover recovery.',
        ],
        'check_method' => [
            'title' => 'Probe method',
            'recommendation' => 'Use the desired native probing method.',
        ],
        'probe_timeout' => [
            'title' => 'Probe timeout',
            'recommendation' => 'Keep probe timeout aligned with GuideLink recovery policy.',
        ],
        'include_vod' => [
            'title' => 'VOD scan scope',
            'recommendation' => 'Keep GuideLink-managed scrubber scans limited to live channels.',
        ],
        'protect_failover_channels' => [
            'title' => 'Failover re-enable protection',
            'recommendation' => 'Keep hidden failovers hidden when direct live re-enable is used.',
        ],
        'rebuild_failovers_after_scan' => [
            'title' => 'Post-scan failover rebuild',
            'recommendation' => 'Run native auto-merge after successful scrubber scans so promotions and recoveries use fresh health state.',
        ],
    ];

    /**
     * @return array<int, string>
     */
    public function requiredWarningTitles(): array
    {
        return array_values([
            ...self::REQUIRED_TABLES,
            ...self::REQUIRED_CHANNEL_COLUMNS,
            ...self::REQUIRED_SCRUBBER_COLUMNS,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     scrubber_id:int|null,
     *     management:string,
     *     name:string,
     *     recurring:bool,
     *     include_vod:bool,
     *     scan_all:bool,
     *     check_method:string,
     *     probe_timeout:int,
     *     disable_dead:bool,
     *     enable_live:bool,
     *     protect_failover_channels:bool,
     *     rebuild_failovers_after_scan:bool
     * }
     */
    public function desiredConfig(Playlist $playlist, array $payload, PluginExecutionContext $context): array
    {
        $scrubberId = Options::nullableInt($payload['scrubber_id'] ?? null);
        if ($scrubberId !== null) {
            $this->assertScrubberAvailable($scrubberId, $playlist, $context);
        }

        $settings = ProfileSettings::nativeOptions($payload);

        return [
            'scrubber_id' => $scrubberId,
            'management' => $scrubberId === null ? 'guidelink_managed' : 'user_selected',
            'name' => $this->managedName($playlist),
            'recurring' => $settings['scrubber_recurring'],
            'include_vod' => false,
            'scan_all' => $settings['scrubber_scan_all'],
            'check_method' => $settings['scrubber_check_method'],
            'probe_timeout' => $settings['scrubber_probe_timeout'],
            'disable_dead' => $settings['scrubber_disable_dead'],
            'enable_live' => false,
            'protect_failover_channels' => $settings['auto_merge_deactivate_failover'],
            'rebuild_failovers_after_scan' => $settings['scrubber_rebuild_failovers_after_scan'],
        ];
    }

    /**
     * @param  array<string, mixed>  $desired
     * @return array<int, array<string, mixed>>
     */
    public function auditRows(Playlist $playlist, array $desired): array
    {
        $tables = [];
        foreach (self::REQUIRED_TABLES as $table => $title) {
            $tables[$table] = Schema::hasTable($table);
        }
        $columns = [
            'channels' => Schema::getColumnListing('channels'),
            'channel_scrubbers' => $tables['channel_scrubbers'] ? Schema::getColumnListing('channel_scrubbers') : [],
        ];
        $rows = $this->nativeCapabilityRows($tables, $columns);
        if (! $tables['channel_scrubbers']) {
            return $rows;
        }

        $scrubber = $this->targetScrubber($playlist, $desired);

        if (! $scrubber instanceof ChannelScrubber) {
            return [
                ...$rows,
                $this->row(
                    'change_required',
                    $desired['management'] === 'guidelink_managed' ? 'GuideLink-managed native scrubber' : 'Selected native scrubber',
                    $desired['management'] === 'guidelink_managed'
                        ? 'Use Run Sync to create a GuideLink-managed native scrubber for this playlist.'
                        : 'Select an existing native scrubber for this playlist, or leave the field blank so GuideLink can manage one.',
                    [
                        'playlist_id' => (int) $playlist->id,
                        'selected_scrubber_id' => $desired['scrubber_id'],
                    ],
                ),
                $this->scrubberStateRow($playlist, null, $columns['channels']),
            ];
        }

        return [
            ...$rows,
            $this->managementRow($scrubber, $desired),
            ...$this->configRows($scrubber, $desired, $columns['channel_scrubbers']),
            $this->scrubberStateRow($playlist, $scrubber, $columns['channels']),
        ];
    }

    /**
     * @param  array<string, mixed>  $desired
     * @return array<string, mixed>
     */
    public function apply(Playlist $playlist, array $desired, bool $dryRun): array
    {
        if (! Schema::hasTable('channel_scrubbers')) {
            throw new \RuntimeException('The native channel scrubber table is missing; migrate m3u-editor before applying scrubber setup.');
        }

        $scrubber = $this->targetScrubber($playlist, $desired);
        if (($desired['scrubber_id'] ?? null) !== null && ! $scrubber instanceof ChannelScrubber) {
            throw new \RuntimeException('The selected scrubber is no longer available for this playlist; review the profile and retry.');
        }
        if (($desired['scrubber_id'] ?? null) !== null && ! $this->isManagedScrubber($scrubber)) {
            return $this->row(
                'validated',
                'User-selected native scrubber',
                'Selected scrubber is user-managed; GuideLink validated it without changing native settings.',
                [
                    'dry_run' => $dryRun,
                    'playlist_id' => (int) $playlist->id,
                    'selected_scrubber_id' => $desired['scrubber_id'],
                    'management' => 'user_selected',
                    'current' => $this->scrubberSnapshot($scrubber),
                    'desired' => $this->desiredNativeAttributes($playlist, $desired),
                ],
            );
        }

        $attributes = $this->desiredNativeAttributes($playlist, $desired);

        if (! $scrubber instanceof ChannelScrubber) {
            if ($dryRun) {
                return $this->row(
                    'change_required',
                    'GuideLink-managed native scrubber setup',
                    'Create a GuideLink-managed native scrubber for this playlist.',
                    [
                        'dry_run' => true,
                        'playlist_id' => (int) $playlist->id,
                        'desired' => $attributes,
                    ],
                );
            }

            $scrubber = ChannelScrubber::withoutEvents(function () use ($attributes): ChannelScrubber {
                $model = new ChannelScrubber;
                $model->forceFill([
                    ...$attributes,
                    'status' => 'pending',
                    'processing' => false,
                    'progress' => 0,
                    'channel_count' => 0,
                    'dead_count' => 0,
                ])->save();

                return $model;
            });

            return $this->row(
                'created',
                'GuideLink-managed native scrubber setup',
                'GuideLink created a native recurring scrubber for this playlist.',
                [
                    'dry_run' => false,
                    'playlist_id' => (int) $playlist->id,
                    'scrubber_id' => (int) $scrubber->id,
                    'desired' => $attributes,
                ],
            );
        }

        $before = $this->scrubberSnapshot($scrubber);
        $after = [...$before, ...$attributes];
        $changes = $this->changedFields($before, $after);

        if (! $dryRun && $changes !== []) {
            ChannelScrubber::withoutEvents(function () use ($scrubber, $attributes): void {
                $scrubber->forceFill($attributes)->save();
            });
            $scrubber->refresh();
        }

        return $this->row(
            $changes === [] ? 'ok' : ($dryRun ? 'change_required' : 'updated'),
            'GuideLink-managed native scrubber setup',
            match (true) {
                $changes === [] => 'Native scrubber settings already match the desired GuideLink setup.',
                $dryRun => 'Use Run Sync to update the GuideLink-managed native scrubber.',
                default => 'GuideLink updated the native scrubber settings for this playlist.',
            },
            [
                'dry_run' => $dryRun,
                'playlist_id' => (int) $playlist->id,
                'scrubber_id' => (int) $scrubber->id,
                'changed_fields' => array_keys($changes),
                'changes' => $changes,
                'before' => $before,
                'desired' => $after,
            ],
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, int>
     */
    public function summary(array $rows): array
    {
        return $this->summaryFor($rows, ['ok', 'change_required', 'warning', 'created', 'updated', 'validated', 'info']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function nativeCapabilityRows(array $tables, array $columns): array
    {
        $rows = [];
        foreach (self::REQUIRED_TABLES as $table => $title) {
            $rows[] = $this->tableRow($table, $title, $tables[$table]);
        }

        foreach (self::REQUIRED_CHANNEL_COLUMNS as $column => $title) {
            $rows[] = $this->columnRow('channels', $column, $title, in_array($column, $columns['channels'], true));
        }

        if ($tables['channel_scrubbers']) {
            foreach (self::REQUIRED_SCRUBBER_COLUMNS as $column => $title) {
                $rows[] = $this->columnRow('channel_scrubbers', $column, $title, in_array($column, $columns['channel_scrubbers'], true));
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $desired
     * @return array<int, array<string, mixed>>
     */
    private function configRows(ChannelScrubber $scrubber, array $desired, array $columns): array
    {
        $rows = [];
        foreach (self::SETTING_ROWS as $key => $setting) {
            $details = ['scrubber_id' => (int) $scrubber->id];
            $rows[] = in_array($key, $columns, true)
                ? $this->checkRow(
                    $key,
                    $setting['title'],
                    $this->scrubberValue($scrubber, $key),
                    $desired[$key],
                    $setting['recommendation'],
                    $details,
                )
                : $this->row('warning', $setting['title'], 'Native host does not expose this scrubber setting.', [
                    ...$details,
                    'key' => $key,
                    'desired' => $desired[$key],
                ]);
        }

        return $rows;
    }

    private function managementRow(ChannelScrubber $scrubber, array $desired): array
    {
        $managed = $this->isManagedScrubber($scrubber);

        return $this->row(
            $managed ? 'ok' : 'info',
            'Scrubber ownership',
            $managed
                ? 'Selected scrubber is GuideLink-managed.'
                : 'Selected scrubber is user-managed; GuideLink audits it but does not change it.',
            [
                'scrubber_id' => (int) $scrubber->id,
                'name' => (string) $scrubber->name,
                'management' => $managed ? 'guidelink_managed' : 'user_managed',
                'desired_management' => $desired['management'],
            ],
        );
    }

    private function scrubberStateRow(Playlist $playlist, ?ChannelScrubber $scrubber, array $channelColumns): array
    {
        $latestLog = $scrubber instanceof ChannelScrubber
            ? ChannelScrubberLog::query()
                ->where('channel_scrubber_id', $scrubber->id)
                ->latest('id')
                ->first()
            : null;

        $health = in_array('last_scrubber_live', $channelColumns, true)
            ? Channel::query()->where('playlist_id', $playlist->id)->toBase()
                ->selectRaw('SUM(CASE WHEN last_scrubber_live = true THEN 1 ELSE 0 END) AS live_count')
                ->selectRaw('SUM(CASE WHEN last_scrubber_live = false THEN 1 ELSE 0 END) AS dead_count')
                ->selectRaw('SUM(CASE WHEN last_scrubber_live IS NULL THEN 1 ELSE 0 END) AS never_scanned')
                ->first()
            : null;

        return $this->row(
            $latestLog instanceof ChannelScrubberLog ? 'info' : 'warning',
            'Scrubber health state',
            $latestLog instanceof ChannelScrubberLog
                ? 'Latest native scrubber run is available for promotion/recovery context.'
                : 'No native scrubber log exists yet; run the scrubber before relying on health-driven recovery.',
            [
                'playlist_id' => (int) $playlist->id,
                'scrubber_id' => $scrubber instanceof ChannelScrubber ? (int) $scrubber->id : null,
                'latest_log' => $latestLog instanceof ChannelScrubberLog ? [
                    'id' => (int) $latestLog->id,
                    'status' => (string) $latestLog->status,
                    'channel_count' => (int) $latestLog->channel_count,
                    'dead_count' => (int) $latestLog->dead_count,
                    'disabled_count' => (int) $latestLog->disabled_count,
                    'live_count' => (int) ($latestLog->live_count ?? 0),
                    'created_at' => optional($latestLog->created_at)->toISOString(),
                ] : null,
                'channels' => [
                    'scrubber_live' => (int) ($health->live_count ?? 0),
                    'scrubber_dead' => (int) ($health->dead_count ?? 0),
                    'never_scanned' => (int) ($health->never_scanned ?? 0),
                ],
                'promotion_recovery_policy' => 'native_first',
                'guidelink_channel_state_required' => false,
            ],
        );
    }

    private function tableRow(string $table, string $title, bool $exists): array
    {
        return $this->row(
            $exists ? 'ok' : 'warning',
            $title,
            $exists ? 'Native host schema is available.' : 'Install or migrate the current m3u-editor host before applying scrubber setup.',
            ['table' => $table],
        );
    }

    private function columnRow(string $table, string $column, string $title, bool $exists): array
    {
        return $this->row(
            $exists ? 'ok' : 'warning',
            $title,
            $exists ? 'Native host column is available.' : 'Install or migrate the current m3u-editor host before relying on this scrubber capability.',
            [
                'table' => $table,
                'column' => $column,
            ],
        );
    }

    private function scrubberValue(ChannelScrubber $scrubber, string $key): mixed
    {
        $default = ProfileDefaults::value('scrubber_'.$key);
        $value = $scrubber->{$key} ?? $default;

        return match (true) {
            is_bool($default) => (bool) $value,
            is_int($default) => (int) $value,
            default => (string) $value,
        };
    }

    /**
     * @param  array<string, mixed>  $desired
     * @return array<string, mixed>
     */
    private function desiredNativeAttributes(Playlist $playlist, array $desired): array
    {
        return $this->filterScrubberColumns([
            'name' => $desired['name'],
            'user_id' => (int) $playlist->user_id,
            'playlist_id' => (int) $playlist->id,
            ...array_intersect_key($desired, self::SETTING_ROWS),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function filterScrubberColumns(array $attributes): array
    {
        return array_intersect_key(
            $attributes,
            array_fill_keys(Schema::getColumnListing('channel_scrubbers'), true),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function scrubberSnapshot(ChannelScrubber $scrubber): array
    {
        $settings = [];
        foreach (array_keys(self::SETTING_ROWS) as $key) {
            $settings[$key] = $this->scrubberValue($scrubber, $key);
        }

        return $this->filterScrubberColumns([
            'name' => (string) $scrubber->name,
            'user_id' => (int) $scrubber->user_id,
            'playlist_id' => (int) $scrubber->playlist_id,
            ...$settings,
        ]);
    }

    /**
     * @param  array<string, mixed>  $desired
     */
    private function targetScrubber(Playlist $playlist, array $desired): ?ChannelScrubber
    {
        $query = ChannelScrubber::query()->where('playlist_id', $playlist->id)->where('user_id', $playlist->user_id);
        $scrubberId = Options::nullableInt($desired['scrubber_id'] ?? null);
        if ($scrubberId !== null) {
            return $query->where('id', $scrubberId)->first();
        }

        return $query
            ->where(function (Builder $query) use ($playlist): void {
                $query->where('name', $this->managedName($playlist))
                    ->orWhere('name', 'like', self::MANAGED_NAME_PREFIX.'%');
            })
            ->oldest('id')
            ->first();
    }

    private function assertScrubberAvailable(int $scrubberId, Playlist $playlist, PluginExecutionContext $context): void
    {
        if (! Schema::hasTable('channel_scrubbers')) {
            throw new \RuntimeException('The native channel scrubber table is missing; migrate m3u-editor before selecting a scrubber.');
        }

        $query = ChannelScrubber::query()
            ->where('id', $scrubberId)
            ->where('playlist_id', $playlist->id);

        if ($context->user !== null && ! $context->user->isAdmin()) {
            $query->where('user_id', $context->user->id);
        }

        if (! $query->exists()) {
            throw new \RuntimeException("Selected scrubber [{$scrubberId}] is unavailable for playlist [{$playlist->id}].");
        }
    }

    private function isManagedScrubber(?ChannelScrubber $scrubber): bool
    {
        return $scrubber instanceof ChannelScrubber
            && str_starts_with((string) $scrubber->name, self::MANAGED_NAME_PREFIX);
    }

    private function managedName(Playlist $playlist): string
    {
        return mb_substr(self::MANAGED_NAME_PREFIX.(string) $playlist->name, 0, 255);
    }
}
