<?php

namespace AppLocalPlugins\GuideLink\Sources\Countries\UK\SkyUK\Sources;

use AppLocalPlugins\GuideLink\Sources\Countries\UK\SkyUK\Support\AbstractChannelSource;

/**
 * ViewersVault Sky UK source, kept close to sources/sky/viewersvault.py so the
 * source URL, section maps, and row rules are easy to edit by hand.
 */
class ViewersVaultSource extends AbstractChannelSource
{
    protected const CHANNEL_URL = 'https://viewersvault.com/List_of_channels_on_Sky_(United_Kingdom_and_Ireland)';

    protected const REGION_URL = 'https://viewersvault.com/Region_mapping_on_Sky_(United_Kingdom_and_Ireland)';

    private const SECTION_CATEGORIES = [
        'entertainment & documentaries' => 'Entertainment',
        'entertainment & documentaries +1' => 'Entertainment',
        'entertainment & documentaries (regional & interactive)' => 'Regional',
        'regional & interactive' => 'Regional',
        'movies' => 'Movies',
        'music' => 'Music',
        'sports' => 'Sports',
        'news' => 'News',
        'religion' => 'Religion',
        'kids' => 'Children',
        'shopping' => 'Shopping',
        'international' => 'International',
        'secondary & information' => 'Entertainment',
        'adult' => 'Adult',
    ];

