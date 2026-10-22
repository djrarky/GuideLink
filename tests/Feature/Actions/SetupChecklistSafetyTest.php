<?php

namespace AppLocalPlugins\GuideLink\Tests\Feature\Actions;

require_once dirname(__DIR__).'/PluginFeatureTestCase.php';

use AppLocalPlugins\GuideLink\Actions\SetupChecklistAction;
use AppLocalPlugins\GuideLink\Plugin;
use AppLocalPlugins\GuideLink\Services\GuideLinkServices;
use AppLocalPlugins\GuideLink\Services\ProfileRepository;
use AppLocalPlugins\GuideLink\Services\SetupChecklistService;
use AppLocalPlugins\GuideLink\Services\SourceCacheService;
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

    public function test_checklist_reads_each_snapshot_once_and_preserves_freshness_and_minimum_checks(): void
    {
        Storage::fake('local');
        [$context] = $this->context(applyPluginSchema: true);
        $cache = new class extends SourceCacheService
        {
            public int $reads = 0;

            public function readLastGood(array $config): array
            {
                $this->reads++;

                return parent::readLastGood($config);
            }
        };
        $settings = [...$this->cacheConfig(), 'source_mode' => 'scraper_cache'];
        $profile = DB::table(ProfileRepository::PROFILES_TABLE)->insertGetId([
            'extension_plugin_id' => $context->plugin->id, 'name' => 'Cache checks',
            'enabled' => true, 'settings' => json_encode($settings),
        ]);
        $service = new SetupChecklistService($cache);
        $cases = [
            [['fetched_at' => now()->toIso8601String()], [], true],
            [['fetched_at' => now()->subDays(3)->toIso8601String()], [], false],
            [['fetched_at' => now()->addDay()->toIso8601String()], [], false],
            [['fetched_at' => 'not a date'], [], false],
            [[], [], true],
            [[], ['force_refresh' => true], false],
            [[], ['source_cache_days' => 0], false],
        ];
        foreach ($cases as [$metadata, $overrides, $fresh]) {
            $config = [...$settings, ...$overrides];
            $path = $cache->writeLastGood($config, [['number' => 101, 'name' => 'BBC One', 'category' => '', 'aliases' => []]], $metadata);
            touch(Storage::disk('local')->path($path), now()->getTimestamp());
            clearstatcache();
            DB::table(ProfileRepository::PROFILES_TABLE)->where('id', $profile)->update(['settings' => json_encode($config)]);
            $before = $cache->reads;
            $row = collect($service->rows($context))->firstWhere('title', 'Profile source: Cache checks');
            $this->assertSame($before + 1, $cache->reads);
            $this->assertSame('ready', $row['status']);
            $this->assertSame($fresh, $row['details']['source_cache_fresh']);
        }
        DB::table(ProfileRepository::PROFILES_TABLE)->where('id', $profile)->update(['settings' => json_encode([...$settings, 'min_channels' => 2])]);
        $row = collect($service->rows($context))->firstWhere('title', 'Profile source: Cache checks');
        $this->assertSame('missing', $row['status']);
        $this->assertStringContainsString('minimum of 2', $row['details']['error']);
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
