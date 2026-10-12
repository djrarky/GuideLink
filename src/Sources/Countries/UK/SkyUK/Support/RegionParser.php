<?php

namespace AppLocalPlugins\GuideLink\Sources\Countries\UK\SkyUK\Support;

use DOMElement;

class RegionParser
{
    public function __construct(
        private readonly string $sourceName,
        private readonly HtmlTable $html = new HtmlTable,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function resolve(string $content, int|string|null $regionValue, ?callable $fetch = null): array
    {
        $regions = $this->parse($content);

        $regionValue ??= 1;
        foreach ($regions as $region) {
            if ((string) $region['num'] === (string) $regionValue) {
                return $region;
            }
        }

        $query = strtolower(trim((string) $regionValue));
        foreach ($regions as $region) {
            foreach (['name', 'bbc_one', 'itv'] as $field) {
                if (strtolower((string) ($region[$field] ?? '')) === $query) {
                    return $region;
                }
            }
        }

        if ($this->sourceName === 'viewersvault' && $fetch !== null) {
            $document = $this->html->document($content);
            foreach ($document->getElementsByTagName('a') as $link) {
                // Only follow the source's numbered region templates, never arbitrary page links.
                if (preg_match('~^/Template:Region(\d{1,2})$~', $link->getAttribute('href'), $number) !== 1
                    || preg_match('/^(\d{1,2})\.\s+(.+)$/', $this->html->text($link), $label) !== 1
                    || $number[1] !== $label[1]) {
                    continue;
                }
                if ((string) $regionValue !== $number[1] && $query !== strtolower($label[2])) {
                    continue;
                }

                return $this->resolve($fetch('https://viewersvault.com/Template:Region'.$number[1]), (int) $number[1]);
            }
        }

        if ($regions === []) {
            throw new \RuntimeException("No Sky regions were parsed for source [{$this->sourceName}].");
        }

        throw new \RuntimeException("Sky region [{$regionValue}] was not found in source [{$this->sourceName}].");
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function parse(string $content): array
    {
        $document = $this->html->document($content);
        $regions = [];
        $country = 'England';
        $sectionRegion = null;

        foreach ($this->html->elements($document, ['h2', 'h3', 'h4', 'table']) as $element) {
            if ($element->tagName !== 'table') {
                $heading = strtolower($this->html->sectionName($element));
                $sectionRegion = $this->sourceName === 'viewersvault'
                    && preg_match('/^(\d{1,2})\.\s+(.+)$/', $this->html->text($element), $matches) === 1
                    ? ['num' => (int) $matches[1], 'name' => trim($matches[2])]
                    : null;
                $country = match (true) {
                    str_contains($heading, 'england') => 'England',
                    str_contains($heading, 'scotland') => 'Scotland',
                    str_contains($heading, 'northern ireland') => 'Northern Ireland',
                    str_contains($heading, 'ireland') => 'Ireland',
                    str_contains($heading, 'wales') => 'Wales',
                    default => $country,
                };

                continue;
            }

            if (! $element instanceof DOMElement || ! $this->html->hasClass($element, 'wikitable')) {
                continue;
            }

            $grid = $this->html->grid($element);
            if (count($grid) < 2) {
                continue;
            }

            $headers = $grid[0];
            if ($this->sourceName === 'viewersvault') {
                if ($sectionRegion !== null && $this->html->findColumn($headers, ['sky q number']) >= 0) {
                    $region = $this->channelTableRegion($grid, $sectionRegion);
                    if ($region !== null) {
                        $regions[] = $region;
                    }
                }
                $sectionRegion = null;

                continue;
            }
            $numberColumn = $this->html->findColumn($headers, ['region #', 'region']);
            $nameColumn = $this->html->findColumn($headers, ['sky+ app', 'sky region', 'region name']);
            if ($numberColumn < 0 || $nameColumn < 0) {
                continue;
            }

            foreach (array_slice($grid, 1) as $row) {
                if (preg_match('/(\d{1,2})/', $row[$numberColumn] ?? '', $matches) !== 1) {
                    continue;
                }

                $regions[] = [
                    'num' => (int) $matches[1],
                    'name' => $row[$nameColumn] ?? '',
                    'country' => $country,
                    'bbc_one' => $row[$this->html->findColumn($headers, ['bbc one'])] ?? '',
                    'bbc_two' => $row[$this->html->findColumn($headers, ['bbc two'])] ?? '',
                    'itv' => $row[$this->html->findColumn($headers, ['stv/itv1 hd', 'itv1 hd', 'stv (or itv1)', 'itv1'])] ?? '',
                    'itv_plus1' => $row[$this->html->findColumn($headers, ['itv1 +1'])] ?? '',
                    'ch4' => $row[$this->html->findColumn($headers, ['channel 4 hd', 'channel 4'])] ?? '',
                    'ch4_plus1' => $row[$this->html->findColumn($headers, ['channel 4 +1'])] ?? '',
                ];
            }
        }

        return $regions;
    }

    /** @param array<int, array<int, string>> $grid */
    private function channelTableRegion(array $grid, array $region): ?array
    {
        $headers = $grid[0];
        $numberColumn = $this->html->findColumn($headers, ['sky q number']);
        $nameColumn = array_search('channel', array_map('strtolower', $headers), true);
        if ($nameColumn === false) {
            return null;
        }

        $feeds = [];
        foreach (array_slice($grid, 1) as $row) {
            $number = trim($row[$numberColumn] ?? '');
            if (preg_match('/^[1-9]\d{2}$/', $number) !== 1) {
                continue;
            }
            $name = trim(preg_replace('/\s+(?:HD|SD|UHD|4K)$/i', '', $row[$nameColumn] ?? '') ?? '');
            $feeds[(int) $number] ??= $name;
        }
        if ($feeds === []) {
            return null;
        }
        ksort($feeds);
        $first = function (string $pattern) use ($feeds): string {
            foreach ($feeds as $name) {
                if (preg_match($pattern, $name, $match) === 1) {
                    return trim($match[1] ?? '') ?: 'Network';
                }
            }

            return '';
        };
        $bbcOne = $first('/^BBC One(?:\s+(.*))?$/i');
        $country = match (true) {
            $region['num'] === 50 => 'Ireland',
            str_contains($bbcOne, 'Northern Ireland') => 'Northern Ireland',
            str_contains($bbcOne, 'Scotland') => 'Scotland',
            str_contains($bbcOne, 'Wales') => 'Wales',
            default => 'England',
        };
        $itv = $first('/^(?:ITV1|STV|UTV)(?:\s+(.*))?$/i');

        return [
            ...$region,
            'country' => $country,
            'bbc_one' => $bbcOne,
            'bbc_two' => $first('/^BBC Two(?:\s+(.*))?$/i'),
            'itv' => $country === 'Scotland' && in_array('STV', $feeds, true) ? 'STV' : ($itv === 'Network' ? $region['name'] : $itv),
            'itv_plus1' => $first('/^(?:ITV1|STV|UTV)\s*\+1(?:\s+(.*))?$/i'),
            'ch4' => $first('/^Channel 4(?:\s+(.*))?$/i'),
            'ch4_plus1' => $first('/^Channel 4\s*\+1(?:\s+(.*))?$/i'),
        ];
    }
}