    public function parseChannels(string $content, array $region, array $rules): array
    {
        $document = $this->html->document($content);
        $tokens = $this->regions->tokens($region);
        $regionValues = $this->regions->values($region, $tokens, $rules);
        $countryTokens = $this->countryTokens($rules);
        $regionalRenames = [];
        $regionalAliases = [];
        $parsed = [];

        foreach ($this->sectionTables($document, ['h2', 'h3'], self::SECTION_CATEGORIES) as $section) {
            $table = $section['table'];
            $category = $section['category'];
            [$numberColumn, $nameColumn] = $this->columns($table);

            if ($category === 'Regional') {
                // Regional rows can either rename the main feed for this region or emit other-region channels.
                [$rows, $renames, $aliases] = $this->regionalRows($table, $numberColumn, $nameColumn, $tokens, $regionValues, $countryTokens, $rules);
                $parsed = [...$parsed, ...$rows];
                $regionalRenames = [...$regionalRenames, ...$renames];
                $this->mergeRegionalAliases($regionalAliases, $aliases);

                continue;
            }

            $parsed = [...$parsed, ...$this->standardRows($table, $numberColumn, $nameColumn, $tokens, $category)];
        }

        return $this->postProcessor->finish($parsed, $regionalRenames, $regionalAliases, $document);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function columns(\DOMElement $table): array
    {
        $grid = $this->html->grid($table);
        $nameColumn = -1;

        foreach (array_slice($grid, 0, 5) as $row) {
            $candidate = $this->html->findColumn($row, ['channel name']);
            if ($candidate >= 0) {
                $nameColumn = $candidate;
            }
        }

        $numberColumn = -1;
        foreach (array_slice($this->html->rows($table), 0, 5) as $row) {
            $column = 0;
            foreach ($this->html->cells($row, 'th', 'td') as $cell) {
                if ($nameColumn > 0 && $column >= $nameColumn) {
                    break;
                }

                if ($this->html->containsLink($cell, 'Sky_Q')) {
                    $numberColumn = $column;
                    break 2;
                }

                $column += max((int) ($cell->getAttribute('colspan') ?: 1), 1);
            }
        }

        return [$numberColumn >= 0 ? $numberColumn : ($nameColumn > 2 ? 1 : 0), max($nameColumn, 1)];
    }

    /**
     * @param  array<int, string>  $tokens
     * @return array<int, array<string, mixed>>
     */
    private function standardRows(\DOMElement $table, int $numberColumn, int $nameColumn, array $tokens, string $category): array
    {
        $rows = [];
        $lastRowspanName = '';
        $lastRowspanAlias = '';

        foreach ($this->html->rows($table) as $row) {
            // Rowspanned channel cells carry the name onto continuation rows with extra EPG numbers.
            $cells = $this->html->cells($row, 'td');
            if ($cells === []) {
                continue;
            }

            $isContinuation = count($cells) <= $nameColumn;
            $currentName = '';
            $currentAlias = '';

            if (! $isContinuation && isset($cells[$nameColumn])) {
                [$currentName, $currentAlias] = $this->names->row($cells, $nameColumn);
                if ($this->hasRowspan($cells[$nameColumn])) {
                    $lastRowspanName = $currentName;
                    $lastRowspanAlias = $currentAlias;
                } else {
                    $lastRowspanName = '';
                    $lastRowspanAlias = '';
                }
            }

            if ($isContinuation) {
                if ($lastRowspanName === '') {
                    continue;
                }

                $number = $this->continuationNumber($cells, $numberColumn, $tokens);
                $name = $lastRowspanName;
                $alias = $lastRowspanAlias;
            } else {
                $number = $this->rowNumber($cells, $numberColumn, $tokens);
                $name = $currentName !== '' ? $currentName : $lastRowspanName;
                $alias = $currentName !== '' ? $currentAlias : $lastRowspanAlias;
            }

            if ($number === null || $name === '' || strlen($name) > 60) {
                continue;
            }

            $entry = $this->entry($number, $name, $category, $alias !== '' ? [$alias] : []);
            // Full rows should beat inherited rowspan continuations for duplicate EPG numbers.
            // ViewersVault can list a continuation number before the real full channel row.
            $entry['_source_priority'] = $isContinuation ? 0 : 1;
            $rows[] = $entry;
        }

        return $rows;
    }

    /**
     * @param  array<int, string>  $tokens
     * @param  array<int, string>  $regionValues
     * @param  array<int, string>  $countryTokens
     * @param  array<string, mixed>  $rules
     * @return array{0: array<int, array<string, mixed>>, 1: array<string, string>, 2: array<string, array<int, string>>}
     */
    private function regionalRows(
        \DOMElement $table,
        int $numberColumn,
        int $nameColumn,
        array $tokens,
        array $regionValues,
        array $countryTokens,
        array $rules,
    ): array {
        $rows = [];
        $renames = [];
        $aliases = [];
        $tokenSet = array_values(array_map(fn (string $token): string => strtolower($token), $tokens));
        $regionColumn = $this->html->findColumn($this->html->grid($table)[0] ?? [], ['region/notes']);

        foreach ($this->html->rows($table) as $row) {
            // ViewersVault regional rows use "excl." text to describe where a regional feed is not shown.
            $cells = $this->html->cells($row, 'td');
            if ($cells === []) {
                continue;
            }

            $exclusion = $this->regionalExclusion($cells, $nameColumn);
            if ($exclusion === '') {
                continue;
            }

            $nameCell = $cells[$nameColumn] ?? $cells[2] ?? $this->names->firstNameCell($cells);
            if (! $nameCell) {
                continue;
            }

            [$name, $cellSuffix] = $this->names->regional($nameCell);
            if ($name === '' || strlen($name) > 60) {
                continue;
            }

            $number = $this->rowNumber($cells, $numberColumn);
            if ($cellSuffix === '' && $number !== null && $regionColumn >= 0) {
                $regionCell = $this->html->logicalCell($cells, $regionColumn);
                $cellSuffix = $regionCell ? $this->html->text($regionCell) : '';
            }
            $suffix = $cellSuffix !== '' ? $cellSuffix : $this->regionalSuffix($exclusion, $rules);
            $parts = $this->regionalParts($exclusion);
            $isOwnRegion = $this->isOwnRegion($exclusion, $parts, $regionValues, $rules);
            $isOwnCountryCompound = count($parts) > 1 && count(array_diff($parts, $countryTokens)) === 0;
            $isOtherCountryCompound = count($parts) > 1 && $tokenSet !== [] && count(array_diff($parts, $tokenSet)) === 0;

            if ($isOwnRegion) {
                if ($isOwnCountryCompound) {
                    foreach ($parts as $part) {
                        $aliases[strtolower($name)][] = $name.' '.ucwords($part);
                    }
                } elseif ($suffix !== '') {
                    $renames[strtolower($name)] = $name.' '.$suffix;
                }
            } elseif ($number !== null) {
                $rows[] = $this->entry(
                    $number,
                    $isOtherCountryCompound || $suffix === '' ? $name : $name.' '.$suffix,
                    'Regional',
                );
            }
        }

        return [$rows, $renames, $aliases];
    }
}
