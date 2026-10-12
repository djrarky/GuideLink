<?php

namespace AppLocalPlugins\GuideLink\Tests\Feature\Sources;

require_once dirname(__DIR__).'/PluginFeatureTestCase.php';

use App\Models\Channel;
use AppLocalPlugins\GuideLink\Services\ChannelMatcher;
use AppLocalPlugins\GuideLink\Support\CountryRulesRepository;
use AppLocalPlugins\GuideLink\Tests\Feature\PluginFeatureTestCase;

class MatchingSafetyTest extends PluginFeatureTestCase
{
    public function test_provider_prefixes_use_normal_name_and_fingerprint_matching(): void
    {
        foreach (['US: Discovery Channel', 'CA: Discovery Channel', 'UK: Discovery Channel'] as $title) {
            foreach (['', 'discovery.uk'] as $streamId) {
                $result = $this->match(['title' => $title, 'stream_id' => $streamId, 'channel' => 125]);
                $this->assertSame($streamId === '' ? 'auto_apply' : 'fingerprint', $result['decision']);
                $this->assertSame('discovery.uk', $result['source']['stream_id']);
            }
        }
    }

    public function test_explicit_aliases_allow_different_digits_but_keep_timeshift_guards(): void
    {
        foreach (['DISC CHNL' => 'auto_apply', 'DISC CHNL +1' => 'needs_review', 'DISC CHNL 2' => 'auto_apply'] as $alias => $decision) {
            $result = $this->match(['title' => $alias], [$alias => [5 => 'Provider names']]);
            $this->assertSame($decision, $result['decision']);
            $this->assertSame([5 => 'Provider names'], $result['alias_sets']);
        }
        foreach (['DISC CHNL 2', 'Discovery Channel 2', 'Discovery Channel +1'] as $title) {
            $automatic = $this->match(['title' => $title]);
            $this->assertNotContains($automatic['decision'], ['auto_apply', 'fingerprint'], $title);
        }
        $result = $this->match(['title' => 'US: DISC CHNL'], ['DISC CHNL' => [5 => 'Provider names']]);
        $this->assertSame('auto_apply', $result['decision']);
    }

    public function test_id_timeshift_alias_keeps_its_channel_identity(): void
    {
        $countries = new CountryRulesRepository;
        $rules = $countries->resolve([], []);
        $matcher = new ChannelMatcher($countries);
        $source = [[
            'number' => 254, 'name' => 'Investigation Discovery +1', 'category' => 'Entertainment',
            'aliases' => ['ID +1'], 'stream_id' => '254.uk', 'guide_valid' => true,
        ]];
        $thresholds = $matcher->thresholds([], []);
        $index = $matcher->sourceIndex($source, $rules, $thresholds);
        foreach (['', '254.uk'] as $streamId) {
            $channel = (new Channel)->forceFill(['title' => 'UK: ID +1 ◉', 'channel' => 254, 'stream_id' => $streamId]);
            $result = $matcher->match($channel, $index, $thresholds);
            $this->assertContains($result['decision'], ['auto_apply', 'fingerprint']);
            $this->assertSame('254.uk', $result['source']['stream_id']);
        }
    }

    public function test_international_channels_keep_ambiguity_timeshift_and_guide_checks(): void
    {
        $countries = new CountryRulesRepository;
        $rules = $countries->resolve([], []);
        $matcher = new ChannelMatcher($countries);
        $thresholds = $matcher->thresholds([], []);
        $source = [[
            'number' => 737, 'name' => 'GEO TV', 'category' => 'International',
            'aliases' => [], 'stream_id' => '737.uk', 'guide_valid' => true,
        ]];
        $channel = (new Channel)->forceFill(['title' => 'PK: GEO TV']);
        $ambiguous = [...$source, [...$source[0], 'number' => 738, 'stream_id' => '738.uk']];
        $result = $matcher->match($channel, $matcher->sourceIndex($ambiguous, $rules), $thresholds);
        $this->assertSame('needs_review', $result['decision']);
        $this->assertSame('Name identifies more than one source channel.', $result['review_reason']);
        foreach (['PK: GEO TV +1', 'PK: GEO TV 2', 'PK: Completely Unrelated Station'] as $title) {
            $channel->forceFill(['title' => $title]);
            $result = $matcher->match($channel, $matcher->sourceIndex($source, $rules), $thresholds);
            $this->assertNotContains($result['decision'], ['auto_apply', 'fingerprint'], $title);
        }
        $source[0]['guide_valid'] = false;
        $channel->forceFill(['title' => 'PK: GEO TV']);
        $result = $matcher->match($channel, $matcher->sourceIndex($source, $rules), $thresholds);
        $this->assertSame('needs_review', $result['decision']);
    }

