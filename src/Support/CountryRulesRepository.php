<?php

namespace AppLocalPlugins\GuideLink\Support;

use AppLocalPlugins\GuideLink\Sources\CountrySourceSupport;

/**
 * JSON-backed equivalent of countries/__init__.py.
 *
 * Country packs live under src/Sources/Countries/<Country>/ so source-specific
 * country data can grow next to provider/source code without one huge rules file.
 */
class CountryRulesRepository
{
    private const UNIVERSAL_NOISE_TOKENS = [
        'HD',
        'SD',
        'UHD',
        '4K',
        'HEVC',
    ];

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $cache = [];

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_column(CountrySourceSupport::countries(), 'name', 'key');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function resolve(array $payload, array $settings): array
    {
        $country = SourceSettings::identity($payload, $settings)['country'];

        if (isset($this->cache[$country])) {
            return $this->cache[$country];
        }

        $data = self::data($country);

        return $this->cache[$country] = $this->parse($country, $data);
    }

    /**
     * @return array<string, mixed>
     */
    private static function data(string $country): array
    {
        $directory = self::directory($country);
        $data = [];

        foreach (['country', 'normalization', 'regions', 'filters'] as $file) {
            $path = "{$directory}/{$file}.json";
            if (! is_file($path)) {
                continue;
            }

            $data = array_replace_recursive($data, JsonFile::read($path, "Country pack [{$country}] {$file} rules"));
        }

        if ($data === []) {
            throw new \RuntimeException("Country rules [{$country}] were not found for GuideLink.");
        }

        $data['key'] = strtolower((string) ($data['key'] ?? basename($directory)));

        return $data;
    }

    private static function directory(string $country): string
    {
        foreach (CountrySourceSupport::countries() as $key => $metadata) {
            if ($key === $country || strtolower(basename($metadata['path'])) === $country) {
                return $metadata['path'];
            }
        }

        throw new \RuntimeException("Country rules [{$country}] were not found for GuideLink.");
    }

    /**
     * @param  array<string, mixed>|null  $rules
     */
    public function normalize(string $value, ?array $rules = null): string
    {
        $rules ??= $this->resolve([], []);
        if (class_exists(\Normalizer::class)) {
            $value = \Normalizer::normalize($value, \Normalizer::FORM_C) ?: $value;
        }
        // Guide labels often append quality to a mixed-case name.
        $value = preg_replace('/(?<=\p{Ll})(?:UHD|HD|SD|HEVC)\b/u', ' ', $value) ?? $value;
        $value = mb_strtoupper($value, 'UTF-8');
        // Read frame-rate metadata before stripping decorative Unicode digits,
        // so "SPORTS 3 ⁵⁰ FPS" cannot lose its channel number.
        $value = preg_replace('/(?<![\p{L}\p{N}])\p{N}+(?:\.\p{N}+)?\s*FPS\b/iu', ' ', $value) ?? $value;
        // Raised Latin modifier letters are provider decoration (such as ᴴᴰ).
        // Other scripts use modifier letters and combining marks in real names.
        $value = preg_replace('/(?=\p{Lm})\p{Latin}/u', '', $value) ?? $value;
        // Keep letters in channel names; only discard decorative Unicode.
        // Dropping non-Latin words can turn unrelated names into short matches.
        $value = preg_replace('/[^\x00-\x7F\s\p{L}\p{M}\p{Nd}]/u', '', $value) ?? $value;
        $value = str_replace(['.', '!'], '', $value);
        $value = preg_replace('/^(?:\s*[\p{L}\p{Nd}][\p{L}\p{M}\p{Nd}&+._-]*\s*[:;]\s*)+/u', '', $value) ?? $value;
        $value = ltrim($value, " \t\n\r:;");
        $value = preg_replace_callback(
            $rules['number_pattern'] ?? '~(?!)~',
            fn (array $match): string => $rules['number_words'][$match[0]],
            $value,
        ) ?? $value;

        // Formatting metadata is not part of a channel's identity. Preserve
        // the timeshift number while accepting provider spacing variations.
        $value = preg_replace('/\s*\+\s*(\d+)\b/', ' +$1', $value) ?? $value;

        $value = preg_replace($rules['noise_pattern'] ?? self::noisePattern($rules['noise_tokens'] ?? []), ' ', $value) ?? $value;
        $value = trim((string) preg_replace('/\s+/', ' ', $value));
        $value = $this->substitute($value, $rules['canonical_subs'] ?? []);

        $value = self::normalizeSeparators($value, $rules);
        if (! empty($rules['region_pattern'])) {
            $value = preg_replace_callback(
                $rules['region_pattern'],
                fn (array $match): string => $rules['region_replacements'][$match[0]],
                $value,
            ) ?? $value;
        }

        $value = trim((string) preg_replace('/\s+/', ' ', $value));

        return $value;
    }

    /** Build a spacing-insensitive key from an unnormalized channel name. */
    public function lookupKey(string $value, array $rules): string
    {
        return self::compactKey($this->normalize($value, $rules));
    }

    /** Compact an already normalized name without applying name rules twice. */
    public static function compactKey(string $normalized): string
    {
        return str_replace(' ', '', $normalized);
    }

    /** @param array<int, mixed> $substitutions */
    public function substitute(string $value, array $substitutions, string $flags = 'iu'): string
    {
        foreach ($substitutions as $substitution) {
            if (! is_array($substitution) || empty($substitution['pattern'])) {
                continue;
            }
            $value = preg_replace(
                $this->regex((string) $substitution['pattern'], $flags),
                (string) ($substitution['replace'] ?? ''),
                $value,
            ) ?? $value;
        }

        return $value;
    }

