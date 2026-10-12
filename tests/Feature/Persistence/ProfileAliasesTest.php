<?php

namespace AppLocalPlugins\GuideLink\Tests\Feature\Persistence;

require_once dirname(__DIR__).'/PluginFeatureTestCase.php';

use App\Models\Channel;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\Playlist;
use App\Plugins\PluginSchemaMapper;
use App\Plugins\PluginUiTableRegistry;
use App\Plugins\Support\PluginExecutionContext;
use App\Plugins\Support\PluginSelectOptionsContext;
use AppLocalPlugins\GuideLink\Plugin;
use AppLocalPlugins\GuideLink\Services\ProfileAliasResolver;
use AppLocalPlugins\GuideLink\Services\RunResultRepository;
use AppLocalPlugins\GuideLink\Tests\Feature\PluginFeatureTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class ProfileAliasesTest extends PluginFeatureTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Http::preventStrayRequests();
        Storage::fake('local');
    }

    public function test_saved_alias_set_is_shared_by_profiles_in_preview_report_and_batch_apply(): void
    {
        [$context, $source] = $this->uniqueContext(applyPluginSchema: true, dryRun: false);
        $guide = $this->guideEpgId($context);
        $set = $this->aliasSet($context, $guide);
        DB::table(ProfileAliasResolver::ALIASES)->whereJsonContains('alias_set_ids', $set)->update(['aliases' => json_encode(['US: THE BEEB ONE'])]);
        $this->channel($context, $source, 'BBC One', 101);
        $targets = [];
        foreach (range(1, 2) as $i) {
            $playlist = Playlist::factory()->for($context->user)->createQuietly();
            $targets[] = $this->channel($context, $playlist, 'THE BEEB ONE', null);
            $this->assignProfile($context, $playlist, [
                'guide_epg_id' => $guide, 'source_playlist_id' => $source->id, 'alias_set_ids' => [$set],
            ]);
        }
        $before = $targets[0]->getAttributes();
        $preview = new PluginExecutionContext($context->plugin, $context->run, 'test', true, null, $context->user, []);
        foreach (['sync_playlist', 'availability_report'] as $action) {
            $result = $this->runGuideLinkAction($action, ['assigned_playlists' => true], $preview);
            $this->assertTrue($result->success, $result->summary);
        }
        $this->assertSame($before, $targets[0]->refresh()->getAttributes());
        $result = $this->runGuideLinkAction('apply_sync_playlist', ['assigned_playlists' => true], $context);
        $this->assertTrue($result->success, $result->summary);
        foreach ($targets as $channel) {
            $this->assertSame('bbc.one', $channel->refresh()->stream_id_custom);
            $row = DB::table(RunResultRepository::TABLE)->where('channel_id', $channel->id)->where('result_type', 'sync_match')->first();
            $details = json_decode($row->details, true);
            $this->assertSame('profile_alias:THE BEEB 1', $details['matched_on']);
            $this->assertSame('Provider names', $details['alias_sets'][$set]);
        }
    }

    public function test_invalid_alias_configuration_fails_before_any_sync_writes(): void
    {
        foreach (['missing_set', 'disabled_set', 'wrong_country', 'foreign_set', 'bad_id', 'hijack', 'compact_hijack', 'duplicate', 'empty_names', 'scalar_names', 'object_names', 'invalid_name'] as $case) {
            [$context, $source] = $this->uniqueContext(applyPluginSchema: true, dryRun: false);
            $guide = $this->guideEpgId($context);
            $set = $this->aliasSet($context, $guide);
            $this->channel($context, $source, 'BBC One', 101);
            $playlist = Playlist::factory()->for($context->user)->createQuietly();
            $channel = $this->channel($context, $playlist, 'THE BEEB ONE', null);
            if ($case === 'missing_set') {
                DB::table(ProfileAliasResolver::SETS)->where('id', $set)->delete();
            } elseif ($case === 'disabled_set') {
                DB::table(ProfileAliasResolver::SETS)->where('id', $set)->update(['enabled' => false]);
            } elseif ($case === 'wrong_country') {
                DB::table(ProfileAliasResolver::SETS)->where('id', $set)->update(['country' => 'us']);
            } elseif ($case === 'foreign_set') {
                [$foreign] = $this->uniqueContext();
                DB::table(ProfileAliasResolver::SETS)->where('id', $set)->update(['extension_plugin_id' => $foreign->plugin->id]);
            } elseif ($case === 'bad_id') {
                $set = '1oops';

            } elseif (in_array($case, ['empty_names', 'scalar_names', 'object_names', 'invalid_name'], true)) {
                $names = match ($case) {
                    'empty_names' => [],
                    'scalar_names' => 'THE BEEB ONE',
                    'object_names' => ['name' => 'THE BEEB ONE'],
                    'invalid_name' => [42],
                };
                DB::table(ProfileAliasResolver::ALIASES)->whereJsonContains('alias_set_ids', $set)->update(['aliases' => json_encode($names)]);
            } elseif (in_array($case, ['hijack', 'compact_hijack', 'duplicate'], true)) {
                EpgChannel::factory()->createQuietly([
                    'epg_id' => $guide, 'user_id' => $context->user->id, 'channel_id' => 'bbc.two', 'display_name' => '102', 'name' => 'BBC Two',
                ]);
                $this->channel($context, $source, 'BBC Two', 102);
                if ($case !== 'duplicate') {
                    DB::table(ProfileAliasResolver::ALIASES)->whereJsonContains('alias_set_ids', $set)->update(['aliases' => json_encode([$case === 'compact_hijack' ? 'BBC2' : 'BBC Two'])]);
                } else {
                    DB::table(ProfileAliasResolver::ALIASES)->insert([
                        'extension_plugin_id' => $context->plugin->id, 'alias_set_ids' => json_encode([$set]),
                        'target_stream_id' => 'bbc.two', 'aliases' => json_encode(['THE BEEB ONE']), 'enabled' => true,
                    ]);
                }
            }
            $this->assignProfile($context, $playlist, ['guide_epg_id' => $guide, 'source_playlist_id' => $source->id, 'alias_set_ids' => [$set]]);
            $before = [$playlist->refresh()->getAttributes(), $channel->getAttributes()];
            $result = $this->runGuideLinkAction('apply_sync_playlist', ['playlist_id' => $playlist->id], $context);
            $this->assertFalse($result->success, $case);
            $this->assertStringContainsStringIgnoringCase('alias', $result->summary, $case);
            $this->assertSame($before, [$playlist->refresh()->getAttributes(), $channel->refresh()->getAttributes()], $case);
        }
    }

    public function test_missing_alias_target_warns_without_blocking_other_channel_updates(): void
    {
        [$context, $source] = $this->uniqueContext(applyPluginSchema: true, dryRun: false);
        $guide = $this->guideEpgId($context);
        $set = $this->aliasSet($context, $guide);
        $this->channel($context, $source, 'BBC One', 101);
        DB::table(ProfileAliasResolver::ALIASES)->insert([
            'extension_plugin_id' => $context->plugin->id, 'alias_set_ids' => json_encode([$set]),
            'target_stream_id' => 'removed.service', 'aliases' => json_encode(['Retired Service']), 'enabled' => true,
        ]);
        $playlist = Playlist::factory()->for($context->user)->createQuietly();
        $channel = $this->channel($context, $playlist, 'THE BEEB ONE', null);
        $this->assignProfile($context, $playlist, ['guide_epg_id' => $guide, 'source_playlist_id' => $source->id, 'alias_set_ids' => [$set]]);
        $result = $this->runGuideLinkAction('apply_sync_playlist', ['playlist_id' => $playlist->id], $context);
        $this->assertTrue($result->success, $result->summary);
        $this->assertSame('bbc.one', $channel->refresh()->stream_id_custom);
        $warning = $context->run->logs()->where('message', 'like', 'Alias targets are unavailable%')->firstOrFail();
        $this->assertSame('warning', $warning->level);
        $this->assertSame('removed.service', $warning->context['unavailable_alias_targets'][0]['target_stream_id']);
        $this->assertSame(['Retired Service'], $warning->context['unavailable_alias_targets'][0]['aliases']);
    }

    public function test_ambiguous_alias_target_still_blocks_resolution(): void
    {
        $entry = ['number' => 101, 'name' => 'BBC One', 'aliases' => [], 'stream_id' => 'bbc.one', 'guide_valid' => true];
        $set = ['id' => 1, 'name' => 'Test', 'country' => 'uk', 'aliases' => [
            ['target_stream_id' => 'bbc.one', 'aliases' => ['The Beeb']],
        ]];
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ambiguous');
        (new ProfileAliasResolver)->resolveSets([$entry, [...$entry, 'number' => 102]], [], [$set]);
    }

    public function test_shared_ids_across_guides_require_the_same_channel_identity(): void
    {
        [$context, $source] = $this->uniqueContext(applyPluginSchema: true);
        $reference = $this->guideEpgId($context);
        $set = $this->aliasSet($context, $reference);
        $guide = Epg::factory()->for($context->user)->createQuietly();
        $target = EpgChannel::factory()->createQuietly([
            'epg_id' => $guide->id, 'user_id' => $context->user->id, 'channel_id' => 'bbc.one',
            'display_name' => '101', 'name' => 'BBC One HD',
        ]);
        $this->channel($context, $source, 'BBC One', 101);
        $playlist = Playlist::factory()->for($context->user)->createQuietly();
        $this->channel($context, $playlist, 'THE BEEB ONE', null);
        $this->assignProfile($context, $playlist, ['guide_epg_id' => $guide->id, 'source_playlist_id' => $source->id, 'alias_set_ids' => [$set]]);
        $result = $this->runGuideLinkAction('sync_playlist', ['playlist_id' => $playlist->id], $context);
        $this->assertTrue($result->success, $result->summary);
        $target->update(['name' => 'Unrelated News']);
        $before = Channel::orderBy('id')->get()->toArray();
        $result = $this->runGuideLinkAction('sync_playlist', ['playlist_id' => $playlist->id], $context);
        $this->assertFalse($result->success);
        $this->assertStringContainsString('does not identify the same channel', $result->summary);
        $this->assertSame($before, Channel::orderBy('id')->get()->toArray());
    }

    public function test_data_forms_use_host_json_storage_and_owned_guide_options(): void
    {
        [$context] = $this->uniqueContext(applyPluginSchema: true);
        $guide = $this->guideEpgId($context);
        $set = $this->aliasSet($context, $guide);
        $context->plugin->forceFill(['schema_definition' => $this->manifest()['schema']])->save();
        $registry = app(PluginUiTableRegistry::class);
        foreach (['alias_sets', 'aliases', 'profiles'] as $id) {
            $definition = $registry->tableFor($context->plugin, $id);
            $this->assertNotNull($definition);
            $this->assertNotEmpty(app(PluginSchemaMapper::class)->componentsForFieldDefinitions($definition['fields'], plugin: $context->plugin));
        }
        $model = $registry->newModel($context->plugin, ProfileAliasResolver::ALIASES)->firstOrFail();
        $this->assertSame(['THE BEEB ONE'], $model->aliases);
        $options = fn ($user, $plugin) => (new Plugin)->selectOptions('guidelink_alias_targets', new PluginSelectOptionsContext($plugin, $user, [], ['alias_set_ids' => [$set]], []));
        $this->assertArrayHasKey('bbc.one', $options($context->user, $context->plugin));
        [$foreign] = $this->uniqueContext();
        $this->assertSame([], $options($foreign->user, $context->plugin));
        $this->assertSame([], $options($context->user, $foreign->plugin));
    }

    public function test_one_alias_can_be_shared_and_edited_across_multiple_sets(): void
    {
        [$context] = $this->uniqueContext(applyPluginSchema: true);
        $guide = $this->guideEpgId($context);
        $first = $this->aliasSet($context, $guide);
        $second = DB::table(ProfileAliasResolver::SETS)->insertGetId([
            'extension_plugin_id' => $context->plugin->id, 'name' => 'Second set', 'country' => 'uk', 'guide_epg_id' => $guide, 'enabled' => true,
        ]);
        DB::table(ProfileAliasResolver::ALIASES)->whereJsonContains('alias_set_ids', $first)->update([
            'alias_set_ids' => json_encode([(string) $first, (string) $second]), 'aliases' => json_encode(['Shared BBC name']),
        ]);
        $resolver = new ProfileAliasResolver;
        $entry = ['number' => 101, 'name' => 'BBC One', 'aliases' => [], 'stream_id' => 'bbc.one', 'guide_valid' => true];
        $result = $resolver->apply([$entry], ['guide_epg_id' => $guide, 'alias_set_ids' => [$first, $second]], $context);
        $this->assertSame([$first => 'Provider names', $second => 'Second set'], $result[0]['profile_aliases']['SHARED BBC NAME']);
        $this->assertDatabaseCount(ProfileAliasResolver::ALIASES, 1);
        $options = (new Plugin)->selectOptions('guidelink_alias_targets', new PluginSelectOptionsContext(
            $context->plugin, $context->user, [], ['alias_set_ids' => [$first, $second]], [],
        ));
        $this->assertArrayHasKey('bbc.one', $options);
        DB::table(ProfileAliasResolver::ALIASES)->whereJsonContains('alias_set_ids', (string) $first)->update(['enabled' => false]);
        $this->assertSame([], $resolver->enabledAliases($first, $context));
        $this->assertSame([], $resolver->enabledAliases($second, $context));
    }

    public function test_shared_target_picker_excludes_incompatible_and_foreign_sets(): void
    {
        [$context] = $this->uniqueContext(applyPluginSchema: true);
        $guide = $this->guideEpgId($context);
        $first = $this->aliasSet($context, $guide);
        $otherGuide = Epg::factory()->for($context->user)->createQuietly();
        EpgChannel::factory()->createQuietly([
            'epg_id' => $otherGuide->id, 'user_id' => $context->user->id,
            'channel_id' => 'bbc.one', 'name' => 'Different service', 'display_name' => '101',
        ]);
        $second = DB::table(ProfileAliasResolver::SETS)->insertGetId([
            'extension_plugin_id' => $context->plugin->id, 'name' => 'Other guide', 'country' => 'uk', 'guide_epg_id' => $otherGuide->id, 'enabled' => true,
        ]);
        $options = fn ($ids) => (new Plugin)->selectOptions('guidelink_alias_targets', new PluginSelectOptionsContext(
            $context->plugin, $context->user, [], ['alias_set_ids' => $ids], [],
        ));
        $this->assertSame([], $options([$first, $second]));
        $this->assertSame([], $options(null));
        DB::table(ProfileAliasResolver::SETS)->where('id', $second)->update(['guide_epg_id' => $guide]);
        $this->assertArrayHasKey('bbc.one', $options([$first, $second]));
        [$foreign] = $this->uniqueContext();
        DB::table(ProfileAliasResolver::SETS)->where('id', $second)->update(['extension_plugin_id' => $foreign->plugin->id]);
        $this->assertSame([], $options([$first, $second]));
    }

    private function uniqueContext(bool $applyPluginSchema = false, bool $dryRun = true): array
    {
        $result = $this->context($applyPluginSchema, $dryRun);
        $result[0]->plugin->forceFill(['plugin_id' => uniqid('guidelink-test-')])->save();

        return $result;
    }

    private function aliasSet($context, int $guide): int
    {
        $id = DB::table(ProfileAliasResolver::SETS)->insertGetId([
            'extension_plugin_id' => $context->plugin->id, 'name' => 'Provider names', 'country' => 'uk', 'guide_epg_id' => $guide, 'enabled' => true,
        ]);
        DB::table(ProfileAliasResolver::ALIASES)->insert([
            'extension_plugin_id' => $context->plugin->id, 'alias_set_ids' => json_encode([$id]),
            'target_stream_id' => 'bbc.one', 'aliases' => json_encode(['THE BEEB ONE']), 'enabled' => true,
        ]);

        return $id;
    }

    private function channel($context, Playlist $playlist, string $name, ?int $number): Channel
    {
        return Channel::factory()->createQuietly([
            'playlist_id' => $playlist->id, 'user_id' => $context->user->id, 'group_id' => null,
            'enabled' => true, 'is_vod' => false, 'channel' => $number, 'name' => $name,
            'title' => $name, 'name_custom' => null, 'title_custom' => null,
            'stream_id' => 'provider.old', 'stream_id_custom' => null,
        ])->refresh();
    }
}
