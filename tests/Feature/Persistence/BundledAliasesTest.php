<?php

namespace AppLocalPlugins\GuideLink\Tests\Feature\Persistence;

require_once dirname(__DIR__).'/PluginFeatureTestCase.php';

use App\Models\Channel;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\Playlist;
use App\Models\User;
use App\Plugins\Support\PluginSelectOptionsContext;
use AppLocalPlugins\GuideLink\Plugin;
use AppLocalPlugins\GuideLink\Services\BundledAliasService;
use AppLocalPlugins\GuideLink\Services\ProfileAliasResolver;
use AppLocalPlugins\GuideLink\Services\ProfileRepository;
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
        $this->assertSame(['guide_entries' => 30, 'alternative_names' => 44], $result->data['totals']);
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

    public function test_wrong_channel_identity_aborts_before_channel_or_alias_writes(): void
    {
        [$context, $playlist] = $this->prepare(false);
        EpgChannel::where('channel_id', '705.uk')->update(['name' => 'Unrelated News']);
        $before = Channel::orderBy('id')->get()->toArray();
        $result = $this->runGuideLinkAction('apply_sync_playlist', ['playlist_id' => $playlist->id], $context);
        $this->assertFalse($result->success);
        $this->assertStringContainsString('does not identify [Star Plus]', $result->summary);
        $this->assertSame($before, Channel::orderBy('id')->get()->toArray());
        $this->assertDatabaseCount(ProfileAliasResolver::ALIASES, 0);
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

    public function test_guide_formatting_and_declared_short_names_pass_but_timeshift_mismatch_fails(): void
    {
        [$context, $playlist] = $this->prepare(true);
        EpgChannel::where('channel_id', '705.uk')->update(['name' => 'Star Plus HD']);
        EpgChannel::where('channel_id', '768.uk')->update(['name' => 'PBC']);
        EpgChannel::where('channel_id', '321.uk')->update(['name' => 'G! Mystery+1']);
        $result = $this->runGuideLinkAction('sync_playlist', ['playlist_id' => $playlist->id], $context);
        $this->assertTrue($result->success, $result->summary);
        EpgChannel::where('channel_id', '321.uk')->update(['name' => 'G! Mystery']);
        $result = $this->runGuideLinkAction('sync_playlist', ['playlist_id' => $playlist->id], $context);
        $this->assertFalse($result->success);
        $this->assertStringContainsString('321.uk', $result->summary);
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
