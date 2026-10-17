<?php

namespace AppLocalPlugins\GuideLink\Services;

use App\Models\Channel;
use App\Models\Playlist;
use App\Plugins\Support\PluginExecutionContext;
use AppLocalPlugins\GuideLink\Support\BuildsSetupReports;
use AppLocalPlugins\GuideLink\Support\Options;
use AppLocalPlugins\GuideLink\Support\ProfileDefaults;
use AppLocalPlugins\GuideLink\Support\ProfileSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SetupChecklistService
{
    use BuildsSetupReports;

    public function __construct(
        private readonly SourceCacheService $sourceCache = new SourceCacheService,
        private readonly AutoMergeSetupService $autoMergeSetup = new AutoMergeSetupService,
        private readonly ScrubberSetupService $scrubberSetup = new ScrubberSetupService,
        private readonly PlaylistSetupService $playlistSetup = new PlaylistSetupService,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function rows(PluginExecutionContext $context): array
    {
        $rows = [];
        $tables = [
            ProfileRepository::PROFILES_TABLE => Schema::hasTable(ProfileRepository::PROFILES_TABLE),
            ProfileRepository::ASSIGNMENTS_TABLE => Schema::hasTable(ProfileRepository::ASSIGNMENTS_TABLE),
            RunResultRepository::TABLE => Schema::hasTable(RunResultRepository::TABLE),
            ChannelQuarantineService::TABLE => Schema::hasTable(ChannelQuarantineService::TABLE),
            NativeAlertService::TABLE => Schema::hasTable(NativeAlertService::TABLE),
        ];
        $schemaReady = ! in_array(false, $tables, true);
        $playlistCount = (new PlaylistResolver)->availableTo($context)->count();

        $rows[] = $this->row(
            $schemaReady ? 'ready' : 'missing',
            'Declared plugin schema',
            $schemaReady
                ? 'Plugin-owned tables are installed.'
                : 'Trust or reinstall the plugin so m3u-editor creates the declared schema tables.',
            ['tables' => $tables],
        );

        $rows[] = $this->row(
            $playlistCount > 0 ? 'ready' : 'missing',
            'Available playlists',
            $playlistCount > 0
                ? 'At least one playlist is available to this plugin run.'
                : 'Create or import a playlist before assigning a GuideLink profile.',
            ['playlists' => $playlistCount],
        );

        if (! $schemaReady) {
            return $rows;
        }

        $profileRows = $this->profileRows($context);
        $enabledProfiles = array_values(array_filter(
            $profileRows,
            fn (object $row): bool => (bool) $row->enabled,
        ));

        $rows[] = $this->row(
            $enabledProfiles !== [] ? 'ready' : ($profileRows === [] ? 'missing' : 'warning'),
            'GuideLink profiles',
            match (true) {
                $enabledProfiles !== [] => 'At least one enabled GuideLink profile is configured.',
                $profileRows !== [] => 'Enable a GuideLink profile before scheduled or assigned runs can use it.',
                default => 'Create a GuideLink profile in the plugin Data tab.',
            },
            [
                'profiles' => count($profileRows),
                'enabled_profiles' => count($enabledProfiles),
            ],
        );

        $profilesById = array_column($profileRows, null, 'id');

        foreach ($enabledProfiles as $profile) {
            $rows[] = $this->profileSourceRow($profile, $context);
        }

        $assignmentRows = $this->assignmentRows($context);
        $playlists = (new PlaylistResolver)->availableTo($context)
            ->whereKey(array_column($assignmentRows, 'playlist_id'))
            ->get()->keyBy('id');
        $assignmentDetails = [];
        $enabled = 0;
        $ready = 0;

        foreach ($assignmentRows as $assignment) {
            if (! (bool) $assignment->enabled) {
                continue;
            }

            $enabled++;
            $playlist = $playlists->get((int) $assignment->playlist_id);
            $profileId = Options::nullableInt($assignment->extension_plugin_profile_id ?? null);
            $profile = $profilesById[$profileId] ?? null;
            $assignmentRow = $this->assignmentRow($assignment, $playlist, $profile);
            $assignmentDetails[] = $assignmentRow;

            if (($assignmentRow['status'] ?? null) === 'ready') {
                $ready++;
                array_push($assignmentDetails, ...$this->nativeSetupRows($playlist, $profile, $context));
            }
        }

        return [...$rows, $this->assignmentSummaryRow(count($assignmentRows), $enabled, $ready), ...$assignmentDetails];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, int|bool>
     */
    public function summary(array $rows): array
    {
        $summary = $this->summaryFor($rows, ['ready', 'missing', 'warning', 'info']);
        $summary['setup_ready'] = $summary['missing'] === 0 && $summary['warning'] === 0;

        return $summary;
    }

    /**
     * @return array<int, object>
     */
    private function profileRows(PluginExecutionContext $context): array
    {
        return DB::table(ProfileRepository::PROFILES_TABLE)
            ->where('extension_plugin_id', $context->plugin->id)
            ->orderBy('name')
            ->get()
            ->all();
    }

    /**
     * @return array<int, object>
     */
    private function assignmentRows(PluginExecutionContext $context): array
    {
        return DB::table(ProfileRepository::ASSIGNMENTS_TABLE)
            ->where('extension_plugin_id', $context->plugin->id)
            ->orderBy('playlist_id')
            ->get()
            ->all();
    }

    private function profileSourceRow(object $profile, PluginExecutionContext $context): array
    {
        $settings = Options::decodedArray($profile->settings ?? null);
        $mode = strtolower(Options::string($settings, [], 'source_mode', ProfileDefaults::SOURCE_MODE_SCRAPER_CACHE));

        return match ($mode) {
            ProfileDefaults::SOURCE_MODE_SCRAPER_CACHE => $this->scraperCacheProfileRow($profile, $settings),
            ProfileDefaults::SOURCE_MODE_NATIVE_PLAYLIST => $this->nativePlaylistProfileRow($profile, $settings, $context),
            default => $this->row(
                'missing',
                'Profile source: '.(string) $profile->name,
                'Choose scraper cache or native playlist as the profile source mode.',
                [
                    'profile_id' => (int) $profile->id,
                    'profile_name' => (string) $profile->name,
                    'source_mode' => $mode,
                ],
            ),
        };
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function scraperCacheProfileRow(object $profile, array $settings): array
    {
        $config = $this->sourceCache->config($settings, []);

        try {
            $lastGood = $this->sourceCache->readLastGood($config);
            $entries = count($lastGood['entries']);

            return $this->row(
                $entries > 0 ? 'ready' : 'missing',
                'Profile source: '.(string) $profile->name,
                $entries > 0
                    ? 'Parsed last-good source cache is available.'
                    : 'Refresh source cache before using this scraper-cache profile.',
                [
                    'profile_id' => (int) $profile->id,
                    'profile_name' => (string) $profile->name,
                    'source_mode' => ProfileDefaults::SOURCE_MODE_SCRAPER_CACHE,
                    'provider' => $config['provider'],
                    'provider_source' => $config['provider_source'],
                    'region' => $config['region'],
                    'source_cache_path' => $lastGood['path'],
                    'source_channels' => $entries,
                    'source_cache_fresh' => $this->sourceCache->isFresh($config),
                    'source_fetched_at' => $lastGood['metadata']['fetched_at'] ?? null,
                ],
            );
        } catch (Throwable $exception) {
            return $this->row(
                'missing',
                'Profile source: '.(string) $profile->name,
                'Refresh source cache before using this scraper-cache profile.',
                [
                    'profile_id' => (int) $profile->id,
                    'profile_name' => (string) $profile->name,
                    'source_mode' => ProfileDefaults::SOURCE_MODE_SCRAPER_CACHE,
                    'provider' => $config['provider'],
                    'provider_source' => $config['provider_source'],
                    'region' => $config['region'],
                    'error' => $exception->getMessage(),
                ],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function nativePlaylistProfileRow(object $profile, array $settings, PluginExecutionContext $context): array
    {
        $sourcePlaylistId = Options::nullableInt($settings['source_playlist_id'] ?? null);
        if ($sourcePlaylistId === null) {
            return $this->row(
                'missing',
                'Profile source: '.(string) $profile->name,
                'Choose a source playlist for this native-playlist profile.',
                [
                    'profile_id' => (int) $profile->id,
                    'profile_name' => (string) $profile->name,
                    'source_mode' => ProfileDefaults::SOURCE_MODE_NATIVE_PLAYLIST,
                ],
            );
        }

        $playlist = (new PlaylistResolver)->availableTo($context)->whereKey($sourcePlaylistId)->first();
        if (! $playlist) {
            return $this->row(
                'missing',
                'Profile source: '.(string) $profile->name,
                'Choose a source playlist that is available to this plugin run.',
                [
                    'profile_id' => (int) $profile->id,
                    'profile_name' => (string) $profile->name,
                    'source_mode' => ProfileDefaults::SOURCE_MODE_NATIVE_PLAYLIST,
                    'source_playlist_id' => $sourcePlaylistId,
                ],
            );
        }

        $sourceChannels = Channel::query()
            ->where('playlist_id', $playlist->id)
            ->where('is_vod', false)
            ->where('enabled', true)
            ->whereNotNull('channel')
            ->count();

        return $this->row(
            $sourceChannels > 0 ? 'ready' : 'missing',
            'Profile source: '.(string) $profile->name,
            $sourceChannels > 0
                ? 'Native source playlist has enabled live channels with channel numbers.'
                : 'Add enabled numbered live channels to the native source playlist.',
            [
                'profile_id' => (int) $profile->id,
                'profile_name' => (string) $profile->name,
                'source_mode' => ProfileDefaults::SOURCE_MODE_NATIVE_PLAYLIST,
                'source_playlist_id' => (int) $playlist->id,
                'source_playlist_name' => (string) $playlist->name,
                'source_channels' => $sourceChannels,
            ],
        );
    }

    private function assignmentSummaryRow(int $assignments, int $enabled, int $ready): array
    {
        return $this->row(
            $ready > 0 ? 'ready' : ($enabled > 0 ? 'warning' : 'missing'),
            'Playlist assignments',
            match (true) {
                $ready > 0 => 'At least one enabled playlist assignment has an enabled GuideLink profile.',
                $enabled > 0 => 'Fix enabled playlist assignments that are missing a usable profile or playlist.',
                default => 'Enable a playlist assignment and choose a GuideLink profile.',
            },
            [
                'assignments' => $assignments,
                'enabled_assignments' => $enabled,
                'ready_assignments' => $ready,
            ],
        );
    }

    private function assignmentRow(object $assignment, ?Playlist $playlist, ?object $profile): array
    {
        $profileId = Options::nullableInt($assignment->extension_plugin_profile_id ?? null);

        if (! $playlist) {
            return $this->row(
                'missing',
                'Playlist assignment: '.(string) $assignment->playlist_id,
                'Choose a playlist that is available to this plugin run.',
                [
                    'playlist_id' => (int) $assignment->playlist_id,
                    'profile_id' => $profileId,
                ],
            );
        }

        if (! $profile) {
            return $this->row(
                'missing',
                'Playlist assignment: '.(string) $playlist->name,
                'Choose an enabled GuideLink profile for this playlist assignment.',
                [
                    'playlist_id' => (int) $playlist->id,
                    'playlist_name' => (string) $playlist->name,
                    'profile_id' => $profileId,
                ],
            );
        }

        return $this->row(
            (bool) $profile->enabled ? 'ready' : 'warning',
            'Playlist assignment: '.(string) $playlist->name,
            (bool) $profile->enabled
                ? 'Playlist assignment is enabled and linked to an enabled GuideLink profile.'
                : 'Enable the linked GuideLink profile or choose a different profile.',
            [
                'playlist_id' => (int) $playlist->id,
                'playlist_name' => (string) $playlist->name,
                'profile_id' => (int) $profile->id,
                'profile_name' => (string) $profile->name,
                'profile_enabled' => (bool) $profile->enabled,
            ],
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function nativeSetupRows(Playlist $playlist, object $profile, PluginExecutionContext $context): array
    {
        $payload = [
            ...ProfileSettings::fromPayload(Options::decodedArray($profile->settings ?? null)),
            'playlist_id' => (int) $playlist->id,
            'target_playlist_id' => (int) $playlist->id,
        ];

        return [
            $this->nativeSetupRow($playlist, $profile, $payload, $context, $this->playlistSetup, 'playlist layout'),
            $this->nativeSetupRow($playlist, $profile, $payload, $context, $this->autoMergeSetup, 'auto-merge'),
            $this->nativeSetupRow($playlist, $profile, $payload, $context, $this->scrubberSetup, 'scrubber'),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function nativeSetupRow(
        Playlist $playlist,
        object $profile,
        array $payload,
        PluginExecutionContext $context,
        AutoMergeSetupService|ScrubberSetupService|PlaylistSetupService $service,
        string $kind,
    ): array {
        $title = 'Native '.$kind.' setup: '.(string) $playlist->name;
        $details = [
            'playlist_id' => (int) $playlist->id,
            'playlist_name' => (string) $playlist->name,
            'profile_id' => (int) $profile->id,
            'profile_name' => (string) $profile->name,
        ];

        try {
            $desired = $service->desiredConfig($playlist, $payload, $context);
            $auditRows = $service->auditRows($playlist, $desired);
            $status = $this->nativeSetupStatus($auditRows, $service->requiredWarningTitles());

            return $this->row(
                $status,
                $title,
                match (true) {
                    $service instanceof PlaylistSetupService && ! $desired['managed'] => 'Playlist layout remains user-managed. Enable layout management in the profile to protect guide order and numbers.',
                    $status === 'ready' => 'Native '.$kind.' settings match the assigned GuideLink profile.',
                    $service instanceof PlaylistSetupService => 'Run Preview Sync to review layout changes and conflicting automation. Apply the profile settings with Run Sync and adjust any identified rules in m3u-editor.',
                    default => 'Run Preview Sync, review the report, then Run Sync with confirmation to restore the assigned GuideLink profile settings.',
                },
                [
                    ...$details,
                    'summary' => $service->summary($auditRows),
                    'desired' => $desired,
                    'audit_rows' => $auditRows,
                ],
            );
        } catch (Throwable $exception) {
            return $this->row(
                'warning',
                $title,
                'Fix the assigned GuideLink profile native setup values before applying '.$kind.' setup.',
                [
                    ...$details,
                    'error' => $exception->getMessage(),
                ],
            );
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $auditRows
     * @param  array<int, string>  $requiredWarningTitles
     */
    private function nativeSetupStatus(array $auditRows, array $requiredWarningTitles): string
    {
        foreach ($auditRows as $row) {
            if (($row['status'] ?? null) === 'change_required') {
                return 'warning';
            }

            if (($row['status'] ?? null) === 'warning'
                && in_array((string) ($row['title'] ?? ''), $requiredWarningTitles, true)) {
                return 'warning';
            }
        }

        return 'ready';
    }
}
