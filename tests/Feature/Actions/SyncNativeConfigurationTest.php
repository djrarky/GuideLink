<?php

namespace AppLocalPlugins\GuideLink\Tests\Feature\Actions;

require_once dirname(__DIR__).'/PluginFeatureTestCase.php';

use App\Jobs\MergeChannels;
use App\Jobs\ProcessChannelScrubber;
use App\Models\Channel;
use App\Models\ChannelScrubber;
use App\Models\Group;
use App\Models\Playlist;
use App\Plugins\Support\PluginExecutionContext;
use AppLocalPlugins\GuideLink\Actions\SyncPlaylistAction;
use AppLocalPlugins\GuideLink\Plugin;
use AppLocalPlugins\GuideLink\Services\ChannelQuarantineService;
use AppLocalPlugins\GuideLink\Services\ChannelWriter;
use AppLocalPlugins\GuideLink\Services\GroupSyncService;
use AppLocalPlugins\GuideLink\Services\GuideLinkServices;
use AppLocalPlugins\GuideLink\Services\ProfileRepository;
use AppLocalPlugins\GuideLink\Services\RunResultRepository;
use AppLocalPlugins\GuideLink\Services\ScrubberSetupService;
use AppLocalPlugins\GuideLink\Tests\Feature\PluginFeatureTestCase;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SyncNativeConfigurationTest extends PluginFeatureTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Http::preventStrayRequests();
        Storage::fake('local');
    }

    public function test_preview_reports_native_and_channel_changes_without_applying_them(): void
    {
        [$context, $playlist] = $this->context(applyPluginSchema: true);
        $channel = $this->configure($context, $playlist);
        $before = [$playlist->refresh()->getAttributes(), $channel->getAttributes()];

        $result = $this->runGuideLinkAction('sync_playlist', ['playlist_id' => $playlist->id], $context);

        $this->assertTrue($result->success, $result->summary);
        $this->assertSame('change_required', $result->data['native_settings']['auto_merge']['status']);
        $this->assertSame('change_required', $result->data['native_settings']['scrubber']['status']);
        $this->assertGreaterThan(0, $result->data['writes']['write_plan_channels']);
        $this->assertSame($before, [$playlist->refresh()->getAttributes(), $channel->refresh()->getAttributes()]);
        $this->assertSame(0, ChannelScrubber::count());
        foreach (['auto_merge_audit', 'scrubber_audit', 'sync_match'] as $type) {
            $this->assertDatabaseHas(RunResultRepository::TABLE, [
                'extension_plugin_run_id' => $context->run->id, 'result_type' => $type,
            ]);
        }
        Bus::assertNotDispatched(ProcessChannelScrubber::class);
        Bus::assertNotDispatched(MergeChannels::class);
    }

    public function test_run_applies_native_settings_and_metadata_without_a_separate_setup_or_scan(): void
    {
        [$context, $playlist] = $this->context(applyPluginSchema: true, dryRun: false);
        $backup = Playlist::factory()->for($context->user)->createQuietly();
        $backupBefore = $backup->refresh()->getAttributes();
        $channel = $this->configure($context, $playlist, ['auto_merge_failover_playlist_ids' => [$backup->id]]);
        $playlist->forceFill(['auto_merge_config' => ['custom_option' => 'preserved']])->saveQuietly();
        $profile = DB::table(ProfileRepository::PROFILES_TABLE)->first();
        $assignment = DB::table(ProfileRepository::ASSIGNMENTS_TABLE)->first();

        $result = $this->runGuideLinkAction('apply_sync_playlist', ['playlist_id' => $playlist->id], $context);

        $this->assertTrue($result->success, $result->summary);
        $this->assertTrue($playlist->refresh()->auto_merge_channels_enabled);
        $this->assertSame('preserved', $playlist->auto_merge_config['custom_option']);
        $this->assertSame([['playlist_failover_id' => (int) $backup->id]], $playlist->auto_merge_config['failover_playlists']);
        $this->assertTrue($playlist->auto_merge_config['scrubber_aware_master_selection']);
        $this->assertTrue($playlist->auto_merge_config['check_resolution']);
        $this->assertTrue($playlist->auto_merge_config['prefer_catchup_as_primary']);
        $this->assertSame('bbc.one', $channel->refresh()->stream_id_custom);
        $this->assertSame(1, $result->data['writes']['channels_written']);
        $scrubber = ChannelScrubber::where('playlist_id', $playlist->id)->firstOrFail();
        $this->assertTrue($scrubber->recurring);
        $this->assertTrue($scrubber->scan_all);
        $this->assertTrue($scrubber->disable_dead);
        $this->assertTrue($scrubber->protect_failover_channels);
        $this->assertTrue($scrubber->rebuild_failovers_after_scan);
        $this->assertFalse($scrubber->enable_live);
        $this->assertFalse($scrubber->include_vod);
        $this->assertSame($backupBefore, $backup->refresh()->getAttributes());
        $this->assertEquals($profile, DB::table(ProfileRepository::PROFILES_TABLE)->first());
        $this->assertEquals($assignment, DB::table(ProfileRepository::ASSIGNMENTS_TABLE)->first());
        Bus::assertNotDispatched(ProcessChannelScrubber::class);
        Bus::assertNotDispatched(MergeChannels::class);

        $again = $this->runGuideLinkAction('apply_sync_playlist', ['playlist_id' => $playlist->id], $context);
        $this->assertTrue($again->success, $again->summary);
        $this->assertSame('ok', $again->data['native_settings']['auto_merge']['status']);
        $this->assertSame('ok', $again->data['native_settings']['scrubber']['status']);
        $this->assertSame(1, ChannelScrubber::count());
    }

    public function test_sync_plans_and_writes_using_the_same_existing_group(): void
    {
        [$context, $playlist] = $this->context(applyPluginSchema: true, dryRun: false);
        $channel = $this->configure($context, $playlist);
        $channel->forceFill(['group_internal' => 'Entertainment'])->saveQuietly();
        Channel::factory()->createQuietly([
            'user_id' => $playlist->user_id, 'playlist_id' => $playlist->id, 'group_id' => null,
            'name' => 'Test Children', 'title' => 'Test Children', 'group_internal' => 'Children',
            'channel' => 100, 'enabled' => true, 'is_vod' => false,
        ]);
        $groups = [];
        foreach ([0, 1] as $sortOrder) {
            $groups[] = Group::factory()->createQuietly([
                'user_id' => $playlist->user_id, 'playlist_id' => $playlist->id,
                'name' => 'Entertainment', 'type' => 'live', 'sort_order' => $sortOrder,
            ]);
        }

        $result = $this->runGuideLinkAction('apply_sync_playlist', ['playlist_id' => $playlist->id], $context);

        $this->assertTrue($result->success, $result->summary);
        $this->assertSame((int) $groups[0]->id, (int) $channel->refresh()->group_id);
        $this->assertSame(1, $result->data['writes']['groups_updated']);
        $this->assertSame(2, (int) $groups[0]->refresh()->sort_order);
        $this->assertSame(1, (int) $groups[1]->refresh()->sort_order);
    }

    public function test_next_run_uses_current_profile_and_preserves_unrelated_native_options(): void
    {
        [$context, $playlist] = $this->context(applyPluginSchema: true, dryRun: false);
        $this->configure($context, $playlist);
        $payload = ['playlist_id' => $playlist->id];
        $this->assertTrue($this->runGuideLinkAction('apply_sync_playlist', $payload, $context)->success);
        $profile = DB::table(ProfileRepository::PROFILES_TABLE)->first();
        $settings = json_decode($profile->settings, true);
        $settings['scrubber_probe_timeout'] = 20;
        $settings['auto_merge_check_resolution'] = false;
        DB::table(ProfileRepository::PROFILES_TABLE)->where('id', $profile->id)->update(['settings' => json_encode($settings)]);
        $playlist->refresh()->forceFill(['auto_merge_config' => [...$playlist->auto_merge_config, 'custom_option' => 'new']])->saveQuietly();

        $result = $this->runGuideLinkAction('apply_sync_playlist', $payload, $context);

        $this->assertTrue($result->success, $result->summary);
        $this->assertSame(20, ChannelScrubber::firstOrFail()->probe_timeout);
        $this->assertFalse($playlist->refresh()->auto_merge_config['check_resolution']);
        $this->assertSame('new', $playlist->auto_merge_config['custom_option']);
    }

    public function test_user_managed_scrubber_is_preserved(): void
    {
        [$context, $playlist] = $this->context(applyPluginSchema: true, dryRun: false);
        $scrubber = ChannelScrubber::withoutEvents(fn () => ChannelScrubber::create([
            'name' => 'Operator scrubber', 'user_id' => $context->user->id, 'playlist_id' => $playlist->id,
            'status' => 'pending', 'recurring' => false, 'check_method' => 'http', 'probe_timeout' => 20,
        ]));
        $before = $scrubber->refresh()->getAttributes();
        $this->configure($context, $playlist, ['scrubber_id' => $scrubber->id]);

        $result = $this->runGuideLinkAction('apply_sync_playlist', ['playlist_id' => $playlist->id], $context);

        $this->assertTrue($result->success, $result->summary);
        $this->assertSame('validated', $result->data['native_settings']['scrubber']['status']);
        $this->assertSame($before, $scrubber->refresh()->getAttributes());
        $this->assertSame(1, ChannelScrubber::count());
    }

    public function test_invalid_backup_stops_sync_before_native_or_channel_writes(): void
    {
        [$context, $playlist] = $this->context(applyPluginSchema: true, dryRun: false);
        $foreign = Playlist::factory()->createQuietly();
        $channel = $this->configure($context, $playlist, ['auto_merge_failover_playlist_ids' => [$foreign->id]]);
        $before = $channel->getAttributes();

        $result = $this->runGuideLinkAction('apply_sync_playlist', ['playlist_id' => $playlist->id], $context);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('unavailable playlist', $result->summary);
        $this->assertFalse((bool) $playlist->refresh()->auto_merge_channels_enabled);
        $this->assertSame(0, ChannelScrubber::count());
        $this->assertSame($before, $channel->refresh()->getAttributes());
    }

    public function test_failed_initial_source_fetch_does_not_apply_native_settings_or_expose_profile_key(): void
    {
        [$context, $playlist] = $this->context(applyPluginSchema: true, dryRun: false);
        $this->configure($context, $playlist, [
            'source_mode' => 'scraper_cache', 'max_retries' => 1,
            'scraping_api' => 'scrapedo', 'scraping_api_key' => 'test-secret',
            'playlist_layout_managed' => true,
        ]);
        $playlist->forceFill(['auto_sort' => true, 'force_channel_numbering' => true])->saveQuietly();
        Http::fake(['*' => Http::response('Unavailable: token=test-secret', 503)]);

        $result = $this->runGuideLinkAction('apply_sync_playlist', ['playlist_id' => $playlist->id], $context);

        $this->assertFalse($result->success);
        $this->assertFalse((bool) $playlist->refresh()->auto_merge_channels_enabled);
        $this->assertTrue((bool) $playlist->auto_sort);
        $this->assertTrue((bool) $playlist->force_channel_numbering);
        $this->assertSame(0, ChannelScrubber::count());
        $this->assertStringNotContainsString('test-secret', json_encode($result->toArray()));
        $this->assertStringNotContainsString('test-secret', $context->run->logs()->get()->toJson());
        Http::assertSentCount(1);
    }

    public function test_failed_channel_write_reports_failure_and_skips_disabling_unmanaged_channels(): void
    {
        [$context, $playlist] = $this->context(applyPluginSchema: true, dryRun: false);
        $channel = $this->configure($context, $playlist, ['disable_other_groups' => true]);
        $before = $channel->getAttributes();
        $writer = \Mockery::mock(ChannelWriter::class)->makePartial();
        $writer->shouldReceive('apply')->once()->andReturnUsing(function (array $plan, PluginExecutionContext $context): array {
            $this->assertNotEmpty($plan);
            $plan[0]['channel_id'] = -1;

            return (new ChannelWriter)->apply($plan, $context);
        });
        $quarantine = \Mockery::mock(ChannelQuarantineService::class)->makePartial();
        $quarantine->shouldNotReceive('apply');
        $services = \Mockery::mock(GuideLinkServices::class)->makePartial();
        $services->shouldReceive('channelWriter')->andReturn($writer);
        $services->shouldReceive('channelQuarantine')->andReturn($quarantine);

        $result = (new SyncPlaylistAction($services))->handle(['playlist_id' => $playlist->id], $context);

        $this->assertSame('failed', $result->status);
        $this->assertSame(1, $result->data['writes']['channels_failed']);
        $this->assertNotEmpty($result->data['writes']['errors']);
        $this->assertSame(0, $result->data['writes']['unmanaged_channels_disabled']);
        $this->assertSame($before, $channel->refresh()->getAttributes());
        $this->assertDatabaseHas(RunResultRepository::TABLE, [
            'extension_plugin_run_id' => $context->run->id, 'result_type' => 'sync_match',
        ]);
    }

    public function test_cancellation_during_channel_writes_is_reported_and_skips_disabling_channels(): void
    {
        [$context, $playlist] = $this->context(applyPluginSchema: true, dryRun: false);
        $channel = $this->configure($context, $playlist, ['disable_other_groups' => true]);
        $before = $channel->getAttributes();
        $writer = \Mockery::mock(ChannelWriter::class)->makePartial();
        $writer->shouldReceive('apply')->once()->andReturnUsing(function (array $plan, PluginExecutionContext $context): array {
            $context->run->forceFill(['cancel_requested' => true])->save();

            return (new ChannelWriter)->apply($plan, $context);
        });
        $quarantine = \Mockery::mock(ChannelQuarantineService::class)->makePartial();
        $quarantine->shouldNotReceive('apply');
        $services = \Mockery::mock(GuideLinkServices::class)->makePartial();
        $services->shouldReceive('channelWriter')->andReturn($writer);
        $services->shouldReceive('channelQuarantine')->andReturn($quarantine);

        $result = (new SyncPlaylistAction($services))->handle(['playlist_id' => $playlist->id], $context);

        $this->assertSame('cancelled', $result->status);
        $this->assertTrue($result->data['writes']['aborted']);
        $this->assertSame(0, $result->data['writes']['channels_written']);
        $this->assertSame($before, $channel->refresh()->getAttributes());
        $this->assertDatabaseHas(RunResultRepository::TABLE, [
            'extension_plugin_run_id' => $context->run->id, 'result_type' => 'sync_match',
        ]);
    }

    public function test_cancellation_during_group_setup_is_reported_as_cancelled(): void
    {
        [$context, $playlist] = $this->context(applyPluginSchema: true, dryRun: false);
        $channel = $this->configure($context, $playlist);
        $before = $channel->getAttributes();
        $services = \Mockery::mock(GuideLinkServices::class)->makePartial();
        $groups = \Mockery::mock(GroupSyncService::class, [$services->countryRules()])->makePartial();
        $groups->shouldReceive('apply')->once()->andReturnUsing(function (Playlist $playlist, array $plan, PluginExecutionContext $context) use ($services): array {
            $context->run->forceFill(['cancel_requested' => true])->save();

            return (new GroupSyncService($services->countryRules()))->apply($playlist, $plan, $context);
        });
        $services->shouldReceive('groupSync')->andReturn($groups);

        $result = (new SyncPlaylistAction($services))->handle(['playlist_id' => $playlist->id], $context);

        $this->assertSame('cancelled', $result->status);
        $this->assertTrue($result->data['writes']['aborted']);
        $this->assertSame(0, $result->data['writes']['channels_written']);
        $this->assertSame($before, $channel->refresh()->getAttributes());
    }

    public function test_first_run_prepares_missing_cache_without_requiring_preview_and_reuses_it(): void
    {
        [$context, $playlist] = $this->context(applyPluginSchema: true, dryRun: false);
        $this->configure($context, $playlist, ['source_mode' => 'scraper_cache', 'min_channels' => 1, 'max_retries' => 1]);
        Http::fake([
            'https://viewersvault.com/Region_mapping_on_Sky_*' => Http::response(
                (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/Regions/viewersvault-index.html'),
            ),
            'https://viewersvault.com/List_of_channels_on_Sky_*' => Http::response(
                (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/Sources/Countries/UK/SkyUK/Sources/ViewersVault/basic.html'),
            ),
        ]);

        $result = $this->runGuideLinkAction('apply_sync_playlist', ['playlist_id' => $playlist->id], $context);

        $this->assertTrue($result->success, $result->summary);
        $this->assertSame('prepared', $result->data['source_cache']['status']);
        $this->assertTrue($playlist->refresh()->auto_merge_channels_enabled);
        Http::fake();
        $again = $this->runGuideLinkAction('apply_sync_playlist', ['playlist_id' => $playlist->id], $context);
        $this->assertTrue($again->success, $again->summary);
        $this->assertSame('ready', $again->data['source_cache']['status']);
        Http::assertNothingSent();
    }

    public function test_native_configuration_failure_rolls_back_before_channel_writes(): void
    {
        [$context, $playlist] = $this->context(applyPluginSchema: true, dryRun: false);
        $channel = $this->configure($context, $playlist, ['playlist_layout_managed' => true]);
        $playlist->forceFill(['auto_sort' => true, 'force_channel_numbering' => true])->saveQuietly();
        $before = $channel->getAttributes();
        $scrubber = \Mockery::mock(ScrubberSetupService::class)->makePartial();
        $scrubber->shouldReceive('apply')->once()->andThrow(new \RuntimeException('Scrubber save failed'));
        $services = \Mockery::mock(GuideLinkServices::class)->makePartial();
        $services->shouldReceive('scrubberSetup')->andReturn($scrubber);

        try {
            (new SyncPlaylistAction($services))->handle(['playlist_id' => $playlist->id], $context);
            $this->fail('Expected native configuration failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Scrubber save failed', $exception->getMessage());
        }
        $this->assertFalse((bool) $playlist->refresh()->auto_merge_channels_enabled);
        $this->assertTrue((bool) $playlist->auto_sort);
        $this->assertTrue((bool) $playlist->force_channel_numbering);
        $this->assertSame($before, $channel->refresh()->getAttributes());
    }

    public function test_scheduled_sync_configures_each_enabled_assignment_only(): void
    {
        [$context, $first] = $this->context(applyPluginSchema: true, dryRun: false);
        $second = Playlist::factory()->for($context->user)->createQuietly();
        $disabled = Playlist::factory()->for($context->user)->createQuietly();
        foreach ([$first, $second, $disabled] as $playlist) {
            $this->configure($context, $playlist);
        }
        DB::table(ProfileRepository::ASSIGNMENTS_TABLE)->where('playlist_id', $disabled->id)->update(['enabled' => false]);
        $scheduled = (new Plugin)->scheduledActions(CarbonImmutable::parse('2026-10-09 03:00:00'), [
            'sync_schedule_enabled' => true, 'sync_cron' => '0 3 * * *', 'sync_schedule_mode' => 'apply',
        ])[0];
        $this->assertFalse($scheduled['dry_run']);

        $result = $this->runGuideLinkAction($scheduled['name'], $scheduled['payload'], $context);

        $this->assertTrue($result->success, $result->summary);
        $this->assertSame(2, $result->data['batch']['completed']);
        $this->assertTrue($first->refresh()->auto_merge_channels_enabled);
        $this->assertTrue($second->refresh()->auto_merge_channels_enabled);
        $this->assertFalse((bool) $disabled->refresh()->auto_merge_channels_enabled);
        $this->assertSame(2, ChannelScrubber::count());
    }

    public function test_sync_requires_an_enabled_assignment_and_guide(): void
    {
        [$context, $playlist] = $this->context(applyPluginSchema: true, dryRun: false);
        $payload = ['playlist_id' => $playlist->id];
        $result = $this->runGuideLinkAction('apply_sync_playlist', $payload, $context);
        $this->assertFalse($result->success);
        $this->assertStringContainsString('Assign a GuideLink profile', $result->summary);
        $this->assertDatabaseCount(ProfileRepository::PROFILES_TABLE, 0);
        $this->assignProfile($context, $playlist);
        $result = $this->runGuideLinkAction('apply_sync_playlist', $payload, $context);
        $this->assertFalse($result->success);
        $this->assertStringContainsString('Choose a Guide EPG', $result->summary);
        DB::table(ProfileRepository::ASSIGNMENTS_TABLE)->update(['enabled' => false]);
        $result = $this->runGuideLinkAction('apply_sync_playlist', $payload, $context);
        $this->assertFalse($result->success);
        $this->assertStringContainsString('disabled', $result->summary);
        $this->assertFalse((bool) DB::table(ProfileRepository::ASSIGNMENTS_TABLE)->value('enabled'));
        $this->assertSame(0, ChannelScrubber::count());
    }

    public function test_preserved_channels_are_untouched_even_when_disabling_other_groups(): void
    {
        [$context, $playlist] = $this->context(applyPluginSchema: true, dryRun: false);
        $source = Playlist::factory()->for($context->user)->createQuietly();
        $safe = $this->configure($context, $playlist, [
            'source_playlist_id' => $source->id, 'disable_other_groups' => true,
            'preserve_channel_title_regex' => ['Private channel'],
        ]);
        $safe->replicate()->forceFill(['playlist_id' => $source->id, 'uuid' => (string) Str::uuid()])->saveQuietly();
        $protected = [];
        foreach (['Private channel'] as $title) {
            $channel = $safe->replicate()->forceFill([
                'title' => $title, 'name' => $title, 'channel' => null, 'stream_id' => 'bbc.one',
                'uuid' => (string) Str::uuid(),
            ]);
            $channel->saveQuietly();
            $protected[] = [$channel, $channel->refresh()->getAttributes()];
        }
        $result = $this->runGuideLinkAction('apply_sync_playlist', ['playlist_id' => $playlist->id], $context);
        $this->assertTrue($result->success, $result->summary);
        $this->assertSame('bbc.one', $safe->refresh()->stream_id_custom);
        foreach ($protected as [$channel, $before]) {
            $this->assertSame($before, $channel->refresh()->getAttributes());
        }
    }

    private function configure(PluginExecutionContext $context, Playlist $playlist, array $settings = []): Channel
    {
        $this->assignProfile($context, $playlist, ['guide_epg_id' => $this->guideEpgId($context), ...$settings]);

        return Channel::factory()->createQuietly([
            'playlist_id' => $playlist->id, 'user_id' => $context->user->id, 'group_id' => null,
            'group' => 'Entertainment', 'group_internal' => 'Entertainment',
            'enabled' => true, 'is_vod' => false, 'channel' => 101, 'name' => 'BBC One',
            'title' => 'BBC One', 'name_custom' => null, 'title_custom' => null,
            'stream_id' => 'provider.old', 'stream_id_custom' => null,
        ])->refresh();
    }
}
