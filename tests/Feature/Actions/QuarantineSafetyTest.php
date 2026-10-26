<?php

namespace AppLocalPlugins\GuideLink\Tests\Feature\Actions;

require_once dirname(__DIR__).'/PluginFeatureTestCase.php';

use App\Models\Channel;
use App\Models\ChannelFailover;
use App\Models\Group;
use App\Plugins\Support\PluginUninstallContext;
use AppLocalPlugins\GuideLink\Plugin;
use AppLocalPlugins\GuideLink\Services\ChannelQuarantineService;
use AppLocalPlugins\GuideLink\Tests\Feature\PluginFeatureTestCase;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

class QuarantineSafetyTest extends PluginFeatureTestCase
{
    use RefreshDatabase;

    public function test_missing_uuid_does_not_block_quarantine_or_later_release(): void
    {
        [$context, $playlist, $channel, $service, $plan] = $this->fixture();
        $this->assertNull($plan[0]['channel_uuid']);
        $this->assertSame(1, $service->apply($playlist, $plan, $context)['quarantined']);
        $this->assertFalse($channel->refresh()->enabled);
        $release = $service->plan($playlist, new Collection([$channel]), [
            ['channel_id' => $channel->id, 'decision' => 'auto_apply'],
        ], true, $context);

        $this->assertSame(1, $service->apply($playlist, $release, $context)['released']);
        $this->assertTrue((bool) $channel->refresh()->can_merge);
        $this->assertNull($channel->uuid);
        $this->assertSame('provider-stream-1', $channel->source_id);
    }

    public function test_quarantine_detaches_incoming_and_outgoing_links_but_keeps_valid_backups(): void
    {
        [$context, $playlist, $channel, $service, $plan] = $this->fixture();
        $primary = Channel::factory()->createQuietly(['playlist_id' => $playlist->id, 'user_id' => $playlist->user_id]);
        $backup = Channel::factory()->createQuietly(['playlist_id' => $playlist->id, 'user_id' => $playlist->user_id, 'enabled' => false]);
        foreach ([[$primary, $channel], [$channel, $backup], [$primary, $backup]] as [$master, $child]) {
            ChannelFailover::create(['user_id' => $playlist->user_id, 'channel_id' => $master->id, 'channel_failover_id' => $child->id]);
        }
        $result = $service->apply($playlist, $plan, $context);

        $this->assertSame(2, $result['failover_links_removed']);
        $this->assertFalse($channel->refresh()->enabled);
        $this->assertDatabaseCount('channel_failovers', 1);
        $this->assertDatabaseHas('channel_failovers', ['channel_id' => $primary->id, 'channel_failover_id' => $backup->id]);
        $this->assertFalse($backup->refresh()->enabled);
        $this->assertFalse($primary->failoverChannels()->where('channels.id', $channel->id)->exists());
    }

    public function test_already_quarantined_channels_with_old_links_are_cleaned_once(): void
    {
        [$context, $playlist, $channel, $service, $plan] = $this->fixture();
        $service->apply($playlist, $plan, $context);
        $primary = Channel::factory()->createQuietly(['playlist_id' => $playlist->id, 'user_id' => $playlist->user_id]);
        ChannelFailover::create(['user_id' => $playlist->user_id, 'channel_id' => $primary->id, 'channel_failover_id' => $channel->id]);
        $decisions = [['channel_id' => $channel->id, 'decision' => 'unmatched']];
        $plan = $service->plan($playlist, new Collection([$channel->refresh()]), $decisions, true, $context);

        $this->assertCount(1, $plan);
        $this->assertDatabaseCount('channel_failovers', 1); // Planning is read-only.
        $this->assertSame(1, $service->apply($playlist, $plan, $context)['failover_links_removed']);
        $this->assertSame(0, $service->apply($playlist, $plan, $context)['failover_links_removed']);
        $this->assertSame([], $service->plan($playlist, new Collection([$channel->refresh()]), $decisions, true, $context));
    }

