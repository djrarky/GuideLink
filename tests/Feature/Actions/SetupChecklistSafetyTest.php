<?php

namespace AppLocalPlugins\GuideLink\Tests\Feature\Actions;

require_once dirname(__DIR__).'/PluginFeatureTestCase.php';

use AppLocalPlugins\GuideLink\Actions\SetupChecklistAction;
use AppLocalPlugins\GuideLink\Plugin;
use AppLocalPlugins\GuideLink\Services\GuideLinkServices;
use AppLocalPlugins\GuideLink\Services\ProfileRepository;
use AppLocalPlugins\GuideLink\Services\SourceRefreshService;
use AppLocalPlugins\GuideLink\Support\RunCancelled;
use AppLocalPlugins\GuideLink\Tests\Feature\PluginFeatureTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Mockery;

class SetupChecklistSafetyTest extends PluginFeatureTestCase
{
    use RefreshDatabase;

    public function test_setup_fetches_a_failing_shared_source_once_and_keeps_each_profile_result(): void
    {
        Storage::fake('local');
        [$context] = $this->context(applyPluginSchema: true);
        $this->profiles($context->plugin->id);
        $refresh = Mockery::mock(SourceRefreshService::class);
        $refresh->shouldReceive('refresh')->once()->andThrow(new \RuntimeException('quota exhausted'));
        $result = $this->action($refresh)->handle([], $context);
        $this->assertSame(2, $result->data['source_refresh']['failed']);
        $this->assertCount(2, $result->data['source_refresh']['profiles']);
    }

    public function test_setup_does_not_swallow_cancellation_or_try_the_next_source(): void
    {
        Storage::fake('local');
        [$context] = $this->context(applyPluginSchema: true);
        $this->profiles($context->plugin->id);
        $refresh = Mockery::mock(SourceRefreshService::class);
        $refresh->shouldReceive('refresh')->once()->andThrow(new RunCancelled('Cancelled while preparing setup'));
        $this->expectException(RunCancelled::class);
        $this->action($refresh)->handle([], $context);
    }

    public function test_playlist_hook_honours_availability_opt_out_but_manual_report_can_run(): void
    {
        [$context, $playlist] = $this->context(applyPluginSchema: true);
        $this->assignProfile($context, $playlist);
        DB::table(ProfileRepository::ASSIGNMENTS_TABLE)->where('playlist_id', $playlist->id)
            ->update(['settings' => json_encode(['run_availability' => false])]);
        $plugin = new Plugin;
        $hook = $plugin->runHook('playlist.synced', ['playlist_id' => $playlist->id], $context);
        $this->assertTrue($hook->success, $hook->summary);
        $this->assertTrue($hook->data['skipped']);
        $manual = $plugin->runAction('availability_report', ['playlist_id' => $playlist->id], $context);
        $this->assertFalse($manual->success);
        $this->assertStringContainsString('Choose a Guide EPG', $manual->summary);
    }

    public function test_disabling_all_automatic_assignments_is_a_quiet_no_op(): void
    {
        [$context, $playlist] = $this->context(applyPluginSchema: true);
        $this->assignProfile($context, $playlist);
        DB::table(ProfileRepository::ASSIGNMENTS_TABLE)->where('playlist_id', $playlist->id)
            ->update(['settings' => json_encode(['run_sync' => false, 'run_availability' => false])]);
        foreach (['sync_playlist', 'availability_report'] as $action) {
            $result = $this->runGuideLinkAction($action, ['assigned_playlists' => true], $context);
            $this->assertTrue($result->success, $result->summary);
            $this->assertTrue($result->data['skipped']);
        }
        Notification::assertNothingSent();
    }

    private function action(SourceRefreshService $refresh): SetupChecklistAction
    {
        $services = Mockery::mock(GuideLinkServices::class)->makePartial();
        $services->shouldReceive('sourceRefresh')->andReturn($refresh);

        return new SetupChecklistAction($services);
    }

    private function profiles(int $pluginId): void
    {
        foreach (['First', 'Second'] as $name) {
            DB::table(ProfileRepository::PROFILES_TABLE)->insert([
                'extension_plugin_id' => $pluginId, 'name' => $name, 'enabled' => true,
                'settings' => json_encode(['source_mode' => 'scraper_cache']),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
}
