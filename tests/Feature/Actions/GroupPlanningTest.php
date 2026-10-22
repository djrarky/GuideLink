<?php

namespace AppLocalPlugins\GuideLink\Tests\Feature\Actions;

require_once dirname(__DIR__).'/PluginFeatureTestCase.php';

use App\Models\Channel;
use App\Models\Group;
use App\Models\Playlist;
use AppLocalPlugins\GuideLink\Services\GroupSyncService;
use AppLocalPlugins\GuideLink\Support\CountryRulesRepository;
use AppLocalPlugins\GuideLink\Tests\Feature\PluginFeatureTestCase;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

class GroupPlanningTest extends PluginFeatureTestCase
{
    use RefreshDatabase;

    public function test_an_older_create_plan_reuses_groups_created_since_planning(): void
    {
        [$context, $playlist] = $this->context(dryRun: false);
        $service = new GroupSyncService(new CountryRulesRepository);
        $plan = $service->plan([['category' => 'Entertainment']], [], []);
        $first = $service->apply($playlist, $plan, $context);
        $second = $service->apply($playlist, $plan, $context);

        $this->assertSame(1, $first['groups_created']);
        $this->assertSame(0, $second['groups_created']);
        $this->assertSame(0, $second['groups_updated']);
        $this->assertSame($first['managed_group_ids'], $second['managed_group_ids']);
        $this->assertSame(1, Group::where('playlist_id', $playlist->id)->count());
    }

    public function test_renamed_managed_group_is_not_updated_using_an_old_plan(): void
    {
        [$context, $playlist] = $this->context(dryRun: false);
        $group = Group::factory()->createQuietly([
            'playlist_id' => $playlist->id, 'user_id' => $playlist->user_id,
            'name' => 'Entertainment', 'sort_order' => 88, 'custom' => true,
        ]);
        $service = new GroupSyncService(new CountryRulesRepository);
        $plan = $service->plan([['category' => 'Entertainment']], [], ['entertainment' => $group]);
        $group->forceFill(['name' => 'Personal channels'])->saveQuietly();
        $result = $service->apply($playlist, $plan, $context);
        $this->assertTrue($result['aborted']);
        $this->assertSame(1, $result['groups_failed']);
        $this->assertSame(88, (int) $group->refresh()->sort_order);
        $this->assertSame('Personal channels', $group->name);
    }

    public function test_fractional_group_order_is_corrected_and_intervening_changes_are_rejected(): void
    {
        [$context, $playlist] = $this->context(dryRun: false);
        $group = Group::factory()->createQuietly([
            'playlist_id' => $playlist->id, 'user_id' => $playlist->user_id,
            'name' => 'Entertainment', 'sort_order' => 1.5, 'custom' => true,
        ]);
        $service = new GroupSyncService(new CountryRulesRepository);
        $plan = $service->plan([['category' => 'Entertainment']], [], ['entertainment' => $group]);
        $this->assertSame('update_sort', $plan[0]['action']);
        $group->forceFill(['sort_order' => 1.75])->saveQuietly();
        $stale = $service->apply($playlist, $plan, $context);
        $this->assertSame(1, $stale['groups_failed']);
        $this->assertSame(1.75, (float) $group->refresh()->sort_order);

        $plan = $service->plan([['category' => 'Entertainment']], [], ['entertainment' => $group]);
        $first = $service->apply($playlist, $plan, $context);
        $second = $service->apply($playlist, $plan, $context);
        $this->assertSame(1, $first['groups_updated']);
        $this->assertSame(0, $second['groups_updated']);
        $this->assertSame(0, $second['groups_failed']);
        $this->assertSame(1.0, (float) $group->refresh()->sort_order);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Http::preventStrayRequests();
    }

    public function test_equivalent_source_categories_create_one_group_without_collapsing_provider_labels(): void
    {
        [$context, $playlist] = $this->context(applyPluginSchema: true, dryRun: false);
        $service = new GroupSyncService(new CountryRulesRepository);
        $entries = array_map(fn (string $category): array => ['category' => $category], [
            'Entertainment', ' entertainment ', 'ENTERTAINMENT', 'UK| Entertainment HD', '   ', 'Sports',
        ]);
        $plan = $service->plan($entries, [], []);
        $this->assertCount(3, $plan);
        $this->assertSame([1, 2, 3], array_column($plan, 'desired_sort_order'));
        $first = $service->apply($playlist, $plan, $context);
        $this->assertSame(3, $first['groups_created']);
        $this->assertSame(3, Group::where('playlist_id', $playlist->id)->count());
        $again = $service->apply($playlist, $service->plan($entries, [], $first['groups_by_name']), $context);
        $this->assertSame(0, $again['groups_created']);
        $this->assertSame(0, $again['groups_updated']);
    }

