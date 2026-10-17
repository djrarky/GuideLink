<?php

namespace AppLocalPlugins\GuideLink\Tests\Feature\Alerts;

require_once dirname(__DIR__).'/PluginFeatureTestCase.php';

use App\Models\Channel;
use App\Models\Playlist;
use App\Models\User;
use App\Plugins\PluginSchemaManager;
use App\Plugins\Support\PluginActionResult;
use App\Plugins\Support\PluginExecutionContext;
use App\Services\AlertService;
use App\Settings\GeneralSettings;
use AppLocalPlugins\GuideLink\Plugin;
use AppLocalPlugins\GuideLink\Services\NativeAlertService;
use AppLocalPlugins\GuideLink\Services\ProfileRepository;
use AppLocalPlugins\GuideLink\Services\SourceCacheService;
use AppLocalPlugins\GuideLink\Services\SourceRefreshService;
use AppLocalPlugins\GuideLink\Sources\Contracts\ProviderSource;
use AppLocalPlugins\GuideLink\Sources\SourceRegistry;
use AppLocalPlugins\GuideLink\Support\CountryRulesRepository;
use AppLocalPlugins\GuideLink\Tests\Feature\PluginFeatureTestCase;
use Filament\Notifications\BroadcastNotification;
use Filament\Notifications\DatabaseNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;

class NativeAlertTest extends PluginFeatureTestCase
{
    use RefreshDatabase;

    public function test_first_failure_reminder_recovery_and_recurrence_use_persistent_state(): void
    {
        [$context, $playlist] = $this->context(applyPluginSchema: true, dryRun: false);
        app(GeneralSettings::class)->suppress_success_notifications = false;
        $messages = [];
        $this->externalAlerts($messages);
        $this->freezeTime();
        $payload = ['playlist_id' => $playlist->id];
        $failure = PluginActionResult::failure('private SQL with password=do-not-send');
        foreach (range(1, 3) as $attempt) {
            (new NativeAlertService)->recordResult('apply_sync_playlist', $payload, $failure, $context);
        }
        $this->assertCount(1, $messages);
        $this->assertSame(3, DB::table(NativeAlertService::TABLE)->value('occurrences'));
        Notification::assertSentToTimes($context->user, DatabaseNotification::class, 1);
        Notification::assertSentToTimes($context->user, BroadcastNotification::class, 1);
        $this->travel(23)->hours();
        (new NativeAlertService)->recordResult('apply_sync_playlist', $payload, $failure, $context);
        $this->assertCount(1, $messages);
        $this->travel(1)->hours();
        (new NativeAlertService)->recordResult('apply_sync_playlist', $payload, $failure, $context);
        $this->assertCount(2, $messages);
        foreach (range(1, 2) as $attempt) {
            (new NativeAlertService)->recordResult('apply_sync_playlist', $payload, PluginActionResult::success('ok'), $context);
        }
        $this->assertCount(3, $messages);
        $this->assertStringContainsString('recovered', $messages[2]);
        $this->assertDatabaseHas(NativeAlertService::TABLE, ['active' => false]);
        (new NativeAlertService)->recordResult('apply_sync_playlist', $payload, $failure, $context);
        $this->assertCount(4, $messages);
        $this->assertStringNotContainsString('do-not-send', implode('', $messages));
    }

    public function test_source_outage_escalates_without_cache_and_never_leaks_credentials(): void
    {
        [$context] = $this->context(applyPluginSchema: true);
        $messages = [];
        $this->externalAlerts($messages);
        $alerts = new NativeAlertService;
        $config = $this->cacheConfig(['scraping_api_key' => 'secret-token', 'provider_source' => 'viewersvault', 'region' => '7']);
        $error = new RuntimeException('HTTP 401 monthly request limit exceeded https://private.invalid/?token=secret-token');
        $alerts->sourceFailed($config, $error, true, $context);
        $alerts->sourceFailed([...$config, 'force_refresh' => true, 'profile_id' => 99], $error, true, $context);
        $alerts->sourceFailed($config, $error, false, $context);
        $this->assertCount(2, $messages);
        $this->assertStringContainsString('quota or request limit', $messages[0]);
        $this->assertStringContainsString('ViewersVault, region 7', $messages[0]);
        $this->assertStringContainsString('last-good cache', $messages[0]);
        $this->assertStringContainsString('No usable', $messages[1]);
        foreach (['secret-token', 'private.invalid'] as $secret) {
            $this->assertStringNotContainsString($secret, implode('', $messages).json_encode(DB::table(NativeAlertService::TABLE)->get()));
        }
        Notification::assertSentTo($context->user, DatabaseNotification::class, fn ($n) => $n->data['status'] === 'danger');
    }

