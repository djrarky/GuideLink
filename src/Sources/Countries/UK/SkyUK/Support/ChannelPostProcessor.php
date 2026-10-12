<?php

namespace AppLocalPlugins\GuideLink\Sources\Countries\UK\SkyUK\Support;

use DOMDocument;
use DOMElement;

/**
 * Mirrors the Python Sky post-processing flow: dedupe by number, remove lower
 * priority simulcasts, apply regional names, then merge channel-change aliases.
 */
class ChannelPostProcessor
{
    public function __construct(private readonly HtmlTable $html) {}

    /**
     * @param  array<int, array{number: int, name: string, category: string, aliases: array<int, string>, stream_id: string, guide_valid: bool}>  $parsed
     * @param  array<string, string>|null  $regionalRenames
     * @param  array<string, array<int, string>>|null  $regionalAliases
     * @return array<int, array{number: int, name: string, category: string, aliases: array<int, string>, stream_id: string, guide_valid: bool}>
     */
    public function finish(
        array $parsed,
        ?array $regionalRenames = null,
        ?array $regionalAliases = null,
        ?DOMDocument $document = null,
    ): array {
        $deduped = $this->removeSimulcasts($this->dedupeByNumber($parsed));
        $this->applyRegionalNames($deduped, $regionalRenames, $regionalAliases);

        if ($document) {
            $this->mergeChannelChanges($deduped, $document);
        }

        foreach ($deduped as &$entry) {
            unset($entry['_source_priority']);
        }
        unset($entry);
        usort($deduped, fn (array $left, array $right): int => $left['number'] <=> $right['number']);

        return $deduped;
    }

    /**
     * @param  array<int, array<string, mixed>>  $parsed
     * @return array<int, array<string, mixed>>
     */
    private function dedupeByNumber(array $parsed): array
    {
        $byNumber = [];
        foreach ($parsed as $entry) {
            $number = (int) $entry['number'];
            $priority = (int) ($entry['_source_priority'] ?? 1);
            $existingPriority = (int) ($byNumber[$number]['_source_priority'] ?? 1);
            if (! isset($byNumber[$number]) || $priority > $existingPriority) {
                $byNumber[$number] = $entry;

                continue;
            }

            if ($priority < $existingPriority) {
                continue;
            }

            if (strcasecmp($entry['name'], $byNumber[$number]['name']) !== 0) {
                $byNumber[$number]['aliases'][] = $entry['name'];
            }

            $byNumber[$number]['aliases'] = array_values(array_unique([
                ...$byNumber[$number]['aliases'],
                ...$entry['aliases'],
            ]));
        }

        return array_values($byNumber);
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @return array<int, array<string, mixed>>
     */
    private function removeSimulcasts(array $entries): array
    {
        $lowestByName = [];
        foreach ($entries as $entry) {
            foreach ([$entry['name'], ...$entry['aliases']] as $name) {
                $key = strtolower((string) $name);
                if ($key !== '' && (! isset($lowestByName[$key]) || $entry['number'] < $lowestByName[$key])) {
                    $lowestByName[$key] = $entry['number'];
                }
            }
        }

        return array_values(array_filter($entries, function (array $entry) use ($lowestByName): bool {
            $key = strtolower($entry['name']);

            return $key === '' || ($lowestByName[$key] ?? $entry['number']) === $entry['number'];
        }));
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @param  array<string, string>|null  $regionalRenames
     * @param  array<string, array<int, string>>|null  $regionalAliases
     */
    private function applyRegionalNames(array &$entries, ?array $regionalRenames, ?array $regionalAliases): void
    {
        foreach ($entries as &$entry) {
            $key = strtolower((string) $entry['name']);
            if (($entry['category'] ?? '') !== 'Regional' && isset($regionalRenames[$key])) {
                $entry['name'] = $regionalRenames[$key];
                $key = strtolower((string) $entry['name']);
            }

            if (isset($regionalAliases[$key])) {
                $entry['aliases'] = array_values(array_unique([
                    ...$entry['aliases'],
                    ...$regionalAliases[$key],
                ]));
            }
        }
        unset($entry);
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     */
    private function mergeChannelChanges(array &$entries, DOMDocument $document): void
    {
        $nameIndex = [];
        foreach ($entries as $index => $entry) {
            $nameIndex[strtolower((string) $entry['name'])] ??= $index;
        }

        foreach ($this->channelChanges($document) as [$current, $replacement]) {
            $index = $nameIndex[strtolower($current)] ?? null;
            if ($index === null || in_array($replacement, $entries[$index]['aliases'], true)) {
                continue;
            }

            $entries[$index]['aliases'][] = $replacement;
        }
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    private function channelChanges(DOMDocument $document): array
    {
        $changes = [];
        $renameTypes = ['rename', 'temporary rename', 'reversion'];

        foreach ($document->getElementsByTagName('table') as $table) {
            if (! $table instanceof DOMElement || ! $this->html->hasClass($table, 'wikitable')) {
                continue;
            }

            $grid = $this->html->grid($table);
            if (! $this->isChangesTable($grid)) {
                continue;
            }

            $nameOffset = $this->changeNameOffset($grid);
            foreach ($grid as $row) {
                foreach ($row as $index => $cell) {
                    if (! in_array(strtolower(trim($cell)), $renameTypes, true) || $index < 1 || ! isset($row[$index + $nameOffset])) {
                        continue;
                    }

                    $current = $this->stripBadge($row[$index - 1]);
                    $replacement = $this->stripBadge($row[$index + $nameOffset]);
                    if ($current !== '' && $replacement !== '' && strcasecmp($current, $replacement) !== 0) {
                        $changes[] = [$current, $replacement];
                    }

                    break;
                }
            }
        }

        return $changes;
    }

    /**
     * @param  array<int, array<int, string>>  $grid
     */
    private function isChangesTable(array $grid): bool
    {
        foreach (array_slice($grid, 0, 3) as $row) {
            if (str_contains(strtolower(implode(' ', $row)), 'type of change')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array<int, string>>  $grid
     */
    private function changeNameOffset(array $grid): int
    {
        foreach (array_slice($grid, 0, 3) as $row) {
            $lower = array_map(fn (string $cell): string => strtolower($cell), $row);
            $typeIndex = array_search('type of change', $lower, true);
            $nameIndex = array_search('new channel name', $lower, true);

            if ($typeIndex !== false && $nameIndex !== false) {
                return (int) $nameIndex - (int) $typeIndex;
            }
        }

        return 2;
    }

    private function stripBadge(string $name): string
    {
        $name = str_replace("\xc2\xa0", ' ', $name);
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
        $name = preg_replace('/(HD|SD|UHD|4K)\s*$/i', '', trim($name)) ?? $name;

        return trim($name);
    }
}
