<?php

namespace AppLocalPlugins\GuideLink\Services;

use App\Models\Playlist;
use App\Plugins\Support\PluginExecutionContext;
use AppLocalPlugins\GuideLink\Support\Options;
use AppLocalPlugins\GuideLink\Support\ProfileDefaults;
use AppLocalPlugins\GuideLink\Support\ProfileSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ProfileRepository
{
    public const PROFILES_TABLE = 'plugin_guidelink_profiles';

    public const ASSIGNMENTS_TABLE = 'plugin_guidelink_links';

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function payloadForPlaylist(Playlist $playlist, array $payload, PluginExecutionContext $context): array
    {
        $this->assertTables();

        $profile = $this->assignedProfilePayload($playlist, $context);

        if ($profile === null || $profile === []) {
            throw new \RuntimeException("Assign a GuideLink profile to playlist [{$playlist->name}] before running this action.");
        }

        return [...$profile, ...$payload];
    }

    /**
     * @param  array<string, mixed>  $basePayload
     * @return array<int, array{playlist: Playlist, payload: array<string, mixed>}>
     */
    public function assignedRuns(array $basePayload, PluginExecutionContext $context, string $purpose): array
    {
        $this->assertTables();

        $flag = $purpose === 'sync' ? 'run_sync' : 'run_availability';
        $rows = DB::table(self::ASSIGNMENTS_TABLE)
            ->where('extension_plugin_id', $context->plugin->id)
            ->where('enabled', true)
            ->orderBy('playlist_id')
            ->get()
            ->filter(fn (object $row): bool => $row->extension_plugin_profile_id !== null
                && Options::bool(Options::decodedArray($row->settings ?? null), [], $flag, true));
        if ($rows->isEmpty()) {
            return [];
        }

        $playlists = (new PlaylistResolver)->availableTo($context)
            ->whereKey($rows->pluck('playlist_id'))->get()->keyBy('id');
        $profiles = DB::table(self::PROFILES_TABLE)
            ->where('extension_plugin_id', $context->plugin->id)
            ->where('enabled', true)
            ->whereIn('id', $rows->pluck('extension_plugin_profile_id'))
            ->get()->keyBy('id');

        $runs = [];
        foreach ($rows as $row) {
            $playlist = $playlists->get((int) $row->playlist_id);
            $profile = $profiles->get((int) $row->extension_plugin_profile_id);
            if (! $playlist || ! $profile) {
                continue;
            }

            $runs[] = [
                'playlist' => $playlist,
                'payload' => [
                    ...$this->profilePayloadFromRow($profile),
                    ...$basePayload,
                    'playlist_id' => (int) $playlist->id,
                ],
            ];
        }

        return $runs;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    public function sourceRefreshPayloads(array $payload, PluginExecutionContext $context): array
    {
        $this->assertTables();

        return DB::table(self::PROFILES_TABLE)
            ->where('extension_plugin_id', $context->plugin->id)
            ->where('enabled', true)
            ->orderBy('name')
            ->get()
            ->filter(fn (object $row): bool => strtolower(Options::string(
                Options::decodedArray($row->settings ?? null),
                [],
                'source_mode',
                ProfileDefaults::SOURCE_MODE_SCRAPER_CACHE,
            )) === ProfileDefaults::SOURCE_MODE_SCRAPER_CACHE)
            ->map(fn (object $row): array => [
                ...$this->profilePayloadFromRow($row),
                ...$payload,
                'profile_id' => (int) $row->id,
                'profile_name' => (string) $row->name,
            ])
            ->values()
            ->all();
    }

    private function assignedProfilePayload(Playlist $playlist, PluginExecutionContext $context): ?array
    {
        $assignment = DB::table(self::ASSIGNMENTS_TABLE)
            ->where('extension_plugin_id', $context->plugin->id)
            ->where('playlist_id', $playlist->id)
            ->first();

        if (! $assignment) {
            return null;
        }

        if (! (bool) $assignment->enabled) {
            if ($assignment->extension_plugin_profile_id === null) {
                return null;
            }

            throw new \RuntimeException("GuideLink is disabled for playlist [{$playlist->name}].");
        }

        if ($assignment->extension_plugin_profile_id === null) {
            throw new \RuntimeException("Choose a GuideLink profile for playlist [{$playlist->name}] before running this action.");
        }

        return $this->profilePayload((int) $assignment->extension_plugin_profile_id, $context);
    }

    private function profilePayload(int $profileId, PluginExecutionContext $context): array
    {
        $row = DB::table(self::PROFILES_TABLE)
            ->where('extension_plugin_id', $context->plugin->id)
            ->where('id', $profileId)
            ->where('enabled', true)
            ->first();

        return $row ? $this->profilePayloadFromRow($row) : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function profilePayloadFromRow(object $row): array
    {
        return ProfileSettings::fromPayload(Options::decodedArray($row->settings ?? null));
    }

    private function assertTables(): void
    {
        if (! Schema::hasTable(self::PROFILES_TABLE) || ! Schema::hasTable(self::ASSIGNMENTS_TABLE)) {
            throw new \RuntimeException('Plugin profile tables are missing. Trust the plugin so m3u-editor can create its declared schema tables.');
        }
    }
}
