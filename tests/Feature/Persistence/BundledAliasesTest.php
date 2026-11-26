<?php

namespace AppLocalPlugins\GuideLink\Tests\Feature\Persistence;

require_once dirname(__DIR__).'/PluginFeatureTestCase.php';

use App\Models\Channel;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\EpgMap;
use App\Models\Playlist;
use App\Models\User;
use App\Plugins\Support\PluginExecutionContext;
use App\Plugins\Support\PluginSelectOptionsContext;
use AppLocalPlugins\GuideLink\Plugin;
use AppLocalPlugins\GuideLink\Services\BundledAliasService;
use AppLocalPlugins\GuideLink\Services\ProfileAliasResolver;
use AppLocalPlugins\GuideLink\Services\ProfileRepository;
use AppLocalPlugins\GuideLink\Support\CountryRulesRepository;
use AppLocalPlugins\GuideLink\Tests\Feature\PluginFeatureTestCase;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class BundledAliasesTest extends PluginFeatureTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Http::preventStrayRequests();
    }

    public function test_preview_reads_the_file_without_copying_aliases_or_changing_settings(): void
    {
        [$context, $playlist, $set] = $this->prepare(true);
        $before = DB::table(ProfileRepository::PROFILES_TABLE)->first()->settings;
        $sets = DB::table(ProfileAliasResolver::SETS)->get()->toArray();
        $result = $this->runGuideLinkAction('preview_alias_set', ['alias_set_id' => $set], $context);
        $this->assertTrue($result->success, $result->summary);
        $this->assertSame(['guide_entries' => 33, 'alternative_names' => 58], $result->data['totals']);
        $this->assertSame('Read-only bundled defaults', $result->data['source']);
        $this->assertSame('sky-uk', $result->data['bundle_id']);
        $this->assertSame(['Utsav Plus'], array_column($result->data['entries'], 'alternative_names', 'guide_id')['705.uk']);
        $this->assertTrue($context->run->logs()->where('message', 'like', 'Star Plus [705.uk]:%')->exists());
        $this->assertDatabaseCount(ProfileAliasResolver::ALIASES, 0);
        $this->assertSame($before, DB::table(ProfileRepository::PROFILES_TABLE)->first()->settings);
        $this->assertEquals($sets, DB::table(ProfileAliasResolver::SETS)->get()->toArray());
    }

    public function test_matching_reads_defaults_even_if_database_aliases_are_edited_or_disabled(): void
    {
        [$context, $playlist, $set] = $this->prepare(false);
        // An old or forged database row must never override a read-only dataset.
        DB::table(ProfileAliasResolver::ALIASES)->insert([
            'extension_plugin_id' => $context->plugin->id, 'alias_set_ids' => json_encode([$set]),
            'target_stream_id' => '705.uk', 'aliases' => json_encode(['Incorrect local edit']), 'enabled' => false,
        ]);
        $channel = Channel::factory()->createQuietly([
            'playlist_id' => $playlist->id, 'user_id' => $context->user->id, 'group_id' => null,
            'title' => 'Utsav Plus', 'name' => 'Utsav Plus', 'enabled' => true, 'is_vod' => false,
        ]);
        $rows = DB::table(ProfileAliasResolver::ALIASES)->get()->toArray();
        $result = $this->runGuideLinkAction('apply_sync_playlist', ['playlist_id' => $playlist->id], $context);
        $this->assertTrue($result->success, $result->summary);
        $this->assertSame('705.uk', $channel->refresh()->stream_id_custom);
        $this->assertEquals($rows, DB::table(ProfileAliasResolver::ALIASES)->get()->toArray());
    }

    public function test_replacing_the_dataset_file_is_visible_without_a_revision_job_or_import(): void
    {
        $directory = sys_get_temp_dir().'/guidelink-bundle-'.uniqid();
        mkdir($directory);
        $file = $directory.'/sky-uk.json';
        try {
            $bundle = (new BundledAliasService)->load('sky-uk');
            file_put_contents($file, json_encode($bundle));
            $reader = new BundledAliasService($directory);
            $this->assertSame(['Utsav Plus'], $reader->load('sky-uk')['entries'][0]['aliases']);
            // Even the same informational revision reads the replacement contents.
            $bundle['entries'][0]['aliases'] = ['Replacement default'];
            file_put_contents($file, json_encode($bundle));
            $this->assertSame(['Replacement default'], $reader->load('sky-uk')['entries'][0]['aliases']);
        } finally {
            unlink($file);
            rmdir($directory);
        }
        $this->assertSame([], (new Plugin)->scheduledActions(CarbonImmutable::now(), []));
    }

    public function test_verified_rebrands_and_slot_moves_sync_once_without_enabling_disabled_copies(): void
    {
        [$context, $playlist] = $this->prepare(false);
        $settings = json_decode(DB::table(ProfileRepository::PROFILES_TABLE)->first()->settings, true);
        EpgChannel::factory()->createQuietly([
            'epg_id' => $settings['guide_epg_id'], 'user_id' => $context->user->id,
            'channel_id' => '153.uk', 'display_name' => '153', 'name' => '5Action',
        ]);
        Channel::factory()->createQuietly([
            'playlist_id' => $settings['source_playlist_id'], 'user_id' => $context->user->id,
            'group_id' => null, 'channel' => 153, 'title' => '5Action', 'name' => '5Action',
            'enabled' => true, 'is_vod' => false,
        ]);
        $channels = [];
        foreach ([
            ['5Select', 153, '5Drama', 150, true],
            ['5Action', 150, '5Action', 153, true],
            ['5Action', 150, '5Action', 153, false],
            ['Now 90s', 999, 'Now 90s & 00s', 357, true],
        ] as [$oldName, $oldNumber, $name, $number, $enabled]) {
            $channel = Channel::factory()->createQuietly([
                'playlist_id' => $playlist->id, 'user_id' => $context->user->id, 'group_id' => null,
                'title' => 'UK: '.$oldName.' HD', 'name' => 'UK: '.$oldName.' HD',
                'title_custom' => $oldName, 'name_custom' => $oldName, 'channel' => $oldNumber,
                'stream_id' => '', 'stream_id_custom' => $oldNumber.'.uk', 'enabled' => $enabled, 'is_vod' => false,
            ]);
            $channels[] = [$channel, $name, $number, $enabled];
        }

        $payload = ['playlist_id' => $playlist->id];
        $result = $this->runGuideLinkAction('apply_sync_playlist', $payload, $context);
        $this->assertTrue($result->success, $result->summary);
        foreach ($channels as [$channel, $name, $number, $enabled]) {
            $channel->refresh();
            $this->assertSame($name, $channel->title_custom);
            $this->assertSame($number.'.uk', $channel->stream_id_custom);
            $this->assertSame($enabled, $channel->enabled);
        }
        $again = $this->runGuideLinkAction('apply_sync_playlist', $payload, $context);
        $this->assertTrue($again->success, $again->summary);
        $this->assertSame(0, $again->data['writes']['channels_written']);
    }

    public function test_verified_seasonal_names_and_rebrands_recover_saved_matches(): void
    {
        [$context, $playlist] = $this->prepare(false);
        $settings = json_decode(DB::table(ProfileRepository::PROFILES_TABLE)->first()->settings, true);
        $channels = [];
        foreach ([
            [302, 'Sky Cinema Animation', 'Sky Cinema Christmas', 'Sky Christmas HD'],
            [303, 'Sky Cinema Bourne', 'Sky Cinema The Mummy', 'Sky M:I HD'],
            [605, 'Nick Alvinnn!!!', 'Nick SpongeBob', 'Nick SpongeBob'],
            [735, 'New Vision TV', 'ARY World', 'ARY World'],
        ] as [$number, $oldName, $currentName, $guideName]) {
            EpgChannel::where('channel_id', $number.'.uk')->update(['name' => $guideName]);
            Channel::where('playlist_id', $settings['source_playlist_id'])->where('channel', $number)
                ->update(['title' => $currentName, 'name' => $currentName]);
            $channel = Channel::factory()->createQuietly([
                'playlist_id' => $playlist->id, 'user_id' => $playlist->user_id, 'group_id' => null,
                'title' => 'UK: '.$oldName.' HD', 'name' => 'UK: '.$oldName.' HD',
                'title_custom' => $oldName, 'name_custom' => $oldName, 'channel' => $number,
                'stream_id' => '', 'stream_id_custom' => $number.'.uk', 'enabled' => true, 'is_vod' => false,
            ]);
            $channels[] = [$channel, $oldName, $currentName, $number];
        }
        $preview = new PluginExecutionContext(
            $context->plugin, $context->run, 'test', true, null, $context->user, [],
        );
        $payload = ['playlist_id' => $playlist->id];
        $result = $this->runGuideLinkAction('sync_playlist', $payload, $preview);
        $this->assertTrue($result->success, $result->summary);
        foreach ($channels as [$channel, $oldName]) {
            $this->assertSame($oldName, $channel->refresh()->title_custom);
        }

        $result = $this->runGuideLinkAction('apply_sync_playlist', $payload, $context);
        $this->assertTrue($result->success, $result->summary);
        foreach ($channels as [$channel, , $currentName, $number]) {
            $this->assertSame($currentName, $channel->refresh()->title_custom);
            $this->assertSame($number.'.uk', $channel->stream_id_custom);
        }
        $again = $this->runGuideLinkAction('apply_sync_playlist', $payload, $context);
        $this->assertTrue($again->success, $again->summary);
        $this->assertSame(0, $again->data['writes']['channels_written']);

        EpgChannel::where('channel_id', '303.uk')->update(['name' => 'Unrelated Movies']);
        $result = $this->runGuideLinkAction('sync_playlist', $payload, $preview);
        $this->assertTrue($result->success, $result->summary);
        $this->assertTrue($context->run->logs()->where('message', 'like', 'Bundled alias identity changed%')->exists());
    }

    public function test_wrong_bundled_identity_withholds_alias_and_preserves_affected_channel(): void
    {
        [$context, $playlist] = $this->prepare(false);
        EpgChannel::where('channel_id', '705.uk')->update(['name' => 'Unrelated News']);
        $channel = Channel::factory()->createQuietly([
            'playlist_id' => $playlist->id, 'user_id' => $context->user->id, 'group_id' => null,
            'title' => 'Utsav Plus', 'name' => 'Utsav Plus', 'title_custom' => null, 'name_custom' => null,
            'stream_id' => '', 'stream_id_custom' => null, 'channel' => null, 'enabled' => true, 'is_vod' => false,
        ]);
        $before = Channel::orderBy('id')->get()->toArray();
        $result = $this->runGuideLinkAction('apply_sync_playlist', ['playlist_id' => $playlist->id], $context);
        $this->assertTrue($result->success, $result->summary);
        $this->assertSame(1, $result->data['matching']['needs_review']);
        $this->assertSame(0, $result->data['quarantine']['planned']);
        $this->assertSame('queued', $result->data['native_settings']['epg_mapping']['status']);
        $this->assertSame($before, Channel::orderBy('id')->get()->toArray());
        $this->assertDatabaseCount(ProfileAliasResolver::ALIASES, 0);
    }

    public function test_spider_man_alias_updates_old_names_and_hands_channel_303_to_native_mapping(): void
    {
        [$context, $playlist] = $this->prepare(false);
        EpgChannel::where('channel_id', '303.uk')->update(['name' => 'Spider-Man HD']);
        $channels = [];
        foreach ([true, false] as $previouslyMatched) {
            $channels[] = Channel::factory()->createQuietly([
                'playlist_id' => $playlist->id, 'user_id' => $context->user->id, 'group_id' => null,
                'title' => 'UK: SKY CINEMA HITS ◉', 'name' => 'UK: SKY CINEMA HITS ◉',
                'title_custom' => $previouslyMatched ? 'Sky Cinema The Mummy' : null,
                'name_custom' => $previouslyMatched ? 'Sky Cinema The Mummy' : null,
                'channel' => $previouslyMatched ? 303 : null,
                'stream_id' => 'provider', 'stream_id_custom' => $previouslyMatched ? '303.uk' : null,
                'enabled' => true, 'is_vod' => false, 'epg_channel_id' => null, 'epg_map_enabled' => true,
            ]);
        }
        $result = $this->runGuideLinkAction('apply_sync_playlist', ['playlist_id' => $playlist->id], $context);
        $this->assertTrue($result->success, $result->summary);
        $this->assertSame(0, $result->data['matching']['needs_review']);
        foreach ($channels as $channel) {
            $this->assertSame('Sky Cinema Spider-Man', $channel->refresh()->title_custom);
            $this->assertEquals(303, $channel->channel);
            $this->assertSame('303.uk', $channel->stream_id_custom);
            $this->assertNull($channel->epg_channel_id);
        }
        $this->runNativeMap(EpgMap::sole());
        $epgChannelId = EpgChannel::where('channel_id', '303.uk')->value('id');
        foreach ($channels as $channel) {
            $this->assertSame($epgChannelId, $channel->refresh()->epg_channel_id);
        }
    }

    public function test_unlisted_seasonal_name_does_not_block_existing_ids_or_current_source_names(): void
    {
        [$context, $playlist] = $this->prepare(false);
        $settings = json_decode(DB::table(ProfileRepository::PROFILES_TABLE)->first()->settings, true);
        EpgChannel::where('channel_id', '303.uk')->update(['name' => 'Future Festival HD']);
        Channel::where('playlist_id', $settings['source_playlist_id'])->where('channel', 303)
            ->update(['title' => 'Sky Cinema Future Festival', 'name' => 'Sky Cinema Future Festival']);
        $old = Channel::factory()->createQuietly([
            'playlist_id' => $playlist->id, 'user_id' => $context->user->id, 'group_id' => null,
            'title' => 'UK: SKY CINEMA HITS', 'name' => 'UK: SKY CINEMA HITS',
            'title_custom' => 'Sky Cinema The Mummy', 'name_custom' => 'Sky Cinema The Mummy',
            'channel' => 303, 'stream_id_custom' => '303.uk', 'enabled' => true, 'is_vod' => false,
            'epg_channel_id' => null, 'epg_map_enabled' => true,
        ]);
        $fresh = Channel::factory()->createQuietly([
            'playlist_id' => $playlist->id, 'user_id' => $context->user->id, 'group_id' => null,
            'title' => 'Sky Cinema Future Festival', 'name' => 'Sky Cinema Future Festival',
            'title_custom' => null, 'name_custom' => null, 'channel' => null, 'stream_id_custom' => null,
            'enabled' => true, 'is_vod' => false, 'epg_channel_id' => null, 'epg_map_enabled' => true,
        ]);
        $before = $old->refresh()->getAttributes();
        $result = $this->runGuideLinkAction('apply_sync_playlist', ['playlist_id' => $playlist->id], $context);
        $this->assertTrue($result->success, $result->summary);
        $this->assertSame(1, $result->data['matching']['needs_review']);
        $this->assertSame(0, $result->data['quarantine']['planned']);
        $this->assertSame($before, $old->refresh()->getAttributes());
        $this->assertSame('303.uk', $fresh->refresh()->stream_id_custom);
        $this->assertNull(EpgMap::sole()->channels);
        $this->assertNull($old->epg_channel_id);
        $this->assertNull($fresh->epg_channel_id);
        $this->assertSame('queued', $result->data['native_settings']['epg_mapping']['status']);
        $this->runNativeMap(EpgMap::sole());
        $epgChannelId = EpgChannel::where('channel_id', '303.uk')->value('id');
        $this->assertSame($epgChannelId, $old->refresh()->epg_channel_id);
        $this->assertSame($epgChannelId, $fresh->refresh()->epg_channel_id);
    }

    public function test_missing_target_warns_and_skips_without_installing_partial_defaults(): void
    {
        [$context, $playlist] = $this->prepare(true);
        EpgChannel::where('channel_id', '705.uk')->delete();
        $result = $this->runGuideLinkAction('sync_playlist', ['playlist_id' => $playlist->id], $context);
        $this->assertTrue($result->success, $result->summary);
        $this->assertTrue($context->run->logs()->where('message', 'like', 'Alias targets are unavailable%')->exists());
        $this->assertDatabaseCount(ProfileAliasResolver::ALIASES, 0);
    }

    public function test_guide_formatting_and_short_names_pass_but_timeshift_mismatch_withholds_alias(): void
    {
        [$context, $playlist] = $this->prepare(true);
        EpgChannel::where('channel_id', '705.uk')->update(['name' => 'Star Plus HD']);
        EpgChannel::where('channel_id', '768.uk')->update(['name' => 'PBC']);
        EpgChannel::where('channel_id', '321.uk')->update(['name' => 'G! Mystery+1']);
        $result = $this->runGuideLinkAction('sync_playlist', ['playlist_id' => $playlist->id], $context);
        $this->assertTrue($result->success, $result->summary);
        EpgChannel::where('channel_id', '321.uk')->update(['name' => 'G! Mystery']);
        $result = $this->runGuideLinkAction('sync_playlist', ['playlist_id' => $playlist->id], $context);
        $this->assertTrue($result->success, $result->summary);
        $this->assertTrue($context->run->logs()->where('message', 'like', 'Bundled alias identity changed%')->exists());
    }

    public function test_alias_editor_only_offers_owned_custom_sets(): void
    {
        [$context, , $set] = $this->prepare(true);
        $copy = (array) DB::table(ProfileAliasResolver::SETS)->where('id', $set)->first();
        unset($copy['id']);
        $custom = DB::table(ProfileAliasResolver::SETS)->insertGetId([...$copy, 'name' => 'Custom', 'bundle_id' => null]);
        $options = new PluginSelectOptionsContext($context->plugin, $context->user, [], ['alias_set_ids' => [$set]], []);
        $this->assertSame([$custom => 'Custom'], (new Plugin)->selectOptions('guidelink_custom_alias_sets', $options));
        $this->assertSame([], (new Plugin)->selectOptions('guidelink_alias_targets', $options));
        $this->assertSame(['sky-uk' => 'Sky UK channel aliases'], (new Plugin)->selectOptions('guidelink_bundled_sets', $options));
        $context->user->update(['is_admin' => false]);
        Epg::whereKey($copy['guide_epg_id'])->update(['user_id' => User::factory()->create()->id]);
        $this->assertSame([], (new Plugin)->selectOptions('guidelink_custom_alias_sets', $options));
    }

    public function test_preview_is_scoped_and_includes_disabled_custom_aliases(): void
    {
        [$context, , $set] = $this->prepare(true);
        DB::table(ProfileAliasResolver::SETS)->where('id', $set)->update(['bundle_id' => null, 'enabled' => false]);
        DB::table(ProfileAliasResolver::ALIASES)->insert([
            'extension_plugin_id' => $context->plugin->id, 'alias_set_ids' => json_encode([$set]),
            'target_stream_id' => '705.uk', 'aliases' => json_encode(['My custom name']), 'enabled' => false,
        ]);
        $result = $this->runGuideLinkAction('preview_alias_set', ['alias_set_id' => $set], $context);
        $this->assertTrue($result->success, $result->summary);
        $this->assertSame('Custom aliases', $result->data['source']);
        $this->assertFalse($result->data['entries'][0]['enabled']);
        $this->assertSame(['My custom name'], $result->data['entries'][0]['alternative_names']);
        $result = $this->runGuideLinkAction('preview_alias_set', ['alias_set_id' => $set + 1], $context);
        $this->assertFalse($result->success);
        $context->plugin->update(['plugin_id' => 'guidelink']);
        [$foreign] = $this->context();
        $result = $this->runGuideLinkAction('preview_alias_set', ['alias_set_id' => $set], $foreign);
        $this->assertFalse($result->success);
    }

    public function test_disabled_wrong_country_and_unknown_bundles_cannot_be_used(): void
    {
        [$context, $playlist, $set] = $this->prepare(false);
        foreach ([['enabled' => false], ['enabled' => true, 'country' => 'us'], ['country' => 'uk', 'bundle_id' => '../sky-uk']] as $change) {
            DB::table(ProfileAliasResolver::SETS)->where('id', $set)->update($change);
            $result = $this->runGuideLinkAction('apply_sync_playlist', ['playlist_id' => $playlist->id], $context);
            $this->assertFalse($result->success);
            $this->assertDatabaseCount(ProfileAliasResolver::ALIASES, 0);
        }
    }

    public function test_bundled_and_custom_sets_share_one_selected_guide_read(): void
    {
        [$context, , $bundleSet] = $this->prepare(true);
        $guide = (int) DB::table(ProfileAliasResolver::SETS)->where('id', $bundleSet)->value('guide_epg_id');
        $reference = Epg::factory()->for($context->user)->createQuietly();
        EpgChannel::factory()->createQuietly([
            'epg_id' => $reference->id, 'user_id' => $context->user->id, 'channel_id' => '705.uk',
            'display_name' => '705', 'name' => 'Star Plus HD',
        ]);
        $custom = DB::table(ProfileAliasResolver::SETS)->insertGetId([
            'extension_plugin_id' => $context->plugin->id, 'name' => 'Custom', 'country' => 'uk',
            'guide_epg_id' => $reference->id, 'enabled' => true,
        ]);
        DB::table(ProfileAliasResolver::ALIASES)->insert([
            'extension_plugin_id' => $context->plugin->id, 'alias_set_ids' => json_encode([$custom]),
            'target_stream_id' => '705.uk', 'aliases' => json_encode(['Provider Star']), 'enabled' => true,
        ]);
        $entries = array_map(fn (array $row): array => [
            'number' => (int) $row['target_stream_id'], 'name' => $row['target_name'],
            'aliases' => [], 'stream_id' => $row['target_stream_id'], 'guide_valid' => true,
        ], (new BundledAliasService)->load('sky-uk')['entries']);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $resolved = (new ProfileAliasResolver)->apply($entries, ['guide_epg_id' => $guide, 'alias_set_ids' => [$bundleSet, $custom]], $context);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $this->assertCount(6, array_filter($queries, fn (array $query): bool => str_starts_with($query['query'], 'select')));
        $target = array_column($resolved, null, 'stream_id')['705.uk'];
        $key = (new CountryRulesRepository)->normalize('Utsav Plus');
        $this->assertSame([$bundleSet => 'Sky defaults'], $target['profile_aliases'][$key]);
        $this->assertSame([$custom => 'Custom'], $target['profile_aliases']['PROVIDER STAR']);
    }

    private function prepare(bool $dryRun): array
    {
        [$context, $playlist] = $this->context(applyPluginSchema: true, dryRun: $dryRun);
        $source = Playlist::factory()->for($context->user)->createQuietly();
        $guide = Epg::factory()->for($context->user)->createQuietly();
        $pack = json_decode(file_get_contents(dirname(__DIR__, 3).'/src/AliasSets/sky-uk.json'), true);
        foreach ($pack['entries'] as $entry) {
            $number = (int) $entry['target_stream_id'];
            EpgChannel::factory()->createQuietly([
                'epg_id' => $guide->id, 'user_id' => $context->user->id, 'channel_id' => $entry['target_stream_id'],
                'display_name' => (string) $number, 'name' => $entry['target_name'],
            ]);
            Channel::factory()->createQuietly([
                'playlist_id' => $source->id, 'user_id' => $context->user->id, 'group_id' => null,
                'channel' => $number, 'title' => $entry['target_name'], 'name' => $entry['target_name'],
                'enabled' => true, 'is_vod' => false,
            ]);
        }
        $set = DB::table(ProfileAliasResolver::SETS)->insertGetId([
            'extension_plugin_id' => $context->plugin->id, 'name' => 'Sky defaults',
            'country' => 'uk', 'guide_epg_id' => $guide->id, 'enabled' => true, 'bundle_id' => 'sky-uk',
        ]);
        $this->assignProfile($context, $playlist, [
            'source_playlist_id' => $source->id, 'guide_epg_id' => $guide->id, 'alias_set_ids' => [$set], 'unrelated_option' => 'kept',
        ]);

        return [$context, $playlist, $set];
    }
}
