<?php

namespace AppLocalPlugins\GuideLink\Tests\Feature\Sources;

require_once dirname(__DIR__).'/PluginFeatureTestCase.php';

use App\Models\Channel;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\User;
use AppLocalPlugins\GuideLink\Actions\RefreshSourceCacheAction;
use AppLocalPlugins\GuideLink\Services\ParsedSourceLoader;
use AppLocalPlugins\GuideLink\Services\ProfileRepository;
use AppLocalPlugins\GuideLink\Services\RunResultRepository;
use AppLocalPlugins\GuideLink\Services\SourceCacheService;
use AppLocalPlugins\GuideLink\Tests\Feature\PluginFeatureTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class NativeSourceTest extends PluginFeatureTestCase
{
    use RefreshDatabase;

    public function test_native_playlist_source_loader_reads_enabled_live_channels(): void
    {
        [$context] = $this->context();
        $sourcePlaylist = Playlist::factory()->for($context->user)->createQuietly();
        $group = Group::factory()->createQuietly([
            'playlist_id' => $sourcePlaylist->id,
            'user_id' => $sourcePlaylist->user_id,
            'name' => 'UK| TEST',
        ]);
        Channel::factory()->createQuietly([
            'playlist_id' => $sourcePlaylist->id,
            'user_id' => $sourcePlaylist->user_id,
            'group_id' => $group->id,
            'enabled' => true,
            'is_vod' => false,
            'channel' => 101,
            'title' => 'Native One',
            'name' => 'Native One Raw',
            'stream_id' => 'native.one',
        ]);
        Channel::factory()->createQuietly([
            'playlist_id' => $sourcePlaylist->id,
            'user_id' => $sourcePlaylist->user_id,
            'group_id' => $group->id,
            'enabled' => false,
            'is_vod' => false,
            'channel' => 102,
            'title' => 'Disabled Source',
        ]);

        $loader = new ParsedSourceLoader(new SourceCacheService);
        $payload = [
            'source_mode' => 'native_playlist',
            'source_playlist_id' => $sourcePlaylist->id,
        ];
        $entries = $loader->entries($payload, $context, [101 => 'guide.one']);

        $this->assertCount(1, $entries);
        $this->assertSame(101, $entries[0]['number']);
        $this->assertSame('Native One', $entries[0]['name']);
        $this->assertSame('UK| TEST', $entries[0]['category']);
        $this->assertSame('guide.one', $entries[0]['stream_id']);
        $this->assertTrue($entries[0]['guide_valid']);
        $this->assertFalse($loader->entries($payload, $context, [102 => 'guide.two'])[0]['guide_valid']);
        $this->assertFalse($loader->entries($payload, $context, [])[0]['guide_valid']);
    }

    public function test_assigned_runs_and_native_sources_reject_another_users_playlist(): void
    {
        [$context, $playlist] = $this->context(applyPluginSchema: true);
        $context->user->update(['is_admin' => false]);
        $other = Playlist::factory()->for(User::factory())->createQuietly();
        foreach ([$playlist, $other] as $assigned) {
            $this->assignProfile($context, $assigned);
        }
        $runs = (new ProfileRepository)->assignedRuns([], $context, 'sync');
        $this->assertSame([(int) $playlist->id], array_map(fn (array $run): int => (int) $run['playlist']->id, $runs));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Source playlist ['.$other->id.'] was not found or is not available');
        (new ParsedSourceLoader(new SourceCacheService))->entries([
            'source_mode' => 'native_playlist', 'source_playlist_id' => $other->id,
        ], $context, []);
    }

    public function test_cached_source_rows_need_a_matching_guide_number(): void
    {
        Storage::fake('local');
        [$context] = $this->context();
        $cache = new SourceCacheService;
        $payload = $this->cacheConfig();
        $cache->writeLastGood($cache->config($payload, []), [
            ['number' => 101, 'name' => 'One', 'category' => 'Test', 'aliases' => []],
            ['number' => 102, 'name' => 'Two', 'category' => 'Test', 'aliases' => []],
        ], ['channel_count' => 2]);
        $loader = new ParsedSourceLoader($cache);

        $entries = $loader->entries($payload, $context, [101 => 'guide.one']);

        $this->assertSame('guide.one', $entries[0]['stream_id']);
        $this->assertTrue($entries[0]['guide_valid']);
        $this->assertFalse($entries[1]['guide_valid']);
        $this->assertSame([false, false], array_column($loader->entries($payload, $context, []), 'guide_valid'));
    }

    public function test_profile_source_refresh_skips_native_profiles_and_persists_result_rows(): void
    {
        Storage::fake('local');
        [$context] = $this->context(applyPluginSchema: true);
        $now = now();
        $cacheConfig = $this->cacheConfig([
            'provider' => 'sky',
            'provider_source' => 'viewersvault',
            'region' => '1',
        ]);
        (new SourceCacheService)->writeLastGood($cacheConfig, [[
            'number' => 101,
            'name' => 'Cached One',
            'category' => 'UK| TEST',
            'aliases' => [],
        ]], [
            'provider' => 'sky',
            'provider_source' => 'viewersvault',
            'region' => '1',
            'fetched_at' => $now->toIso8601String(),
            'channel_count' => 1,
        ]);

        DB::table(ProfileRepository::PROFILES_TABLE)->insert([
            [
                'extension_plugin_id' => $context->plugin->id,
                'name' => 'Scraper Source',
                'enabled' => true,
                'settings' => json_encode([
                    'min_channels' => 1,
                    'source_mode' => 'scraper_cache',
                    'provider' => 'sky',
                    'provider_source' => 'viewersvault',
                    'region' => '1',
                    'source_cache_days' => 2,
                ], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'extension_plugin_id' => $context->plugin->id,
                'name' => 'Native Source',
                'enabled' => true,
                'settings' => json_encode([
                    'source_mode' => 'native_playlist',
                    'source_playlist_id' => $context->run->user_id,
                ], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $result = (new RefreshSourceCacheAction)->handle(['profile_sources' => true], $context);

        $this->assertTrue($result->success);
        $this->assertCount(1, $result->data['profiles']);
        $this->assertSame('Scraper Source', $result->data['profiles'][0]['profile_name']);
        $this->assertDatabaseHas(RunResultRepository::TABLE, [
            'extension_plugin_run_id' => $context->run->id,
            'result_type' => 'source_refresh',
            'decision' => 'cached',
            'title' => 'Scraper Source',
            'source_name' => 'uk/sky/viewersvault/1',
        ]);

        $row = DB::table(RunResultRepository::TABLE)
            ->where('extension_plugin_run_id', $context->run->id)
            ->where('result_type', 'source_refresh')
            ->first();
        $details = json_decode((string) $row->details, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('uk', $details['country']);
        $this->assertSame('sky', $details['provider']);
        $this->assertSame('viewersvault', $details['provider_source']);
        $this->assertSame('1', $details['region']);
    }

    public function test_native_source_combines_equivalent_duplicates_and_rejects_conflicting_services(): void
    {
        [$context, $playlist] = $this->context();
        foreach (['BBC One HD', 'BBC One SD'] as $title) {
            $channel = Channel::factory()->createQuietly([
                'playlist_id' => $playlist->id, 'user_id' => $context->user->id, 'group_id' => null,
                'enabled' => true, 'is_vod' => false, 'channel' => 101, 'title' => $title, 'name' => $title,
            ]);
        }
        $loader = new ParsedSourceLoader(new SourceCacheService);
        $payload = ['source_mode' => 'native_playlist', 'source_playlist_id' => $playlist->id];
        $entries = $loader->entries($payload, $context, [101 => 'bbc.one']);
        $this->assertCount(1, $entries);
        $this->assertContains('BBC One SD', $entries[0]['aliases']);
        $channel->forceFill(['title' => 'BBC Two', 'name' => 'BBC Two'])->saveQuietly();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('conflicting channels at number [101]');
        $loader->entries($payload, $context, [101 => 'bbc.one']);
    }
}
