<?php

namespace AppLocalPlugins\GuideLink\Services;

use App\Models\Channel;
use App\Plugins\Support\PluginExecutionContext;
use AppLocalPlugins\GuideLink\Support\ChannelAttributes;
use AppLocalPlugins\GuideLink\Support\CountryRulesRepository;
use AppLocalPlugins\GuideLink\Support\Options;
use AppLocalPlugins\GuideLink\Support\ProfileDefaults;

/**
 * Build matching input from a validated source and the current guide/profile.
 * entries() supplies stream_id and guide_valid; matching helpers also accept
 * the core source fields alone. EPG-only services use the managed Other group;
 * empty native-source categories preserve existing groups.
 * profile_aliases maps normalized names to alias-set IDs and display names.
 *
 * @phpstan-import-type SourceSnapshot from SourceCacheService
 *
 * @phpstan-type SourceEntry array{
 *     number: int, name: string, category: string, aliases: array<int, string>,
 *     stream_id?: string, guide_valid?: bool, guide_only?: bool,
 *     profile_aliases?: array<string, array<int, string>>
 * }
 * @phpstan-type PreparedSourceEntry SourceEntry&array{stream_id: string, guide_valid: bool}
 */
class ParsedSourceLoader
{
    private readonly ProfileAliasResolver $profileAliases;

    private ?array $guideAliasInputs = null;

    private array $guideAliasEntries = [];

