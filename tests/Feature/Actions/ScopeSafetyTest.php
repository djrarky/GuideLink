<?php

namespace AppLocalPlugins\GuideLink\Tests\Feature\Actions;

require_once dirname(__DIR__).'/PluginFeatureTestCase.php';

use App\Models\Channel;
use App\Models\Group;
use App\Models\Playlist;
use AppLocalPlugins\GuideLink\Services\ScopeFilter;
use AppLocalPlugins\GuideLink\Support\CountryRulesRepository;
use AppLocalPlugins\GuideLink\Support\ProfileSettings;
use AppLocalPlugins\GuideLink\Tests\Feature\PluginFeatureTestCase;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

class ScopeSafetyTest extends PluginFeatureTestCase
{
    use RefreshDatabase;

    public static function regexFields(): array
    {
        return array_map(fn ($key) => [$key], ['exclude_group_regex', 'preserve_group_name_regex', 'preserve_channel_title_regex']);
    }

    #[DataProvider('regexFields')]
    public function test_invalid_scope_regex_stops_sync_before_native_changes(string $key): void
    {
        Bus::fake();
        [$context, $playlist] = $this->context(applyPluginSchema: true, dryRun: false);
        $channel = Channel::factory()->createQuietly([
            'playlist_id' => $playlist->id, 'user_id' => $context->user->id,
            'enabled' => true, 'can_merge' => true, 'is_vod' => false, 'channel' => 101,
            'name' => 'BBC One', 'title' => 'BBC One', 'group_id' => null,
        ]);
        $this->assignProfile($context, $playlist, ['guide_epg_id' => $this->guideEpgId($context), $key => ['['], 'disable_other_groups' => true]);
        $before = $channel->refresh()->getAttributes();
        $playlistBefore = $playlist->refresh()->getAttributes();
        $result = $this->runGuideLinkAction('apply_sync_playlist', ['playlist_id' => $playlist->id], $context);
        $this->assertFalse($result->success);
        $this->assertStringContainsString("Invalid regex in [{$key}]", $result->summary);
        $this->assertSame($before, $channel->refresh()->getAttributes());
        $this->assertSame($playlistBefore, $playlist->refresh()->getAttributes());
        $this->assertSame(0, DB::table('plugin_guidelink_quarantines')->count());
    }

    public function test_regex_string_keeps_commas_in_quantifiers_and_separates_newlines(): void
    {
        [$context, $playlist] = $this->context();
        $settings = ProfileSettings::fromPayload(['preserve_channel_title_regex' => '^BBC [0-9]{1,3}$'."\n".'^Custom, Live$']);
        $this->assertSame(['^BBC [0-9]{1,3}$', '^Custom, Live$'], $settings['preserve_channel_title_regex']);
        $channel = Channel::factory()->createQuietly([
            'playlist_id' => $playlist->id, 'user_id' => $context->user->id, 'group_id' => null,
            'title' => 'BBC 12', 'title_custom' => null,
        ]);
        $countries = new CountryRulesRepository;
        $scope = (new ScopeFilter($countries))->split($playlist, new Collection([$channel]), $settings, [], $countries->resolve([], []));
        $this->assertSame('title_preserved', $scope['excluded'][0]['exclusion_reason']);
    }

    public function test_managed_groups_are_exact_normalized_names_not_prefixes(): void
    {
        Bus::fake();
        [$context, $playlist] = $this->context(applyPluginSchema: true);
        $source = Playlist::factory()->for($context->user)->createQuietly();
        $group = Group::factory()->createQuietly(['playlist_id' => $source->id, 'user_id' => $context->user->id, 'name' => 'Sports']);
        Channel::factory()->createQuietly([
            'playlist_id' => $source->id, 'user_id' => $context->user->id, 'group_id' => $group->id,
            'enabled' => true, 'is_vod' => false, 'channel' => 101, 'title' => 'BBC One', 'name' => 'BBC One',
        ]);
        foreach (['UK | General', ' sports ', 'Sports Extra'] as $name) {
            $targetGroup = Group::factory()->createQuietly(['playlist_id' => $playlist->id, 'user_id' => $context->user->id, 'name' => $name]);
            Channel::factory()->createQuietly([
                'playlist_id' => $playlist->id, 'user_id' => $context->user->id, 'group_id' => $targetGroup->id,
                'enabled' => true, 'is_vod' => false, 'title' => 'BBC One', 'name' => 'BBC One',
            ]);
        }
        $this->assignProfile($context, $playlist, ['guide_epg_id' => $this->guideEpgId($context), 'source_playlist_id' => $source->id, 'include_group_prefixes' => ['UK |']]);
        $result = $this->runGuideLinkAction('sync_playlist', ['playlist_id' => $playlist->id], $context);
        $this->assertTrue($result->success, $result->summary);
        $this->assertSame(2, $result->data['matching']['in_scope_channels']);
        $this->assertSame(1, $result->data['matching']['excluded_channels']);
    }

    public function test_disabling_managed_groups_preserves_channel_group_and_group_settings(): void
    {
        Bus::fake();
        [$context, $playlist] = $this->context(applyPluginSchema: true, dryRun: false);
        $source = Playlist::factory()->for($context->user)->createQuietly();
        $sourceGroup = Group::factory()->createQuietly(['playlist_id' => $source->id, 'user_id' => $context->user->id, 'name' => 'Entertainment']);
        Channel::factory()->createQuietly([
            'playlist_id' => $source->id, 'user_id' => $context->user->id, 'group_id' => $sourceGroup->id,
            'enabled' => true, 'is_vod' => false, 'channel' => 101, 'title' => 'BBC One', 'name' => 'BBC One',
        ]);
        $targetGroup = Group::factory()->createQuietly(['playlist_id' => $playlist->id, 'user_id' => $context->user->id, 'name' => 'UK | General', 'custom' => false]);
        $channel = Channel::factory()->createQuietly([
            'playlist_id' => $playlist->id, 'user_id' => $context->user->id, 'group_id' => $targetGroup->id,
            'enabled' => true, 'is_vod' => false, 'title' => 'BBC One', 'name' => 'BBC One', 'channel' => null,
        ]);
        $before = $targetGroup->refresh()->getAttributes();
        $this->assignProfile($context, $playlist, ['guide_epg_id' => $this->guideEpgId($context), 'source_playlist_id' => $source->id, 'include_managed_groups' => false, 'include_group_prefixes' => ['UK |']]);
        $result = $this->runGuideLinkAction('apply_sync_playlist', ['playlist_id' => $playlist->id], $context);
        $this->assertTrue($result->success, $result->summary);
        $this->assertSame(101, (int) $channel->refresh()->channel);
        $this->assertSame($targetGroup->id, $channel->group_id);
        $this->assertSame($before, $targetGroup->refresh()->getAttributes());
        $this->assertSame(1, Group::where('playlist_id', $playlist->id)->count());
    }
}
