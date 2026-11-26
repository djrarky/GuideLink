<?php

namespace AppLocalPlugins\GuideLink\Tests\Feature\Actions;

require_once dirname(__DIR__).'/PluginFeatureTestCase.php';

use AppLocalPlugins\GuideLink\Actions\RefreshSourceCacheAction;
use AppLocalPlugins\GuideLink\Services\GuideLinkServices;
use AppLocalPlugins\GuideLink\Services\ProfileRepository;
use AppLocalPlugins\GuideLink\Services\RunResultRepository;
use AppLocalPlugins\GuideLink\Services\SourceRefreshService;
use AppLocalPlugins\GuideLink\Tests\Feature\PluginFeatureTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;

class SourceRefreshBatchTest extends PluginFeatureTestCase
{
    use RefreshDatabase;

    public function test_failed_profile_does_not_abort_independent_refreshes_and_shared_sources_run_once(): void
    {
        [$context] = $this->context(applyPluginSchema: true);
        $this->profiles($context->plugin->id, [
            'A failure' => ['region' => '1'], 'B success' => ['region' => '2'], 'C shared' => ['region' => '2'],
        ]);
        $calls = [];
        $refresh = Mockery::mock(SourceRefreshService::class);
        $refresh->shouldReceive('refresh')->twice()->andReturnUsing(function ($payload) use (&$calls) {
            $calls[] = $payload['region'];
            $this->assertTrue($payload['force_refresh']);
            if ($payload['region'] === '1') {
                throw new \RuntimeException('Synthetic source unavailable');
            }

            return ['summary' => ['source' => ['channel_count' => 10]]];
        });
        $result = $this->action($refresh)->handle(['force_refresh' => true], $context);
        $this->assertFalse($result->success);
        $this->assertSame(['1', '2'], $calls);
        $this->assertSame(['failed', 'completed', 'completed'], array_column($result->data['profiles'], 'status'));
        $this->assertSame(1, $result->data['failed']);
        $this->assertSame(['failed', 'refreshed', 'refreshed'], DB::table(RunResultRepository::TABLE)->orderBy('id')->pluck('decision')->all());
    }

    public function test_different_validation_policies_are_not_deduplicated(): void
    {
        [$context] = $this->context(applyPluginSchema: true);
        $this->profiles($context->plugin->id, ['A lenient' => ['min_channels' => 1], 'B strict' => ['min_channels' => 500]]);
        $minimums = [];
        $refresh = Mockery::mock(SourceRefreshService::class);
        $refresh->shouldReceive('refresh')->twice()->andReturnUsing(function ($payload) use (&$minimums) {
            $minimums[] = $payload['min_channels'];

            return ['summary' => ['source' => ['channel_count' => 500]]];
        });
        $result = $this->action($refresh)->handle(['force_refresh' => true], $context);
        $this->assertTrue($result->success, $result->summary);
        $this->assertSame([1, 500], $minimums);
    }

    public function test_stale_fallback_fails_shared_profiles_and_preserves_diagnostics_while_other_sources_refresh(): void
    {
        [$context] = $this->context(applyPluginSchema: true);
        $this->profiles($context->plugin->id, [
            'A fallback' => ['region' => '7'], 'B shared fallback' => ['region' => '7'], 'C fresh' => ['region' => '1'],
        ]);
        $refresh = Mockery::mock(SourceRefreshService::class);
        $refresh->shouldReceive('refresh')->twice()->andReturnUsing(fn ($payload): array => [
            'summary' => ['source' => [
                'channel_count' => 298,
                'from_cache' => $payload['region'] === '7',
                'stale_fallback' => $payload['region'] === '7',
                'fetched_at' => '2026-11-13T02:33:29+00:00',
                'refresh_error' => $payload['region'] === '7' ? 'No Sky regions were parsed.' : null,
            ]],
        ]);
        $result = $this->action($refresh)->handle(['force_refresh' => true], $context);
        $this->assertFalse($result->success);
        $this->assertSame('failed', $result->status);
        $this->assertSame(['failed', 'failed', 'completed'], array_column($result->data['profiles'], 'status'));
        $this->assertSame(2, $result->data['failed']);
        $this->assertSame(2, $result->data['stale_fallbacks']);
        $this->assertTrue($result->data['source_failure']);
        $this->assertStringContainsString('Last-good caches retained for 2 profile(s)', $result->summary);
        $this->assertSame('No Sky regions were parsed.', $result->data['profiles'][0]['summary']['source']['refresh_error']);
        $this->assertSame('2026-11-13T02:33:29+00:00', $result->data['profiles'][0]['summary']['source']['fetched_at']);
        $this->assertSame(['stale_fallback', 'stale_fallback', 'refreshed'], DB::table(RunResultRepository::TABLE)->orderBy('id')->pluck('decision')->all());
    }

    public function test_cancellation_persists_completed_profile_results(): void
    {
        [$context] = $this->context(applyPluginSchema: true);
        $this->profiles($context->plugin->id, ['A completed' => ['region' => '1'], 'B pending' => ['region' => '2']]);
        $refresh = Mockery::mock(SourceRefreshService::class);
        $refresh->shouldReceive('refresh')->once()->andReturnUsing(function () use ($context) {
            $context->run->forceFill(['cancel_requested' => true])->saveQuietly();

            return ['summary' => ['source' => ['channel_count' => 10]]];
        });
        $result = $this->action($refresh)->handle([], $context);
        $this->assertSame('cancelled', $result->status);
        $this->assertCount(1, $result->data['profiles']);
        $this->assertDatabaseHas(RunResultRepository::TABLE, ['title' => 'A completed', 'decision' => 'refreshed']);
        $this->assertDatabaseMissing(RunResultRepository::TABLE, ['title' => 'B pending']);
    }

    private function action(SourceRefreshService $refresh): RefreshSourceCacheAction
    {
        $services = Mockery::mock(GuideLinkServices::class)->makePartial();
        $services->shouldReceive('sourceRefresh')->once()->andReturn($refresh);

        return new RefreshSourceCacheAction($services);
    }

    private function profiles(int $pluginId, array $profiles): void
    {
        foreach ($profiles as $name => $settings) {
            DB::table(ProfileRepository::PROFILES_TABLE)->insert([
                'extension_plugin_id' => $pluginId, 'name' => $name, 'enabled' => true,
                'settings' => json_encode(['source_mode' => 'scraper_cache', ...$settings]),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
}
