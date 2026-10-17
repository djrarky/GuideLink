<?php

namespace AppLocalPlugins\GuideLink\Tests\Feature\Actions;

require_once dirname(__DIR__).'/PluginFeatureTestCase.php';

use App\Jobs\ProcessChannelScrubber;
use App\Models\Channel;
use App\Models\ChannelScrubber;
use App\Models\ChannelScrubberLog;
use AppLocalPlugins\GuideLink\Services\RunResultRepository;
use AppLocalPlugins\GuideLink\Tests\Feature\PluginFeatureTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

class ScrubberSetupActionPersistenceTest extends PluginFeatureTestCase
{
    use RefreshDatabase;

    public function test_deleted_selected_scrubber_does_not_report_successful_setup(): void
    {
        [$context, $playlist] = $this->context(applyPluginSchema: true, dryRun: false);
        $this->assignProfile($context, $playlist);
        $scrubber = ChannelScrubber::withoutEvents(fn () => ChannelScrubber::create([
            'name' => 'Operator Scrubber', 'user_id' => $context->user->id,
            'playlist_id' => $playlist->id, 'status' => 'pending',
        ]));
        Event::listen('eloquent.updated: '.get_class($context->run), function ($run) use ($context, $scrubber): void {
            if ($run->id === $context->run->id && $run->progress === 50) {
                DB::table('channel_scrubbers')->where('id', $scrubber->id)->delete();
            }
        });
        $result = $this->runGuideLinkAction('apply_scrubber_setup', [
            'playlist_id' => $playlist->id, 'scrubber_id' => $scrubber->id,
        ], $context);
        $this->assertFalse($result->success);
        $this->assertStringContainsString('no longer available', $result->summary);
        $this->assertSame(0, ChannelScrubber::query()->where('playlist_id', $playlist->id)->count());
    }

    public function test_apply_scrubber_setup_creates_guidelink_managed_native_scrubber_without_starting_a_scan(): void
    {
        Bus::fake();

        [$context, $playlist] = $this->context(applyPluginSchema: true, dryRun: false);
        $this->assignProfile($context, $playlist, ['scrubber_protect_failover_channels' => false]);

        $result = $this->runGuideLinkAction('apply_scrubber_setup', [
            'playlist_id' => $playlist->id,
            'scrubber_protect_failover_channels' => false,
        ], $context);

        $this->assertTrue($result->success);

        $scrubber = ChannelScrubber::query()->where('playlist_id', $playlist->id)->firstOrFail();
        $this->assertStringStartsWith('GuideLink - ', $scrubber->name);
        $this->assertSame((int) $context->user->id, (int) $scrubber->user_id);
        $this->assertTrue($scrubber->recurring);
        $this->assertTrue($scrubber->scan_all);
        $this->assertTrue($scrubber->disable_dead);
        $this->assertFalse($scrubber->enable_live);
        $this->assertFalse($scrubber->include_vod);
        $this->assertFalse($scrubber->use_batching);
        $this->assertTrue($scrubber->protect_failover_channels);
        $this->assertTrue($scrubber->rebuild_failovers_after_scan);
        $this->assertSame('http', $scrubber->check_method);
        $this->assertSame(10, $scrubber->probe_timeout);

        Bus::assertNotDispatched(ProcessChannelScrubber::class);

        $this->assertDatabaseHas(RunResultRepository::TABLE, [
            'extension_plugin_run_id' => $context->run->id,
            'playlist_id' => $playlist->id,
            'result_type' => 'scrubber_audit',
            'decision' => 'created',
            'title' => 'GuideLink-managed native scrubber setup',
        ]);
    }

    public function test_apply_scrubber_setup_derives_failover_protection_from_profile_visibility_policy(): void
    {
        [$context, $playlist] = $this->context(applyPluginSchema: true, dryRun: false);
        $this->assignProfile($context, $playlist, [
            'auto_merge_deactivate_failover' => false,
            'scrubber_enable_live' => true,
            'scrubber_protect_failover_channels' => true,
        ]);

        $result = $this->runGuideLinkAction('apply_scrubber_setup', [
            'playlist_id' => $playlist->id,
            'scrubber_protect_failover_channels' => true,
        ], $context);

        $this->assertTrue($result->success);

        $scrubber = ChannelScrubber::query()->where('playlist_id', $playlist->id)->firstOrFail();
        $this->assertFalse($scrubber->enable_live);
        $this->assertFalse($scrubber->protect_failover_channels);
    }

