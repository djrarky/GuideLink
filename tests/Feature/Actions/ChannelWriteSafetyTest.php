<?php

namespace AppLocalPlugins\GuideLink\Tests\Feature\Actions;

require_once dirname(__DIR__).'/PluginFeatureTestCase.php';

use App\Models\Channel;
use App\Models\Group;
use App\Models\Playlist;
use AppLocalPlugins\GuideLink\Services\ChannelMatcher;
use AppLocalPlugins\GuideLink\Services\ChannelWriter;
use AppLocalPlugins\GuideLink\Services\WritePlanBuilder;
use AppLocalPlugins\GuideLink\Support\CountryRulesRepository;
use AppLocalPlugins\GuideLink\Tests\Feature\PluginFeatureTestCase;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;

class ChannelWriteSafetyTest extends PluginFeatureTestCase
{
    use RefreshDatabase;

    #[DataProvider('numericStreamIds')]
    public function test_numeric_looking_stream_ids_keep_their_exact_identity(string $current, string $desired): void
    {
        [$context, , $channel, $plan] = $this->fixture($current, $desired);
        $this->assertSame($desired, $plan[0]['native_changes']['stream_id_custom'] ?? null);
        $result = (new ChannelWriter)->apply($plan, $context);
        $this->assertSame(1, $result['channels_written']);
        $this->assertSame($desired, $channel->refresh()->stream_id_custom);
    }

    public static function numericStreamIds(): array
    {
        return [['00101', '101'], ['9007199254740992', '9007199254740993'], ['1e2', '100']];
    }

    public function test_reapplying_an_already_satisfied_plan_does_not_save_or_count_a_write(): void
    {
        [$context, , $channel, $plan] = $this->fixture();
        $writer = new ChannelWriter;
        $this->assertSame(1, $writer->apply($plan, $context)['channels_written']);
        Event::fake(['eloquent.saving: '.Channel::class]);

        $again = $writer->apply($plan, $context);

        $this->assertSame(0, $again['channels_written']);
        $this->assertSame(1, $again['channels_skipped']);
        $this->assertSame([], $again['written_ids']);
        Event::assertNotDispatched('eloquent.saving: '.Channel::class);
        $this->assertSame('BBC One', $channel->refresh()->title_custom);
    }

    public function test_stale_plan_preserves_an_intervening_edit_without_partial_channel_writes(): void
    {
        [$context, , $channel, $plan] = $this->fixture();
        $channel->forceFill(['title_custom' => 'My edited title'])->saveQuietly();
        $before = $channel->refresh()->getAttributes();

        $result = (new ChannelWriter)->apply($plan, $context);

        $this->assertSame(1, $result['channels_failed']);
        $this->assertSame(0, $result['channels_written']);
        $this->assertStringContainsString('changed after planning', implode(' ', $result['errors']));
        $this->assertSame($before, $channel->refresh()->getAttributes());
    }

    public function test_provider_identity_changes_invalidate_the_old_metadata_plan(): void
    {
        [$context, , $channel, $plan] = $this->fixture();
        $channel->forceFill(['name' => 'Sky News', 'title' => 'Sky News'])->saveQuietly();
        $before = $channel->refresh()->getAttributes();
        $result = (new ChannelWriter)->apply($plan, $context);
        $this->assertSame(1, $result['channels_failed']);
        $this->assertSame($before, $channel->refresh()->getAttributes());
    }

    public function test_partly_completed_plan_finishes_without_overwriting_unrelated_changes(): void
    {
        [$context, , $channel, $plan] = $this->fixture();
        $channel->forceFill(['title_custom' => 'BBC One', 'enabled' => false])->saveQuietly();

        $result = (new ChannelWriter)->apply($plan, $context);

        $this->assertSame(1, $result['channels_written']);
        $this->assertSame(0, $result['channels_failed']);
        $this->assertFalse($channel->refresh()->enabled);
        $this->assertSame('bbc.one', $channel->stream_id_custom);
        $this->assertEquals(101, $channel->channel);
    }

    public function test_cancellation_during_the_write_heartbeat_prevents_the_next_save(): void
    {
        [$context, , $channel, $plan] = $this->fixture();
        $before = $channel->getAttributes();
        Event::listen('eloquent.updated: '.get_class($context->run), function ($run) use ($context): void {
            if ($run->id === $context->run->id && str_starts_with((string) $run->progress_message, 'Channel writes:')) {
                DB::table($run->getTable())->where('id', $run->id)->update(['cancel_requested' => true]);
            }
        });

        $result = (new ChannelWriter)->apply($plan, $context);

        $this->assertTrue($result['aborted']);
        $this->assertSame(0, $result['channels_written']);
        $this->assertSame($before, $channel->refresh()->getAttributes());
    }

    public function test_plan_cannot_follow_a_channel_into_another_playlist(): void
    {
        [$context, , $channel, $plan] = $this->fixture();
        $other = Playlist::factory()->for($context->user)->createQuietly();
        $channel->forceFill(['playlist_id' => $other->id])->saveQuietly();
        $before = $channel->refresh()->getAttributes();

        $result = (new ChannelWriter)->apply($plan, $context);

        $this->assertSame(1, $result['channels_failed']);
        $this->assertSame(0, $result['channels_written']);
        $this->assertSame($before, $channel->refresh()->getAttributes());
    }

    public function test_a_renamed_target_group_does_not_receive_channels_from_an_old_plan(): void
    {
        [$context, $playlist, $channel, $plan] = $this->fixture();
        $target = Group::factory()->createQuietly([
            'playlist_id' => $playlist->id, 'user_id' => $playlist->user_id, 'name' => 'Entertainment',
        ]);
        $plan[0]['native_changes']['group_id'] = $target->id;
        $plan[0]['native_originals']['group_id'] = null;
        $plan[0]['desired']['group'] = 'Entertainment';
        $target->forceFill(['name' => 'Personal channels'])->saveQuietly();
        $before = $channel->refresh()->getAttributes();
        $result = (new ChannelWriter)->apply($plan, $context);
        $this->assertSame(1, $result['channels_failed']);
        $this->assertStringContainsString('target group changed', implode(' ', $result['errors']));
        $this->assertSame($before, $channel->refresh()->getAttributes());
    }

    private function fixture(string $currentId = 'provider.one', string $desiredId = 'bbc.one'): array
    {
        Bus::fake();
        [$context, $playlist] = $this->context(dryRun: false);
        $channel = Channel::factory()->createQuietly([
            'playlist_id' => $playlist->id, 'user_id' => $playlist->user_id, 'group_id' => null,
            'is_vod' => false, 'enabled' => true, 'title' => 'UK: BBC One HD', 'name' => 'UK: BBC One HD',
            'title_custom' => null, 'name_custom' => null, 'stream_id_custom' => null,
            'channel' => 501, 'sort' => 501, 'stream_id' => $currentId,
        ])->refresh();
        $countries = new CountryRulesRepository;
        $matcher = new ChannelMatcher($countries);
        $rules = $countries->resolve([], []);
        $matches = $matcher->rows($playlist, new Collection([$channel]), [[
            'number' => 101, 'name' => 'BBC One', 'category' => '', 'aliases' => [],
            'stream_id' => $desiredId, 'guide_valid' => true,
        ]], $matcher->thresholds([], []), $rules);
        $plan = (new WritePlanBuilder($countries))->rows($matches, [], $rules);
        $this->assertCount(1, $plan);

        return [$context, $playlist, $channel, $plan];
    }
}
