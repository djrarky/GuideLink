<?php

namespace AppLocalPlugins\GuideLink\Tests\Feature\Actions;

require_once dirname(__DIR__).'/PluginFeatureTestCase.php';

use App\Enums\ChannelLogoType;
use App\Jobs\MergeChannels;
use App\Jobs\ProcessM3uImportComplete;
use App\Models\Channel;
use App\Models\ChannelFailover;
use App\Models\EpgChannel;
use App\Models\Group;
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
use AppLocalPlugins\GuideLink\Support\CountryRulesRepository;
use AppLocalPlugins\GuideLink\Tests\Feature\PluginFeatureTestCase;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

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

    public function test_sync_omits_failover_queries_while_availability_retains_failover_details(): void
    {
        [$context, $playlist] = $this->configured();
        $primary = $this->channel($playlist);
        $backup = $this->channel($playlist, ['enabled' => false]);
        ChannelFailover::create(['user_id' => $context->user->id, 'channel_id' => $primary->id, 'channel_failover_id' => $backup->id]);

        DB::enableQueryLog();
        try {
            $this->sync($context, $playlist);
            $failoverQueries = array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains($query['query'], 'channel_failovers'));
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $this->assertSame([], $failoverQueries);

        $repository = new ChannelRepository(new CountryRulesRepository);
        $channels = $repository->liveChannels($playlist, true);
        $master = $channels->find($primary->id);
        $this->assertTrue($master->relationLoaded('group'));
        $this->assertTrue($master->relationLoaded('failovers'));
        $this->assertSame(['total' => 1, 'enabled' => 0, 'enabled_ids' => [], 'enabled_titles' => []], $repository->failoverSummary($master));
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
        $this->assertFalse((bool) $backup->can_merge);
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

    public function test_quarantined_channel_can_be_matched_later_without_overriding_manual_merge_opt_outs(): void
    {
        [$context, $playlist] = $this->configured(['disable_other_groups' => true]);
        $channel = $this->channel($playlist, ['name' => 'Zebra Boutique', 'title' => 'Zebra Boutique']);
        $this->sync($context, $playlist);
        $this->assertFalse((bool) $channel->refresh()->can_merge);
        $channel->forceFill(['title' => 'BBC One', 'name' => 'BBC One'])->saveQuietly();
        $this->sync($context, $playlist);
        $this->assertTrue((bool) $channel->refresh()->can_merge);
        $this->assertFalse($channel->enabled);
        $this->assertSame('bbc.one', $channel->stream_id_custom);
        $this->assertDatabaseCount(ChannelQuarantineService::TABLE, 0);
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

    public function test_needs_review_decisions_do_not_quarantine_channels(): void
    {
        [$context, $playlist] = $this->configured(['disable_other_groups' => true]);
        $channel = $this->channel($playlist);
        $service = new ChannelQuarantineService;
        $this->assertSame([], $service->plan($playlist, new Collection([$channel]), [
            ['channel_id' => $channel->id, 'decision' => 'needs_review', 'exclusion_reason' => ''],
        ], true, $context));
        $this->assertTrue($channel->refresh()->enabled);
    }

    public function test_guide_only_matches_without_a_category_survive_identical_runs(): void
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
        $this->sync($context, $playlist);
        $this->assertSame('specialty', $channel->refresh()->stream_id_custom);
        $this->assertSame($originalGroupId, $channel->group_id);
        $again = $this->sync($context, $playlist);
        $this->assertSame(0, $again->data['writes']['channels_written']);
        $this->assertSame(0, $again->data['quarantine']['planned']);
        $this->assertTrue($channel->refresh()->enabled);
    }

    public function test_logo_preference_is_previewed_and_applied_to_primary_and_disabled_backup_with_fallbacks(): void
    {
        [$context, $playlist] = $this->configured(['logo_source' => 'epg']);
        $playlist->forceFill(['enable_logo_proxy' => false])->saveQuietly();
        $channel = $this->channel($playlist, ['logo_internal' => 'https://example.test/provider.png']);
        $backup = $this->channel($playlist, ['enabled' => false, 'logo' => 'https://example.test/custom.png']);
        $epg = EpgChannel::where('channel_id', 'bbc.one')->firstOrFail();
        $epg->forceFill(['icon' => 'https://example.test/epg.png', 'icon_custom' => null])->saveQuietly();
        $this->sync($this->previewContext($context), $playlist);
        $this->assertSame(ChannelLogoType::Channel, $channel->refresh()->logo_type);
        $row = DB::table(RunResultRepository::TABLE)->where('channel_id', $channel->id)->value('details');
        $this->assertSame('epg', json_decode($row, true)['write_plan']['native_changes']['logo_type']);

        $this->sync($context, $playlist);
        $this->assertSame(ChannelLogoType::Epg, $channel->refresh()->logo_type);
        $this->assertSame(ChannelLogoType::Epg, $backup->refresh()->logo_type);
        $this->assertFalse($backup->enabled);
        $this->assertSame('https://example.test/epg.png', LogoService::getChannelLogoUrl($channel));
        $this->assertSame('https://example.test/custom.png', LogoService::getChannelLogoUrl($backup));
        $this->assertSame(0, $this->sync($context, $playlist)->data['writes']['channels_written']);
        $epg->forceFill(['icon' => null])->saveQuietly();
        $this->assertSame('https://example.test/provider.png', LogoService::getChannelLogoUrl($channel->fresh()));

        $this->settings(['logo_source' => 'channel']);
        $this->sync($context, $playlist);
        $this->assertSame(ChannelLogoType::Channel, $channel->refresh()->logo_type);
    }

    public function test_existing_logo_preferences_and_quarantine_are_unchanged_by_default(): void
    {
        [$context, $playlist] = $this->configured();
        $channel = $this->channel($playlist, ['logo_type' => 'epg']);
        $unmatched = $this->channel($playlist, ['title' => 'Zebra Boutique', 'name' => 'Zebra Boutique']);
        $this->sync($context, $playlist);
        $this->assertSame(ChannelLogoType::Epg, $channel->refresh()->logo_type);
        $this->assertTrue($unmatched->refresh()->enabled);
        $this->assertTrue((bool) $unmatched->can_merge);
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
