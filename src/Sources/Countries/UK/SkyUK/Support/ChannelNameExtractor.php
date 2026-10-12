<?php

namespace AppLocalPlugins\GuideLink\Sources\Countries\UK\SkyUK\Support;

use DOMElement;

/**
 * Mirrors the Python _cell_name helpers: stop at badge spans, but keep red +1
 * badges as part of the channel name.
 */
class ChannelNameExtractor
{
    public function __construct(private readonly HtmlTable $html) {}

    /**
     * @return array{0: string, 1: string}
     */
    public function channel(DOMElement $cell): array
    {
        $name = $this->visibleChannelName($cell);
        $name = preg_replace('/\b(HD|SD|UHD|4K)\s*$/i', '', $name) ?? $name;
        $name = str_replace("\xc2\xa0", ' ', $name);
        $name = preg_replace('/[\p{So}\x{FE0F}]/u', '', $name) ?? $name;
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
        $name = trim($name, " \t\n\r\0\x0B.,:;");

        return [$name, $this->parentheticalName($cell)];
    }

    /**
     * @param  array<int, DOMElement>  $cells
     * @return array{0: string, 1: string}
     */
    public function row(array $cells, int $nameColumn): array
    {
        if (isset($cells[$nameColumn])) {
            [$name, $alias] = $this->channel($cells[$nameColumn]);
            if ($name !== '') {
                return [$name, $alias];
            }
        }

        foreach (array_slice($cells, 2) as $cell) {
            [$name, $alias] = $this->channel($cell);
            if ($name !== '' && strlen($name) >= 2 && preg_match('/^\d{1,4}$/', $name) !== 1) {
                return [$name, $alias];
            }
        }

        return ['', ''];
    }

    /**
     * @return array{0: string, 1: string}
     */
    public function regional(DOMElement $cell): array
    {
        $name = preg_replace('/\s+/', ' ', $this->visibleChannelName($cell)) ?? '';
        $name = trim($name, " \t\n\r\0\x0B.,:;");

        return [$name, $this->parentheticalName($cell)];
    }

    /**
     * @param  array<int, DOMElement>  $cells
     */
    public function firstNameCell(array $cells): ?DOMElement
    {
        foreach (array_slice($cells, 1) as $cell) {
            $text = $this->html->text($cell);
            if ($text !== '' && preg_match('/^\d{1,4}$/', $text) !== 1) {
                return $cell;
            }
        }

        return null;
    }

    private function parentheticalName(DOMElement $cell): string
    {
        foreach ($cell->getElementsByTagName('small') as $small) {
            if (preg_match('/^\((.+)\)$/', trim($this->html->text($small)), $matches) === 1) {
                return trim($matches[1]);
            }
        }

        return '';
    }

    private function visibleChannelName(DOMElement $cell): string
    {
        $parts = [];
        foreach ($cell->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE || $child->nodeType === XML_CDATA_SECTION_NODE) {
                $parts[] = trim((string) $child->textContent);

                continue;
            }

            if (! $child instanceof DOMElement) {
                continue;
            }

            if ($child->tagName === 'a' && $child->getElementsByTagName('img')->length === 0) {
                $parts[] = $this->html->text($child);

                continue;
            }

            if (in_array($child->tagName, ['sup', 'small', 'img', 'br'], true)) {
                continue;
            }

            if ($child->tagName === 'p') {
                return $this->visibleChannelName($child);
            }

            if ($child->tagName === 'span') {
                $badge = $this->html->text($child);
                if (str_contains(strtolower($child->getAttribute('style')), 'red') && $badge === '+1') {
                    $parts[] = '+1';
                }

                break;
            }

            break;
        }

        return implode(' ', array_filter($parts, fn (string $part): bool => $part !== ''));
    }
}