    public function test_custom_reminder_interval_and_schema_reapplication_preserve_suppression(): void
    {
        [$context] = $this->context(applyPluginSchema: true);
        $context = $this->withContext($context, user: $context->user, settings: ['alert_reminder_hours' => 2]);
        $messages = [];
        $this->externalAlerts($messages);
        $this->freezeTime();
        $alerts = new NativeAlertService;
        $alerts->sourceFailed($this->cacheConfig(), new RuntimeException('outage'), true, $context);
        app(PluginSchemaManager::class)->apply($this->manifest()['schema']);
        $alerts->sourceFailed($this->cacheConfig(), new RuntimeException('outage'), true, $context);
        $this->assertCount(1, $messages);
        $this->travel(2)->hours();
        $alerts->sourceFailed($this->cacheConfig(), new RuntimeException('outage'), true, $context);
        $this->assertCount(2, $messages);
        $this->assertContains(NativeAlertService::TABLE, $this->manifest()['data_ownership']['tables']);
        app(PluginSchemaManager::class)->purge($this->manifest()['schema']);
        $this->assertFalse(Schema::hasTable(NativeAlertService::TABLE));
    }

    public function test_cached_read_does_not_recover_source_but_validated_fresh_fetch_does(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response('synthetic source', 200)]);
        [$context] = $this->context(applyPluginSchema: true);
        $messages = [];
        $this->externalAlerts($messages);
        $config = $this->cacheConfig(['provider_source' => 'viewersvault', 'region' => '1']);
        $entries = [['number' => 101, 'name' => 'BBC One', 'category' => 'Entertainment', 'aliases' => []]];
        $cache = new SourceCacheService;
        $cache->writeLastGood($config, $entries, ['fetched_at' => now()->toIso8601String()]);
        (new NativeAlertService)->sourceFailed($config, new RuntimeException('outage'), true, $context);
        $provider = Mockery::mock(ProviderSource::class);
        $provider->shouldReceive('definition')->once()->andReturn(['url' => 'https://example.invalid/source', 'format' => 'html']);
        $provider->shouldReceive('resolveRegion')->once()->andReturn(['name' => 'Test']);
        $provider->shouldReceive('parseChannels')->once()->andReturn($entries);
        $sources = Mockery::mock(SourceRegistry::class);
        $sources->shouldReceive('provider')->once()->andReturn($provider);
        $refresh = new SourceRefreshService(new CountryRulesRepository, $cache, sources: $sources);
        $cached = $refresh->refresh($config, $context);
        $this->assertTrue($cached['summary']['source']['from_cache']);
        $this->assertCount(1, $messages);
        $fresh = $refresh->refresh([...$config, 'force_refresh' => true], $context);
        $this->assertFalse($fresh['summary']['source']['from_cache']);
        $this->assertCount(2, $messages);
        $this->assertStringContainsString('source recovered', $messages[1]);
        $refresh->refresh($config, $context);
        $this->assertCount(2, $messages);
    }

    public function test_refresh_action_alerts_on_fallback_even_when_run_succeeds(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response('monthly request limit exceeded', 401)]);
        [$context] = $this->context(applyPluginSchema: true);
        $messages = [];
        $this->externalAlerts($messages);
        $payload = $this->cacheConfig(['provider_source' => 'viewersvault', 'region' => '1', 'force_refresh' => true, 'profile_sources' => false]);
        (new SourceCacheService)->writeLastGood($payload, [
            ['number' => 101, 'name' => 'BBC One', 'category' => 'Entertainment', 'aliases' => []],
        ], ['fetched_at' => now()->subDays(5)->toIso8601String()]);
        $result = $this->runGuideLinkAction('refresh_source_cache', $payload, $context);
        $this->assertTrue($result->success, $result->summary);
        $this->assertTrue($result->data['source']['stale_fallback']);
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('source refresh failed', $messages[0]);
        $this->assertSame(1, DB::table(NativeAlertService::TABLE)->count());
    }

    public function test_failed_source_action_and_shared_profiles_do_not_raise_duplicate_operation_alerts(): void
    {
        Storage::fake('local');
        [$context] = $this->context(applyPluginSchema: true);
        $messages = [];
        $this->externalAlerts($messages);
        $payload = $this->cacheConfig(['provider_source' => 'invalid', 'profile_sources' => false]);
        $result = $this->runGuideLinkAction('refresh_source_cache', $payload, $context);
        $this->assertFalse($result->success);
        $this->assertTrue($result->data['source_failure']);
        foreach (['First', 'Second'] as $name) {
            DB::table(ProfileRepository::PROFILES_TABLE)->insert([
                'extension_plugin_id' => $context->plugin->id, 'name' => $name, 'enabled' => true,
                'settings' => json_encode(['source_mode' => 'scraper_cache', ...$payload]),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $result = $this->runGuideLinkAction('refresh_source_cache', ['profile_sources' => true], $context);
        $this->assertFalse($result->success);
        $this->assertSame(2, $result->data['failed']);
        $this->assertTrue($result->data['source_failure']);
        $this->assertCount(1, $messages);
    }

    public function test_source_validation_policies_and_credentials_do_not_resolve_each_others_incidents(): void
    {
        [$context] = $this->context(applyPluginSchema: true);
        $messages = [];
        $this->externalAlerts($messages);
        $alerts = new NativeAlertService;
        $config = $this->cacheConfig();
        $alerts->sourceFailed($config, new RuntimeException('outage'), true, $context);
        $alerts->sourceRecovered([...$config, 'min_channels' => 200], $context);
        $alerts->sourceRecovered([...$config, 'scraping_api_key' => 'another-key'], $context);
        $alerts->sourceRecovered([...$config, 'scraping_timeout_seconds' => 120], $context);
        $alerts->sourceRecovered([...$config, 'max_retries' => 5], $context);
        $this->assertCount(1, $messages);
        $this->assertDatabaseHas(NativeAlertService::TABLE, ['active' => true]);
    }

    public function test_one_assigned_playlist_uses_the_same_incident_as_an_explicit_manual_run(): void
    {
        [$context, $playlist] = $this->context(applyPluginSchema: true);
        $this->assignProfile($context, $playlist, ['guide_epg_id' => null]);
        $messages = [];
        $this->externalAlerts($messages);
        $assigned = $this->runGuideLinkAction('sync_playlist', ['assigned_playlists' => true], $context);
        $explicit = $this->runGuideLinkAction('sync_playlist', ['playlist_id' => $playlist->id], $context);
        $this->assertFalse($assigned->success);
        $this->assertFalse($explicit->success);
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('playlist #'.$playlist->id, $messages[0]);
        (new NativeAlertService)->recordResult('sync_playlist', ['playlist_id' => $playlist->id], PluginActionResult::success('ok'), $context);
        $this->assertCount(2, $messages);
        $this->assertSame(0, DB::table(NativeAlertService::TABLE)->where('active', true)->count());
    }

    public function test_scheduled_playlist_alerts_reach_owner_and_admins_but_not_unrelated_users(): void
    {
        [$context, $playlist] = $this->context(applyPluginSchema: true);
        $context->user->forceFill(['is_admin' => false])->saveQuietly();
        $admin = User::factory()->create(['is_admin' => true]);
        $unrelated = User::factory()->create(['is_admin' => false]);
        $scheduled = $this->withContext($context, user: null);
        $messages = [];
        $this->externalAlerts($messages);
        (new NativeAlertService)->recordResult('availability_report', ['playlist_id' => $playlist->id], PluginActionResult::failure('error'), $scheduled);
        Notification::assertSentToTimes($admin, DatabaseNotification::class, 1);
        Notification::assertSentToTimes($context->user, DatabaseNotification::class, 1);
        Notification::assertNotSentTo($unrelated, DatabaseNotification::class);
        $this->assertCount(1, $messages);
        Notification::fake();
        (new NativeAlertService)->sourceFailed($this->cacheConfig(), new RuntimeException('outage'), true, $scheduled);
        Notification::assertSentToTimes($admin, DatabaseNotification::class, 1);
        Notification::assertNotSentTo($context->user, DatabaseNotification::class);
    }

    public function test_foreign_playlist_failure_does_not_notify_its_owner(): void
    {
        [$context] = $this->context(applyPluginSchema: true);
        $context->user->forceFill(['is_admin' => false])->saveQuietly();
        $other = User::factory()->create(['is_admin' => false]);
        $foreign = Playlist::factory()->for($other)->createQuietly();
        $result = $this->runGuideLinkAction('sync_playlist', ['playlist_id' => $foreign->id], $context);
        $this->assertFalse($result->success);
        Notification::assertSentToTimes($context->user, DatabaseNotification::class, 1);
        Notification::assertNotSentTo($other, DatabaseNotification::class);
    }

    public function test_partial_batch_only_alerts_failed_playlists_and_manual_retry_recovers_same_incident(): void
    {
        [$context, $playlist] = $this->context(applyPluginSchema: true, dryRun: false);
        $other = Playlist::factory()->for($context->user)->createQuietly();
        $messages = [];
        $this->externalAlerts($messages);
        $row = fn ($playlist, $success) => [
            'playlist' => ['id' => $playlist->id], 'status' => $success ? 'completed' : 'failed',
            'success' => $success, 'summary' => '', 'data' => [],
        ];
        $alerts = new NativeAlertService;
        $alerts->recordResult('apply_sync_playlist', ['assigned_playlists' => true], PluginActionResult::failure('batch failed', [
            'playlist_runs' => [$row($playlist, false), $row($other, true)],
        ]), $context);
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('playlist #'.$playlist->id, $messages[0]);
        $alerts->recordResult('sync_playlist', ['playlist_id' => $playlist->id], PluginActionResult::success('ok'), $context);
        $this->assertCount(2, $messages);
        $this->assertStringContainsString('recovered', $messages[1]);
    }

    public function test_preview_success_cannot_recover_failed_apply_and_cancelled_runs_do_not_alert(): void
    {
        [$context, $playlist] = $this->context(applyPluginSchema: true, dryRun: false);
        $messages = [];
        $this->externalAlerts($messages);
        $alerts = new NativeAlertService;
        $payload = ['playlist_id' => $playlist->id];
        $alerts->recordResult('apply_sync_playlist', $payload, PluginActionResult::failure('error'), $context);
        $preview = $this->withContext($context, user: $context->user, dryRun: true);
        $alerts->recordResult('sync_playlist', $payload, PluginActionResult::success('ok'), $preview);
        $alerts->recordResult('apply_sync_playlist', $payload, PluginActionResult::cancelled('cancelled'), $context);
        $context->run->forceFill(['cancel_requested' => true])->saveQuietly();
        $alerts->recordResult('apply_sync_playlist', $payload, PluginActionResult::success('ok'), $context);
        $alerts->sourceFailed($this->cacheConfig(), new RuntimeException('outage'), true, $context);
        $this->assertCount(1, $messages);
        $this->assertDatabaseHas(NativeAlertService::TABLE, ['active' => true]);
    }

    public function test_notifications_can_be_disabled_and_host_success_suppression_is_honoured(): void
    {
        [$context, $playlist] = $this->context(applyPluginSchema: true);
        $disabled = $this->withContext($context, user: $context->user, settings: ['native_alerts_enabled' => false]);
        $alerts = new NativeAlertService;
        $payload = ['playlist_id' => $playlist->id];
        $alerts->recordResult('sync_playlist', $payload, PluginActionResult::failure('error'), $disabled);
        $alerts->sourceFailed($this->cacheConfig(), new RuntimeException('outage'), true, $disabled);
        Notification::assertNothingSent();
        $this->assertSame(0, DB::table(NativeAlertService::TABLE)->count());
        app(GeneralSettings::class)->suppress_success_notifications = true;
        $alerts->recordResult('sync_playlist', $payload, PluginActionResult::failure('error'), $context);
        $alerts->recordResult('sync_playlist', $payload, PluginActionResult::success('ok'), $context);
        Notification::assertSentToTimes($context->user, DatabaseNotification::class, 1);
        $this->assertDatabaseHas(NativeAlertService::TABLE, ['active' => false]);
    }

    public function test_delivery_failure_does_not_change_action_result_or_prevent_other_channels(): void
    {
        [$context] = $this->context(applyPluginSchema: true);
        $this->mock(AlertService::class, function ($mock): void {
            $mock->shouldReceive('isEnabled')->once()->andReturn(true);
            $mock->shouldReceive('send')->once()->andThrow(new RuntimeException('secret webhook credentials'));
        });
        $result = $this->runGuideLinkAction('not_supported', [], $context);
        $this->assertFalse($result->success);
        $this->assertSame('Unsupported action [not_supported]', $result->summary);
        Notification::assertSentToTimes($context->user, DatabaseNotification::class, 1);
        $this->assertSame(1, $context->run->logs()->where('message', 'GuideLink could not record or queue a native alert. Check host notification services.')->count());
        $this->assertStringNotContainsString('secret webhook credentials', $context->run->logs()->get()->toJson());
    }

    public function test_missing_alert_schema_does_not_mask_original_action_result(): void
    {
        [$context] = $this->context();
        $this->assertFalse(Schema::hasTable(NativeAlertService::TABLE));
        $result = $this->runGuideLinkAction('not_supported', [], $context);
        $this->assertFalse($result->success);
        $this->assertSame('Unsupported action [not_supported]', $result->summary);
        Notification::assertNothingSent();
    }

    public function test_invalid_configuration_and_hook_failures_raise_native_notifications(): void
    {
        Bus::fake();
        [$context, $playlist] = $this->context(applyPluginSchema: true);
        $this->assignProfile($context, $playlist, ['guide_epg_id' => null]);
        $result = $this->runGuideLinkAction('sync_playlist', ['playlist_id' => $playlist->id], $context);
        $this->assertFalse($result->success);
        Notification::assertSentToTimes($context->user, DatabaseNotification::class, 1);
        $result = (new Plugin)->runHook('playlist.synced', ['playlist_id' => $playlist->id], $context);
        $this->assertFalse($result->success);
        Notification::assertSentToTimes($context->user, DatabaseNotification::class, 2);
        DB::table(ProfileRepository::ASSIGNMENTS_TABLE)->where('playlist_id', $playlist->id)->delete();
        $result = (new Plugin)->runHook('playlist.synced', ['playlist_id' => $playlist->id], $context);
        $this->assertTrue($result->data['skipped']);
        Notification::assertSentToTimes($context->user, DatabaseNotification::class, 2);
    }

    public function test_actual_channel_write_failure_alerts_and_successful_retry_recovers(): void
    {
        Bus::fake();
        [$context, $playlist] = $this->context(applyPluginSchema: true, dryRun: false);
        $channel = Channel::factory()->createQuietly([
            'playlist_id' => $playlist->id, 'user_id' => $context->user->id,
            'enabled' => true, 'is_vod' => false, 'channel' => 101, 'group_id' => null,
            'name' => 'BBC One', 'title' => 'BBC One', 'stream_id' => 'old-id',
        ]);
        $this->assignProfile($context, $playlist, ['guide_epg_id' => $this->guideEpgId($context)]);
        $messages = [];
        $this->externalAlerts($messages);
        $failWrites = true;
        Event::listen('eloquent.updating: '.Channel::class, function (Channel $saving) use ($channel, &$failWrites): void {
            if ($failWrites && $saving->id === $channel->id) {
                throw new RuntimeException('Synthetic write failure: private database details');
            }
        });
        $result = $this->runGuideLinkAction('apply_sync_playlist', ['playlist_id' => $playlist->id], $context);
        $this->assertFalse($result->success, $result->summary);
        $this->assertSame(1, $result->data['writes']['channels_failed']);
        $this->assertCount(1, $messages);
        $this->assertStringNotContainsString('private database details', $messages[0]);
        $failWrites = false;
        $result = $this->runGuideLinkAction('apply_sync_playlist', ['playlist_id' => $playlist->id], $context);
        $this->assertTrue($result->success, $result->summary);
        $this->assertCount(2, $messages);
        $this->assertStringContainsString('recovered', $messages[1]);
    }

    private function externalAlerts(array &$messages): void
    {
        $this->mock(AlertService::class, function ($mock) use (&$messages): void {
            $mock->shouldReceive('isEnabled')->andReturn(true);
            $mock->shouldReceive('send')->andReturnUsing(function (string $message) use (&$messages): void {
                $messages[] = $message;
            });
        });
    }

    private function withContext(PluginExecutionContext $context, ?User $user, ?bool $dryRun = null, array $settings = []): PluginExecutionContext
    {
        return new PluginExecutionContext($context->plugin, $context->run, $context->trigger,
            $dryRun ?? $context->dryRun, $context->hook, $user, $settings);
    }
}
