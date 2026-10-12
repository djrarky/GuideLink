<?php

namespace AppLocalPlugins\GuideLink\Services;

use AppLocalPlugins\GuideLink\Support\CountryRulesRepository;
use AppLocalPlugins\GuideLink\Support\JsonFile;

class BundledAliasService
{
    private const BUNDLE_IDS = ['sky-uk'];

    public function __construct(private readonly string $directory = __DIR__.'/../AliasSets') {}

    public function options(): array
    {
        $options = [];
        foreach (self::BUNDLE_IDS as $id) {
            $options[$id] = $this->load($id)['name'];
        }

        return $options;
    }

    public function load(string $id): array
    {
        if (! in_array($id, self::BUNDLE_IDS, true)) {
            throw new \RuntimeException('Unknown bundled alias set.');
        }

        return JsonFile::read($this->directory.'/'.$id.'.json', 'Bundled alias set');
    }

    /** Check actual guide names against the bundled target identities. */
    public function validateTargets(array $rows, array $guideNames, array $payload): void
    {
        $countries = new CountryRulesRepository;
        $rules = $countries->resolve($payload, []);
        $keys = fn (array $names): array => array_map(fn (string $name): string => $countries->lookupKey($name, $rules), $names);
        foreach ($rows as $row) {
            $id = $row['target_stream_id'];
            $actual = $guideNames[$id] ?? [];
            $expected = [$row['target_name'], ...($row['guide_names'] ?? [])];
            if (array_intersect($keys($expected), $keys($actual)) === []) {
                throw new \RuntimeException("Alias target [{$id}] does not identify [{$row['target_name']}] in the selected guide. Found: ".($actual === [] ? 'no named guide entry' : implode(', ', $actual)).'. Choose a compatible guide for this bundled set.');
            }
        }
    }
}
