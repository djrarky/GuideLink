<?php

namespace AppLocalPlugins\GuideLink\Sources\Countries\UK\SkyUK\Support;

use DOMDocument;
use DOMElement;
use DOMNode;

class HtmlTable
{
    public function document(string $content): DOMDocument
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$content, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $document;
    }

    /**
     * @return array<int, DOMElement>
     */
    public function elements(DOMDocument $document, array $tags): array
    {
        $items = [];
        foreach ($tags as $tag) {
            foreach ($document->getElementsByTagName($tag) as $element) {
                if ($element instanceof DOMElement) {
                    $items[] = $element;
                }
            }
        }

        usort($items, fn (DOMElement $left, DOMElement $right): int => $left->getLineNo() <=> $right->getLineNo());

        return $items;
    }

    public function nextSortableTable(DOMElement $heading): ?DOMElement
    {
        $node = $this->nextDocumentNode($heading);
        while ($node) {
            if ($node instanceof DOMElement && in_array($node->tagName, ['h2', 'h3', 'h4'], true)) {
                return null;
            }

            if ($node instanceof DOMElement && $node->tagName === 'table' && $this->hasClass($node, 'sortable')) {
                return $node;
            }

            $node = $this->nextDocumentNode($node);
        }

        return null;
    }

    /**
     * @return array<int, array<int, string>>
     */
    public function grid(DOMElement $table): array
    {
        $grid = [];
        $pending = [];
        foreach ($this->rows($table) as $row) {
            $values = [];
            $column = 0;
            $cells = $this->cells($row, 'th', 'td');
            $cellIndex = 0;

            while (true) {
                if (isset($pending[$column])) {
                    $values[] = $pending[$column]['value'];
                    if ($pending[$column]['remaining'] > 1) {
                        $pending[$column]['remaining']--;
                    } else {
                        unset($pending[$column]);
                    }
                    $column++;

                    continue;
                }

                $cell = $cells[$cellIndex] ?? null;
                if (! $cell) {
                    break;
                }
                $cellIndex++;

                $colspan = max((int) ($cell->getAttribute('colspan') ?: 1), 1);
                $rowspan = max((int) ($cell->getAttribute('rowspan') ?: 1), 1);
                $value = $this->text($cell);
                for ($index = 0; $index < $colspan; $index++) {
                    $values[] = $value;
                    if ($rowspan > 1) {
                        $pending[$column] = [
                            'remaining' => $rowspan - 1,
                            'value' => $value,
                        ];
                    }
                    $column++;
                }
            }

            while (isset($pending[$column])) {
                $values[] = $pending[$column]['value'];
                if ($pending[$column]['remaining'] > 1) {
                    $pending[$column]['remaining']--;
                } else {
                    unset($pending[$column]);
                }
                $column++;
            }

            if ($values !== []) {
                $grid[] = $values;
            }
        }

        return $grid;
    }

    /**
     * @return array<int, DOMElement>
     */
    public function rows(DOMElement $table): array
    {
        return $this->childElements($table, 'tr');
    }

    /**
     * @return array<int, DOMElement>
     */
    public function cells(DOMElement $row, string ...$tags): array
    {
        return $this->childElements($row, ...$tags);
    }

    /**
     * @param  array<int, DOMElement>  $cells
     */
    public function logicalCell(array $cells, int $logicalColumn): ?DOMElement
    {
        $column = 0;
        foreach ($cells as $cell) {
            $colspan = max((int) ($cell->getAttribute('colspan') ?: 1), 1);
            if ($column <= $logicalColumn && $logicalColumn < $column + $colspan) {
                return $cell;
            }

            $column += $colspan;
        }

        return null;
    }

    public function findColumn(array $headers, array $patterns): int
    {
        foreach ($headers as $index => $header) {
            $header = strtolower((string) $header);
            foreach ($patterns as $pattern) {
                if (str_contains($header, strtolower($pattern))) {
                    return (int) $index;
                }
            }
        }

        return -1;
    }

    public function sectionName(DOMElement $heading): string
    {
        $text = strtolower($this->text($heading));
        $text = preg_replace('/\[edit\]/i', '', $text) ?? $text;
        $text = str_replace("\xc2\xa0", ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    public function hasClass(DOMElement $element, string $class): bool
    {
        return in_array($class, preg_split('/\s+/', $element->getAttribute('class')) ?: [], true);
    }

    public function containsLink(DOMElement $element, string $needle): bool
    {
        foreach ($element->getElementsByTagName('a') as $link) {
            if (str_contains((string) $link->getAttribute('href'), $needle)) {
                return true;
            }
        }

        return false;
    }

    public function text(DOMNode $node): string
    {
        $text = str_replace("\xc2\xa0", ' ', (string) $node->textContent);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function nextDocumentNode(DOMNode $node): ?DOMNode
    {
        if ($node->firstChild) {
            return $node->firstChild;
        }

        while ($node) {
            if ($node->nextSibling) {
                return $node->nextSibling;
            }

            $node = $node->parentNode;
        }

        return null;
    }

    /**
     * @return array<int, DOMElement>
     */
    private function childElements(DOMElement $node, string ...$tags): array
    {
        $items = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && in_array($child->tagName, $tags, true)) {
                $items[] = $child;
            }
        }

        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && in_array($child->tagName, ['tbody', 'thead'], true)) {
                $items = [...$items, ...$this->childElements($child, ...$tags)];
            }
        }

        return $items;
    }
}