    public function test_stale_quarantine_does_not_detach_links(): void
    {
        [$context, $playlist, $channel, $service, $plan] = $this->fixture();
        $primary = Channel::factory()->createQuietly(['playlist_id' => $playlist->id, 'user_id' => $playlist->user_id]);
        ChannelFailover::create(['user_id' => $playlist->user_id, 'channel_id' => $primary->id, 'channel_failover_id' => $channel->id]);
        $channel->forceFill(['source_id' => 'replacement-stream'])->saveQuietly();

        $this->assertSame(1, $service->apply($playlist, $plan, $context)['failed']);
        $this->assertDatabaseCount('channel_failovers', 1);
        $this->assertTrue($channel->refresh()->enabled);
    }

    public function test_failed_quarantine_rolls_back_backup_cleanup(): void
    {
        [$context, $playlist, $channel, $service, $plan] = $this->fixture();
        $primary = Channel::factory()->createQuietly(['playlist_id' => $playlist->id, 'user_id' => $playlist->user_id]);
        ChannelFailover::create(['user_id' => $playlist->user_id, 'channel_id' => $primary->id, 'channel_failover_id' => $channel->id]);
        Event::listen('eloquent.updating: '.Channel::class, function (): void {
            throw new \RuntimeException('Synthetic quarantine failure');
        });

        $this->assertSame(1, $service->apply($playlist, $plan, $context)['failed']);
        $this->assertTrue($channel->refresh()->enabled);
        $this->assertDatabaseCount('channel_failovers', 1);
        $this->assertDatabaseCount(ChannelQuarantineService::TABLE, 0);
    }

    public function test_provider_stream_changes_invalidate_quarantine_without_a_uuid(): void
    {
        [$context, $playlist, $channel, $service, $plan] = $this->fixture();
        $channel->forceFill(['source_id' => 'replacement-stream'])->saveQuietly();
        $this->assertSame(1, $service->apply($playlist, $plan, $context)['failed']);
        $this->assertTrue($channel->refresh()->enabled);
        $this->assertDatabaseCount(ChannelQuarantineService::TABLE, 0);
    }

    public function test_an_old_unmatched_decision_cannot_disable_a_renamed_channel(): void
    {
        [$context, $playlist, $channel, $service, $plan] = $this->fixture();
        $channel->forceFill(['name' => 'BBC One', 'title' => 'BBC One'])->saveQuietly();
        $result = $service->apply($playlist, $plan, $context);
        $this->assertSame(1, $result['failed']);
        $this->assertTrue($channel->refresh()->enabled);
        $this->assertDatabaseCount(ChannelQuarantineService::TABLE, 0);
    }

    public function test_cancellation_at_the_apply_heartbeat_leaves_channel_and_quarantine_unchanged(): void
    {
        [$context, $playlist, $channel, $service, $plan] = $this->fixture();
        Event::listen('eloquent.updated: '.get_class($context->run), function ($run) use ($context): void {
            if ($run->id === $context->run->id && str_starts_with((string) $run->progress_message, 'Applying channel quarantine')) {
                DB::table($run->getTable())->where('id', $run->id)->update(['cancel_requested' => true]);
            }
        });
        $result = $service->apply($playlist, $plan, $context);
        $this->assertTrue($result['aborted']);
        $this->assertSame(0, $result['quarantined']);
        $this->assertTrue($channel->refresh()->enabled);
        $this->assertDatabaseCount(ChannelQuarantineService::TABLE, 0);
    }

    public function test_replaying_quarantine_and_release_does_not_claim_duplicate_changes(): void
    {
        [$context, $playlist, $channel, $service, $plan] = $this->fixture();
        $this->assertSame(1, $service->apply($playlist, $plan, $context)['quarantined']);
        $again = $service->apply($playlist, $plan, $context);
        $this->assertSame(0, $again['quarantined']);
        $this->assertSame(1, $again['skipped']);
        $release = $service->plan($playlist, new Collection([$channel->refresh()]), [
            ['channel_id' => $channel->id, 'decision' => 'unmatched'],
        ], false, $context);
        $this->assertSame(1, $service->apply($playlist, $release, $context)['released']);
        $again = $service->apply($playlist, $release, $context);
        $this->assertSame(0, $again['released']);
        $this->assertSame(1, $again['skipped']);
        $this->assertFalse($channel->refresh()->enabled);
        $this->assertTrue((bool) $channel->can_merge);
    }

