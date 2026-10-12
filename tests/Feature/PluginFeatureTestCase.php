<?php

namespace AppLocalPlugins\GuideLink\Tests\Feature;

use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\Playlist;
use App\Models\Plugin as PluginRecord;
use App\Models\User;
use App\Plugins\PluginSchemaManager;
use App\Plugins\Support\PluginActionResult;
use App\Plugins\Support\PluginExecutionContext;
use AppLocalPlugins\GuideLink\Plugin as GuideLinkPlugin;
use AppLocalPlugins\GuideLink\Services\ProfileRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

abstract class PluginFeatureTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        require_once dirname(__DIR__, 2).'/Plugin.php';
        new GuideLinkPlugin;
        Notification::fake();
    }

    /**
     * @return array{PluginExecutionContext, Playlist}
     */
    protected function context(bool $applyPluginSchema = false, bool $dryRun = true): array
    {
        $user = User::factory()->create();
        $playlist = Playlist::factory()->for($user)->createQuietly();
        $plugin = new PluginRecord;
        $plugin->forceFill([
            'plugin_id' => 'guidelink-test',
            'name' => 'GuideLink Test',
            'path' => '/tmp/guidelink-test-'.uniqid(),
            'enabled' => true,
        ])->save();

        if ($applyPluginSchema) {
            app(PluginSchemaManager::class)->apply($this->manifest()['schema']);
        }

        $run = $plugin->runs()->create([
            'user_id' => $user->id,
            'trigger' => 'test',
            'invocation_type' => 'action',
            'action' => 'sync_playlist',
            'dry_run' => $dryRun,
            'status' => 'running',
        ]);

        return [
            new PluginExecutionContext($plugin, $run, 'test', $dryRun, null, $user, []),
            $playlist,
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    protected function assignProfile(PluginExecutionContext $context, Playlist $playlist, array $settings = []): void
    {
        $now = now();
        $profileId = DB::table(ProfileRepository::PROFILES_TABLE)->insertGetId([
            'extension_plugin_id' => $context->plugin->id,
            'name' => 'Native Auto-Merge '.$playlist->id,
            'enabled' => true,
            'settings' => json_encode([
                'source_mode' => 'native_playlist',
                'source_playlist_id' => $playlist->id,
                ...$settings,
            ], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table(ProfileRepository::ASSIGNMENTS_TABLE)->insert([
            'extension_plugin_id' => $context->plugin->id,
            'playlist_id' => $playlist->id,
            'extension_plugin_profile_id' => $profileId,
            'enabled' => true,
            'settings' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    protected function guideEpgId(PluginExecutionContext $context): int
    {
        $epg = Epg::factory()->for($context->user)->createQuietly();
        EpgChannel::factory()->createQuietly([
            'epg_id' => $epg->id,
            'user_id' => $context->user->id,
            'channel_id' => 'bbc.one',
            'display_name' => '101',
            'name' => 'BBC One',
        ]);

        return (int) $epg->id;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function runGuideLinkAction(string $action, array $payload, PluginExecutionContext $context): PluginActionResult
    {
        return (new GuideLinkPlugin)->runAction($action, $payload, $context);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function cacheConfig(array $overrides = []): array
    {
        return [
            'country' => 'uk',
            'provider' => 'sky',
            'provider_source' => 'synthetic',
            'region' => 'test',
            'scraping_api' => 'direct_http',
            'scraping_api_key' => '',
            'scraping_timeout_seconds' => 1,
            'source_cache_days' => 2,
            'min_channels' => 1,
            'max_drop_percent' => 20,
            'max_retries' => 1,
            'force_refresh' => false,
            ...$overrides,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function manifest(): array
    {
        return json_decode(
            (string) file_get_contents(dirname(__DIR__, 2).'/plugin.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }
}
