<?php

namespace AppLocalPlugins\GuideLink\Tests\Feature\Actions;

require_once dirname(__DIR__).'/PluginFeatureTestCase.php';

use App\Enums\ChannelLogoType;
use App\Jobs\MergeChannels;
use App\Jobs\ProcessM3uImportChunk;
use App\Jobs\ProcessM3uImportComplete;
use App\Models\Channel;
use App\Models\ChannelFailover;
use App\Models\EpgChannel;
use App\Models\EpgMap;
use App\Models\Group;
use App\Models\Job;
use App\Models\Playlist;
use App\Plugins\Support\PluginActionResult;
use App\Plugins\Support\PluginExecutionContext;
use App\Services\LogoService;
use App\Settings\GeneralSettings;
use AppLocalPlugins\GuideLink\Services\ChannelQuarantineService;
use AppLocalPlugins\GuideLink\Services\ChannelRepository;
use AppLocalPlugins\GuideLink\Services\ProfileRepository;
use AppLocalPlugins\GuideLink\Services\RunResultRepository;
use AppLocalPlugins\GuideLink\Services\SourceCacheService;
use AppLocalPlugins\GuideLink\Support\ChannelAttributes;
use AppLocalPlugins\GuideLink\Tests\Feature\PluginFeatureTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;