    public function test_sync_preserves_group_order_and_custom_flag_for_exact_and_regex_preserve_rules(): void
    {
        [$context, $playlist] = $this->context(applyPluginSchema: true, dryRun: false);
        $this->assignProfile($context, $playlist, [
            'guide_epg_id' => $this->guideEpgId($context),
            'preserve_groups' => ['Private'], 'preserve_group_name_regex' => ['^Personal'],
        ]);
        $before = [];
        foreach (['Private', 'Personal channels'] as $index => $name) {
            $group = Group::factory()->createQuietly([
                'playlist_id' => $playlist->id, 'user_id' => $playlist->user_id,
                'name' => $name, 'sort_order' => 88 + $index, 'type' => 'live', 'custom' => false,
                'import_batch_no' => null, 'source_group_id' => null,
            ]);
            $channel = Channel::factory()->createQuietly([
                'playlist_id' => $playlist->id, 'user_id' => $playlist->user_id, 'group_id' => $group->id,
                'enabled' => true, 'is_vod' => false, 'channel' => 101 + $index, 'title' => 'BBC One',
            ]);
            $before[] = [$group, $group->refresh()->getAttributes(), $channel, $channel->refresh()->getAttributes()];
        }

        $result = $this->runGuideLinkAction('apply_sync_playlist', ['playlist_id' => $playlist->id], $context);
        $this->assertTrue($result->success, $result->summary);
        foreach ($before as [$group, $groupState, $channel, $channelState]) {
            $this->assertSame($groupState, $group->refresh()->getAttributes());
            $this->assertSame($channelState, $channel->refresh()->getAttributes());
        }
        $this->assertSame(0, $result->data['writes']['groups_updated']);
        $this->assertSame(2, $result->data['writes']['groups_preserved']);
    }

    public function test_preserved_source_category_is_not_created_or_used_to_move_matched_channels(): void
    {
        [$context, $playlist] = $this->context(applyPluginSchema: true, dryRun: false);
        $source = Playlist::factory()->for($context->user)->createQuietly();
        $this->assignProfile($context, $playlist, [
            'guide_epg_id' => $this->guideEpgId($context), 'source_playlist_id' => $source->id,
            'preserve_groups' => ['Private'],
        ]);
        Channel::factory()->createQuietly([
            'playlist_id' => $source->id, 'user_id' => $source->user_id, 'group_id' => null,
            'group_internal' => 'Private', 'title' => 'BBC One', 'name' => 'BBC One',
            'title_custom' => null, 'name_custom' => null, 'channel' => 101, 'enabled' => true, 'is_vod' => false,
        ]);
        $group = Group::factory()->createQuietly([
            'playlist_id' => $playlist->id, 'user_id' => $playlist->user_id,
            'name' => 'Provider group', 'type' => 'live',
        ]);
        $channel = Channel::factory()->createQuietly([
            'playlist_id' => $playlist->id, 'user_id' => $playlist->user_id, 'group_id' => $group->id,
            'title' => 'BBC One', 'name' => 'BBC One', 'title_custom' => null, 'name_custom' => null,
            'channel' => 999, 'stream_id_custom' => null, 'enabled' => true, 'is_vod' => false,
        ]);

        $result = $this->runGuideLinkAction('apply_sync_playlist', ['playlist_id' => $playlist->id], $context);
        $this->assertTrue($result->success, $result->summary);
        $this->assertSame(101, (int) $channel->refresh()->channel);
        $this->assertSame($group->id, $channel->group_id);
        $this->assertSame(0, Group::where('playlist_id', $playlist->id)->where('name', 'Private')->count());
        $this->assertSame(0, $result->data['writes']['missing_groups']);
        $again = $this->runGuideLinkAction('apply_sync_playlist', ['playlist_id' => $playlist->id], $context);
        $this->assertTrue($again->success, $again->summary);
        $this->assertSame(0, $again->data['writes']['channels_written']);
    }

    public function test_provider_cleanup_rejects_a_group_renamed_after_planning(): void
    {
        [$context, $playlist, $group, $service, $plan] = $this->providerCleanupFixture();
        $group->forceFill(['name' => 'Keep this group'])->saveQuietly();
        $result = $service->applyDisabling($playlist, $plan, $context);
        $this->assertSame(1, $result['failed']);
        $this->assertTrue((bool) $group->refresh()->enabled);
    }

    public function test_provider_cleanup_does_not_hide_channels_added_after_planning(): void
    {
        [$context, $playlist, $group, $service, $plan] = $this->providerCleanupFixture();
        $channel = Channel::factory()->createQuietly([
            'playlist_id' => $playlist->id, 'user_id' => $playlist->user_id,
            'group_id' => $group->id, 'enabled' => true, 'is_vod' => false,
        ]);
        $this->assertSame(1, $service->applyDisabling($playlist, $plan, $context)['failed']);
        $channel->forceFill(['enabled' => false, 'can_merge' => false])->saveQuietly();
        $this->assertSame(1, $service->applyDisabling($playlist, $plan, $context)['failed']);
        $this->assertTrue((bool) $group->refresh()->enabled);
    }

