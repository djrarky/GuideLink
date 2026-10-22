<?php

namespace AppLocalPlugins\GuideLink\Sources;

use AppLocalPlugins\GuideLink\Support\JsonFile;
use Illuminate\Container\Attributes\Scoped;

#[Scoped]
final class CountrySourceSupport
{
    private ?array $countryCache = null;

    private ?array $packCache = null;

    /** @return array<string, array{key: string, name: string, path: string}> */
    public static function countries(): array
    {
        return function_exists('app')
            ? (app(self::class)->countryCache ??= self::loadCountries())
            : self::loadCountries();
    }

    private static function loadCountries(): array
    {
        $countries = [];
        foreach (glob(__DIR__.'/Countries/*', GLOB_ONLYDIR) ?: [] as $directory) {
            $data = JsonFile::read($directory.'/country.json', 'Country metadata');
            $key = strtolower((string) ($data['key'] ?? basename($directory)));
            $countries[$key] = [
                'key' => $key,
                'name' => (string) ($data['name'] ?? strtoupper($key)),
                'path' => $directory,
            ];
        }
        ksort($countries);

        return $countries;
    }

    /**
     * @return array<int, array{country: string, provider: string, label: string, sources: array<int, string>, path: string}>
     */
    public static function packs(): array
    {
        return function_exists('app')
            ? (app(self::class)->packCache ??= self::loadPacks())
            : self::loadPacks();
    }

    private static function loadPacks(): array
    {
        $packs = [];
        foreach (self::countries() as $country) {
            foreach (glob($country['path'].'/*/provider.json') ?: [] as $path) {
                $data = JsonFile::read($path, 'Country source support file');
                $provider = strtolower((string) ($data['provider'] ?? ''));

                $sources = array_values(array_unique(array_map(
                    fn (mixed $source): string => strtolower((string) $source),
                    is_array($data['sources'] ?? null) ? $data['sources'] : [],
                )));

                $packs[] = [
                    'country' => $country['key'],
                    'provider' => $provider,
                    'label' => (string) ($data['label'] ?? strtoupper($country['key']).' '.ucfirst($provider)),
                    'sources' => $sources,
                    'path' => $path,
                ];
            }
        }

        usort(
            $packs,
            fn (array $left, array $right): int => [$left['provider'], $left['country'], $left['path']]
                <=> [$right['provider'], $right['country'], $right['path']],
        );

        return $packs;
    }

    /**
     * @return array<int, string>
     */
    public static function supportedCountries(string $provider, ?string $source = null): array
    {
        $provider = strtolower($provider);
        $source = $source === null ? null : strtolower($source);
        $countries = [];

        foreach (self::packs() as $pack) {
            if ($pack['provider'] === $provider && ($source === null || in_array($source, $pack['sources'], true))) {
                $countries[] = $pack['country'];
            }
        }

        return array_values(array_unique($countries));
    }
}
