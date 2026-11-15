<?php

namespace AppLocalPlugins\GuideLink\Tests\Feature\Actions;

require_once dirname(__DIR__).'/PluginFeatureTestCase.php';

use App\Models\Channel;
use App\Models\ChannelFailover;
use App\Models\Playlist;
use AppLocalPlugins\GuideLink\Actions\RebuildFailoversAction;
use AppLocalPlugins\GuideLink\Services\AutoMergeSetupService;
use AppLocalPlugins\GuideLink\Services\RunResultRepository;
use AppLocalPlugins\GuideLink\Tests\Feature\PluginFeatureTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

class AutoMergeActionPersistenceTest extends PluginFeatureTestCase
{
    use RefreshDatabase;

    public function test_apply_auto_merge_setup_updates_native_playlist_settings_and_persists_rows(): void
    {
        [$context, $playlist] = $this->context(applyPluginSchema: true, dryRun: false);
        $failoverPlaylist = Playlist::factory()->for($context->user)->createQuietly();
        $this->assignProfile($context, $playlist);

        $result = $this->runGuideLinkAction('apply_auto_merge_setup', [
            'playlist_id' => $playlist->id,
            'auto_merge_preferred_playlist_id' => $playlist->id,
            'auto_merge_failover_playlist_ids' => [$failoverPlaylist->id],
            'auto_merge_deactivate_failover' => true,
            'auto_merge_new_channels_only' => false,
            'auto_merge_force_complete_remerge' => true,
            'auto_merge_merge_key' => 'stream_id',
            'auto_merge_exclude_disabled_groups' => true,
        ], $context);

        $this->assertTrue($result->success);

        $playlist->refresh();
        $this->assertTrue($playlist->auto_merge_channels_enabled);
        $this->assertTrue($playlist->auto_merge_deactivate_failover);
        $this->assertSame((int) $playlist->id, $playlist->auto_merge_config['preferred_playlist_id']);
        $this->assertSame([
            ['playlist_failover_id' => (int) $failoverPlaylist->id],
        ], $playlist->auto_merge_config['failover_playlists']);
        $this->assertFalse($playlist->auto_merge_config['new_channels_only']);
        $this->assertTrue($playlist->auto_merge_config['force_complete_remerge']);
        $this->assertTrue($playlist->auto_merge_config['scrubber_aware_master_selection']);
        $this->assertTrue($playlist->auto_merge_config['check_resolution']);
        $this->assertTrue($playlist->auto_merge_config['prefer_catchup_as_primary']);
        $this->assertTrue($playlist->auto_probe_streams);

        $this->assertDatabaseHas(RunResultRepository::TABLE, [
            'extension_plugin_run_id' => $context->run->id,
            'playlist_id' => $playlist->id,
            'result_type' => 'auto_merge_audit',
            'decision' => 'applied',
            'title' => 'Native auto-merge setup',
        ]);
    }

    public function test_auto_merge_audit_reports_conditional_probing_without_changing_the_playlist(): void
    {
        [$context, $playlist] = $this->context(applyPluginSchema: true);
        $playlist->forceFill(['auto_probe_streams' => false])->saveQuietly();
        $service = new AutoMergeSetupService;
        $before = $playlist->refresh()->getAttributes();

        foreach ([true, false] as $preferResolution) {
            $desired = $service->desiredConfig($playlist, ['auto_merge_check_resolution' => $preferResolution], $context);
            $row = collect($service->auditRows($playlist, $desired))->firstWhere('title', 'Probe Live streams after sync');

            $this->assertSame($preferResolution ? 'change_required' : 'info', $row['status']);
            if ($preferResolution) {
                $this->assertFalse($row['details']['current']);
                $this->assertTrue($row['details']['desired']);
            }
            $this->assertSame($before, $playlist->refresh()->getAttributes());
        }
    }

