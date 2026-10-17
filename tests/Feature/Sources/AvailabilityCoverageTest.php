<?php

namespace AppLocalPlugins\GuideLink\Tests\Feature\Sources;

require_once dirname(__DIR__).'/PluginFeatureTestCase.php';

use App\Models\Channel;
use App\Models\ChannelFailover;
use App\Models\Playlist;
use AppLocalPlugins\GuideLink\Services\AvailabilityAnalyzer;
use AppLocalPlugins\GuideLink\Services\ChannelMatcher;
use AppLocalPlugins\GuideLink\Services\ChannelRepository;
use AppLocalPlugins\GuideLink\Services\WritePlanBuilder;
use AppLocalPlugins\GuideLink\Support\CountryRulesRepository;
use AppLocalPlugins\GuideLink\Tests\Feature\PluginFeatureTestCase;
use Illuminate\Database\Eloquent\Collection;

class AvailabilityCoverageTest extends PluginFeatureTestCase
{
    public function test_sync_summary_counts_unique_confirmed_and_review_only_sources_separately(): void
    {
        $countries = new CountryRulesRepository;
        $matcher = new ChannelMatcher($countries);
        $playlist = (new Playlist)->forceFill(['id' => 1, 'uuid' => 'test']);
        $sources = [
            ['number' => 737, 'name' => 'GEO TV', 'category' => 'International', 'aliases' => [], 'guide_valid' => true],
            ['number' => 738, 'name' => 'GEO TV', 'category' => 'International', 'aliases' => [], 'guide_valid' => true],
            ['number' => 101, 'name' => 'BBC One', 'category' => 'Entertainment', 'aliases' => [], 'guide_valid' => true],
        ];
        $rows = $matcher->rows($playlist, new Collection([
            $this->channel(1, 'GEO TV', true), $this->channel(2, 'BBC One', true), $this->channel(3, 'BBC One HD', false),
        ]), $sources, $matcher->thresholds([], []), $countries->resolve([], []));

        $summary = (new WritePlanBuilder($countries))->summary($playlist, $rows, [], $sources, []);

        $this->assertSame(1, $summary['matching']['source_matched']);
        $this->assertSame(1, $summary['matching']['source_needs_review']);
        $this->assertSame(1, $summary['matching']['source_no_iptv_match']);
    }

    public function test_ambiguous_enabled_channels_are_review_candidates_not_confirmed_coverage(): void
    {
        $source = ['number' => 737, 'name' => 'GEO TV', 'category' => 'International', 'aliases' => [], 'guide_valid' => true];
        [$rows, $summary] = $this->coverage([$source, [...$source, 'number' => 738]], [
            $this->channel(1, 'PK: GEO TV', true),
        ]);

        $this->assertSame('needs_review', $rows[0]['status']);
        $this->assertSame('review_mapping', $rows[0]['recommendation']);
        $this->assertSame(0, $rows[0]['enabled_matches']);
        $this->assertSame('', $rows[0]['matched_channel_ids']);
        $this->assertSame('1', $rows[0]['review_channel_ids']);
        $this->assertSame(1, $rows[0]['review_matches']);
        $this->assertSame(1, $summary['needs_review']);
        $this->assertEquals(0, $summary['coverage_percent']);
    }

    public function test_uncertain_enabled_match_does_not_mask_confirmed_disabled_coverage(): void
    {
        $source = [
            'number' => 125, 'name' => 'Discovery Channel', 'category' => 'Entertainment', 'aliases' => [],
            'profile_aliases' => ['DISC CHNL +1' => [5 => 'Provider names']], 'guide_valid' => true,
        ];
        [$rows, $summary] = $this->coverage([$source], [
            $this->channel(1, 'DISC CHNL +1', true), $this->channel(2, 'Discovery Channel', false),
        ]);

        $this->assertSame('disabled_only_no_fallback', $rows[0]['status']);
        $this->assertSame('auto_apply', $rows[0]['best_decision']);
        $this->assertSame(0, $rows[0]['enabled_matches']);
        $this->assertSame(1, $rows[0]['disabled_matches']);
        $this->assertSame('2', $rows[0]['matched_channel_ids']);
        $this->assertSame('1', $rows[0]['review_channel_ids']);
        $this->assertEquals(0, $summary['coverage_percent']);
    }

    public function test_coverage_respects_custom_alias_thresholds_like_sync(): void
    {
        $source = [['number' => 101, 'name' => 'BBC One North West', 'category' => 'Entertainment', 'aliases' => [], 'guide_valid' => true]];
        $channels = [$this->channel(1, 'BBC One', true)];
        [$default] = $this->coverage($source, $channels);
        $this->assertSame('enabled_coverage', $default[0]['status']);

        [$strict, $summary] = $this->coverage($source, $channels, [
            'min_alias_chars' => 100, 'min_alias_words' => 100, 'fuzzy_threshold' => 100, 'min_auto_score' => 100,
            'min_set_tokens' => 100, 'min_partial_len' => 100,
        ]);
        $this->assertSame('no_iptv_match', $strict[0]['status'], json_encode($strict));
        $this->assertEquals(0, $summary['coverage_percent']);
    }

    public function test_failover_counts_use_unique_channel_ids_and_exclude_invalid_fallbacks(): void
    {
        $primary = $this->channel(1, 'Discovery Channel', false)->forceFill(['user_id' => 1]);
        $second = $this->channel(2, 'Discovery Channel HD', false)->forceFill(['user_id' => 1]);
        $backup = fn (int $id, int $userId = 1, bool $vod = false): Channel => $this->channel($id, 'Same backup title', true)->forceFill(['user_id' => $userId, 'is_vod' => $vod]);
        $reference = fn (Channel $channel): ChannelFailover => (new ChannelFailover)->setRelation('channelFailover', $channel);
        $a = $backup(10);
        $b = $backup(11);
        $primary->setRelation('failovers', new Collection([$reference($a), $reference($a), $reference($backup(12, 2)), $reference($backup(13, 1, true))]));
        $second->setRelation('failovers', new Collection([$reference($a), $reference($b)]));
        $repository = new ChannelRepository(new CountryRulesRepository);
        $this->assertSame(1, $repository->failoverSummary($primary)['total']);
        $this->assertSame(1, $repository->failoverSummary($primary)['enabled']);
        [$rows] = $this->coverage([['number' => 125, 'name' => 'Discovery Channel', 'category' => 'Entertainment', 'aliases' => [], 'guide_valid' => true]], [$primary, $second]);
        $this->assertSame(2, $rows[0]['enabled_failovers']);
        $this->assertSame('disabled_only_with_enabled_fallback', $rows[0]['status']);
    }

    private function channel(int $id, string $title, bool $enabled): Channel
    {
        return (new Channel)->forceFill(['id' => $id, 'title' => $title, 'enabled' => $enabled])
            ->setRelation('failovers', new Collection);
    }

    private function coverage(array $sources, array $channels, array $settings = []): array
    {
        $countries = new CountryRulesRepository;
        $matcher = new ChannelMatcher($countries);
        $analyzer = new AvailabilityAnalyzer(new ChannelRepository($countries), $matcher);
        $rows = $analyzer->sourceCoverageRows(
            (new Playlist)->forceFill(['id' => 1, 'uuid' => 'test']), $sources, new Collection($channels), [],
            $matcher->thresholds($settings, []), $countries->resolve([], []),
        );

        return [$rows, $analyzer->sourceCoverageSummary($rows)];
    }
}