    public function test_regional_fallback_uses_unique_main_lineup_feed_not_lowest_number(): void
    {
        $countries = new CountryRulesRepository;
        $rules = $countries->resolve([], []);
        $matcher = new ChannelMatcher($countries);
        $thresholds = $matcher->thresholds([], []);
        $source = [
            ['number' => 901, 'name' => 'BBC One North West', 'category' => 'Entertainment', 'aliases' => [], 'stream_id' => 'bbc.nw', 'guide_valid' => true],
            ['number' => 101, 'name' => 'BBC One Yorkshire', 'category' => 'Regional', 'aliases' => [], 'stream_id' => 'bbc.yorks', 'guide_valid' => true],
        ];
        foreach (['UK: BBC 1 HD ◉', 'UK: BBC 1 4K ◉', 'UK: BBC 1 HEVC HD', 'UK: BBC 1 HEVC 4K'] as $name) {
            $channel = (new Channel)->forceFill(['title' => $name]);
            $result = $matcher->match($channel, $matcher->sourceIndex($source, $rules), $thresholds);
            $this->assertSame('auto_apply', $result['decision']);
            $this->assertSame('bbc.nw', $result['source']['stream_id']);
        }
        $channel = (new Channel)->forceFill(['title' => 'UK: BBC One Yorkshire HD']);
        $result = $matcher->match($channel, $matcher->sourceIndex($source, $rules), $thresholds);
        $this->assertSame('bbc.yorks', $result['source']['stream_id']);
        $channel->forceFill(['title' => 'UK: BBC One']);
        foreach (['Regional', 'Entertainment', ''] as $category) {
            $source[0]['category'] = $category;
            $source[1]['category'] = $category;
            $result = $matcher->match($channel, $matcher->sourceIndex($source, $rules), $thresholds);
            $this->assertSame('needs_review', $result['decision']);
        }
    }

    public function test_numbered_timeshift_regional_and_ambiguous_names_remain_distinct(): void
    {
        $countries = new CountryRulesRepository;
        $rules = $countries->resolve([], []);
        $matcher = new ChannelMatcher($countries);
        $thresholds = $matcher->thresholds([], []);
        $entries = [];
        foreach (['BBC One', 'BBC One +1', 'BBC Two', 'ITV1 Yorkshire', 'ITV1 Granada'] as $i => $name) {
            $entries[] = [
                'number' => 101 + $i, 'name' => $name, 'category' => 'Entertainment',
                'aliases' => ['Shared provider name'], 'stream_id' => 'guide.'.$i, 'guide_valid' => true,
            ];
        }
        $index = $matcher->sourceIndex($entries, $rules, $thresholds);
        foreach (['UK: BBC One HD' => 101, 'BBC 1 +1' => 102, 'BBC Two' => 103, 'ITV1 Granada HD' => 105] as $title => $number) {
            $channel = new Channel;
            $channel->forceFill(['title' => $title]);
            $result = $matcher->match($channel, $index, $thresholds);
            $this->assertSame('auto_apply', $result['decision'], $title);
            $this->assertSame($number, $result['source']['number'], $title);
        }
        foreach (['Shared provider name', 'BBC One +2', 'ITV1', '完全不同的頻道'] as $title) {
            $channel = new Channel;
            $channel->forceFill(['title' => $title]);
            $result = $matcher->match($channel, $index, $thresholds);
            $this->assertNotContains($result['decision'], ['auto_apply', 'fingerprint'], $title);
        }
    }

