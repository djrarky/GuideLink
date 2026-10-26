<?php

namespace AppLocalPlugins\GuideLink\Tests\Feature\Actions;

require_once dirname(__DIR__).'/PluginFeatureTestCase.php';

use App\Models\Channel;
use App\Models\Group;
use App\Models\Playlist;
use AppLocalPlugins\GuideLink\Services\ChannelMatcher;
use AppLocalPlugins\GuideLink\Services\MatchedChannelRecoveryService;
use AppLocalPlugins\GuideLink\Support\CountryRulesRepository;
use AppLocalPlugins\GuideLink\Tests\Feature\PluginFeatureTestCase;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

class MatchedChannelRecoveryTest extends PluginFeatureTestCase
{
    use RefreshDatabase;

    #[DataProvider('identityChanges')]
    public function test_stale_matches_cannot_restore_mergeability_or_enable_any_channel(string $field, mixed $value): void
    {
        [$context, $playlist, $channels, $matches] = $this->fixture();
        $channels->last()->forceFill([$field => $value])->saveQuietly();
        $before = $channels->fresh()->map->getAttributes()->all();

        $result = (new MatchedChannelRecoveryService)->apply($playlist, $matches, [], $context);

        $this->assertSame(1, $result['failed']);
        $this->assertSame($before, $channels->fresh()->map->getAttributes()->all());
        $this->assertDatabaseCount('channel_failovers', 0);
    }

    public static function identityChanges(): array
    {
        return [
            ['source_id', 'replacement-stream'],
            ['uuid', ''],
            ['name', 'Different channel'],
        ];
    }

    public function test_merge_failure_rolls_back_primary_backup_and_mergeability_changes_together(): void
    {
        [$context, $playlist, $channels, $matches] = $this->fixture();
        $before = $channels->map->getAttributes()->all();
        DB::listen(function (QueryExecuted $query): void {
            if (str_starts_with($query->sql, 'insert into "channel_failovers"')) {
                throw new \RuntimeException('Simulated failover persistence failure');
            }
        });

        $result = (new MatchedChannelRecoveryService)->apply($playlist, $matches, [], $context);

        $this->assertSame(1, $result['failed']);
        $this->assertStringContainsString('Simulated failover', implode(' ', $result['errors']));
        $this->assertSame($before, $channels->fresh()->map->getAttributes()->all());
        $this->assertDatabaseCount('channel_failovers', 0);
    }

    public function test_cancellation_prevents_recovery(): void
    {
        [$context, $playlist, $channels, $matches] = $this->fixture();
        $before = $channels->map->getAttributes()->all();
        $context->run->forceFill(['cancel_requested' => true])->save();

        $result = (new MatchedChannelRecoveryService)->apply($playlist, $matches, [], $context);

        $this->assertTrue($result['aborted']);
        $this->assertSame($before, $channels->fresh()->map->getAttributes()->all());
        $this->assertDatabaseCount('channel_failovers', 0);
    }

    public function test_unmatched_and_preserved_channels_do_not_enter_recovery(): void
    {
        [$context, $playlist, $channels, $matches] = $this->fixture();
        $matches[1]['decision'] = 'needs_review';
        $before = $channels->last()->getAttributes();
        $preserved = $channels->last()->replicate(['source_id']);
        $preserved->forceFill(['source_id' => 'preserved', 'enabled' => false, 'can_merge' => true])->saveQuietly();
        $preservedBefore = $preserved->refresh()->getAttributes();

        $result = (new MatchedChannelRecoveryService)->apply($playlist, $matches, [], $context);

        $this->assertSame(0, $result['failed']);
        $this->assertTrue($channels->first()->refresh()->enabled);
        $this->assertSame($before, $channels->last()->refresh()->getAttributes());
        $this->assertSame($preservedBefore, $preserved->refresh()->getAttributes());
        $this->assertDatabaseCount('channel_failovers', 0);
    }

    public function test_configured_preferred_playlist_can_supply_the_primary(): void
    {
        [$context, $playlist, $channels, $matches] = $this->fixture();
        $other = Playlist::factory()->for($context->user)->createQuietly();
        $primary = $channels->first()->replicate(['source_id']);
        $primary->forceFill(['playlist_id' => $other->id, 'source_id' => 'other-primary', 'url' => 'https://example.test/other-stream', 'enabled' => true, 'can_merge' => true])->saveQuietly();
        $playlist->forceFill(['auto_merge_config' => [
            'preferred_playlist_id' => $other->id, 'check_resolution' => false,
        ]])->saveQuietly();

        $result = (new MatchedChannelRecoveryService)->apply($playlist, $matches, [], $context);

        $this->assertSame(0, $result['failed']);
        $this->assertSame(0, $result['enabled']);
        $this->assertTrue($primary->refresh()->enabled);
        foreach ($channels->fresh() as $channel) {
            $this->assertFalse($channel->enabled);
            $this->assertTrue((bool) $channel->can_merge);
            $this->assertDatabaseHas('channel_failovers', ['channel_id' => $primary->id, 'channel_failover_id' => $channel->id]);
        }
    }

