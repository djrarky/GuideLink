<?php

namespace AppLocalPlugins\GuideLink\Sources\Countries\UK\SkyUK\Sources;

use AppLocalPlugins\GuideLink\Sources\Countries\UK\SkyUK\Support\AbstractChannelSource;

/**
 * TVChannelLists Sky UK source, kept close to sources/sky/tvchannellists.py so
 * source sections and table-shape rules remain human-editable.
 */
class TvChannelListsSource extends AbstractChannelSource
{
    protected const CHANNEL_URL = 'https://www.tvchannellists.com/w/List_of_channels_on_Sky_Q_(United_Kingdom_%26_Ireland)';

    protected const REGION_URL = 'https://www.tvchannellists.com/w/Additional_information_for_List_of_channels_on_Sky_(UK_and_Ireland)';

    private const SECTION_CATEGORIES = [
        'entertainment & documentaries' => 'Entertainment',
        'secondary entertainment & documentaries' => 'Entertainment',
        'movies' => 'Movies',
        'secondary movies' => 'Movies',
        'music' => 'Music',
        'sports' => 'Sports',
        'secondary sports' => 'Sports',
        'interactive sports' => 'Sports',
        'news' => 'News',
        'secondary news' => 'News',
        'religion' => 'Religion',
        'kids' => 'Children',
        'secondary kids' => 'Children',
        'shopping' => 'Shopping',
        'international' => 'International',
        'secondary international' => 'International',
        'adult' => 'Adult',
        'regional & interactive' => 'Entertainment',
        'other' => 'Entertainment',
        'ultra hd & hdr' => 'Entertainment',
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

        foreach ($this->sectionTables($document, ['h2', 'h3', 'h4'], self::SECTION_CATEGORIES) as $section) {
            $table = $section['table'];
            $category = $section['category'];
            $isRegional = str_contains($section['section'], 'regional');
            [$ukColumn, $nameColumn, $hasRegionColumn] = $this->columns($table);

            if ($isRegional) {
                // Regional rows build rename/alias maps first; main entries are renamed after all rows are parsed.
                [$rows, $renames, $aliases] = $this->regionalRows($table, $nameColumn, $regionValues, $countryTokens, $rules);
                $parsed = [...$parsed, ...$rows];
                $regionalRenames = [...$regionalRenames, ...$renames];
                $this->mergeRegionalAliases($regionalAliases, $aliases);

                continue;
            }

            $parsed = [...$parsed, ...$this->standardRows($table, $ukColumn, $nameColumn, $hasRegionColumn, $tokens, $category, $rules)];
        }

        return $this->postProcessor->finish($parsed, $regionalRenames, $regionalAliases, $document);
    }

    /**
     * @return array{0: int, 1: int, 2: bool}
     */
    private function columns(\DOMElement $table): array
    {
        $nameColumn = -1;
        $ukColumn = 0;
        $hasRegionColumn = false;

        foreach (array_slice($this->html->rows($table), 0, 5) as $row) {
            $column = 0;
            foreach ($this->html->cells($row, 'th', 'td') as $cell) {
                $text = strtolower($this->html->text($cell));
                if (str_contains($text, 'channel name') && $nameColumn < 0) {
                    $nameColumn = $column;
                }

                if (($nameColumn < 0 || $column < $nameColumn) && $text === 'uk') {
                    $ukColumn = $column;
                }

                if (str_contains($text, 'epg') && (int) ($cell->getAttribute('colspan') ?: 1) >= 3) {
                    $hasRegionColumn = true;
                }

                $column += max((int) ($cell->getAttribute('colspan') ?: 1), 1);
            }
        }

        return [$ukColumn, $nameColumn, $hasRegionColumn];
    }

    /**
     * @param  array<int, string>  $tokens
     * @param  array<string, mixed>  $rules
     * @return array<int, array<string, mixed>>
     */
    private function standardRows(\DOMElement $table, int $ukColumn, int $nameColumn, bool $hasRegionColumn, array $tokens, string $category, array $rules): array
    {
        $rows = [];
        $lastRowspanName = '';
        $lastRowspanAlias = '';

        foreach ($this->html->rows($table) as $row) {
            // Continuation rows omit rowspanned columns, so they inherit the last channel name.
            $cells = $this->html->cells($row, 'td');
            if ($cells === []) {
                continue;
            }

            $isContinuation = count($cells) < 3;
            if (! $isContinuation) {
                $nameCell = $nameColumn >= 0 ? $this->html->logicalCell($cells, $nameColumn) : null;
                if ($nameCell && $this->hasRowspan($nameCell)) {
                    [$lastRowspanName, $lastRowspanAlias] = $this->extractLogicalName($cells, $nameColumn);
                } else {
                    $lastRowspanName = '';
                    $lastRowspanAlias = '';
                }
            }

            if ($isContinuation) {
                if ($lastRowspanName === '') {
                    continue;
                }

                $number = isset($cells[0]) ? $this->extractNumber($this->html->text($cells[0])) : null;
                $name = $lastRowspanName;
                $alias = $lastRowspanAlias;
            } else {
                $numberCell = $this->html->logicalCell($cells, $ukColumn) ?? $cells[0];
                $number = $this->extractNumber($this->html->text($numberCell));
                [$name, $alias] = $this->extractLogicalName($cells, $nameColumn);
                if ($name === '') {
                    $name = $lastRowspanName;
                    $alias = $lastRowspanAlias;
                }
            }

            if ($number === null || ! $this->regionApplies($cells, $isContinuation, $hasRegionColumn, $tokens, $rules)) {
                continue;
            }

            $name = str_replace("\xc2\xa0", ' ', $name);
            $name = preg_replace('/\{\{[^}]*\}\}/', '', preg_replace('/\s+/u', ' ', $name) ?? $name) ?? $name;
            $name = trim($name, " \t\n\r\0\x0B.,:;");
            if ($name === '' || strlen($name) > 60) {
                continue;
            }

            $rows[] = $this->entry($number, $name, $category, $alias !== '' ? [$alias] : []);
        }

        return $rows;
    }