    public function test_rebuild_failovers_invokes_native_merge_and_promotes_live_hidden_failover(): void
    {
        [$context, $playlist] = $this->context(applyPluginSchema: true, dryRun: false);
        $failoverPlaylist = Playlist::factory()->for($context->user)->createQuietly();
        $this->assignProfile($context, $playlist);

        $playlist->forceFill([
            'auto_merge_channels_enabled' => true,
            'auto_merge_deactivate_failover' => true,
            'auto_merge_config' => [
                'preferred_playlist_id' => (int) $playlist->id,
                'scrubber_aware_master_selection' => true,
                'failover_playlists' => [['playlist_failover_id' => (int) $failoverPlaylist->id]],
                'merge_key' => 'stream_id',
                'new_channels_only' => false,
                'force_complete_remerge' => true,
                'check_resolution' => false,
                'prefer_catchup_as_primary' => false,
                'exclude_disabled_groups' => true,
            ],
        ])->save();

        $oldMaster = Channel::factory()->create([
            'user_id' => $context->user->id,
            'playlist_id' => $playlist->id,
            'group_id' => null,
            'enabled' => false,
            'is_vod' => false,
            'stream_id' => 'guidelink.native.rebuild',
            'last_scrubber_live' => false,
            'stream_stats' => [['stream' => ['codec_type' => 'video', 'width' => 1280, 'height' => 720]]],
        ]);
        $newMaster = Channel::factory()->create([
            'user_id' => $context->user->id,
            'playlist_id' => $failoverPlaylist->id,
            'group_id' => null,
            'enabled' => false,
            'is_vod' => false,
            'stream_id' => 'guidelink.native.rebuild',
            'last_scrubber_live' => true,
            'stream_stats' => [['stream' => ['codec_type' => 'video', 'width' => 1280, 'height' => 720]]],
        ]);
        ChannelFailover::create([
            'user_id' => $context->user->id,
            'channel_id' => $oldMaster->id,
            'channel_failover_id' => $newMaster->id,
        ]);

        $result = (new RebuildFailoversAction)->handle([
            'playlist_id' => $playlist->id,
        ], $context);

        $this->assertTrue($result->success);
        $this->assertTrue($newMaster->refresh()->enabled);
        $this->assertFalse($oldMaster->refresh()->enabled);
        $this->assertDatabaseMissing('channel_failovers', [
            'channel_id' => $oldMaster->id,
            'channel_failover_id' => $newMaster->id,
        ]);
        $this->assertDatabaseHas(RunResultRepository::TABLE, [
            'extension_plugin_run_id' => $context->run->id,
            'playlist_id' => $playlist->id,
            'result_type' => 'auto_merge_audit',
            'decision' => 'rebuilt',
            'title' => 'Native failover rebuild',
        ]);

        $record = DB::table(RunResultRepository::TABLE)
            ->where('extension_plugin_run_id', $context->run->id)
            ->where('result_type', 'auto_merge_audit')->first();
        $details = json_decode($record->details, true)['details'];
        foreach (['before' => [0, 2], 'after' => [1, 1]] as $phase => [$enabled, $disabled]) {
            $this->assertSame(2, $details[$phase]['channels']);
            $this->assertSame($enabled, $details[$phase]['enabled_channels']);
            $this->assertSame($disabled, $details[$phase]['disabled_channels']);
            $this->assertSame(1, $details[$phase]['scrubber_dead_channels']);
            $this->assertSame(1, $details[$phase]['failover_rows']);
        }
    }

    public function test_merge_key_audit_counts_duplicates_across_batches_with_existing_key_semantics(): void
    {
        [$context, $playlist] = $this->context();
        $channels = Channel::factory()->count(502)->make([
            'user_id' => $context->user->id, 'playlist_id' => $playlist->id, 'group_id' => null,
            'is_vod' => false, 'can_merge' => true,
        ])->map(function (Channel $channel, int $i): array {
            return [...$channel->getAttributes(), 'stream_id' => 'key.'.$i,
                'stream_id_custom' => null, 'tmdb_id' => $i + 1];
        })->all();
        $channels[500]['stream_id_custom'] = ' key.0 ';
        $channels[500]['tmdb_id'] = 1;
        $channels[501]['stream_id'] = 'key.1';
        $channels[501]['stream_id_custom'] = '0'; // Preserve the native falsey custom-ID fallback.
        $channels[501]['tmdb_id'] = 2;
        foreach (array_chunk($channels, 100) as $batch) {
            DB::table('channels')->insert($batch);
        }
        $service = new AutoMergeSetupService;
        $desired = $service->desiredConfig($playlist, [], $context);
        foreach (['stream_id', 'tmdb_id'] as $mergeKey) {
            if ($mergeKey === 'tmdb_id') {
                DB::table('channels')->where('playlist_id', $playlist->id)->update(['is_vod' => true]);
            }
            DB::enableQueryLog();
            DB::flushQueryLog();
            try {
                $rows = $service->auditRows($playlist, [...$desired, 'merge_key' => $mergeKey]);
                $queries = DB::getQueryLog();
            } finally {
                DB::disableQueryLog();
                DB::flushQueryLog();
            }
            $duplicates = collect($rows)->firstWhere('title', 'Duplicate merge keys');
            $this->assertSame(2, $duplicates['details']['duplicate_key_groups']);
            $this->assertSame(4, $duplicates['details']['duplicate_channel_count']);
            $batches = array_filter($queries, fn (array $query): bool => str_contains($query['query'], 'limit 500'));
            $this->assertCount(2, $batches);
        }
    }

    public function test_empty_playlist_rebuild_preview_reports_zero_counts(): void
    {
        [$context, $playlist] = $this->context();
        $playlist->forceFill(['auto_merge_channels_enabled' => true])->saveQuietly();

        $row = (new AutoMergeSetupService)->rebuildFailovers($playlist, $context, dryRun: true);

        foreach (['channels', 'enabled_channels', 'disabled_channels', 'scrubber_dead_channels', 'failover_rows'] as $key) {
            $this->assertSame(0, $row['details']['before'][$key]);
        }
    }
}
