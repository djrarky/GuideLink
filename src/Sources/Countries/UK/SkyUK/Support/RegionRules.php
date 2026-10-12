<?php

namespace AppLocalPlugins\GuideLink\Sources\Countries\UK\SkyUK\Support;

use AppLocalPlugins\GuideLink\Support\CountryRulesRepository;

class RegionRules
{
    /**
     * @param  array<int, string>  $tokens
     * @param  array<string, mixed>  $rules
     */
    public function applies(string $label, array $tokens, array $rules): bool
    {
        if (trim($label) === '' || $tokens === []) {
            return true;
        }

        $tokens = array_map('strtolower', $tokens);
        foreach ($rules['region_groups'] ?? [] as $group => $members) {
            if (array_intersect($tokens, array_map('strtolower', $members)) !== []) {
                $tokens[] = strtolower($group);
            }
        }
        $pattern = '~\b(?:'.implode('|', array_map(fn (string $token): string => preg_quote($token, '~'), $tokens)).')\b~i';
        [$included, $excluded] = array_pad(preg_split('/\b(?:excl(?:uding)?\.?|not)\s*/i', $label, 2), 2, '');

        return (trim($included) === '' || preg_match($pattern, $included) === 1)
            && preg_match($pattern, $excluded) !== 1;
    }

    /**
     * @param  array<string, mixed>  $region
     * @return array<int, string>
     */
    public function tokens(array $region): array
    {
        $country = strtolower((string) ($region['country'] ?? ''));
        $bbcOne = strtolower((string) ($region['bbc_one'] ?? ''));
        $itv = strtolower((string) ($region['itv'] ?? ''));
        $name = strtolower((string) ($region['name'] ?? ''));

        if (str_contains($country, 'scotland')) {
            return str_contains($itv, 'stv') ? ['Scot', 'ScotSTV'] : ['Scot', 'ScotITVB'];
        }

        if (str_contains($country, 'northern ireland') || str_contains($bbcOne, 'northern ireland')) {
            return ['NI'];
        }

        if (str_contains($country, 'wales')) {
            return ['Wales'];
        }

        if (str_contains($name, 'channel') && str_contains($name, 'isle')) {
            return ['CI'];
        }

        return ['Eng'];
    }

    /**
     * @param  array<string, mixed>  $region
     * @param  array<int, string>  $tokens
     * @param  array<string, mixed>  $rules
     * @return array<int, string>
     */
    public function values(array $region, array $tokens, array $rules): array
    {
        $values = [];
        foreach ($region as $key => $value) {
            if (in_array($key, ['num', 'country'], true) || ! is_string($value)) {
                continue;
            }

            $value = strtolower(CountryRulesRepository::normalizeSeparators($value, $rules));
            if ($value !== '' && $value !== 'network') {
                $values[] = $value;
            }
        }

        foreach ($tokens as $token) {
            $values[] = strtolower($token);
        }

        foreach (($rules['region_abbrevs'] ?? []) as $canonical => $abbreviations) {
            $aliases = array_map(
                fn (string $value): string => strtolower(CountryRulesRepository::normalizeSeparators($value, $rules)),
                [str_replace('_', ' ', (string) $canonical), ...(array) $abbreviations],
            );
            if (array_intersect($aliases, $values) !== []) {
                $values = [...$values, ...$aliases];
            }
        }

        return array_values(array_unique($values));
    }
}