    public function test_purge_restores_recorded_merge_policy_without_enabling_channels(): void
    {
        [$context, $playlist, $channel, $service, $plan] = $this->fixture();
        $service->apply($playlist, $plan, $context);
        $manual = Channel::factory()->createQuietly([
            'playlist_id' => $playlist->id, 'user_id' => $playlist->user_id,
            'enabled' => false, 'can_merge' => false, 'is_vod' => false,
        ]);
        DB::table(ChannelQuarantineService::TABLE)->insert([
            'extension_plugin_id' => $context->plugin->id, 'playlist_id' => $playlist->id,
            'channel_id' => $manual->id, 'original_can_merge' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $plugin = new Plugin;
        $plugin->uninstall(new PluginUninstallContext($context->plugin, 'preserve', []));
        $this->assertFalse((bool) $channel->refresh()->can_merge);
        $this->assertDatabaseCount(ChannelQuarantineService::TABLE, 2);
        $plugin->uninstall(new PluginUninstallContext($context->plugin, 'purge', []));
        $this->assertTrue((bool) $channel->refresh()->can_merge);
        $this->assertFalse($channel->enabled);
        $this->assertFalse((bool) $manual->refresh()->can_merge);
        $this->assertFalse($manual->enabled);
        $this->assertDatabaseCount(ChannelQuarantineService::TABLE, 0);
        $plugin->uninstall(new PluginUninstallContext($context->plugin, 'purge', []));
        $this->assertTrue((bool) $channel->refresh()->can_merge);
    }

    public function test_release_rechecks_target_group_after_its_own_metadata_write(): void
    {
        [$context, $playlist, $channel, $service, $plan] = $this->fixture();
        $service->apply($playlist, $plan, $context);
        $release = $service->plan($playlist, new Collection([$channel->refresh()]), [
            ['channel_id' => $channel->id, 'decision' => 'auto_apply'],
        ], true, $context);
        $group = Group::factory()->createQuietly([
            'playlist_id' => $playlist->id, 'user_id' => $playlist->user_id, 'name' => 'Entertainment',
        ]);
        $changes = ['title_custom' => 'BBC One', 'name_custom' => 'BBC One', 'group_id' => $group->id];
        $channel->forceFill($changes)->saveQuietly();
        $group->forceFill(['name' => 'Personal channels'])->saveQuietly();
        $result = $service->apply($playlist, $release, $context, [[
            'channel_id' => $channel->id, 'native_changes' => $changes, 'desired' => ['group' => 'Entertainment'],
        ]]);
        $this->assertSame(1, $result['failed']);
        $this->assertSame(0, $result['released']);
        $this->assertFalse((bool) $channel->refresh()->can_merge);
        $this->assertDatabaseCount(ChannelQuarantineService::TABLE, 1);
    }

    private function fixture(): array
    {
        Bus::fake();
        [$context, $playlist] = $this->context(applyPluginSchema: true, dryRun: false);
        $channel = Channel::factory()->createQuietly([
            'uuid' => null, 'source_id' => 'provider-stream-1',
            'playlist_id' => $playlist->id, 'user_id' => $playlist->user_id, 'group_id' => null,
            'enabled' => true, 'can_merge' => true, 'is_vod' => false, 'name' => 'Unknown', 'title' => 'Unknown',
        ])->refresh();
        $service = new ChannelQuarantineService;
        $plan = $service->plan($playlist, new Collection([$channel]), [
            ['channel_id' => $channel->id, 'decision' => 'unmatched'],
        ], true, $context);

        return [$context, $playlist, $channel, $service, $plan];
    }
}
