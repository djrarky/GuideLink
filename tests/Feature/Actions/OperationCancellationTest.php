<?php

namespace AppLocalPlugins\GuideLink\Tests\Feature\Actions;

require_once dirname(__DIR__).'/PluginFeatureTestCase.php';

use App\Models\Channel;
use AppLocalPlugins\GuideLink\Plugin;
use AppLocalPlugins\GuideLink\Services\AvailabilityAnalyzer;
use AppLocalPlugins\GuideLink\Services\ChannelMatcher;
use AppLocalPlugins\GuideLink\Services\ChannelRepository;
use AppLocalPlugins\GuideLink\Services\SourceCacheService;
use AppLocalPlugins\GuideLink\Services\SourceRefreshService;
use AppLocalPlugins\GuideLink\Support\CountryRulesRepository;
use AppLocalPlugins\GuideLink\Support\RunCancelled;
use AppLocalPlugins\GuideLink\Tests\Feature\PluginFeatureTestCase;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class OperationCancellationTest extends PluginFeatureTestCase
{
    use RefreshDatabase;

    public function test_pre_cancelled_actions_stop_before_validation_fetch_or_native_changes(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        [$context, $playlist] = $this->context(dryRun: false);
        $context->run->forceFill(['cancel_requested' => true])->saveQuietly();
        $before = $playlist->refresh()->getAttributes();
        foreach (['apply_sync_playlist', 'apply_auto_merge_setup', 'apply_scrubber_setup', 'rebuild_failovers', 'apply_repair_availability', 'refresh_source_cache', 'availability_report'] as $action) {
            $result = $this->runGuideLinkAction($action, ['playlist_id' => $playlist->id], $context);
            $this->assertSame('cancelled', $result->status, $action);
        }
        $this->assertSame('cancelled', (new Plugin)->runHook('playlist.synced', ['playlist_id' => $playlist->id], $context)->status);
        $this->assertSame($before, $playlist->refresh()->getAttributes());
        $this->assertSame([], Storage::disk('local')->allFiles());
        Http::assertNothingSent();
    }

    public function test_cancelling_a_fetch_does_not_retry_or_fall_back_to_cache(): void
    {
        Storage::fake('local');
        [$context] = $this->context();
        $cache = new SourceCacheService;
        $payload = $this->cacheConfig(['provider_source' => 'viewersvault', 'force_refresh' => true, 'max_retries' => 3]);
        $entries = [['number' => 101, 'name' => 'BBC One', 'category' => 'Entertainment', 'aliases' => []]];
        $cache->writeLastGood($payload, $entries, []);
        Http::fake(function () use ($context) {
            $context->run->forceFill(['cancel_requested' => true])->saveQuietly();

            return Http::response('cancelled response');
        });
        try {
            (new SourceRefreshService(new CountryRulesRepository, $cache))->refresh($payload, $context);
            $this->fail('Expected cancellation rather than stale cache success.');
        } catch (RunCancelled) {
            $this->assertSame($entries, $cache->readLastGood($payload)['entries']);
        }
        Http::assertSentCount(1);
        $this->assertSame(0, $context->run->logs()->where('level', 'warning')->count());
    }

    public function test_matching_stops_at_a_cancellation_checkpoint(): void
    {
        [$context, $playlist] = $this->context();
        Event::listen('eloquent.updated: '.get_class($context->run), function ($run) use ($context): void {
            if ($run->id === $context->run->id && str_starts_with((string) $run->progress_message, 'Matching channels:')) {
                DB::table($run->getTable())->where('id', $run->id)->update(['cancel_requested' => true]);
            }
        });
        $countries = new CountryRulesRepository;
        $matcher = new ChannelMatcher($countries);
        $this->expectException(RunCancelled::class);
        $matcher->rows($playlist, new Collection([(new Channel)->forceFill(['title' => 'BBC One'])]), [], $matcher->thresholds([], []), $countries->resolve([], []), $context);
    }

    public function test_standalone_setup_preserves_config_changed_during_planning(): void
    {
        Bus::fake();
        [$context, $playlist] = $this->context(applyPluginSchema: true, dryRun: false);
        $this->assignProfile($context, $playlist);
        Event::listen('eloquent.updated: '.get_class($context->run), function ($run) use ($context, $playlist): void {
            if ($run->id === $context->run->id && $run->progress === 50) {
                DB::table('playlists')->where('id', $playlist->id)->update(['auto_merge_config' => json_encode(['unmanaged_preference' => 'concurrent edit'])]);
            }
        });
        $result = $this->runGuideLinkAction('apply_auto_merge_setup', ['playlist_id' => $playlist->id], $context);
        $this->assertTrue($result->success, $result->summary);
        $this->assertSame('concurrent edit', $playlist->refresh()->auto_merge_config['unmanaged_preference']);
        $this->assertTrue($playlist->auto_merge_channels_enabled);
    }

    public function test_source_coverage_matching_can_be_cancelled(): void
    {
        [$context, $playlist] = $this->context();
        Event::listen('eloquent.updated: '.get_class($context->run), function ($run) use ($context): void {
            if ($run->id === $context->run->id && str_starts_with((string) $run->progress_message, 'Checking source coverage:')) {
                DB::table($run->getTable())->where('id', $run->id)->update(['cancel_requested' => true]);
            }
        });
        $countries = new CountryRulesRepository;
        $matcher = new ChannelMatcher($countries);
        $analyzer = new AvailabilityAnalyzer(new ChannelRepository($countries), $matcher);
        $this->expectException(RunCancelled::class);
        $analyzer->sourceCoverageRows($playlist, [], new Collection([(new Channel)->forceFill(['title' => 'BBC One'])]), [], $matcher->thresholds([], []), $countries->resolve([], []), $context);
    }
}
