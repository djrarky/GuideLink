<?php

namespace AppLocalPlugins\GuideLink\Support;

final class ProfileSettings
{
    /**
     * @var array<int, string>
     */
    private const REPORT_BOOL_KEYS = [
        'include_scrubber_logs',
        'include_managed_groups',
        'disable_other_groups',
    ];

    /**
     * @var array<int, string>
     */
    private const NATIVE_BOOL_KEYS = [
        'auto_merge_deactivate_failover',
        'auto_merge_new_channels_only',
        'auto_merge_force_complete_remerge',
        'auto_merge_check_resolution',
        'auto_merge_prefer_catchup_as_primary',
        'auto_merge_exclude_disabled_groups',
        'auto_merge_scrubber_aware_master_selection',
        'scrubber_recurring',
        'scrubber_scan_all',
        'scrubber_disable_dead',
        'scrubber_rebuild_failovers_after_scan',
    ];

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function fromPayload(array $payload): array
    {
        return [
            'guide_epg_id' => Options::nullableInt($payload['guide_epg_id'] ?? null),
            ...SourceSettings::profileCache($payload),
            'source_mode' => strtolower(self::stringDefault($payload, 'source_mode')),
            'source_playlist_id' => Options::nullableInt($payload['source_playlist_id'] ?? null),
            'alias_set_ids' => $payload['alias_set_ids'] ?? [],
            ...self::boolSettings($payload, self::REPORT_BOOL_KEYS),
            'repair_mode' => self::stringDefault($payload, 'repair_mode'),
            'include_group_prefixes' => Options::list($payload, [], 'include_group_prefixes', ProfileDefaults::list('include_group_prefixes')),
            'exclude_group_regex' => Options::list($payload, [], 'exclude_group_regex', ProfileDefaults::list('exclude_group_regex')),
            'preserve_groups' => Options::list($payload, [], 'preserve_groups', ProfileDefaults::list('preserve_groups')),
            'preserve_group_name_regex' => Options::list($payload, [], 'preserve_group_name_regex', ProfileDefaults::list('preserve_group_name_regex')),
            'preserve_channel_title_regex' => Options::list($payload, [], 'preserve_channel_title_regex', ProfileDefaults::list('preserve_channel_title_regex')),
            'auto_merge_preferred_playlist_id' => Options::nullableInt($payload['auto_merge_preferred_playlist_id'] ?? null),
            'scrubber_id' => Options::nullableInt($payload['scrubber_id'] ?? null),
            'auto_merge_failover_playlist_ids' => Options::list($payload, [], 'auto_merge_failover_playlist_ids', ProfileDefaults::list('auto_merge_failover_playlist_ids')),
            ...self::nativeOptions($payload),
            ...self::matching($payload),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function nativeOptions(array $payload): array
    {
        $mergeKey = self::stringDefault($payload, 'auto_merge_merge_key');
        if (! in_array($mergeKey, ['stream_id', 'tmdb_id'], true)) {
            $mergeKey = ProfileDefaults::string('auto_merge_merge_key');
        }

        $checkMethod = self::stringDefault($payload, 'scrubber_check_method');
        if (! in_array($checkMethod, ['http', 'ffprobe'], true)) {
            $checkMethod = ProfileDefaults::string('scrubber_check_method');
        }

        return [
            'auto_merge_merge_key' => $mergeKey,
            'scrubber_check_method' => $checkMethod,
            'scrubber_probe_timeout' => max(3, min(30, Options::number($payload, [], 'scrubber_probe_timeout', ProfileDefaults::int('scrubber_probe_timeout')))),
            ...self::boolSettings($payload, self::NATIVE_BOOL_KEYS),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $keys
     * @return array<string, bool>
     */
    private static function boolSettings(array $payload, array $keys): array
    {
        $settings = [];
        foreach ($keys as $key) {
            $settings[$key] = Options::bool($payload, [], $key, ProfileDefaults::bool($key));
        }

        return $settings;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, float>
     */
    private static function matching(array $payload): array
    {
        $settings = [];
        foreach (ProfileDefaults::matchingKeys() as $key) {
            $settings[$key] = Options::float($payload, [], $key, ProfileDefaults::float($key));
        }

        return $settings;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function stringDefault(array $payload, string $key): string
    {
        $value = Options::string($payload, [], $key, ProfileDefaults::string($key));

        return $value !== '' ? $value : ProfileDefaults::string($key);
    }
}
