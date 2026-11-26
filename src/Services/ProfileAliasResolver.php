<?php

namespace AppLocalPlugins\GuideLink\Services;

use App\Models\Epg;
use App\Models\EpgChannel;
use App\Plugins\Support\PluginExecutionContext;
use App\Plugins\Support\PluginSelectOptionsContext;
use AppLocalPlugins\GuideLink\Support\CountryRulesRepository;
use Illuminate\Support\Facades\DB;

final class ProfileAliasResolver
{
    public const SETS = 'plugin_guidelink_alias_sets';

    public const ALIASES = 'plugin_guidelink_aliases';

    private ?array $resolutionInputs = null;

    private array $resolution = [];

    public function __construct(private readonly CountryRulesRepository $countryRules = new CountryRulesRepository) {}

    public function apply(array $entries, array $payload, PluginExecutionContext $context): array
    {
        $ids = AliasSetRepository::setIds($payload['alias_set_ids'] ?? []);
        if ($ids === []) {
            return $entries;
        }
        sort($ids);

        return DB::transaction(function () use ($entries, $payload, $context, $ids): array {
            $selected = DB::table(self::SETS)->where('extension_plugin_id', $context->plugin->id)
                ->whereIn('id', $ids)->get()->keyBy('id');
            $customIds = $selected->filter(fn (object $set): bool => $set->enabled && ! $set->bundle_id)->keys()->all();
            $aliasesBySet = array_fill_keys($customIds, []);
            foreach ((new AliasSetRepository)->rows($customIds, $context, enabledOnly: true) as $alias) {
                foreach ($alias['alias_set_ids'] as $setId) {
                    if (array_key_exists($setId, $aliasesBySet)) {
                        $aliasesBySet[$setId][] = $alias;
                    }
                }
            }
            $sets = [];
            $bundles = new BundledAliasService(countryRules: $this->countryRules);
            foreach ($ids as $id) {
                $set = $selected->get($id);
                if (! $set || ! $set->enabled) {
                    throw new \RuntimeException("Selected alias set [{$id}] is missing or disabled. Update Data → Profiles.");
                }
                if ($set->bundle_id) {
                    $bundle = $bundles->load($set->bundle_id);
                    if ($set->country !== $bundle['country']) {
                        throw new \RuntimeException("Alias set [{$set->name}] does not match its bundled country.");
                    }
                    $aliases = $bundle['entries'];
                } else {
                    $aliases = $aliasesBySet[$id];
                }
                $sets[] = [
                    'id' => (int) $set->id, 'name' => $set->name, 'country' => $set->country, 'aliases' => $aliases,
                    'guide_epg_id' => (int) $set->guide_epg_id, 'bundle_id' => $set->bundle_id,
                ];
            }
            $this->validateGuides($sets, $entries, $payload, $context, $bundles);
            $resolved = $this->resolveSets($entries, $payload, $sets);
            if ($resolved['unavailable_targets'] !== []) {
                $context->warning('Alias targets are unavailable in this profile. Their mappings were skipped; ordinary matching rules still apply.', [
                    'unavailable_alias_targets' => $resolved['unavailable_targets'],
                ]);
            }

            return $resolved['entries'];
        });
    }

