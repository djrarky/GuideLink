<?php

namespace AppLocalPlugins\GuideLink\Support;

final class ProfileDefaults
{
    public const SOURCE_MODE_SCRAPER_CACHE = 'scraper_cache';

    public const SOURCE_MODE_NATIVE_PLAYLIST = 'native_playlist';

    private const DEFINITIONS = [
        'country' => ['default' => 'uk', 'manifest' => true],
        'provider' => ['default' => 'sky', 'manifest' => true],
        'provider_source' => ['default' => 'viewersvault', 'manifest' => true],
        'region' => ['default' => '1', 'manifest' => true],
        'source_mode' => ['default' => self::SOURCE_MODE_SCRAPER_CACHE, 'manifest' => true],
        'scraping_api' => ['default' => 'direct_http', 'manifest' => true],
        'scraping_api_key' => ['default' => ''],
        'scraping_timeout_seconds' => ['default' => 60, 'manifest' => true],
        'source_cache_days' => ['default' => 2, 'manifest' => true],
        'max_retries' => ['default' => 3, 'manifest' => true],
        'min_channels' => ['default' => 100, 'manifest' => true],
        'max_drop_percent' => ['default' => 20, 'manifest' => true],
        'include_scrubber_logs' => ['default' => true, 'manifest' => true],
        'include_managed_groups' => ['default' => true, 'manifest' => true],
        'disable_other_groups' => ['default' => false, 'manifest' => true],
        'repair_mode' => ['default' => 'report_only'],
        'include_group_prefixes' => ['default' => [], 'manifest' => true],
        'exclude_group_regex' => ['default' => []],
        'preserve_groups' => ['default' => []],
        'preserve_group_name_regex' => ['default' => []],
        'preserve_channel_title_regex' => ['default' => []],
        'fuzzy_threshold' => ['default' => 90, 'manifest' => true, 'matching' => true],
        'min_auto_score' => ['default' => 90, 'manifest' => true, 'matching' => true],
        'min_auto_margin' => ['default' => 12, 'manifest' => true, 'matching' => true],
        'sort_floor_offset' => ['default' => 10, 'manifest' => true, 'matching' => true],
        'min_alias_words' => ['default' => 2, 'manifest' => true, 'matching' => true],
        'min_alias_chars' => ['default' => 4, 'manifest' => true, 'matching' => true],
        'min_set_tokens' => ['default' => 2, 'manifest' => true, 'matching' => true],
        'min_ratio_len_ratio' => ['default' => 0.7, 'manifest' => true, 'matching' => true],
        'min_ratio_score' => ['default' => 90, 'manifest' => true, 'matching' => true],
        'min_partial_len_ratio' => ['default' => 0.65, 'manifest' => true, 'matching' => true],
        'min_partial_len' => ['default' => 5, 'manifest' => true, 'matching' => true],
        'min_partial_ratio_score' => ['default' => 80, 'manifest' => true, 'matching' => true],
        'max_short_token_len' => ['default' => 2, 'manifest' => true, 'matching' => true],
        'min_token_similarity' => ['default' => 60, 'manifest' => true, 'matching' => true],
        'auto_merge_failover_playlist_ids' => ['default' => [], 'manifest' => true],
        'auto_merge_deactivate_failover' => ['default' => true, 'manifest' => true],
        'auto_merge_new_channels_only' => ['default' => false, 'manifest' => true],
        'auto_merge_force_complete_remerge' => ['default' => true, 'manifest' => true],
        'auto_merge_merge_key' => ['default' => 'stream_id', 'manifest' => true],
        'auto_merge_check_resolution' => ['default' => true, 'manifest' => true],
        'auto_merge_prefer_catchup_as_primary' => ['default' => true, 'manifest' => true],
        'auto_merge_exclude_disabled_groups' => ['default' => true, 'manifest' => true],
        'auto_merge_scrubber_aware_master_selection' => ['default' => true, 'manifest' => true],
        'scrubber_recurring' => ['default' => true, 'manifest' => true],
        'scrubber_scan_all' => ['default' => true, 'manifest' => true],
        'scrubber_disable_dead' => ['default' => true, 'manifest' => true],
        'scrubber_enable_live' => ['default' => false],
        'scrubber_protect_failover_channels' => ['default' => true],
        'scrubber_rebuild_failovers_after_scan' => ['default' => true, 'manifest' => true],
        'scrubber_check_method' => ['default' => 'http', 'manifest' => true],
        'scrubber_probe_timeout' => ['default' => 10, 'manifest' => true],
        'scrubber_include_vod' => ['default' => false],
    ];

    /**
     * @return array<string, mixed>
     */
    public static function manifestDefaults(): array
    {
        return self::defaultsWhere('manifest');
    }

    /**
     * @return array<int, string>
     */
    public static function matchingKeys(): array
    {
        return array_keys(self::defaultsWhere('matching'));
    }

    public static function value(string $key): mixed
    {
        if (! array_key_exists($key, self::DEFINITIONS)) {
            throw new \InvalidArgumentException("Unknown GuideLink profile default [{$key}].");
        }

        return self::DEFINITIONS[$key]['default'];
    }

    public static function string(string $key): string
    {
        return (string) self::value($key);
    }

    public static function int(string $key): int
    {
        return (int) self::value($key);
    }

    public static function float(string $key): float
    {
        return (float) self::value($key);
    }

    public static function bool(string $key): bool
    {
        return (bool) self::value($key);
    }

    /**
     * @return array<int, string>
     */
    public static function list(string $key): array
    {
        $value = self::value($key);

        if (! is_array($value)) {
            throw new \InvalidArgumentException("GuideLink profile default [{$key}] is not a list.");
        }

        return array_values(array_map(fn (mixed $item): string => (string) $item, $value));
    }

    /**
     * @return array<string, mixed>
     */
    private static function defaultsWhere(string $flag): array
    {
        $defaults = [];
        foreach (self::DEFINITIONS as $key => $definition) {
            if (($definition[$flag] ?? false) !== true) {
                continue;
            }

            $defaults[$key] = $definition['default'];
        }

        return $defaults;
    }
}
