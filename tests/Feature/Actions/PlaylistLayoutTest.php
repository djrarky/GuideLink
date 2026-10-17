<?php

namespace AppLocalPlugins\GuideLink\Tests\Feature\Actions;

require_once dirname(__DIR__).'/PluginFeatureTestCase.php';

use App\Events\PlaylistUpdated;
use App\Http\Controllers\PlaylistGenerateController;
use App\Jobs\ProcessM3uImportChunk;
use App\Jobs\ProcessM3uImportComplete;
use App\Models\Channel;
use App\Models\Group;
use App\Models\Job;
use App\Models\Playlist;
use App\Plugins\Support\PluginExecutionContext;
use App\Settings\GeneralSettings;
use AppLocalPlugins\GuideLink\Services\ProfileRepository;
use AppLocalPlugins\GuideLink\Services\RunResultRepository;
use AppLocalPlugins\GuideLink\Services\SetupChecklistService;
use AppLocalPlugins\GuideLink\Tests\Feature\PluginFeatureTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PlaylistLayoutTest extends PluginFeatureTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Http::preventStrayRequests();
        Storage::fake('local');
    }

    public function test_existing_profiles_leave_layout_unchanged_until_opted_in(): void
    {
        [$context, $playlist] = $this->configured();
        $before = $this->layout($playlist);
        $result = $this->runGuideLinkAction('apply_sync_playlist', ['playlist_id' => $playlist->id], $context);

        $this->assertTrue($result->success, $result->summary);
        $this->assertSame($before, $this->layout($playlist));
        $this->assertSame('info', $result->data['native_settings']['playlist_layout']['status']);
        $this->assertSame([], $result->data['native_settings']['playlist_layout']['details']['changes']);
        $this->assertStringContainsString('user-managed', $this->checklistLayout($context)['recommendation']);
    }

    public function test_preview_reports_each_setting_and_reason_without_native_writes(): void
    {
        [$context, $playlist, $channel] = $this->configured(['playlist_layout_managed' => true], dryRun: true);
        $before = [$playlist->getAttributes(), $channel->getAttributes()];
        $result = $this->runGuideLinkAction('sync_playlist', ['playlist_id' => $playlist->id], $context);

        $this->assertTrue($result->success, $result->summary);
        $this->assertSame($before, [$playlist->refresh()->getAttributes(), $channel->refresh()->getAttributes()]);
        $layout = $result->data['native_settings']['playlist_layout'];
        $this->assertSame('change_required', $layout['status']);
        $this->assertCount(4, $layout['details']['changes']);
        $this->assertSame(['from' => true, 'to' => false], $layout['details']['changes']['force_channel_numbering']);
        $this->assertSame(['from' => false, 'to' => true], $layout['details']['changes']['sort_by_channel_number']);
        foreach ($layout['details']['checks'] as $check) {
            $this->assertNotEmpty($check['details']['reason']);
            $this->assertArrayHasKey('current', $check['details']);
            $this->assertArrayHasKey('desired', $check['details']);
            $this->assertDatabaseHas(RunResultRepository::TABLE, [
                'extension_plugin_run_id' => $context->run->id,
                'result_type' => 'playlist_layout_audit', 'title' => $check['title'], 'decision' => 'change_required',
            ]);
        }
    }

    public function test_apply_is_idempotent_and_preserves_other_settings_and_playlists(): void
    {
        [$context, $playlist] = $this->configured(['playlist_layout_managed' => true]);
        $backup = Playlist::factory()->for($context->user)->createQuietly();
        $backupBefore = $backup->refresh()->getAttributes();
        $this->settings(['auto_merge_failover_playlist_ids' => [$backup->id]]);
        $profileBefore = DB::table(ProfileRepository::PROFILES_TABLE)->first();
        $updates = [];
        Event::listen(PlaylistUpdated::class, function (PlaylistUpdated $event) use (&$updates): void {
            $updates[] = $event->playlist->getChanges();
        });
        $first = $this->runGuideLinkAction('apply_sync_playlist', ['playlist_id' => $playlist->id], $context);

        $this->assertTrue($first->success, $first->summary);
        $this->assertSame('applied', $first->data['native_settings']['playlist_layout']['status']);
        $this->assertSame([
            'auto_sort' => false, 'auto_sort_groups' => false, 'force_channel_numbering' => false,
            'sort_by_channel_number' => true, 'auto_channel_increment' => true,
        ], $this->layout($playlist));
        $this->assertSame(800, (int) $playlist->channel_start);
        $this->assertSame('stream_id', $playlist->id_channel_by->value);
        $this->assertSame($backupBefore, $backup->refresh()->getAttributes());
        $this->assertEquals($profileBefore, DB::table(ProfileRepository::PROFILES_TABLE)->first());
        $this->assertCount(1, $updates, 'Layout and merge settings should trigger native playlist update hooks once.');
        $this->assertArrayHasKey('auto_sort', $updates[0]);
        $this->assertArrayHasKey('auto_merge_channels_enabled', $updates[0]);

        $before = $playlist->refresh()->getAttributes();
        $again = $this->runGuideLinkAction('apply_sync_playlist', ['playlist_id' => $playlist->id], $context);
        $this->assertTrue($again->success, $again->summary);
        $this->assertSame('ok', $again->data['native_settings']['playlist_layout']['status']);
        $this->assertSame([], $again->data['native_settings']['playlist_layout']['details']['changes']);
        $this->assertSame($before, $playlist->refresh()->getAttributes());
        $this->assertCount(1, $updates, 'An unchanged sync should not trigger playlist update hooks.');
    }

    public function test_current_profile_controls_output_and_optional_missing_number_preference(): void
    {
        [$context, $playlist] = $this->configured(['playlist_layout_managed' => true]);
        foreach ([['group', 'disabled', false], ['number', 'enabled', true], ['group', 'preserve', true]] as [$order, $missing, $increment]) {
            $this->settings(['playlist_output_order' => $order, 'playlist_missing_numbers' => $missing]);
            $result = $this->runGuideLinkAction('apply_sync_playlist', ['playlist_id' => $playlist->id], $context);
            $this->assertTrue($result->success, $result->summary);
            $this->assertSame($order === 'number', $playlist->refresh()->sort_by_channel_number);
            $this->assertSame($increment, (bool) $playlist->auto_channel_increment);
        }

        // Direct edits are restored while managed; opting out leaves current values alone.
        $playlist->forceFill(['auto_sort' => true])->saveQuietly();
        $this->assertSame('warning', $this->checklistLayout($context)['status']);
        $this->runGuideLinkAction('apply_sync_playlist', ['playlist_id' => $playlist->id], $context);
        $this->assertFalse((bool) $playlist->refresh()->auto_sort);
        $this->settings(['playlist_layout_managed' => false, 'playlist_missing_numbers' => 'disabled']);
        $before = $this->layout($playlist);
        $this->runGuideLinkAction('apply_sync_playlist', ['playlist_id' => $playlist->id], $context);
        $this->assertSame($before, $this->layout($playlist));
    }

    public function test_reports_specific_live_sorting_rules_without_altering_them(): void
    {
        [$context, $playlist] = $this->configured(['playlist_layout_managed' => true]);
        $rules = [
            ['enabled' => false, 'target' => 'live_groups'],
            ['enabled' => true, 'target' => 'vod_groups'],
            ['enabled' => true, 'target' => 'series_categories'],
            ['enabled' => true, 'target' => 'live_groups', 'group' => ['Entertainment'], 'column' => 'title', 'sort' => 'DESC'],
        ];
        $playlist->forceFill(['sort_alpha_config' => $rules])->saveQuietly();
        $result = $this->runGuideLinkAction('apply_sync_playlist', ['playlist_id' => $playlist->id], $context);
        $this->assertTrue($result->success, $result->summary);
        $this->assertSame(1, $result->data['native_settings']['playlist_layout']['details']['warnings']);
        $this->assertSame($rules, $playlist->refresh()->sort_alpha_config);
        $warning = DB::table(RunResultRepository::TABLE)->where('result_type', 'playlist_layout_audit')->where('decision', 'warning')->sole();
        $details = json_decode($warning->details, true)['details'];
        $this->assertSame(4, $details['rule_number']);
        $this->assertSame(['Entertainment'], $details['groups']);
        $this->assertSame('title', $details['column']);
        $this->assertStringContainsString('rule #4', $warning->reason);
        $check = $this->checklistLayout($context);
        $this->assertSame('warning', $check['status']);
        $this->assertSame(0, $check['details']['summary']['change_required']);
        $this->assertSame(1, $check['details']['summary']['warning']);
        $again = $this->runGuideLinkAction('apply_sync_playlist', ['playlist_id' => $playlist->id], $context);
        $this->assertSame('warning', $again->data['native_settings']['playlist_layout']['status']);

        $rules[3]['enabled'] = false;
        $playlist->forceFill(['sort_alpha_config' => $rules])->saveQuietly();
        $this->assertSame('ready', $this->checklistLayout($context)['status']);
    }

    public function test_native_import_preserves_layout_and_the_next_sync_has_no_edits(): void
    {
        [$context, $playlist, $channel] = $this->configured(['playlist_layout_managed' => true]);
        $first = $this->runGuideLinkAction('apply_sync_playlist', ['playlist_id' => $playlist->id], $context);
        $this->assertTrue($first->success, $first->summary);
        $group = $channel->refresh()->group;
        $groupId = $channel->group_id;
        $sort = $channel->sort;
        $groupSort = Group::findOrFail($groupId)->sort_order;
        // Use the native importer and cleanup against an isolated jobs database.
        $oldJobsConfig = config('database.connections.jobs');
        config(['database.connections.jobs' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('jobs');
        try {
            $migration = require database_path('migrations/2025_02_13_215803_create_jobs_table.php');
            $migration->up();
            $payload = $channel->getAttributes();
            unset($payload['id']);
            $payload = [...$payload, 'sort' => 999, 'channel' => 999, 'import_batch_no' => 'layout-test'];
            $job = Job::create([
                'title' => 'Layout test', 'batch_no' => 'layout-test', 'payload' => [$payload],
                'variables' => [
                    'playlistId' => $playlist->id, 'groupId' => $groupId, 'groupName' => $group,
                    'autoSort' => (bool) $playlist->refresh()->auto_sort,
                ],
            ]);
            (new ProcessM3uImportChunk([$job->id], 1))->handle();
            $cleanup = new ProcessM3uImportComplete($context->user->id, $playlist->id, 'layout-test', now(), runningVodImport: false);
            $cleanup->invalidateImport = false;
            $cleanup->handle(app(GeneralSettings::class));
        } finally {
            DB::purge('jobs');
            config(['database.connections.jobs' => $oldJobsConfig]);
        }

        $this->assertSame($sort, $channel->refresh()->sort);
        $this->assertSame(101, (int) $channel->channel);
        $this->assertSame($groupSort, Group::findOrFail($groupId)->sort_order);
        $again = $this->runGuideLinkAction('apply_sync_playlist', ['playlist_id' => $playlist->id], $context);
        $this->assertTrue($again->success, $again->summary);
        $this->assertSame(0, $again->data['writes']['channels_written']);
        $this->assertSame(0, $again->data['writes']['groups_created']);
        $this->assertSame(0, $again->data['writes']['groups_updated']);
        $this->assertSame([], $again->data['native_settings']['playlist_layout']['details']['changes']);
    }

    public function test_generated_m3u_keeps_guide_numbers_in_flat_and_grouped_output(): void
    {
        [$context, $playlist, $channel] = $this->configured(['playlist_layout_managed' => true]);
        $source = Playlist::factory()->for($context->user)->createQuietly();
        $channel->replicate()->forceFill(['playlist_id' => $source->id, 'uuid' => (string) Str::uuid()])->saveQuietly();
        $this->settings(['source_playlist_id' => $source->id]);
        $this->runGuideLinkAction('apply_sync_playlist', ['playlist_id' => $playlist->id], $context);
        $group = Group::factory()->createQuietly([
            'user_id' => $context->user->id, 'playlist_id' => $playlist->id,
            'name' => 'Earlier group', 'sort_order' => 0, 'type' => 'live', 'custom' => true,
        ]);
        Channel::factory()->createQuietly([
            'user_id' => $context->user->id, 'playlist_id' => $playlist->id, 'group_id' => $group->id,
            'enabled' => true, 'is_vod' => false, 'channel' => 401, 'sort' => 401,
            'name' => 'Private channel', 'title' => 'Private channel', 'name_custom' => null, 'title_custom' => null,
        ]);
        $this->assertSame(['101', '401'], $this->outputNumbers($playlist));
        $this->settings(['playlist_output_order' => 'group', 'preserve_groups' => ['Earlier group']]);
        $result = $this->runGuideLinkAction('apply_sync_playlist', ['playlist_id' => $playlist->id], $context);
        $this->assertTrue($result->success, $result->summary);
        $this->assertFalse($playlist->refresh()->sort_by_channel_number);
        $this->assertSame(['401', '101'], $this->outputNumbers($playlist));
    }

    private function configured(array $settings = [], bool $dryRun = false): array
    {
        [$context, $playlist] = $this->context(applyPluginSchema: true, dryRun: $dryRun);
        $this->assignProfile($context, $playlist, ['guide_epg_id' => $this->guideEpgId($context), ...$settings]);
        $playlist->forceFill([
            'auto_sort' => true, 'auto_sort_groups' => true, 'force_channel_numbering' => true,
            'sort_by_channel_number' => false, 'auto_channel_increment' => true, 'channel_start' => 800,
            'id_channel_by' => 'stream_id', 'm3u_enabled' => true, 'enable_proxy' => false,
            'enable_logo_proxy' => false, 'include_series_in_m3u' => false,
        ])->saveQuietly();
        $channel = Channel::factory()->createQuietly([
            'playlist_id' => $playlist->id, 'user_id' => $playlist->user_id, 'group_id' => null,
            'group' => 'Entertainment', 'group_internal' => 'Entertainment',
            'enabled' => true, 'is_vod' => false, 'channel' => 101, 'name' => 'BBC One',
            'title' => 'BBC One', 'name_custom' => null, 'title_custom' => null,
            'stream_id' => 'provider.old', 'stream_id_custom' => null, 'source_id' => 'bbc-one',
        ]);

        return [$context, $playlist->refresh(), $channel->refresh()];
    }

    private function settings(array $changes): void
    {
        $profile = DB::table(ProfileRepository::PROFILES_TABLE)->first();
        DB::table(ProfileRepository::PROFILES_TABLE)->where('id', $profile->id)->update([
            'settings' => json_encode([...json_decode($profile->settings, true), ...$changes], JSON_THROW_ON_ERROR),
        ]);
    }

    private function layout(Playlist $playlist): array
    {
        return array_map(fn ($value): bool => (bool) $value, $playlist->refresh()->only([
            'auto_sort', 'auto_sort_groups', 'force_channel_numbering', 'sort_by_channel_number', 'auto_channel_increment',
        ]));
    }

    private function checklistLayout(PluginExecutionContext $context): array
    {
        return collect((new SetupChecklistService)->rows($context))
            ->first(fn (array $row): bool => str_starts_with($row['title'], 'Native playlist layout setup:'));
    }

    private function outputNumbers(Playlist $playlist): array
    {
        $response = (new PlaylistGenerateController)(Request::create('/'), $playlist->uuid);
        ob_start();
        try {
            $response->sendContent();
            $output = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        $this->assertStringContainsString('#EXTM3U', $output);
        preg_match_all('/tvg-chno="(\d+)"/', $output, $matches);

        return $matches[1];
    }
}