    public function test_recovery_limits_large_reads_to_matching_streams_using_native_key_rules(): void
    {
        [$context, $playlist, $channels, $matches] = $this->fixture();
        $other = Playlist::factory()->for($context->user)->createQuietly();
        $peer = $channels->first()->replicate(['source_id']);
        $peer->forceFill([
            'playlist_id' => $other->id, 'source_id' => 'peer', 'enabled' => true, 'can_merge' => true,
            'stream_id_custom' => '0', 'stream_id' => "\tBBC.ONE\n", 'url' => 'https://example.test/peer',
        ])->saveQuietly();
        $unrelated = $peer->replicate(['source_id']);
        $unrelated->forceFill(['source_id' => 'unrelated', 'stream_id' => 'unrelated', 'stream_stats' => ['large' => str_repeat('x', 10000)]])->saveQuietly();
        $playlist->forceFill(['auto_merge_config' => ['preferred_playlist_id' => $other->id]])->saveQuietly();
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (str_starts_with($query->sql, 'select ') && str_contains($query->sql, 'from "channels"')) {
                $queries[] = $query->sql;
            }
        });

        $result = (new MatchedChannelRecoveryService)->apply($playlist, $matches, [], $context);

        $this->assertSame(0, $result['failed'], implode(' ', $result['errors']));
        $this->assertDatabaseHas('channel_failovers', ['channel_id' => $peer->id, 'channel_failover_id' => $channels->first()->id]);
        $ranking = array_values(array_filter($queries, fn (string $sql): bool => str_contains($sql, '"stream_stats"')));
        $this->assertCount(1, $ranking);
        $ids = implode(', ', [...$channels->modelKeys(), $peer->id]);
        $this->assertStringContainsString('"id" in ('.$ids.')', $ranking[0]);
        $this->assertStringNotContainsString('select *', $ranking[0]);
        $this->assertDatabaseMissing('channel_failovers', ['channel_failover_id' => $unrelated->id]);
    }

    public function test_disabled_group_exclusion_does_not_promote_a_matched_channel(): void
    {
        [$context, $playlist, $channels] = $this->fixture();
        $group = Group::factory()->createQuietly(['playlist_id' => $playlist->id, 'user_id' => $playlist->user_id, 'enabled' => false]);
        foreach ($channels as $channel) {
            $channel->forceFill(['group_id' => $group->id, 'enabled' => true])->saveQuietly();
        }
        $matches = $this->matchRows($playlist, $channels->fresh(['group']));
        $playlist->forceFill(['auto_merge_config' => ['exclude_disabled_groups' => true]])->saveQuietly();

        $result = (new MatchedChannelRecoveryService)->apply($playlist, $matches, [], $context);

        $this->assertSame(0, $result['failed']);
        $this->assertSame(2, $result['disabled']);
        $this->assertDatabaseCount('channel_failovers', 0);
    }

    private function fixture(): array
    {
        Bus::fake();
        [$context, $playlist] = $this->context(applyPluginSchema: true, dryRun: false);
        $playlist->forceFill(['auto_merge_channels_enabled' => true, 'auto_merge_deactivate_failover' => true])->saveQuietly();
        $channels = Channel::factory()->count(2)->createQuietly([
            'playlist_id' => $playlist->id, 'user_id' => $playlist->user_id, 'uuid' => null,
            'is_vod' => false, 'enabled' => false, 'can_merge' => false, 'group_id' => null,
            'name' => 'BBC One', 'title' => 'BBC One', 'name_custom' => null, 'title_custom' => null,
            'stream_id' => 'provider', 'stream_id_custom' => 'bbc.one', 'channel' => 101,
            'last_scrubber_live' => true, 'stream_stats' => null,
        ])->fresh(['group']);

        return [$context, $playlist, $channels, $this->matchRows($playlist, $channels)];
    }

    private function matchRows(Playlist $playlist, Collection $channels): array
    {
        $countries = new CountryRulesRepository;
        $matcher = new ChannelMatcher($countries);

        return $matcher->rows($playlist, $channels, [[
            'number' => 101, 'name' => 'BBC One', 'category' => '', 'aliases' => [],
            'stream_id' => 'bbc.one', 'guide_valid' => true,
        ]], $matcher->thresholds([], []), $countries->resolve([], []));
    }
}