    public function __construct(
        private readonly SourceCacheService $cache,
        private readonly CountryRulesRepository $countryRules = new CountryRulesRepository,
    ) {
        $this->profileAliases = new ProfileAliasResolver($countryRules);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $guideMap
     * @param  array<int, array<int, string>>  $guideAliases
     * @param  SourceSnapshot|null  $sourceSnapshot  Already validated for this playlist's source configuration.
     * @return list<PreparedSourceEntry>
     */
    public function entries(array $payload, PluginExecutionContext $context, array $guideMap, array $guideAliases = [], ?array $sourceSnapshot = null): array
    {
        $sourceMode = strtolower(Options::string($payload, [], 'source_mode', ProfileDefaults::SOURCE_MODE_SCRAPER_CACHE));
        if ($sourceMode === ProfileDefaults::SOURCE_MODE_NATIVE_PLAYLIST) {
            return $this->profileAliases->apply($this->withGuideAliases($this->nativePlaylistEntries($payload, $context, $guideMap), $guideAliases, $payload), $payload, $context);
        }

        if ($sourceMode !== ProfileDefaults::SOURCE_MODE_SCRAPER_CACHE) {
            throw new \RuntimeException("Unsupported source mode [{$sourceMode}].");
        }

        $cache = $sourceSnapshot ?? $this->cache->readLastGood($this->cache->config($payload, []));
        $entries = [];
        $missingGuide = 0;

        foreach ($cache['entries'] as $entry) {
            if (! is_array($entry) || ! isset($entry['number'], $entry['name'], $entry['category'])) {
                continue;
            }

            $number = (int) $entry['number'];
            $guideValid = isset($guideMap[$number]);
            $missingGuide += $guideValid ? 0 : 1;

            $entries[] = [
                'number' => $number,
                'name' => (string) $entry['name'],
                'category' => (string) $entry['category'],
                'aliases' => array_values(array_map(
                    fn (mixed $alias): string => (string) $alias,
                    is_array($entry['aliases'] ?? null) ? $entry['aliases'] : [],
                )),
                'stream_id' => $guideMap[$number] ?? (string) ($entry['stream_id'] ?? ''),
                'guide_valid' => $guideValid,
            ];
        }

        if ($missingGuide > 0) {
            $context->warning('Cached source rows are missing guide numbers.', [
                'missing_guide_numbers' => $missingGuide,
                'source_cache_path' => $cache['path'],
            ]);
        }

        $context->info('Loaded canonical source from last-good scraper cache.', [
            'source_cache_path' => $cache['path'],
            'source_total' => count($entries),
            'source_fetched_at' => $cache['metadata']['fetched_at'] ?? null,
        ]);

        return $this->profileAliases->apply($this->withGuideAliases($entries, $guideAliases, $payload, $guideMap), $payload, $context);
    }

    /**
     * Use names from the validated numbered guide instead of maintained name
     * rewrites. Ambiguous guide names cannot become automatic aliases.
     *
     * @param  list<PreparedSourceEntry>  $entries
     * @param  array<int, array<int, string>>  $guideAliases
     * @param  array<string, mixed>  $payload
     * @return list<PreparedSourceEntry>
     */
    private function withGuideAliases(array $entries, array $guideAliases, array $payload, array $guideMap = []): array
    {
        if ($guideAliases === []) {
            return $entries;
        }

        $countries = $this->countryRules;
        $rules = $countries->resolve($payload, []);
        $inputs = [$entries, $guideAliases, $rules, $guideMap];
        if ($this->guideAliasInputs === $inputs) {
            return $this->guideAliasEntries;
        }
        $keyFor = fn (string $name): string => $countries->lookupKey($name, $rules);
        $owners = [];
        $sourceNumbers = array_fill_keys(array_column($entries, 'number'), true);
        foreach ($entries as $entry) {
            foreach ([$entry['name'], ...$entry['aliases']] as $name) {
                $owners[$keyFor($name)][$entry['number']] = true;
            }
        }
        foreach ($guideAliases as $number => $names) {
            if (! isset($sourceNumbers[$number])) {
                continue;
            }
            foreach ($names as $name) {
                $owners[$keyFor($name)][$number] = true;
            }
        }
        foreach ($entries as &$entry) {
            foreach ($guideAliases[$entry['number']] ?? [] as $name) {
                $key = $keyFor($name);
                if ($key !== '' && count($owners[$key]) === 1) {
                    $entry['aliases'][] = $name;
                }
            }
            $entry['aliases'] = array_values(array_unique($entry['aliases']));
        }

        unset($entry);
        foreach ($guideAliases as $number => $names) {
            if (isset($sourceNumbers[$number]) || ! isset($guideMap[$number]) || $names === []) {
                continue;
            }
            foreach ($names as $name) {
                if (isset($owners[$keyFor($name)])) {
                    continue 2; // Duplicate guide slot for an existing service.
                }
            }
            $entries[] = [
                'number' => $number, 'name' => $names[0], 'category' => 'Other',
                'aliases' => $names, 'stream_id' => $guideMap[$number],
                'guide_valid' => true, 'guide_only' => true,
            ];
        }

        $this->guideAliasInputs = $inputs;

        return $this->guideAliasEntries = $entries;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $guideMap
     * @return list<PreparedSourceEntry>
     */
    private function nativePlaylistEntries(array $payload, PluginExecutionContext $context, array $guideMap): array
    {
        $sourcePlaylistId = Options::nullableInt($payload['source_playlist_id'] ?? null);
        if ($sourcePlaylistId === null) {
            throw new \RuntimeException('Native playlist source mode requires a source playlist.');
        }

        $playlist = (new PlaylistResolver)->findAvailable($sourcePlaylistId, $context, 'Source playlist');
        $channels = Channel::query()
            ->where('playlist_id', $playlist->id)
            ->where('is_vod', false)
            ->where('enabled', true)
            ->with('group:id,name')
            ->orderBy('channel')
            ->orderBy('sort')
            ->orderBy('name')
            ->orderBy('id')
            ->get([
                'id', 'channel', 'group_id', 'group_internal', 'group', 'stream_id', 'stream_id_custom',
                ...ChannelAttributes::NAME_FIELDS,
            ]);

        $countries = $this->countryRules;
        $rules = $countries->resolve($payload, []);
        $entries = [];
        $duplicateNumbers = 0;
        $missingNumbers = 0;
        $missingGuide = 0;

        foreach ($channels as $channel) {
            $number = Options::nullableInt($channel->channel);
            if ($number === null || $number <= 0 || (float) $channel->channel !== (float) $number) {
                $missingNumbers++;

                continue;
            }

            if (isset($entries[$number])) {
                if ($countries->lookupKey($entries[$number]['name'], $rules) !== $countries->lookupKey(ChannelAttributes::title($channel), $rules)) {
                    throw new \RuntimeException("Native source playlist has conflicting channels at number [{$number}]. Resolve the duplicate number before syncing.");
                }
                $entries[$number]['aliases'] = array_values(array_unique([
                    ...$entries[$number]['aliases'], ChannelAttributes::title($channel), ...$this->aliases($channel),
                ]));
                $duplicateNumbers++;

                continue;
            }

            $guideValid = isset($guideMap[$number]);
            $missingGuide += $guideValid ? 0 : 1;

            $entries[$number] = [
                'number' => $number,
                'name' => ChannelAttributes::title($channel),
                'category' => ChannelAttributes::groupName($channel),
                'aliases' => $this->aliases($channel),
                'stream_id' => $guideMap[$number] ?? ChannelAttributes::streamId($channel),
                'guide_valid' => $guideValid,
            ];
        }

        if ($missingNumbers > 0 || $duplicateNumbers > 0) {
            $context->warning('Native source playlist omitted invalid numbers and combined equivalent duplicates.', [
                'source_playlist_id' => (int) $playlist->id,
                'missing_channel_numbers' => $missingNumbers,
                'duplicate_channel_numbers' => $duplicateNumbers,
            ]);
        }

        if ($missingGuide > 0) {
            $context->warning('Native source rows are missing guide numbers.', [
                'missing_guide_numbers' => $missingGuide,
                'source_playlist_id' => (int) $playlist->id,
            ]);
        }

        $context->info('Loaded canonical source from native playlist.', [
            'source_playlist_id' => (int) $playlist->id,
            'source_playlist_name' => (string) $playlist->name,
            'source_total' => count($entries),
        ]);

        return array_values($entries);
    }

    /**
     * @return array<int, string>
     */
    private function aliases(Channel $channel): array
    {
        $name = ChannelAttributes::title($channel);
        $values = [
            $channel->title,
            $channel->title_custom,
            $channel->name,
            $channel->name_custom,
        ];

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $value): string => trim((string) $value),
            $values,
        ), fn (string $value): bool => $value !== '' && $value !== $name)));
    }
}