    public function test_explicit_aliases_can_map_rebranded_names_without_changing_timeshifts(): void
    {
        $countries = new CountryRulesRepository;
        $rules = $countries->resolve([], []);
        $matcher = new ChannelMatcher($countries);
        foreach (['Sky Max' => 'Sky One', 'Movies4Men' => 'GREAT! Action', 'Now 90s' => 'Now 90s & 00s'] as $old => $current) {
            $key = $countries->normalize($old, $rules);
            $source = [['number' => 106, 'name' => $current, 'category' => 'Test', 'aliases' => [],
                'stream_id' => '106.uk', 'guide_valid' => true,
                'profile_aliases' => [$key => [1 => 'Imported']]]];
            $result = $matcher->match((new Channel)->forceFill(['title' => $old]), $matcher->sourceIndex($source, $rules), $matcher->thresholds([], []));
            $this->assertSame('auto_apply', $result['decision'], $old);
            $this->assertSame('106.uk', $result['source']['stream_id']);
            $plusKey = $countries->normalize($old.' +1', $rules);
            $source[0]['profile_aliases'] = [$plusKey => [1 => 'Imported']];
            $result = $matcher->match((new Channel)->forceFill(['title' => $old.' +1']), $matcher->sourceIndex($source, $rules), $matcher->thresholds([], []));
            $this->assertSame('needs_review', $result['decision']);
        }
        $this->assertSame('SKY MAX', $countries->normalize('Sky Max', $rules));
    }

    public function test_selected_aliases_work_in_composite_regional_and_spaced_names_without_erasing_ambiguity(): void
    {
        $countries = new CountryRulesRepository;
        $rules = $countries->resolve([], []);
        $matcher = new ChannelMatcher($countries);
        $entries = [];
        foreach (['WATCH' => 'U&W', 'SKY CINEMA SELECT' => 'Sky Cinema Animation', 'ARY FAMILY' => 'ARY Digital'] as $alias => $name) {
            $number = count($entries) + 1;
            $entries[] = ['number' => $number, 'name' => $name, 'category' => 'Test', 'aliases' => [],
                'stream_id' => $number.'.uk', 'guide_valid' => true, 'profile_aliases' => [$alias => [7 => 'Imported']]];
        }
        $index = $matcher->sourceIndex($entries, $rules);
        foreach (['UK: W! - WATCH 4K' => '1.uk', 'UK: SKY CINEMA SELECT/FEEL GOOD HD' => '2.uk',
            'PK: ARY FAMILY EU' => '3.uk', 'SKYCINEMASELECT' => '2.uk'] as $title => $id) {
            $result = $matcher->match((new Channel)->forceFill(['title' => $title]), $index, $matcher->thresholds([], []));
            $this->assertSame('auto_apply', $result['decision'], $title);
            $this->assertSame($id, $result['source']['stream_id']);
            $this->assertSame([7 => 'Imported'], $result['alias_sets']);
        }
        foreach (['WATCH / ARY FAMILY', 'WATCH / Something +1', 'ARY FAMILY EU +1'] as $title) {
            $result = $matcher->match((new Channel)->forceFill(['title' => $title]), $index, $matcher->thresholds([], []));
            $this->assertNotContains($result['decision'], ['auto_apply', 'fingerprint'], $title);
        }
    }

    private function match(array $attributes, array $aliases = []): array
    {
        $countries = new CountryRulesRepository;
        $rules = $countries->resolve([], []);
        $matcher = new ChannelMatcher($countries);
        $thresholds = $matcher->thresholds([], []);
        $source = [[
            'number' => 125, 'name' => 'Discovery Channel', 'category' => 'Entertainment',
            'aliases' => [], 'stream_id' => 'discovery.uk', 'guide_valid' => true, 'profile_aliases' => $aliases,
        ]];
        $channel = new Channel;
        $channel->forceFill($attributes);

        return $matcher->match($channel, $matcher->sourceIndex($source, $rules, $thresholds), $thresholds);
    }
}