    /**
     * Missing targets are reported separately; invalid or conflicting mappings fail.
     *
     * @return array{entries: array, unavailable_targets: array}
     */
    public function resolveSets(array $entries, array $payload, array $sets): array
    {
        $countries = $this->countryRules;
        $rules = $countries->resolve($payload, []);
        $inputs = [$entries, $rules, $sets];
        if ($this->resolutionInputs === $inputs) {
            return $this->resolution;
        }
        $owners = [];
        $targets = [];
        $unavailable = [];
        foreach ($entries as $i => $entry) {
            if ($entry['guide_valid']) {
                $targets[$entry['stream_id']][] = $i;
            }
            foreach ([$entry['name'], ...$entry['aliases'], ...array_keys($entry['profile_aliases'] ?? [])] as $name) {
                $owners[$countries->lookupKey($name, $rules)][$i] = true;
            }
        }
        foreach ($sets as $data) {
            $set = (object) $data;
            if ($set->country !== $rules['key']) {
                throw new \RuntimeException("Alias set [{$set->name}] does not match the profile country.");
            }
            $aliases = $set->aliases;
            foreach ($aliases as $row) {
                $names = $row['aliases'];
                if (! is_array($names) || ! array_is_list($names) || $names === []) {
                    throw new \RuntimeException("Alias set [{$set->name}] requires a nonempty list of names.");
                }
                foreach ($names as $name) {
                    if (! is_string($name) || trim($name) === '' || mb_strlen($name) > 255) {
                        throw new \RuntimeException("Alias set [{$set->name}] has an invalid name (maximum 255 characters).");
                    }
                }
                $target = $targets[$row['target_stream_id']] ?? [];
                if ($target === []) {
                    $unavailable[] = ['set_id' => (int) $set->id, 'set_name' => $set->name,
                        'target_stream_id' => $row['target_stream_id'], 'aliases' => $names];

                    continue;
                }
                if (count($target) !== 1) {
                    throw new \RuntimeException("Alias set [{$set->name}] target [{$row['target_stream_id']}] is ambiguous in this profile's source and guide.");
                }
                $i = $target[0];
                foreach ($names as $name) {
                    $key = $countries->normalize($name, $rules);
                    $compact = CountryRulesRepository::compactKey($key);
                    if ($key === '' || array_diff(array_keys($owners[$compact] ?? []), [$i]) !== []) {
                        throw new \RuntimeException("Alias [{$name}] is empty after normalization or conflicts with another channel. Check the selected alias sets and custom aliases.");
                    }
                    $owners[$compact][$i] = true;
                    $entries[$i]['profile_aliases'][$key][(int) $set->id] = $set->name;
                }
            }
        }

        $this->resolutionInputs = $inputs;

        return $this->resolution = ['entries' => $entries, 'unavailable_targets' => $unavailable];
    }

    /** Read each required guide once per playlist, before reusing any alias preparation. */
    private function validateGuides(array &$sets, array &$entries, array $payload, PluginExecutionContext $context, BundledAliasService $bundles): void
    {
        $guideId = (int) ($payload['guide_epg_id'] ?? 0);
        $available = array_fill_keys(array_column(array_filter($entries, fn (array $entry): bool => $entry['guide_valid']), 'stream_id'), true);
        $targetsByGuide = [];
        $referenceTargets = [];
        foreach ($sets as $set) {
            $ids = array_fill_keys(array_column($set['aliases'], 'target_stream_id'), true);
            if ($set['bundle_id']) {
                $targetsByGuide[$guideId] = ($targetsByGuide[$guideId] ?? []) + $ids;
            } elseif ($set['guide_epg_id'] !== $guideId) {
                $ids = array_intersect_key($ids, $available);
                if ($ids !== []) {
                    $referenceTargets[$set['id']] = array_keys($ids);
                    foreach ([$set['guide_epg_id'], $guideId] as $id) {
                        $targetsByGuide[$id] = ($targetsByGuide[$id] ?? []) + $ids;
                    }
                }
            }
        }
        if ($targetsByGuide === []) {
            return;
        }
        $guides = new GuideMapService;
        $names = [];
        foreach ($targetsByGuide as $id => $targets) {
            $names[$id] = $guides->targetNames($id, array_keys($targets), $context);
        }
        $rules = $this->countryRules->resolve($payload, []);
        $identities = [];
        foreach ($sets as &$set) {
            if ($set['bundle_id']) {
                // A stale optional alias is not a failure of the numbered guide.
                // Withhold it, preserving affected unresolved channels for review.
                foreach ($set['aliases'] as $index => $row) {
                    if (! array_key_exists($row['target_stream_id'], $names[$guideId])) {
                        continue;
                    }
                    try {
                        $bundles->validateTargets([$row], $names[$guideId], $payload);
                    } catch (\RuntimeException $exception) {
                        unset($set['aliases'][$index]);
                        foreach ($entries as &$entry) {
                            if (($entry['stream_id'] ?? '') === $row['target_stream_id']) {
                                $entry['withheld_aliases'] = [...($entry['withheld_aliases'] ?? []), ...$row['aliases']];
                            }
                        }
                        unset($entry);
                        $context->warning('Bundled alias identity changed; these aliases were withheld. Current source matching and native EPG mapping remain available.', [
                            'set_id' => $set['id'], 'target_stream_id' => $row['target_stream_id'],
                            'aliases' => $row['aliases'], 'reason' => $exception->getMessage(),
                        ]);
                    }
                }
                $set['aliases'] = array_values($set['aliases']);

                continue;
            }
            foreach ($referenceTargets[$set['id']] ?? [] as $targetId) {
                foreach ([$set['guide_epg_id'], $guideId] as $id) {
                    $identities[$id][$targetId] ??= array_map(
                        fn (string $name): string => $this->countryRules->lookupKey($name, $rules),
                        $names[$id][$targetId] ?? [],
                    );
                }
                if (array_intersect($identities[$set['guide_epg_id']][$targetId], $identities[$guideId][$targetId]) === []) {
                    throw new \RuntimeException("Alias set [{$set['name']}] target [{$targetId}] does not identify the same channel in its reference guide and this profile's guide. Choose a compatible set.");
                }
            }
        }
    }

