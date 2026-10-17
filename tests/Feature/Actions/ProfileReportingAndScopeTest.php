<?php

namespace AppLocalPlugins\GuideLink\Tests\Feature\Actions;

require_once dirname(__DIR__).'/PluginFeatureTestCase.php';

use App\Models\Channel;
use App\Models\ChannelFailover;
use App\Models\Group;
use App\Models\Playlist;
use AppLocalPlugins\GuideLink\Tests\Feature\PluginFeatureTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ProfileReportingAndScopeTest extends PluginFeatureTestCase
{
    use RefreshDatabase;

    public function test_reports_always_include_disabled_channels_and_failovers_even_with_legacy_flags_off(): void
    {
        [$context, $playlist] = $this->context(applyPluginSchema: true);
        $source = Playlist::factory()->for($context->user)->createQuietly();
        $this->assignProfile($context, $playlist, [
            'guide_epg_id' => $this->guideEpgId($context),
            'source_playlist_id' => $source->id,
            'include_disabled_channels' => false,
            'include_failovers' => false,
        ]);
        $primary = Channel::factory()->createQuietly([
            'playlist_id' => $playlist->id, 'user_id' => $context->user->id,
            'enabled' => false, 'is_vod' => false, 'channel' => 101,
            'name' => 'BBC One', 'stream_id' => 'bbc.one',
        ]);
        $backup = Channel::factory()->createQuietly([
            'playlist_id' => $source->id, 'user_id' => $context->user->id,
            'enabled' => true, 'is_vod' => false, 'channel' => 101,
            'name' => 'BBC One', 'stream_id' => 'bbc.one',
        ]);
        ChannelFailover::create([
            'user_id' => $context->user->id, 'channel_id' => $primary->id,
            'channel_failover_id' => $backup->id,
        ]);
        $payload = ['playlist_id' => $playlist->id, 'include_disabled_channels' => false, 'include_failovers' => false];

        $report = $this->runGuideLinkAction('availability_report', $payload, $context);
        $this->assertTrue($report->success, $report->summary);
        $this->assertSame(1, $report->data['availability']['primary_disabled_with_enabled_fallback']);
        $this->assertSame(1, $report->data['source_coverage']['disabled_only_with_enabled_fallback']);

        $repair = $this->runGuideLinkAction('repair_availability', $payload, $context);
        $this->assertTrue($repair->success, $repair->summary);
        $this->assertSame(1, $repair->data['repair']['reenable_master_candidates']);
        $this->assertFalse($primary->refresh()->enabled);
    }

    public function test_empty_prefix_includes_provider_groups_while_preserving_explicit_scope_rules(): void
    {
        [$context, $playlist] = $this->context(applyPluginSchema: true);
        $source = Playlist::factory()->for($context->user)->createQuietly();
        $this->assignProfile($context, $playlist, [
            'guide_epg_id' => $this->guideEpgId($context),
            'source_playlist_id' => $source->id,
            'preserve_groups' => ['My Favourites'],
        ]);
        $sourceGroup = Group::factory()->createQuietly([
            'playlist_id' => $source->id, 'user_id' => $context->user->id, 'name' => 'Entertainment',
        ]);
        Channel::factory()->createQuietly([
            'playlist_id' => $source->id, 'user_id' => $context->user->id,
            'group_id' => $sourceGroup->id, 'enabled' => true, 'is_vod' => false,
            'channel' => 101, 'name' => 'BBC One', 'stream_id' => 'bbc.one',
        ]);
        foreach (['Provider Channels', 'My Favourites'] as $name) {
            $group = Group::factory()->createQuietly([
                'playlist_id' => $playlist->id, 'user_id' => $context->user->id, 'name' => $name,
            ]);
            Channel::factory()->createQuietly([
                'playlist_id' => $playlist->id, 'user_id' => $context->user->id,
                'group_id' => $group->id, 'enabled' => true, 'is_vod' => false,
                'channel' => 101, 'name' => 'BBC One', 'stream_id' => 'bbc.one',
            ]);
        }

        $report = $this->runGuideLinkAction('sync_playlist', ['playlist_id' => $playlist->id], $context);
        $this->assertTrue($report->success, $report->summary);
        $this->assertSame(1, $report->data['matching']['in_scope_channels']);
        $this->assertSame(['group_preserved' => 1], $report->data['matching']['excluded_by_reason']);

        $restricted = $this->runGuideLinkAction('sync_playlist', [
            'playlist_id' => $playlist->id, 'include_group_prefixes' => ['UK|'],
        ], $context);
        $this->assertTrue($restricted->success, $restricted->summary);
        $this->assertSame(0, $restricted->data['matching']['in_scope_channels']);
        $this->assertSame(['group_prefix' => 1, 'group_preserved' => 1], $restricted->data['matching']['excluded_by_reason']);
    }
}