    public function test_removed_scrubber_options_cannot_override_recovery_scope_or_native_batching(): void
    {
        [$context, $playlist] = $this->context(applyPluginSchema: true, dryRun: false);
        $this->assignProfile($context, $playlist, [
            'scrubber_enable_live' => true,
            'scrubber_include_vod' => true,
            'scrubber_use_batching' => false,
        ]);
        $scrubber = ChannelScrubber::withoutEvents(fn (): ChannelScrubber => ChannelScrubber::create([
            'name' => 'GuideLink - '.$playlist->name,
            'user_id' => $context->user->id,
            'playlist_id' => $playlist->id,
            'status' => 'pending',
            'use_batching' => true,
            'enable_live' => true,
            'include_vod' => true,
        ]));

        foreach ([true, false] as $nativeBatching) {
            $scrubber->update(['use_batching' => $nativeBatching]);
            $result = $this->runGuideLinkAction('apply_scrubber_setup', [
                'playlist_id' => $playlist->id,
                'scrubber_enable_live' => true,
                'scrubber_include_vod' => true,
                'scrubber_use_batching' => ! $nativeBatching,
            ], $context);

            $this->assertTrue($result->success, $result->summary);
            $scrubber->refresh();
            $this->assertSame($nativeBatching, $scrubber->use_batching);
            $this->assertFalse($scrubber->enable_live);
            $this->assertFalse($scrubber->include_vod);
        }
    }

    public function test_apply_scrubber_setup_validates_user_managed_scrubber_without_changing_it(): void
    {
        [$context, $playlist] = $this->context(applyPluginSchema: true, dryRun: false);
        $this->assignProfile($context, $playlist);

        $scrubber = ChannelScrubber::withoutEvents(fn (): ChannelScrubber => ChannelScrubber::create([
            'name' => 'Operator Scrubber',
            'user_id' => $context->user->id,
            'playlist_id' => $playlist->id,
            'status' => 'pending',
            'recurring' => false,
            'include_vod' => false,
            'scan_all' => false,
            'check_method' => 'http',
            'use_batching' => false,
            'probe_timeout' => 10,
            'disable_dead' => true,
            'enable_live' => false,
            'protect_failover_channels' => true,
            'rebuild_failovers_after_scan' => false,
        ]));

        $result = $this->runGuideLinkAction('apply_scrubber_setup', [
            'playlist_id' => $playlist->id,
            'scrubber_id' => $scrubber->id,
            'scrubber_recurring' => true,
            'scrubber_scan_all' => true,
            'scrubber_rebuild_failovers_after_scan' => true,
        ], $context);

        $this->assertTrue($result->success);

        $scrubber->refresh();
        $this->assertSame('Operator Scrubber', $scrubber->name);
        $this->assertFalse($scrubber->recurring);
        $this->assertFalse($scrubber->scan_all);
        $this->assertFalse($scrubber->rebuild_failovers_after_scan);

        $this->assertDatabaseHas(RunResultRepository::TABLE, [
            'extension_plugin_run_id' => $context->run->id,
            'playlist_id' => $playlist->id,
            'result_type' => 'scrubber_audit',
            'decision' => 'validated',
            'title' => 'User-selected native scrubber',
        ]);
    }

    public function test_scrubber_audit_records_native_first_recovery_policy_without_channel_state_table(): void
    {
        [$context, $playlist] = $this->context(applyPluginSchema: true, dryRun: true);
        $this->assignProfile($context, $playlist);

        $scrubber = ChannelScrubber::withoutEvents(fn (): ChannelScrubber => ChannelScrubber::create([
            'name' => 'GuideLink - '.$playlist->name,
            'user_id' => $context->user->id,
            'playlist_id' => $playlist->id,
            'status' => 'pending',
            'recurring' => true,
            'include_vod' => false,
            'scan_all' => true,
            'check_method' => 'http',
            'use_batching' => false,
            'probe_timeout' => 10,
            'disable_dead' => true,
            'enable_live' => false,
            'protect_failover_channels' => true,
            'rebuild_failovers_after_scan' => true,
        ]));

        foreach ([true, false, null] as $live) {
            Channel::withoutEvents(fn (): Channel => Channel::factory()->create([
                'user_id' => $context->user->id,
                'playlist_id' => $playlist->id,
                'group_id' => null,
                'enabled' => false,
                'is_vod' => false,
                'last_scrubber_live' => $live,
            ]));
        }

        ChannelScrubberLog::create([
            'channel_scrubber_id' => $scrubber->id,
            'user_id' => $context->user->id,
            'playlist_id' => $playlist->id,
            'status' => 'completed',
            'channel_count' => 3,
            'dead_count' => 1,
            'disabled_count' => 0,
            'live_count' => 1,
        ]);

        $result = $this->runGuideLinkAction('audit_scrubber_setup', [
            'playlist_id' => $playlist->id,
        ], $context);

        $this->assertTrue($result->success);

        $row = DB::table(RunResultRepository::TABLE)
            ->where('extension_plugin_run_id', $context->run->id)
            ->where('playlist_id', $playlist->id)
            ->where('result_type', 'scrubber_audit')
            ->where('title', 'Scrubber health state')
            ->first();

        $this->assertNotNull($row);

        $details = json_decode($row->details, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('native_first', $details['details']['promotion_recovery_policy']);
        $this->assertFalse($details['details']['guidelink_channel_state_required']);
        $this->assertSame([
            'scrubber_live' => 1, 'scrubber_dead' => 1, 'never_scanned' => 1,
        ], $details['details']['channels']);
    }
}