    public static function customSetOptions(PluginSelectOptionsContext $context): array
    {
        if (! $context->user) {
            return [];
        }
        $guides = Epg::query()->select('id');
        if (! $context->user->isAdmin()) {
            $guides->where('user_id', $context->user->id);
        }

        return DB::table(self::SETS)->where('extension_plugin_id', $context->plugin->id)->where('enabled', true)
            ->where(fn ($query) => $query->whereNull('bundle_id')->orWhere('bundle_id', ''))
            ->whereIn('guide_epg_id', $guides)->orderBy('name')->pluck('name', 'id')->all();
    }

    public static function targetOptions(PluginSelectOptionsContext $context): array
    {
        $ids = AliasSetRepository::setIds($context->value('alias_set_ids', []) ?? []);
        if ($ids === [] || ! $context->user) {
            return [];
        }
        $sets = DB::table(self::SETS)->where('extension_plugin_id', $context->plugin->id)
            ->whereIn('id', $ids)->where(fn ($query) => $query->whereNull('bundle_id')->orWhere('bundle_id', ''))->get();
        if ($sets->count() !== count($ids) || $sets->pluck('country')->unique()->count() !== 1) {
            return [];
        }
        $countries = new CountryRulesRepository;
        $rules = $countries->resolve(['country' => $sets->first()->country], []);
        $options = null;
        $identities = [];
        foreach ($sets->pluck('guide_epg_id')->unique() as $guideId) {
            $epg = Epg::query()->whereKey($guideId);
            if (! $context->user->isAdmin()) {
                $epg->where('user_id', $context->user->id);
            }
            if (! $epg->exists()) {
                return [];
            }
            $channels = EpgChannel::query()->where('epg_id', $guideId)->orderBy('name')->get();
            $names = [];
            $labels = [];
            foreach ($channels as $channel) {
                $names[$channel->channel_id] = array_values(array_unique(array_map(
                    fn (string $name): string => $countries->lookupKey($name, $rules),
                    GuideMapService::identityNames($channel),
                )));
                $labels[$channel->channel_id] = trim($channel->display_name.' '.$channel->name).' ['.$channel->channel_id.']';
            }
            if ($options === null) {
                $options = $labels;
                $identities = $names;
            } else {
                foreach (array_keys($options) as $id) {
                    $identities[$id] = array_intersect($identities[$id], $names[$id] ?? []);
                    if ($identities[$id] === []) {
                        unset($options[$id]);
                    }
                }
            }
        }

        return $options ?? [];
    }
}
