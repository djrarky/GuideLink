<?php

namespace AppLocalPlugins\GuideLink\Tests\Feature\Actions;

require_once dirname(__DIR__).'/PluginFeatureTestCase.php';

use App\Jobs\MapPlaylistChannelsToEpg;
use App\Models\Channel;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\EpgMap;
use App\Models\Playlist;
use App\Plugins\Support\PluginActionResult;
use App\Plugins\Support\PluginExecutionContext;
use AppLocalPlugins\GuideLink\Services\EpgMappingSetupService;
use AppLocalPlugins\GuideLink\Services\GuideMapService;
use AppLocalPlugins\GuideLink\Tests\Feature\PluginFeatureTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NativeEpgMappingTest extends PluginFeatureTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    public function test_sync_hands_ids_to_native_mapping_and_native_refresh_handles_new_names(): void
    {
        [$context, $playlist, $channel, $guideId] = $this->configured();
        $guideChannel = EpgChannel::where('epg_id', $guideId)->firstOrFail();
        $guideChannel->update(['name' => 'A different seasonal guide name']);
        // A competing exact name must lose to the guide ID established by GuideLink.
        EpgChannel::factory()->createQuietly([
            'epg_id' => $guideId, 'user_id' => $playlist->user_id,
            'channel_id' => 'other.service', 'display_name' => '999', 'name' => 'BBC One',
        ]);
        $preview = new PluginExecutionContext($context->plugin, $context->run, 'test', true, null, $context->user, []);
        $result = $this->runGuideLinkAction('sync_playlist', ['playlist_id' => $playlist->id], $preview);
        $this->assertTrue($result->success, $result->summary);
        $this->assertSame('change_required', $result->data['native_settings']['epg_mapping']['status']);
        $this->assertDatabaseCount('epg_maps', 0);
        $this->assertNull($channel->refresh()->epg_channel_id);
        Bus::assertNotDispatched(MapPlaylistChannelsToEpg::class);

        $result = $this->sync($context, $playlist);
        $map = EpgMap::sole();
        $this->assertSame('queued', $result->data['native_settings']['epg_mapping']['status']);
        $this->assertNull($map->channels);
        $this->assertNull($map->group_ids);
        $this->assertTrue((bool) $map->recurring);
        $this->assertTrue((bool) $map->override);
        $this->assertFalse($map->settings['skip_missing']);
        $this->assertFalse($map->settings['prioritize_name_match']);
        $this->assertSame('bbc.one', $channel->refresh()->stream_id_custom);
        $this->assertNull($channel->epg_channel_id, 'GuideLink must not write the association itself.');
        Bus::assertDispatched(MapPlaylistChannelsToEpg::class, fn ($job) => $job->epgMapId === $map->id
            && $job->epg === $guideId && $job->playlist === $playlist->id && $job->connection === 'redis' && $job->queue === 'import');

        $this->runNativeMap($map);
        $this->assertSame($guideChannel->id, $channel->refresh()->epg_channel_id);
        Bus::fake();
        $again = $this->sync($context, $playlist);
        $this->assertSame(0, $again->data['writes']['channels_written']);
        $this->assertSame('queued', $again->data['native_settings']['epg_mapping']['status']);
        $this->assertSame([], $again->data['native_settings']['epg_mapping']['details']['changes']);
        $this->assertDatabaseCount('epg_maps', 1);
        Bus::assertDispatched(MapPlaylistChannelsToEpg::class);

        // An EPG reimport can replace the row and change its display name.
        $guideChannel->delete();
        $replacement = EpgChannel::factory()->createQuietly([
            'epg_id' => $guideId, 'user_id' => $playlist->user_id,
            'channel_id' => 'bbc.one', 'display_name' => '101', 'name' => 'Next seasonal guide name',
        ]);
        $this->runNativeMap($map->refresh());
        $this->assertSame($replacement->id, $channel->refresh()->epg_channel_id);
    }

    public function test_host_mapping_repairs_wrong_or_missing_associations_after_sync(): void
    {
        [$context, $playlist, $channel, $guideId] = $this->configured();
        $correct = EpgChannel::where('epg_id', $guideId)->sole();
        $otherGuide = Epg::factory()->for($context->user)->createQuietly();
        $wrongGuide = EpgChannel::factory()->createQuietly([
            'epg_id' => $otherGuide->id, 'user_id' => $playlist->user_id,
            'channel_id' => 'bbc.one', 'display_name' => '101', 'name' => 'BBC One',
        ]);
        $wrongService = EpgChannel::factory()->createQuietly([
            'epg_id' => $guideId, 'user_id' => $playlist->user_id,
            'channel_id' => 'different.service', 'display_name' => '999', 'name' => 'Different service',
        ]);
        $this->sync($context, $playlist);
        $this->runNativeMap(EpgMap::sole());

        foreach ([$wrongGuide->id, $wrongService->id, null] as $wrongLink) {
            $channel->forceFill(['epg_channel_id' => $wrongLink])->saveQuietly();
            Bus::fake();
            $result = $this->sync($context, $playlist);
            $this->assertSame(0, $result->data['writes']['channels_written']);
            $this->assertSame('queued', $result->data['native_settings']['epg_mapping']['status']);
            $this->assertSame($wrongLink, $channel->refresh()->epg_channel_id);
            Bus::assertDispatched(MapPlaylistChannelsToEpg::class);
            $this->runNativeMap(EpgMap::sole());
            $this->assertSame($correct->id, $channel->refresh()->epg_channel_id);
        }
    }

    public function test_host_mapping_respects_native_opt_out_vod_and_playlist_scope_and_preserves_user_maps(): void
    {
        [$context, $playlist, $channel, $guideId] = $this->configured();
        $optOut = $channel->replicate(['uuid']);
        $optOut->forceFill(['source_id' => 'opt-out', 'epg_map_enabled' => false])->saveQuietly();
        $excluded = $channel->replicate(['uuid']);
        $excluded->forceFill(['source_id' => 'vod', 'is_vod' => true])->saveQuietly();
        $otherPlaylist = Playlist::factory()->for($context->user)->createQuietly();
        $foreign = $channel->replicate(['uuid']);
        $foreign->forceFill(['source_id' => 'foreign', 'playlist_id' => $otherPlaylist->id])->saveQuietly();
        $manual = new EpgMap;
        $manual->forceFill([
            'uuid' => (string) Str::uuid(), 'name' => 'My mapping', 'epg_id' => $guideId,
            'playlist_id' => $playlist->id, 'user_id' => $playlist->user_id,
            'channels' => [$excluded->id], 'settings' => ['prioritize_name_match' => true], 'recurring' => false,
        ])->save();
        $before = $manual->refresh()->getAttributes();
        $this->sync($context, $playlist);
        $map = EpgMap::where('id', '!=', $manual->id)->sole();
        $this->assertNull($map->channels);
        $this->assertSame($before, $manual->refresh()->getAttributes());
        $this->runNativeMap($map);
        foreach ([$optOut, $excluded, $foreign] as $untouched) {
            $this->assertNull($untouched->refresh()->epg_channel_id);
        }
    }

    public function test_recurring_map_includes_later_imports_without_another_guidelink_sync(): void
    {
        [$context, $playlist, $channel, $guideId] = $this->configured();
        $this->sync($context, $playlist);
        $map = EpgMap::sole();
        $this->runNativeMap($map);
        $imports = [];
        foreach ([['Unknown seasonal title', 'bbc.one'], ['BBC One', null]] as [$name, $streamId]) {
            $import = $channel->replicate(['uuid']);
            $import->forceFill([
                'source_id' => 'import-'.count($imports), 'title' => $name, 'name' => $name,
                'title_custom' => null, 'name_custom' => null, 'channel' => null,
                'stream_id' => $streamId, 'stream_id_custom' => null, 'epg_channel_id' => null, 'enabled' => false,
            ])->saveQuietly();
            $imports[] = $import;
        }
        $this->runNativeMap($map->refresh());
        foreach ($imports as $import) {
            $this->assertSame(EpgChannel::where('epg_id', $guideId)->value('id'), $import->refresh()->epg_channel_id);
            $this->assertNull($import->channel);
            $this->assertFalse($import->enabled);
        }
    }

    public function test_established_id_does_not_require_any_alias_set_or_new_name_match(): void
    {
        [$context, $playlist, $channel, $guideId] = $this->configured();
        $channel->forceFill([
            'title_custom' => 'Previous seasonal name', 'name_custom' => 'Previous seasonal name',
            'channel' => 101, 'stream_id_custom' => 'bbc.one',
        ])->saveQuietly();
        $result = $this->sync($context, $playlist);
        $this->assertSame(1, $result->data['matching']['needs_review']);
        $this->assertSame('Previous seasonal name', $channel->refresh()->title_custom);
        $this->assertNull(EpgMap::sole()->channels);
        $this->runNativeMap(EpgMap::sole());
        $this->assertSame(EpgChannel::where('epg_id', $guideId)->value('id'), $channel->refresh()->epg_channel_id);
    }

    public function test_epg_setup_updates_settings_and_supersedes_queued_work_without_inspecting_channels(): void
    {
        [$context, $playlist, , $guideId] = $this->configured();
        $this->sync($context, $playlist);
        $previousJob = Bus::dispatched(MapPlaylistChannelsToEpg::class)->first();
        $map = EpgMap::sole();
        $map->update(['settings' => [...$map->settings, 'fuzzy_max_distance' => 12]]);
        $epg = ['id' => $guideId, 'name' => $map->epg->name];
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            (new EpgMappingSetupService)->apply($playlist, $epg, true, $context);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }
        $this->assertTrue($map->refresh()->settings['set_epg_icon']);
        $this->assertSame(12, $map->settings['fuzzy_max_distance']);
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/\b(?:channels|epg_channels)\b/', $query['query']);
        }
        $previousJob->chained = ['old mapping chain'];
        $this->assertNull($previousJob->middleware()[0]->handle($previousJob, fn () => 'must not run'));
        $this->assertSame([], $previousJob->chained);
    }

    public function test_in_progress_map_is_not_reconfigured_or_queued_again(): void
    {
        [$context, $playlist, , $guideId] = $this->configured();
        $this->sync($context, $playlist);
        $map = EpgMap::sole();
        $map->update(['processing' => true, 'status' => 'processing']);
        Bus::fake();
        $again = $this->sync($context, $playlist);
        $this->assertSame('processing', $again->data['native_settings']['epg_mapping']['status']);
        Bus::assertNotDispatched(MapPlaylistChannelsToEpg::class);
        $before = $map->refresh()->getAttributes();
        $guide = (new GuideMapService)->resolve(['guide_epg_id' => $guideId], $context);
        try {
            (new EpgMappingSetupService)->apply($playlist, $guide['epg'], true, $context);
            $this->fail('An active map cannot change settings.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('mapping is running', $exception->getMessage());
        }
        $this->assertSame($before, $map->refresh()->getAttributes());
    }

    private function configured(): array
    {
        [$context, $playlist] = $this->context(applyPluginSchema: true, dryRun: false);
        $source = Playlist::factory()->for($context->user)->createQuietly();
        $guideId = $this->guideEpgId($context);
        Channel::factory()->createQuietly([
            'playlist_id' => $source->id, 'user_id' => $playlist->user_id, 'group_id' => null,
            'title' => 'BBC One', 'name' => 'BBC One', 'title_custom' => null, 'name_custom' => null,
            'channel' => 101, 'enabled' => true, 'is_vod' => false,
        ]);
        $this->assignProfile($context, $playlist, [
            'source_playlist_id' => $source->id, 'guide_epg_id' => $guideId,
        ]);
        $channel = Channel::factory()->createQuietly([
            'playlist_id' => $playlist->id, 'user_id' => $playlist->user_id, 'group_id' => null,
            'title' => 'BBC One', 'name' => 'BBC One', 'title_custom' => null, 'name_custom' => null,
            'channel' => null, 'stream_id' => 'provider', 'stream_id_custom' => null,
            'enabled' => true, 'is_vod' => false, 'epg_channel_id' => null, 'epg_map_enabled' => true,
        ]);

        return [$context, $playlist, $channel, $guideId];
    }

    private function sync(PluginExecutionContext $context, Playlist $playlist): PluginActionResult
    {
        $result = $this->runGuideLinkAction('apply_sync_playlist', ['playlist_id' => $playlist->id], $context);
        $this->assertTrue($result->success, $result->summary);

        return $result;
    }
}
