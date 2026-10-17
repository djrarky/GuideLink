<?php

namespace AppLocalPlugins\GuideLink\Tests\Feature\Sources;

require_once dirname(__DIR__).'/PluginFeatureTestCase.php';

use AppLocalPlugins\GuideLink\Sources\Countries\UK\SkyUK\Support\ChannelPostProcessor;
use AppLocalPlugins\GuideLink\Sources\Countries\UK\SkyUK\Support\HtmlTable;
use AppLocalPlugins\GuideLink\Tests\Feature\PluginFeatureTestCase;

class SourceDeduplicationTest extends PluginFeatureTestCase
{
    public function test_equal_priority_conflicts_are_not_turned_into_automatic_aliases(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('conflicting services at channel number [101]');
        (new ChannelPostProcessor(new HtmlTable))->finish([$this->entry('BBC One'), $this->entry('BBC Two')]);
    }

    public function test_equivalent_duplicates_combine_aliases(): void
    {
        $rows = (new ChannelPostProcessor(new HtmlTable))->finish([
            $this->entry('BBC One HD'), [...$this->entry('BBC 1 SD'), 'aliases' => ['BBC One SD']],
        ]);
        $this->assertCount(1, $rows);
        $this->assertSame(['BBC 1 SD', 'BBC One SD'], $rows[0]['aliases']);
    }

    public function test_explicit_source_priority_resolves_conflicts_regardless_of_input_order(): void
    {
        $rows = [$this->entry('BBC One'), $this->entry('BBC Two'), [...$this->entry('Regional Selection'), '_source_priority' => 2]];
        $processor = new ChannelPostProcessor(new HtmlTable);
        foreach ([$rows, array_reverse($rows)] as $input) {
            $result = $processor->finish($input);
            $this->assertCount(1, $result);
            $this->assertSame('Regional Selection', $result[0]['name']);
            $this->assertSame([], $result[0]['aliases']);
        }
    }

    private function entry(string $name): array
    {
        return ['number' => 101, 'name' => $name, 'category' => 'Entertainment', 'aliases' => []];
    }
}