    public static function normalizeSeparators(string $value, array $rules = []): string
    {
        $value = str_replace(['/', '|', '-'], ' ', $value);
        $value = preg_replace('/\s*&\s*|(?<=\p{L})\+(?=\p{L})/u', ' ', $value) ?? $value;
        $value = preg_replace($rules['separator_pattern'] ?? '~(?!)~', ' ', $value) ?? $value;

        return trim((string) preg_replace('/\s+/', ' ', $value));
    }

    /**
     * @param  array<int, string>  $prefixes
     */
    public function startsWithAny(string $value, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if ($prefix !== '' && str_starts_with($value, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $patterns
     */
    public function matchesAny(string $value, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($pattern !== '' && @preg_match($this->regex($pattern, 'iu'), $value) === 1) {
                return true;
            }
        }

        return false;
    }

    public function regex(string $pattern, string $flags = ''): string
    {
        return '~'.str_replace('~', '\~', $pattern).'~'.$flags;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function parse(string $country, array $data): array
    {
        $numberWords = [];
        foreach ($data['number_words'] ?? [] as $word => $number) {
            $numberWords[mb_strtoupper((string) $word, 'UTF-8')] = (string) $number;
        }
        $language = [
            'number_words' => $numberWords,
            'number_pattern' => self::wordPattern(array_keys($numberWords)),
            'separator_pattern' => self::wordPattern($data['separator_words'] ?? []),
            'label_pattern' => self::wordPattern($data['channel_labels'] ?? []),
            'presentation_pattern' => '~^(.+ .+) '.self::alternatives($data['presentation_suffixes'] ?? []).'$~u',
            'direction_pattern' => '~^('.self::alternatives($data['region_directions'] ?? []).') (.+)$~u',
            'plural_suffixes' => array_map(fn (string $suffix): string => mb_strtoupper($suffix, 'UTF-8'), $data['plural_suffixes'] ?? []),
        ];
        $regionAbbreviations = [];
        $regionReplacements = [];
        foreach (($data['region_abbrevs'] ?? []) as $region => $abbreviations) {
            if (! is_array($abbreviations)) {
                continue;
            }

            $regionAbbreviations[(string) $region] = array_values(array_map(
                fn (mixed $abbreviation): string => (string) $abbreviation,
                $abbreviations,
            ));
            $canonical = self::normalizeSeparators(mb_strtoupper(str_replace('_', ' ', (string) $region), 'UTF-8'), $language);
            foreach ([$canonical, ...$abbreviations] as $abbreviation) {
                $key = self::normalizeSeparators(mb_strtoupper((string) $abbreviation, 'UTF-8'), $language);
                if ($key !== '') {
                    $regionReplacements[$key] = $canonical;
                }
            }
        }
        // Match complete regional phrases first, and never rewrite a replacement.
        // Canonical names also protect longer regions from shorter aliases.
        uksort($regionReplacements, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        $rules = [
            ...$language,
            'name' => (string) ($data['name'] ?? strtoupper($country)),
            'key' => strtolower((string) ($data['key'] ?? $country)),
            'canonical_subs' => (array) ($data['canonical_subs'] ?? []),
            'region_abbrevs' => $regionAbbreviations,
            'regional_alternate_categories' => array_values($data['regional_alternate_categories'] ?? []),
            'region_replacements' => $regionReplacements,
            'region_pattern' => $regionReplacements === [] ? null : '/(*UCP)\b(?:'.implode('|', array_map(
                fn (string $key): string => preg_quote($key, '/'),
                array_keys($regionReplacements),
            )).')\b/u',
            'region_groups' => $data['region_groups'] ?? [],
            'noise_tokens' => array_values(array_unique(array_map(
                fn (mixed $token): string => mb_strtoupper((string) $token, 'UTF-8'),
                $data['noise_tokens'] ?? [],
            ))),
            'non_linear_group_regex' => array_values(array_map(
                fn (mixed $pattern): string => (string) $pattern,
                $data['non_linear_group_regex'] ?? [],
            )),
            'non_linear_title_regex' => array_values(array_map(
                fn (mixed $pattern): string => (string) $pattern,
                $data['non_linear_title_regex'] ?? [],
            )),
            'country_tokens' => array_values(array_map(
                fn (mixed $token): string => strtolower((string) $token),
                $data['country_tokens'] ?? [],
            )),
            'excl_suffix_cleanup' => $data['excl_suffix_cleanup'] ?? [],
        ];

        $rules['noise_pattern'] = self::noisePattern($rules['noise_tokens']);

        return $rules;
    }

    /** @param array<int, string> $tokens */
    private static function noisePattern(array $tokens): string
    {
        $tokens = array_unique([...self::UNIVERSAL_NOISE_TOKENS, ...$tokens]);

        return '/(*UCP)\b(?:'.implode('|', array_map(fn (string $token): string => preg_quote($token, '/'), $tokens)).')\b/iu';
    }

    /** @param array<int, string> $words */
    private static function wordPattern(array $words): string
    {
        return '~(*UCP)\b'.self::alternatives($words).'\b~iu';
    }

    /** @param array<int, string> $words */
    private static function alternatives(array $words): string
    {
        usort($words, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $words === [] ? '(?!)' : '(?:'.implode('|', array_map(
            fn (string $word): string => preg_quote(mb_strtoupper($word, 'UTF-8'), '~'),
            $words,
        )).')';
    }
}