    public function test_provider_cleanup_rechecks_cancellation_after_its_heartbeat(): void
    {
        [$context, $playlist, $group, $service, $plan] = $this->providerCleanupFixture();
        Event::listen('eloquent.updated: '.get_class($context->run), function ($run) use ($context): void {
            if ($run->id === $context->run->id && str_starts_with((string) $run->progress_message, 'Disabling provider groups:')) {
                DB::table($run->getTable())->where('id', $run->id)->update(['cancel_requested' => true]);
            }
        });
        $result = $service->applyDisabling($playlist, $plan, $context);
        $this->assertTrue($result['aborted']);
        $this->assertSame(0, $result['disabled']);
        $this->assertTrue((bool) $group->refresh()->enabled);
    }

    public function test_provider_cleanup_replay_does_not_count_another_change(): void
    {
        [$context, $playlist, $group, $service, $plan] = $this->providerCleanupFixture();
        $this->assertSame(1, $service->applyDisabling($playlist, $plan, $context)['disabled']);
        $again = $service->applyDisabling($playlist, $plan, $context);
        $this->assertSame(0, $again['disabled']);
        $this->assertSame(1, $again['skipped']);
        $this->assertFalse((bool) $group->refresh()->enabled);
    }

    public function test_provider_cleanup_batches_database_work_for_large_playlists(): void
    {
        [$context, $playlist] = $this->context(applyPluginSchema: true, dryRun: false);
        $service = new GroupSyncService(new CountryRulesRepository);
        foreach ([86, 251] as $count) {
            Group::factory()->count($count)->createQuietly([
                'playlist_id' => $playlist->id, 'user_id' => $playlist->user_id,
                'enabled' => true, 'custom' => false, 'type' => 'live',
            ]);
            $plan = $service->planDisabling($playlist, new Collection, [], [], [], ['disable_other_groups' => true]);
            DB::enableQueryLog();
            try {
                $result = $service->applyDisabling($playlist, $plan, $context);
                $queries = DB::getQueryLog();
            } finally {
                DB::disableQueryLog();
                DB::flushQueryLog();
            }
            $this->assertSame($count, $result['disabled']);
            $this->assertSame(0, $result['failed']);
            $this->assertLessThanOrEqual(12 * ceil($count / 250), count($queries), 'Cleanup should use bounded batches, including progress and cancellation queries.');
        }
    }

    public function test_provider_cleanup_keeps_changed_groups_enabled_while_disabling_safe_groups_in_the_same_batch(): void
    {
        [$context, $playlist, $renamed, $service] = $this->providerCleanupFixture();
        [$imported, $safe, $disabled] = Group::factory()->count(3)->createQuietly([
            'playlist_id' => $playlist->id, 'user_id' => $playlist->user_id,
            'enabled' => true, 'custom' => false, 'type' => 'live',
        ]);
        $plan = $service->planDisabling($playlist, new Collection, [], [], [], ['disable_other_groups' => true]);
        $renamed->forceFill(['name' => 'Personal channels'])->saveQuietly();
        $disabled->forceFill(['enabled' => false])->saveQuietly();
        Channel::factory()->createQuietly([
            'playlist_id' => $playlist->id, 'user_id' => $playlist->user_id,
            'group_id' => $imported->id, 'enabled' => true, 'is_vod' => false,
        ]);
        $result = $service->applyDisabling($playlist, $plan, $context);
        $this->assertSame(1, $result['disabled']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(2, $result['failed']);
        $this->assertTrue((bool) $renamed->refresh()->enabled);
        $this->assertTrue((bool) $imported->refresh()->enabled);
        $this->assertFalse((bool) $safe->refresh()->enabled);
        $this->assertFalse((bool) $disabled->refresh()->enabled);
    }

    private function providerCleanupFixture(): array
    {
        [$context, $playlist] = $this->context(applyPluginSchema: true, dryRun: false);
        $group = Group::factory()->createQuietly([
            'playlist_id' => $playlist->id, 'user_id' => $playlist->user_id,
            'name' => 'UK| GENERAL', 'enabled' => true, 'custom' => false, 'type' => 'live',
        ])->refresh();
        $service = new GroupSyncService(new CountryRulesRepository);
        $plan = $service->planDisabling($playlist, new Collection, [], [], [], ['disable_other_groups' => true]);
        $this->assertCount(1, $plan);

        return [$context, $playlist, $group, $service, $plan];
    }
}
