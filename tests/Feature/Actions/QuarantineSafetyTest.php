<?php

namespace AppLocalPlugins\GuideLink\Tests\Feature\Actions;

require_once dirname(__DIR__).'/PluginFeatureTestCase.php';

use App\Models\Channel;
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