class SyncStabilityTest extends PluginFeatureTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Http::preventStrayRequests();
        Storage::fake('local');
    }

    public function test_managed_groups_survive_native_import_cleanup_and_repeated_sync(): void
    {
        [$context, $playlist] = $this->configured();
        $channel = $this->channel($playlist);
        $backup = $this->channel($playlist, ['enabled' => false]);
        $first = $this->sync($context, $playlist);
        $groupId = $channel->refresh()->group_id;
        $this->assertTrue((bool) Group::findOrFail($groupId)->custom);
        $this->assertSame($groupId, $backup->refresh()->group_id);
        $this->assertFalse($backup->enabled);
        $this->assertSame(2, $first->data['writes']['channels_written']);
        $this->assertSame(1, $first->data['matching']['total_enabled_live_channels']);
        $this->assertSame(2, $first->data['matching']['total_live_channels']);

        // Exercise the host cleanup that removed v0.1.0's groups after every import.
        Channel::where('playlist_id', $playlist->id)->update(['import_batch_no' => 'next-import']);
        $cleanup = new ProcessM3uImportComplete(
            $context->user->id, $playlist->id, 'next-import', now(), runningVodImport: false,
        );
        $cleanup->invalidateImport = false;
        $cleanup->handle(app(GeneralSettings::class));
        $this->assertNotNull(Group::find($groupId));
        $this->assertSame($groupId, $channel->refresh()->group_id);
        $this->assertFalse($backup->refresh()->enabled);

        $again = $this->sync($context, $playlist);
        $this->assertSame(0, $again->data['writes']['channels_written']);
        $this->assertSame(0, $again->data['writes']['groups_created']);
        $this->assertSame(0, $again->data['writes']['groups_updated']);
        $this->assertSame($groupId, $backup->refresh()->group_id);
    }

    public function test_matching_reads_omit_failovers_while_availability_retains_failover_details(): void
    {
        [$context, $playlist] = $this->configured();
        $primary = $this->channel($playlist);
        $backup = $this->channel($playlist, ['enabled' => false]);
        ChannelFailover::create(['user_id' => $context->user->id, 'channel_id' => $primary->id, 'channel_failover_id' => $backup->id]);

        DB::enableQueryLog();
        try {
            (new ChannelRepository)->liveChannels($playlist, true, includeFailovers: false);
            $failoverQueries = array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains($query['query'], 'channel_failovers'));
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $this->assertSame([], $failoverQueries);

        $repository = new ChannelRepository;
        $channels = $repository->liveChannels($playlist, true);
        $master = $channels->find($primary->id);
        $this->assertTrue($master->relationLoaded('group'));
        $this->assertTrue($master->relationLoaded('failovers'));
        $this->assertSame(['total' => 1, 'enabled' => 0, 'enabled_ids' => [], 'enabled_titles' => []], $repository->failoverSummary($master));
    }

    public function test_projected_reads_keep_identity_write_guards_and_failover_names_without_large_metadata(): void
    {
        [$context, $playlist] = $this->configured();
        $group = $this->group($playlist, 'Original group');
        $group->delete();
        $primary = $this->channel($playlist, [
            'group_id' => $group->id, 'group_internal' => 'Provider group',
            'name_custom' => 'Custom name', 'title_custom' => 'Custom title',
            'stream_id_custom' => 'custom.id', 'sort' => 12.5, 'can_merge' => false,
            'stream_stats' => ['large' => str_repeat('x', 10000)],
        ]);
        $backup = $this->channel($playlist, ['title_custom' => 'Backup title', 'enabled' => true]);
        ChannelFailover::create(['user_id' => $context->user->id, 'channel_id' => $primary->id, 'channel_failover_id' => $backup->id, 'metadata' => ['large' => str_repeat('x', 10000)]]);
        $legacy = $this->channel($playlist, ['group_id' => null, 'group_internal' => null, 'group' => 'Legacy group']);
        $repository = new ChannelRepository;
        $projected = $repository->liveChannels($playlist, true);
        foreach ([$primary, $legacy] as $original) {
            $full = Channel::with(['group' => fn ($query) => $query->withTrashed(), 'failovers.channelFailover'])->findOrFail($original->id);
            $read = $projected->find($original->id);
            $this->assertEquals(ChannelAttributes::decisionState($full), ChannelAttributes::decisionState($read));
            $this->assertEquals(ChannelAttributes::nativeMetadata($full), ChannelAttributes::nativeMetadata($read));
            $this->assertSame(ChannelAttributes::reportRow($playlist, $full), ChannelAttributes::reportRow($playlist, $read));
            $this->assertSame($repository->failoverSummary($full), $repository->failoverSummary($read));
            $this->assertArrayNotHasKey('stream_stats', $read->getAttributes());
            $this->assertArrayNotHasKey('url', $read->getAttributes());
        }
        $read = $projected->find($primary->id);
        $this->assertSame(['Backup title'], $repository->failoverSummary($read)['enabled_titles']);
        $this->assertArrayNotHasKey('metadata', $read->failovers->first()->getAttributes());
        $this->assertArrayNotHasKey('url', $read->failovers->first()->channelFailover->getAttributes());
        $this->assertSame('Legacy group', ChannelAttributes::groupName($projected->find($legacy->id)));
    }

    public function test_upgrade_restores_legacy_groups_and_repairs_disabled_backups_without_enabling_them(): void
    {
        [$context, $playlist] = $this->configured();
        $legacy = $this->group($playlist, 'Entertainment', ['custom' => false, 'sort_order' => 1]);
        $older = $this->group($playlist, 'Entertainment', ['custom' => false, 'sort_order' => 2]);
        $primary = $this->channel($playlist, ['group_id' => $legacy->id]);
        $backup = $this->channel($playlist, ['group_id' => $older->id, 'enabled' => false, 'can_merge' => false]);
        $legacy->delete();
        $older->delete();
        $before = $backup->refresh()->getAttributes();

        $preview = $this->sync($this->previewContext($context), $playlist);
        $this->assertSame(1, $preview->data['writes']['groups_to_update']);
        $this->assertTrue($legacy->refresh()->trashed());
        $this->assertSame($before, $backup->refresh()->getAttributes());

        $result = $this->sync($context, $playlist);
        $this->assertSame(0, $result->data['writes']['groups_created']);
        $this->assertFalse($legacy->refresh()->trashed());
        $this->assertTrue((bool) $legacy->custom);
        $this->assertSame($legacy->id, $primary->refresh()->group_id);
        $this->assertSame($legacy->id, $backup->refresh()->group_id);
        $this->assertFalse($backup->enabled);
        $this->assertTrue((bool) $backup->can_merge);
        $this->assertTrue($older->refresh()->trashed());
        $this->assertSame(0, $this->sync($context, $playlist)->data['writes']['channels_written']);
    }

    public function test_provider_groups_are_not_adopted_even_when_their_names_match_a_category(): void
    {
        [$context, $playlist] = $this->configured();
        $provider = $this->group($playlist, 'Entertainment', ['import_batch_no' => 'provider', 'source_group_id' => 55]);
        $prefixed = $this->group($playlist, 'UK| Entertainment ᴴᴰ');
        $merged = $this->group($playlist, 'Entertainment', ['is_merged' => true]);
        $deleted = $this->group($playlist, 'Entertainment', ['custom' => true]);
        $deleted->delete();
        $channel = $this->channel($playlist, ['group_id' => $provider->id]);
        $before = $provider->getAttributes();
        $this->sync($context, $playlist);
        $this->assertNotSame($provider->id, $channel->refresh()->group_id);
        $this->assertSame($before, $provider->refresh()->getAttributes());
        $this->assertTrue((bool) Group::findOrFail($channel->group_id)->custom);
        $this->assertFalse((bool) $prefixed->refresh()->custom);
        $this->assertFalse((bool) $merged->refresh()->custom);
        $this->assertTrue($deleted->refresh()->trashed());
    }

    public function test_quarantine_covers_scope_exclusions_and_unmatched_managed_channels_but_preserves_explicit_exceptions(): void
    {
        [$context, $playlist] = $this->configured([
            'disable_other_groups' => true, 'include_group_prefixes' => ['UK|'],
            'exclude_group_regex' => ['Blocked'], 'preserve_groups' => ['Private'],
            'preserve_channel_title_regex' => ['Keep this'],
        ]);
        $matched = $this->channel($playlist);
        $excluded = [];
        foreach ([
            ['UK| 24/7', 'Some show'], ['UK| GENERAL', 'SERIES 123'],
            ['UK| GENERAL', '##### A heading #####'], ['US| GENERAL', 'Foreign channel'],
            ['UK| Blocked', 'Blocked channel'], ['Entertainment', 'Zebra Boutique'],
        ] as [$group, $title]) {
            $excluded[] = $this->channel($playlist, [
                'group_id' => $this->group($playlist, $group)->id, 'title' => $title, 'name' => $title,
            ]);
        }
        $preserved = [
            $this->channel($playlist, ['group_id' => $this->group($playlist, 'Private')->id, 'title' => 'Private show']),
            $this->channel($playlist, ['group_id' => $this->group($playlist, 'US| Blocked')->id, 'title' => 'Keep this']),
        ];
        $before = array_map(fn (Channel $channel): array => $channel->refresh()->getAttributes(), $preserved);
        $preview = $this->sync($this->previewContext($context), $playlist);
        $this->assertSame(6, $preview->data['quarantine']['to_disable']);
        $this->assertDatabaseCount(ChannelQuarantineService::TABLE, 0);
        foreach ($excluded as $channel) {
            $this->assertTrue($channel->refresh()->enabled);
        }
        $details = DB::table(RunResultRepository::TABLE)->where('channel_id', $excluded[0]->id)->value('details');
        $this->assertSame('quarantine', json_decode($details, true)['quarantine_plan']['action']);

        $result = $this->sync($context, $playlist);
        $this->assertSame(6, $result->data['writes']['unmanaged_channels_disabled']);
        foreach ($excluded as $channel) {
            $this->assertFalse($channel->refresh()->enabled);
            $this->assertFalse((bool) $channel->can_merge);
        }
        $this->assertTrue($matched->refresh()->enabled);
        $this->assertSame($before, array_map(fn (Channel $channel): array => $channel->refresh()->getAttributes(), $preserved));
        $again = $this->sync($context, $playlist);
        $this->assertSame(0, $again->data['quarantine']['planned']);
        $this->assertSame(0, $again->data['writes']['channels_written']);
        $this->assertSame(0, $again->data['writes']['unmanaged_channels_disabled']);
    }

    public function test_quarantine_blocks_native_merge_recovery_and_restores_the_original_merge_preference(): void
    {
        [$context, $playlist] = $this->configured(['disable_other_groups' => true]);
        $primary = $this->channel($playlist, ['name' => 'Zebra Boutique', 'title' => 'Zebra Boutique', 'stream_id' => 'unmatched']);
        $backup = $this->channel($playlist, ['name' => 'Zebra Boutique', 'title' => 'Zebra Boutique', 'stream_id' => 'unmatched', 'enabled' => false, 'can_merge' => false]);
        ChannelFailover::create(['user_id' => $context->user->id, 'channel_id' => $primary->id, 'channel_failover_id' => $backup->id]);
        $this->sync($context, $playlist);
        (new MergeChannels(
            $context->user, collect(), $playlist->id, forceCompleteRemerge: true,
            deactivateFailoverChannels: true, scrubberAwareMasterSelection: true,
        ))->handle();
        $this->assertFalse($primary->refresh()->enabled);
        $this->assertFalse($backup->refresh()->enabled);

        $this->settings(['disable_other_groups' => false]);
        $this->sync($context, $playlist);
        $this->assertTrue((bool) $primary->refresh()->can_merge);
        $this->assertFalse($primary->enabled);
        $this->assertFalse((bool) $backup->refresh()->can_merge);
        $this->assertFalse($backup->enabled);
        $this->assertDatabaseCount(ChannelQuarantineService::TABLE, 0);
    }

    public function test_quarantined_channel_recovers_when_it_matches_later_even_after_manual_disable(): void
    {
        [$context, $playlist] = $this->configured(['disable_other_groups' => true]);
        $channel = $this->channel($playlist, ['name' => 'Zebra Boutique', 'title' => 'Zebra Boutique', 'can_merge' => false]);
        $this->sync($context, $playlist);
        $this->assertFalse((bool) $channel->refresh()->can_merge);
        $channel->forceFill(['title' => 'BBC One', 'name' => 'BBC One'])->saveQuietly();
        $this->sync($context, $playlist);
        $this->assertTrue((bool) $channel->refresh()->can_merge);
        $this->assertTrue($channel->enabled);
        $this->assertSame('bbc.one', $channel->stream_id_custom);
        $this->assertDatabaseCount(ChannelQuarantineService::TABLE, 0);
    }

    public function test_disabled_matches_recover_to_one_primary_and_native_backups_without_repeat_writes(): void
    {
        [$context, $playlist] = $this->configured();
        $primary = $this->channel($playlist, ['enabled' => false, 'can_merge' => false, 'last_scrubber_live' => true]);
        $backup = $this->channel($playlist, ['enabled' => false, 'can_merge' => false, 'last_scrubber_live' => true]);
        $dead = $this->channel($playlist, ['enabled' => true, 'last_scrubber_live' => false]);
        $before = $primary->getAttributes();
        $preview = $this->sync($this->previewContext($context), $playlist);
        $this->assertSame(3, $preview->data['matched_recovery']['candidates']);
        $this->assertSame($before, $primary->refresh()->getAttributes());
        $this->assertDatabaseCount('channel_failovers', 0);

        $result = $this->sync($context, $playlist);
        $this->assertTrue($primary->refresh()->enabled);
        $this->assertTrue((bool) $primary->can_merge);
        $this->assertFalse($backup->refresh()->enabled);
        $this->assertTrue((bool) $backup->can_merge);
        $this->assertFalse($dead->refresh()->enabled);
        $this->assertSame(1, $result->data['matched_recovery']['enabled']);
        $this->assertSame(1, $result->data['matched_recovery']['disabled']);
        $this->assertDatabaseHas('channel_failovers', ['channel_id' => $primary->id, 'channel_failover_id' => $backup->id]);
        $this->assertDatabaseHas('channel_failovers', ['channel_id' => $primary->id, 'channel_failover_id' => $dead->id]);
        $before = [$primary->getAttributes(), $backup->getAttributes(), $dead->getAttributes()];
        $again = $this->sync($context, $playlist);
        $this->assertSame(0, $again->data['matched_recovery']['enabled']);
        $this->assertSame(0, $again->data['matched_recovery']['disabled']);
        $this->assertSame(0, $again->data['matched_recovery']['merge_restored']);
        $this->assertSame($before, [$primary->refresh()->getAttributes(), $backup->refresh()->getAttributes(), $dead->refresh()->getAttributes()]);
    }

    public function test_a_dead_standalone_match_stays_off_until_a_later_probe_succeeds(): void
    {
        [$context, $playlist] = $this->configured();
        $channel = $this->channel($playlist, ['enabled' => false, 'last_scrubber_live' => false]);
        $this->sync($context, $playlist);
        $this->assertFalse($channel->refresh()->enabled);
        $channel->forceFill(['last_scrubber_live' => true])->saveQuietly();
        $this->sync($context, $playlist);
        $this->assertTrue($channel->refresh()->enabled);
    }

    public function test_recovery_uses_native_quality_ranking_and_keeps_identical_urls_hidden(): void
    {
        [$context, $playlist] = $this->configured();
        $low = $this->channel($playlist, ['enabled' => true, 'stream_stats' => [
            ['stream' => ['codec_type' => 'video', 'width' => 1280, 'height' => 720]],
        ]]);
        $high = $this->channel($playlist, ['enabled' => false, 'can_merge' => false, 'stream_stats' => [
            ['stream' => ['codec_type' => 'video', 'width' => 1920, 'height' => 1080]],
        ]]);
        $duplicate = $this->channel($playlist, ['url' => $high->url, 'enabled' => true]);
        $this->sync($context, $playlist);
        $this->assertTrue($high->refresh()->enabled);
        $this->assertFalse($low->refresh()->enabled);
        $this->assertFalse($duplicate->refresh()->enabled);
        $this->assertDatabaseHas('channel_failovers', ['channel_id' => $high->id, 'channel_failover_id' => $low->id]);
        $this->assertDatabaseMissing('channel_failovers', ['channel_id' => $high->id, 'channel_failover_id' => $duplicate->id]);
    }

    public function test_availability_repair_does_not_reenable_quarantined_channels_with_enabled_failovers(): void
    {
        [$context, $playlist] = $this->configured(['disable_other_groups' => true, 'repair_mode' => 'reenable_master']);
        $primary = $this->channel($playlist, ['name' => 'Zebra Boutique', 'title' => 'Zebra Boutique']);
        $backup = $this->channel($playlist);
        ChannelFailover::create(['user_id' => $context->user->id, 'channel_id' => $primary->id, 'channel_failover_id' => $backup->id]);
        $this->sync($context, $playlist);
        $this->assertFalse($primary->refresh()->enabled);
        $this->assertTrue($backup->refresh()->enabled);

        foreach ([$this->previewContext($context), $context] as $repairContext) {
            $result = $this->runGuideLinkAction(
                $repairContext->dryRun ? 'repair_availability' : 'apply_repair_availability',
                ['playlist_id' => $playlist->id], $repairContext,
            );
            $this->assertTrue($result->success, $result->summary);
            $this->assertSame(0, $result->data['repair']['applied']);
            $this->assertSame(0, $result->data['repair']['reenable_master_candidates']);
            $this->assertSame(1, $result->data['repair']['quarantined']);
            $this->assertFalse($primary->refresh()->enabled);
        }
        $details = DB::table(RunResultRepository::TABLE)->where('channel_id', $primary->id)->where('result_type', 'repair')->value('details');
        $this->assertSame('keep_disabled', json_decode($details, true)['repair_action']);
    }

    public function test_needs_review_channels_are_quarantined_without_changing_their_matching_decision(): void
    {
        [$context, $playlist] = $this->configured(['disable_other_groups' => true]);
        $channel = $this->channel($playlist, [
            'name' => 'BBC One +1', 'title' => 'BBC One +1', 'channel' => 101, 'stream_id_custom' => 'bbc.one',
        ]);
        $provider = Group::findOrFail($channel->group_id);
        $preview = $this->sync($this->previewContext($context), $playlist);
        $this->assertSame(1, $preview->data['quarantine']['to_disable']);
        $this->assertSame(1, $preview->data['writes']['groups_to_disable']);
        $this->assertTrue($channel->refresh()->enabled);
        $this->assertTrue((bool) $provider->refresh()->enabled);
        $this->assertDatabaseHas(RunResultRepository::TABLE, [
            'channel_id' => $channel->id, 'decision' => 'needs_review', 'result_type' => 'sync_match',
        ]);

        $result = $this->sync($context, $playlist);
        $this->assertSame(1, $result->data['writes']['unmanaged_channels_disabled']);
        $this->assertSame(1, $result->data['writes']['groups_disabled']);
        $this->assertFalse($channel->refresh()->enabled);
        $this->assertFalse((bool) $channel->can_merge);
        $this->assertFalse((bool) $provider->refresh()->enabled);
        $this->assertDatabaseHas(RunResultRepository::TABLE, [
            'channel_id' => $channel->id, 'decision' => 'needs_review', 'result_type' => 'sync_match',
        ]);
        $this->assertDatabaseHas(RunResultRepository::TABLE, [
            'title' => $provider->name, 'decision' => 'disable', 'result_type' => 'sync_group_disable',
        ]);
        $again = $this->sync($context, $playlist);
        $this->assertSame(0, $again->data['quarantine']['planned']);
        $this->assertSame(0, $again->data['writes']['groups_to_disable']);
        $this->assertSame(0, $again->data['writes']['groups_disabled']);
    }

    public function test_provider_groups_are_disabled_after_confirmed_matches_move_and_empty_groups_are_cleaned_up(): void
    {
        [$context, $playlist] = $this->configured(['disable_other_groups' => true]);
        $channel = $this->channel($playlist);
        $provider = Group::findOrFail($channel->group_id);
        $empty = $this->group($playlist, 'UK| EMPTY');
        $custom = $this->group($playlist, 'Personal', ['custom' => true]);
        $vod = $this->group($playlist, 'Films', ['type' => 'vod']);
        $result = $this->sync($context, $playlist);
        $this->assertSame(2, $result->data['writes']['groups_disabled']);
        $this->assertFalse((bool) $provider->refresh()->enabled);
        $this->assertFalse((bool) $empty->refresh()->enabled);
        $this->assertTrue((bool) $custom->refresh()->enabled);
        $this->assertTrue((bool) $vod->refresh()->enabled);
        $this->assertTrue($channel->refresh()->enabled);
        $this->assertSame('Entertainment', Group::findOrFail($channel->group_id)->name);
        $this->assertTrue((bool) Group::findOrFail($channel->group_id)->enabled);
    }

    public function test_group_cleanup_respects_preserve_rules_and_the_group_management_opt_out(): void
    {
        [$context, $playlist] = $this->configured([
            'disable_other_groups' => true, 'preserve_groups' => ['Private'],
            'preserve_group_name_regex' => ['^Personal'], 'preserve_channel_title_regex' => ['^Keep this$'],
        ]);
        $groups = [
            $this->group($playlist, 'Private'),
            $this->group($playlist, 'Personal channels'),
            $this->group($playlist, 'UK| preserved title'),
        ];
        $preserved = $this->channel($playlist, [
            'group_id' => $groups[2]->id, 'name' => 'Keep this', 'title' => 'Keep this', 'enabled' => false,
        ]);
        $this->sync($context, $playlist);
        foreach ($groups as $group) {
            $this->assertTrue((bool) $group->refresh()->enabled);
        }
        $this->assertFalse($preserved->refresh()->enabled);
        $this->assertTrue((bool) $preserved->can_merge);

        $this->settings(['include_managed_groups' => false]);
        $channel = $this->channel($playlist, ['name' => 'BBC One +1', 'title' => 'BBC One +1']);
        $provider = Group::findOrFail($channel->group_id);
        $result = $this->sync($context, $playlist);
        $this->assertFalse($channel->refresh()->enabled);
        $this->assertTrue((bool) $provider->refresh()->enabled);
        $this->assertSame(0, $result->data['writes']['groups_to_disable']);
    }

    public function test_new_enabled_imports_are_cleaned_up_on_the_next_sync_even_in_a_disabled_provider_group(): void
    {
        [$context, $playlist] = $this->configured(['disable_other_groups' => true]);
        $playlist->forceFill(['enable_channels' => true])->saveQuietly();
        $provider = $this->group($playlist, 'UK| GENERAL', ['enabled' => false]);
        $oldJobsConfig = config('database.connections.jobs');
        config(['database.connections.jobs' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('jobs');
        try {
            $migration = require database_path('migrations/2025_02_13_215803_create_jobs_table.php');
            $migration->up();
            $payloads = [];
            foreach (['BBC One', 'BBC One +1', 'Zebra Boutique'] as $index => $title) {
                $payloads[] = Channel::factory()->make([
                    'playlist_id' => $playlist->id, 'user_id' => $playlist->user_id,
                    'group_id' => $provider->id,
                    'source_id' => 'new-'.($index + 1), 'name' => $title, 'title' => $title,
                    'title_custom' => null, 'name_custom' => null, 'stream_id' => 'new-'.($index + 1),
                    'stream_id_custom' => null, 'channel' => null, 'enabled' => true, 'is_vod' => false,
                    'can_merge' => true, 'group_internal' => $provider->name,
                ])->getAttributes();
            }
            $job = Job::create([
                'title' => 'New channels', 'batch_no' => 'new-channels', 'payload' => $payloads,
                'variables' => ['playlistId' => $playlist->id, 'groupId' => $provider->id, 'groupName' => $provider->name],
            ]);
            (new ProcessM3uImportChunk([$job->id], 1))->handle();
        } finally {
            DB::purge('jobs');
            config(['database.connections.jobs' => $oldJobsConfig]);
        }
        $this->assertSame(3, $playlist->channels()->where('enabled', true)->count());
        $result = $this->sync($context, $playlist);
        $this->assertSame(2, $result->data['writes']['unmanaged_channels_disabled']);
        $this->assertTrue($playlist->channels()->where('source_id', 'new-1')->firstOrFail()->enabled);
        $this->assertSame('Entertainment', Group::findOrFail($playlist->channels()->where('source_id', 'new-1')->firstOrFail()->group_id)->name);
        $this->assertFalse($playlist->channels()->where('source_id', 'new-2')->firstOrFail()->enabled);
        $this->assertFalse($playlist->channels()->where('source_id', 'new-3')->firstOrFail()->enabled);
        $this->assertTrue((bool) $playlist->refresh()->enable_channels);
    }

    public function test_guide_only_matches_move_to_other_and_allow_provider_group_cleanup(): void
    {
        [$context, $playlist] = $this->configured(['disable_other_groups' => true]);
        $profile = DB::table(ProfileRepository::PROFILES_TABLE)->first();
        $settings = json_decode($profile->settings, true);
        EpgChannel::factory()->createQuietly([
            'epg_id' => $settings['guide_epg_id'], 'user_id' => $context->user->id,
            'channel_id' => 'specialty', 'name' => 'Specialty Channel', 'display_name' => '999',
        ]);
        $source = ['source_mode' => 'scraper_cache', 'min_channels' => 1, 'country' => 'uk', 'provider' => 'sky', 'provider_source' => 'viewersvault', 'region' => '1'];
        $this->settings($source);
        $cache = new SourceCacheService;
        $cache->writeLastGood($cache->config($source, []), [
            ['number' => 101, 'name' => 'BBC One', 'category' => 'Entertainment', 'aliases' => []],
        ], ['channel_count' => 1]);
        $channel = $this->channel($playlist, ['name' => 'Specialty Channel', 'title' => 'Specialty Channel']);
        $originalGroupId = $channel->group_id;
        $heading = Channel::factory()->createQuietly([
            'playlist_id' => $playlist->id, 'user_id' => $playlist->user_id,
            'group_id' => $originalGroupId, 'title' => '#### PROVIDER ####', 'name' => '#### PROVIDER ####',
            'title_custom' => null, 'name_custom' => null, 'enabled' => true, 'is_vod' => false,
        ]);
        $this->sync($this->previewContext($context), $playlist);
        $this->assertSame($originalGroupId, $channel->refresh()->group_id);
        $this->assertTrue((bool) Group::findOrFail($originalGroupId)->enabled);
        $this->assertSame(0, Group::where('playlist_id', $playlist->id)->where('name', 'Other')->count());

        $first = $this->sync($context, $playlist);
        $this->assertSame('specialty', $channel->refresh()->stream_id_custom);
        $other = Group::findOrFail($channel->group_id);
        $this->assertSame('Other', $other->name);
        $this->assertTrue((bool) $other->custom);
        $this->assertTrue((bool) $other->enabled);
        $this->assertFalse((bool) Group::findOrFail($originalGroupId)->enabled);
        $this->assertFalse($heading->refresh()->enabled);
        $this->assertSame(1, $first->data['writes']['groups_disabled']);
        $again = $this->sync($context, $playlist);
        $this->assertSame(0, $again->data['writes']['channels_written']);
        $this->assertSame(0, $again->data['quarantine']['planned']);
        $this->assertSame(0, $again->data['writes']['groups_created']);
        $this->assertSame($other->id, $channel->refresh()->group_id);
        $this->assertTrue($channel->refresh()->enabled);
    }

    public static function guideOnlyPreservationSettings(): array
    {
        return [
            'group management disabled' => [['include_managed_groups' => false]],
            'Other category preserved' => [['preserve_groups' => ['Other']]],
        ];
    }

    #[DataProvider('guideOnlyPreservationSettings')]
    public function test_guide_only_other_category_respects_group_preservation(array $settings): void
    {
        [$context, $playlist] = $this->configured(['disable_other_groups' => true, ...$settings]);
        $profile = json_decode(DB::table(ProfileRepository::PROFILES_TABLE)->first()->settings, true);
        EpgChannel::factory()->createQuietly([
            'epg_id' => $profile['guide_epg_id'], 'user_id' => $playlist->user_id,
            'channel_id' => 'specialty', 'name' => 'Specialty Channel', 'display_name' => '999',
        ]);
        $source = ['source_mode' => 'scraper_cache', 'min_channels' => 1];
        $this->settings($source);
        $cache = new SourceCacheService;
        $cache->writeLastGood($cache->config($source, []), [
            ['number' => 101, 'name' => 'BBC One', 'category' => 'Entertainment', 'aliases' => []],
        ], ['channel_count' => 1]);
        $channel = $this->channel($playlist, ['name' => 'Specialty Channel', 'title' => 'Specialty Channel']);
        $originalGroupId = $channel->group_id;
        $this->sync($context, $playlist);
        $this->assertSame('specialty', $channel->refresh()->stream_id_custom);
        $this->assertSame($originalGroupId, $channel->group_id);
        $this->assertTrue((bool) Group::findOrFail($originalGroupId)->enabled);
        $this->assertSame(0, Group::where('playlist_id', $playlist->id)->where('name', 'Other')->count());
    }

    public function test_logo_preference_is_previewed_and_applied_to_primary_and_disabled_backup_with_fallbacks(): void
    {
        [$context, $playlist] = $this->configured(['logo_source' => 'epg']);
        $playlist->forceFill(['enable_logo_proxy' => false])->saveQuietly();
        $channel = $this->channel($playlist, ['logo_internal' => 'https://example.test/provider.png']);
        $backup = $this->channel($playlist, ['enabled' => false, 'logo' => 'https://example.test/custom.png']);
        $epg = EpgChannel::where('channel_id', 'bbc.one')->firstOrFail();
        $epg->forceFill(['icon' => 'https://example.test/epg.png', 'icon_custom' => null])->saveQuietly();
        $preview = $this->sync($this->previewContext($context), $playlist);
        $this->assertSame(ChannelLogoType::Channel, $channel->refresh()->logo_type);
        $row = DB::table(RunResultRepository::TABLE)->where('channel_id', $channel->id)->value('details');
        $this->assertArrayNotHasKey('logo_type', json_decode($row, true)['write_plan']['native_changes']);
        $this->assertArrayNotHasKey('epg_channel_id', json_decode($row, true)['write_plan']['native_changes']);
        $this->assertTrue($preview->data['native_settings']['epg_mapping']['details']['desired']['settings']['set_epg_icon']);

        $this->sync($context, $playlist);
        $this->assertTrue(EpgMap::sole()->settings['set_epg_icon']);
        $this->assertSame(ChannelLogoType::Channel, $channel->refresh()->logo_type);
        $this->assertSame(ChannelLogoType::Channel, $backup->refresh()->logo_type);
        $this->assertFalse($backup->enabled);
        $this->assertNull($channel->epg_channel_id);
        $this->runNativeMap(EpgMap::sole());
        $this->assertSame(ChannelLogoType::Epg, $channel->refresh()->logo_type);
        $this->assertSame(ChannelLogoType::Epg, $backup->refresh()->logo_type);
        $this->assertSame('https://example.test/epg.png', LogoService::getChannelLogoUrl($channel->fresh()));
        $this->assertSame('https://example.test/custom.png', LogoService::getChannelLogoUrl($backup));
        $this->assertSame(0, $this->sync($context, $playlist)->data['writes']['channels_written']);
        $epg->forceFill(['icon' => null])->saveQuietly();
        $this->assertSame('https://example.test/provider.png', LogoService::getChannelLogoUrl($channel->fresh()));

        $this->settings(['logo_source' => 'channel']);
        $this->sync($context, $playlist);
        $this->assertFalse(EpgMap::sole()->settings['set_epg_icon']);
        $this->runNativeMap(EpgMap::sole());
        $this->assertSame(ChannelLogoType::Channel, $channel->refresh()->logo_type);
        $this->assertSame(ChannelLogoType::Channel, $backup->refresh()->logo_type);
        $this->assertSame('https://example.test/custom.png', LogoService::getChannelLogoUrl($backup));
    }

    public function test_native_mapping_repairs_epg_logo_preference_without_metadata_changes_and_keep_preserves_it(): void
    {
        [$context, $playlist] = $this->configured(['logo_source' => 'epg']);
        $channel = $this->channel($playlist);
        $this->sync($context, $playlist);
        $this->runNativeMap(EpgMap::sole());
        $this->assertSame(ChannelLogoType::Epg, $channel->refresh()->logo_type);

        $channel->forceFill(['logo_type' => 'channel'])->saveQuietly();
        $result = $this->sync($context, $playlist);
        $this->assertSame(0, $result->data['writes']['channels_written']);
        $this->assertSame('queued', $result->data['native_settings']['epg_mapping']['status']);
        $this->assertSame([], $result->data['native_settings']['epg_mapping']['details']['changes']);
        $this->assertSame(ChannelLogoType::Channel, $channel->refresh()->logo_type);
        $this->runNativeMap(EpgMap::sole());
        $this->assertSame(ChannelLogoType::Epg, $channel->refresh()->logo_type);
        $this->assertSame('queued', $this->sync($context, $playlist)->data['native_settings']['epg_mapping']['status']);

        $this->settings(['logo_source' => 'preserve']);
        $this->sync($context, $playlist);
        $this->assertFalse(EpgMap::sole()->settings['set_epg_icon']);
        $this->runNativeMap(EpgMap::sole());
        $this->assertSame(ChannelLogoType::Epg, $channel->refresh()->logo_type);
    }

    public function test_existing_logo_preferences_and_quarantine_are_unchanged_by_default(): void
    {
        [$context, $playlist] = $this->configured();
        $channel = $this->channel($playlist, ['logo_type' => 'epg']);
        $unmatched = $this->channel($playlist, ['title' => 'Zebra Boutique', 'name' => 'Zebra Boutique']);
        $review = $this->channel($playlist, ['title' => 'BBC One +1', 'name' => 'BBC One +1']);
        $this->sync($context, $playlist);
        $this->assertFalse(EpgMap::sole()->settings['set_epg_icon']);
        $this->runNativeMap(EpgMap::sole());
        $this->assertSame(ChannelLogoType::Epg, $channel->refresh()->logo_type);
        $this->assertTrue($unmatched->refresh()->enabled);
        $this->assertTrue((bool) $unmatched->can_merge);
        $this->assertTrue($review->refresh()->enabled);
        $this->assertTrue((bool) Group::findOrFail($review->group_id)->enabled);
        $this->assertDatabaseCount(ChannelQuarantineService::TABLE, 0);
    }

    private function configured(array $settings = []): array
    {
        [$context, $playlist] = $this->context(applyPluginSchema: true, dryRun: false);
        $source = Playlist::factory()->for($context->user)->createQuietly();
        $sourceGroup = $this->group($source, 'Entertainment');
        $this->channel($source, ['group_id' => $sourceGroup->id, 'channel' => 101, 'stream_id' => 'bbc.one']);
        $this->assignProfile($context, $playlist, [
            'source_playlist_id' => $source->id, 'guide_epg_id' => $this->guideEpgId($context), ...$settings,
        ]);

        return [$context, $playlist];
    }

    private function group(Playlist $playlist, string $name, array $attributes = []): Group
    {
        return Group::factory()->createQuietly([
            'playlist_id' => $playlist->id, 'user_id' => $playlist->user_id, 'name' => $name,
            'name_internal' => $name, 'type' => 'live', 'enabled' => true, 'custom' => false,
            ...$attributes,
        ])->refresh();
    }

    private function channel(Playlist $playlist, array $attributes = []): Channel
    {
        $group = $this->group($playlist, 'UK| GENERAL');

        return Channel::factory()->createQuietly([
            'uuid' => null,
            'playlist_id' => $playlist->id, 'user_id' => $playlist->user_id, 'group_id' => $group->id,
            'group_internal' => $group->name, 'title' => 'BBC One', 'name' => 'BBC One',
            'title_custom' => null, 'name_custom' => null, 'stream_id' => 'provider-id', 'stream_id_custom' => null,
            'channel' => null, 'enabled' => true, 'is_vod' => false, 'can_merge' => true,
            'logo_type' => 'channel', 'logo' => null, 'epg_channel_id' => null,
            ...$attributes,
        ])->refresh();
    }

    private function settings(array $changes): void
    {
        $profile = DB::table(ProfileRepository::PROFILES_TABLE)->first();
        DB::table(ProfileRepository::PROFILES_TABLE)->where('id', $profile->id)->update([
            'settings' => json_encode([...json_decode($profile->settings, true), ...$changes]),
        ]);
    }

    private function previewContext(PluginExecutionContext $context): PluginExecutionContext
    {
        return new PluginExecutionContext($context->plugin, $context->run, 'test', true, null, $context->user, []);
    }

    private function sync(PluginExecutionContext $context, Playlist $playlist): PluginActionResult
    {
        $result = $this->runGuideLinkAction($context->dryRun ? 'sync_playlist' : 'apply_sync_playlist', ['playlist_id' => $playlist->id], $context);
        $this->assertTrue($result->success, $result->summary);

        return $result;
    }
}
