<?php

namespace AppLocalPlugins\GuideLink\Sources\Countries\UK\SkyUK\Support;

use AppLocalPlugins\GuideLink\Sources\Contracts\ChannelSource;
use AppLocalPlugins\GuideLink\Support\CountryRulesRepository;
use DOMDocument;
use DOMElement;

abstract class AbstractChannelSource implements ChannelSource
{
    protected readonly ChannelNameExtractor $names;

    protected readonly ChannelPostProcessor $postProcessor;

    public function __construct(
        protected readonly CountryRulesRepository $countryRules,
        protected readonly HtmlTable $html = new HtmlTable,
        protected readonly RegionRules $regions = new RegionRules,
        ?ChannelNameExtractor $names = null,
        ?ChannelPostProcessor $postProcessor = null,
    ) {
        $this->names = $names ?? new ChannelNameExtractor($this->html);
        $this->postProcessor = $postProcessor ?? new ChannelPostProcessor($this->html, $this->countryRules);
    }

    public function definition(): array
    {
        return [
            'url' => static::CHANNEL_URL,
            'format' => 'html',
            'region_url' => static::REGION_URL,
            'region_format' => 'html',
        ];
    }

    /**
     * @param  array<int, string>  $headingTags
     * @param  array<string, string>  $categories
     * @return array<int, array{section: string, category: string, table: DOMElement}>
     */
    protected function sectionTables(DOMDocument $document, array $headingTags, array $categories): array
    {
        $tables = [];
        foreach ($this->html->elements($document, $headingTags) as $heading) {
            $section = $this->html->sectionName($heading);
            if (! isset($categories[$section])) {
                continue;
            }

            $table = $this->html->nextSortableTable($heading);
            if ($table) {
                $tables[] = [
                    'section' => $section,
                    'category' => $categories[$section],
                    'table' => $table,
                ];
            }
        }

        return $tables;
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<int, string>
     */
    protected function countryTokens(array $rules): array
    {
        return array_values(array_map(
            fn (mixed $token): string => strtolower((string) $token),
            $rules['country_tokens'] ?? [],
        ));
    }

    /**
     * @param  array<string, array<int, string>>  $target
     * @param  array<string, array<int, string>>  $aliases
     */
    protected function mergeRegionalAliases(array &$target, array $aliases): void
    {
        foreach ($aliases as $name => $values) {
            $target[$name] = [...($target[$name] ?? []), ...$values];
        }
    }

    protected function extractNumber(string $text, array $tokens = []): ?int
    {
        if (preg_match('/(\d{2,4})(.*)/', $text, $matches) !== 1) {
            return null;
        }

        $number = (int) $matches[1];
        if ($number < 100 || $number > 999) {
            return null;
        }

        $annotation = strtolower(trim((string) ($matches[2] ?? '')));
        if (preg_match('/\p{L}/u', $annotation) !== 1 || $tokens === []) {
            return $number;
        }

        foreach ($tokens as $token) {
            if (str_contains($annotation, strtolower($token))) {
                return $number;
            }
        }

        return null;
    }

    /**
     * @param  array<int, DOMElement>  $cells
     * @param  array<int, string>  $tokens
     */
    protected function rowNumber(array $cells, int $numberColumn, array $tokens = []): ?int
    {
        $cell = $numberColumn > 0 && isset($cells[$numberColumn]) ? $cells[$numberColumn] : ($cells[0] ?? null);

        return $cell ? $this->extractNumber($this->html->text($cell), $tokens) : null;
    }

    /**
     * @param  array<int, DOMElement>  $cells
     * @param  array<int, string>  $tokens
     */
    protected function continuationNumber(array $cells, int $numberColumn, array $tokens): ?int
    {
        if ($numberColumn > 0 && count($cells) >= 2) {
            $first = $this->extractNumber($this->html->text($cells[0]), $tokens);
            $second = $this->extractNumber($this->html->text($cells[1]), $tokens);

            if ($first !== null && $second !== null) {
                return $this->hasAnnotation($cells[0]) && ! $this->hasAnnotation($cells[1]) ? $first : $second;
            }

            if ($first !== null) {
                return $first;
            }

            if ($second !== null && ($this->hasRowspan($cells[1]) || $this->hasAnnotation($cells[1]))) {
                return $second;
            }

            return null;
        }

        return isset($cells[0]) ? $this->extractNumber($this->html->text($cells[0]), $tokens) : null;
    }

    /**
     * @param  array<int, DOMElement>  $cells
     */
    protected function regionalExclusion(array $cells, int $nameColumn): string
    {
        foreach (array_slice($cells, 0, max($nameColumn, 0)) as $cell) {
            $text = strtolower($this->html->text($cell));
            if (str_starts_with($text, 'excl.')) {
                return trim(substr($text, 5));
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $rules
     */
    protected function regionalSuffix(string $exclusion, array $rules): string
    {
        return trim($this->countryRules->substitute(ucwords($exclusion), $rules['excl_suffix_cleanup'] ?? [], 'i'));
    }

    /**
     * @return array<int, string>
     */
    protected function regionalParts(string $exclusion): array
    {
        return array_values(array_filter(array_map(
            fn (string $part): string => trim($part),
            preg_split('/[,&]/', strtolower($exclusion)) ?: [],
        )));
    }

    /**
     * @param  array<int, string>  $parts
     * @param  array<int, string>  $regionValues
     */
    protected function isOwnRegion(string $exclusion, array $parts, array $regionValues, array $rules): bool
    {
        if ($regionValues === []) {
            return false;
        }

        $values = array_map(
            fn (string $value): string => strtolower(CountryRulesRepository::normalizeSeparators($value, $rules)),
            [$exclusion, ...$parts],
        );

        return array_intersect($values, $regionValues) !== [];
    }

    protected function hasRowspan(DOMElement $cell): bool
    {
        return (int) ($cell->getAttribute('rowspan') ?: 1) > 1;
    }

    protected function hasAnnotation(DOMElement $cell): bool
    {
        return str_contains($this->html->text($cell), '(');
    }

    protected function entry(int $number, string $name, string $category, array $aliases = []): array
    {
        return [
            'number' => $number,
            'name' => $name,
            'category' => $category,
            'aliases' => array_values(array_filter($aliases, fn (string $alias): bool => $alias !== '')),
            'stream_id' => '',
            'guide_valid' => true,
        ];
    }
}