    /**
     * @param  array<int, string>  $regionValues
     * @param  array<int, string>  $countryTokens
     * @param  array<string, mixed>  $rules
     * @return array{0: array<int, array<string, mixed>>, 1: array<string, string>, 2: array<string, array<int, string>>}
     */
    private function regionalRows(\DOMElement $table, int $nameColumn, array $regionValues, array $countryTokens, array $rules): array
    {
        $rows = [];
        $renames = [];
        $aliases = [];

        foreach ($this->html->grid($table) as $gridRow) {
            // The resolved grid makes TVChannelLists regional rowspan/colspan rows behave like Python lists.
            if (count($gridRow) < 3 || preg_match('/(\d{2,4})/', trim($gridRow[0]), $matches) !== 1) {
                continue;
            }

            $number = (int) $matches[1];
            if ($number < 100 || $number > 999) {
                continue;
            }

            $exclusion = strtolower(trim($gridRow[1] ?? ''));
            if (! str_starts_with($exclusion, 'excl.')) {
                continue;
            }
            $exclusion = trim(substr($exclusion, 5));

            $name = trim((string) ($gridRow[$nameColumn] ?? ''));
            $name = preg_replace('/\s*(HD|SD|UHD|4K)\b/i', '', $name) ?? $name;
            preg_match('/\s*\(([^)]+)\)\s*$/', $name, $feed);
            $name = preg_replace('/\s*\([^)]+\)\s*$/', '', $name) ?? $name;
            $name = trim($name);
            if ($name === '') {
                continue;
            }

            $suffix = trim($feed[1] ?? '') ?: $this->regionalSuffix($exclusion, $rules);
            $parts = $this->regionalParts($exclusion);
            $isOwnRegion = $this->isOwnRegion($exclusion, $parts, $regionValues, $rules);
            $isCountryCompound = count($parts) > 1 && count(array_diff($parts, $countryTokens)) === 0;

            if ($isOwnRegion) {
                if ($suffix !== '') {
                    if ($isCountryCompound) {
                        foreach ($parts as $part) {
                            $aliases[strtolower($name)][] = $name.' '.ucwords($part);
                        }
                    } else {
                        $renames[strtolower($name)] = $name.' '.$suffix;
                    }
                }
            } else {
                $rows[] = $this->entry(
                    $number,
                    $suffix === '' ? $name : $name.' '.$suffix,
                    'Regional',
                );
            }
        }

        return [$rows, $renames, $aliases];
    }

    /**
     * @param  array<int, \DOMElement>  $cells
     * @return array{0: string, 1: string}
     */
    private function extractLogicalName(array $cells, int $nameColumn): array
    {
        $cell = $nameColumn >= 0 ? $this->html->logicalCell($cells, $nameColumn) : null;
        if ($cell) {
            [$name, $alias] = $this->names->channel($cell);
            if ($name !== '') {
                return [$name, $alias];
            }
        }

        $logoCell = $nameColumn > 0 ? $this->html->logicalCell($cells, $nameColumn - 1) : null;
        if ($logoCell) {
            foreach ($logoCell->getElementsByTagName('span') as $span) {
                $sortValue = $span->getAttribute('data-sort-value');
                if ($sortValue !== '' && preg_match('/encode\|(.+?)(?:\s*!|\}\})/', $sortValue, $matches) === 1) {
                    return [trim($matches[1]), ''];
                }
            }
        }

        return ['', ''];
    }

    /**
     * @param  array<int, \DOMElement>  $cells
     * @param  array<int, string>  $tokens
     * @param  array<string, mixed>  $rules
     */
    private function regionApplies(array $cells, bool $isContinuation, bool $hasRegionColumn, array $tokens, array $rules): bool
    {
        if (! $hasRegionColumn || $tokens === []) {
            return true;
        }

        $regionCell = $isContinuation ? ($cells[1] ?? null) : $this->html->logicalCell($cells, 1);
        if (! $regionCell) {
            return true;
        }

        return $this->regions->applies($this->html->text($regionCell), $tokens, $rules);
    }
}
