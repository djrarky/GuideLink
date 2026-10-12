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

    public function apply(array $entries, array $payload, PluginExecutionContext $context): array
    {
        $ids = AliasSetRepository::setIds($payload['alias_set_ids'] ?? []);
        if ($ids === []) {
            return $entries;
        }
        sort($ids);

        return DB::transaction(function () use ($entries, $payload, $context, $ids): array {
            $sets = [];
            foreach ($ids as $id) {
                $set = DB::table(self::SETS)->where('extension_plugin_id', $context->plugin->id)
                    ->where('id', $id)->first();
                if (! $set || ! $set->enabled) {
                    throw new \RuntimeException("Selected alias set [{$id}] is missing or disabled. Update Data → Profiles.");
                }
                if ($set->bundle_id) {
                    $bundles = new BundledAliasService;
                    $bundle = $bundles->load($set->bundle_id);
                    if ($set->country !== $bundle['country']) {
                        throw new \RuntimeException("Alias set [{$set->name}] does not match its bundled country.");
                    }
                    $aliases = $bundle['entries'];
                    $guideNames = (new GuideMapService)->targetNames((int) $payload['guide_epg_id'], array_column($aliases, 'target_stream_id'), $context);
                    // Missing entries retain the ordinary warning/skip behavior below.
                    $present = array_values(array_filter($aliases, fn (array $row): bool => array_key_exists($row['target_stream_id'], $guideNames)));
                    $bundles->validateTargets($present, $guideNames, $payload);
                } else {
                    $aliases = $this->enabledAliases($id, $context);
                    $this->validateReferenceGuide($set, $aliases, $entries, $payload, $context);
                }
                $sets[] = ['id' => (int) $set->id, 'name' => $set->name, 'country' => $set->country, 'aliases' => $aliases];
            }
            $resolved = $this->resolveSets($entries, $payload, $sets);
            if ($resolved['unavailable_targets'] !== []) {
                $context->warning('Alias targets are unavailable in this profile. Their mappings were skipped; ordinary matching rules still apply.', [
                    'unavailable_alias_targets' => $resolved['unavailable_targets'],
                ]);
            }

            return $resolved['entries'];
        });
    }

    /** Load enabled custom aliases; bundled sets read their JSON directly. */
    public function enabledAliases(int $setId, PluginExecutionContext $context): array
    {
        return array_values(array_filter((new AliasSetRepository)->rows($setId, $context), fn (array $row): bool => $row['enabled']));
    }

    /**
     * Missing targets are reported separately; invalid or conflicting mappings fail.
     *
     * @return array{entries: array, unavailable_targets: array}
     */
    public function resolveSets(array $entries, array $payload, array $sets): array
    {
        $countries = new CountryRulesRepository;
        $rules = $countries->resolve($payload, []);
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

        return ['entries' => $entries, 'unavailable_targets' => $unavailable];
    }

    /** IDs shared by two guides must still describe the same service. */
    private function validateReferenceGuide(object $set, array $aliases, array $entries, array $payload, PluginExecutionContext $context): void
    {
        $guideId = (int) ($payload['guide_epg_id'] ?? 0);
        if ((int) $set->guide_epg_id === $guideId) {
            return;
        }
        $available = array_column(array_filter($entries, fn (array $entry): bool => $entry['guide_valid']), 'stream_id');
        $ids = array_values(array_intersect(array_column($aliases, 'target_stream_id'), $available));
        if ($ids === []) {
            return;
        }
        $guides = new GuideMapService;
        $reference = $guides->targetNames((int) $set->guide_epg_id, $ids, $context);
        $selected = $guides->targetNames($guideId, $ids, $context);
        $countries = new CountryRulesRepository;
        $rules = $countries->resolve($payload, []);
        $keys = fn (array $names): array => array_map(fn (string $name): string => $countries->lookupKey($name, $rules), $names);
        foreach ($ids as $id) {
            if (array_intersect($keys($reference[$id] ?? []), $keys($selected[$id] ?? [])) === []) {
                throw new \RuntimeException("Alias set [{$set->name}] target [{$id}] does not identify the same channel in its reference guide and this profile's guide. Choose a compatible set.");
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
