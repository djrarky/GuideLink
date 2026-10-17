<?php

namespace AppLocalPlugins\GuideLink\Tests\Feature\Persistence;

require_once dirname(__DIR__).'/PluginFeatureTestCase.php';

use App\Models\Channel;
use App\Models\Group;
use App\Models\Playlist;
use App\Plugins\Support\PluginExecutionContext;
use AppLocalPlugins\GuideLink\Actions\SetupChecklistAction;
use AppLocalPlugins\GuideLink\Services\ProfileRepository;
use AppLocalPlugins\GuideLink\Services\RunResultRepository;
use AppLocalPlugins\GuideLink\Tests\Feature\PluginFeatureTestCase;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class RunResultPersistenceTest extends PluginFeatureTestCase
{
    use RefreshDatabase;

    public function test_large_result_replacements_keep_chunk_boundaries_and_other_result_types(): void
    {
        [$context, $playlist] = $this->context(applyPluginSchema: true);
        $repository = new RunResultRepository;
        $repository->replaceRows($context, $playlist->id, [['status' => 'ready', 'title' => 'Keep me']], 'setup_check');
        $rows = array_map(fn (int $number): array => [
            'status' => 'enabled', 'source_number' => $number, 'title' => 'Channel '.$number,
        ], range(1, 501));

        foreach ([501, 250, 251, 0] as $count) {
            $result = $repository->replaceRows($context, $playlist->id, array_slice($rows, 0, $count), 'availability');
            $this->assertSame($count, $result['rows']);
            $this->assertSame($count > 0 ? ['enabled' => $count] : [], $result['by_decision']);
            $this->assertDatabaseCount(RunResultRepository::TABLE, $count + 1);
            $this->assertSame($count, DB::table(RunResultRepository::TABLE)->where('result_type', 'availability')->distinct()->count('source_number'));
            $this->assertDatabaseHas(RunResultRepository::TABLE, ['result_type' => 'setup_check', 'title' => 'Keep me']);
        }
    }

    public function test_later_chunk_failure_restores_previous_results_atomically(): void
    {
        [$context, $playlist] = $this->context(applyPluginSchema: true);
        $repository = new RunResultRepository;
        $repository->replaceRows($context, $playlist->id, [['status' => 'enabled', 'title' => 'Previous report']], 'availability');
        $rows = array_fill(0, 250, ['status' => 'enabled', 'title' => 'Replacement']);
        $rows[] = ['status' => 'enabled', 'channel_id' => 99999999];

        try {
            $repository->replaceRows($context, $playlist->id, $rows, 'availability');
            $this->fail('Expected the missing channel to reject the second chunk.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('foreign key', strtolower($exception->getMessage()));
        }

        $this->assertDatabaseCount(RunResultRepository::TABLE, 1);
        $this->assertDatabaseHas(RunResultRepository::TABLE, ['title' => 'Previous report']);
    }

    public function test_run_results_are_replaced_idempotently_and_pruned_with_the_host_run(): void
    {
        [$context, $playlist] = $this->context(applyPluginSchema: true);
        $repository = new RunResultRepository;
        $group = Group::factory()->createQuietly([
            'playlist_id' => $playlist->id,
            'user_id' => $playlist->user_id,
            'name' => 'Test Group',
        ]);
        $channel = fn (string $number, string $name): Channel => Channel::factory()->createQuietly([
            'playlist_id' => $playlist->id,
            'user_id' => $playlist->user_id,
            'group_id' => $group->id,
            'channel' => $number,
            'name' => $name,
        ]);
        $matchedChannel = $channel('101', 'Matched IPTV');
        $unknownChannel = $channel('102', 'Unknown IPTV');
        $protectedChannel = $channel('103', 'Protected IPTV');

        $result = $repository->replaceSync(
            $context,
            (int) $playlist->id,
            [
                [
                    'channel_id' => $matchedChannel->id,
                    'channel_number' => '101',
                    'title' => 'Matched IPTV',
                    'decision' => 'auto_apply',
                    'score' => 100,
                    'matched_on' => 'name:matched iptv',
                    'source_number' => 101,
                    'source_name' => 'Canonical Match',
                ],
                [
                    'channel_id' => $unknownChannel->id,
                    'channel_number' => '102',
                    'title' => 'Unknown IPTV',
                    'decision' => 'unmatched',
                    'score' => 0,
                    'source_number' => '',
                    'source_name' => '',
                ],
            ],
            [[
                'channel_id' => $protectedChannel->id,
                'channel_number' => '103',
                'title' => 'Protected IPTV',
                'decision' => 'excluded',
                'exclusion_reason' => 'group_preserved',
            ]],
            [[
                'channel_id' => $matchedChannel->id,
                'changes' => ['title' => ['from' => 'Matched IPTV', 'to' => 'Canonical Match']],
            ]],
        );

        $this->assertSame(3, $result['rows']);
        $this->assertSame(1, $result['by_decision']['unmatched']);
        $this->assertDatabaseHas(RunResultRepository::TABLE, [
            'extension_plugin_run_id' => $context->run->id,
            'decision' => 'auto_apply',
            'source_name' => 'Canonical Match',
        ]);
        $this->assertDatabaseHas(RunResultRepository::TABLE, [
            'extension_plugin_run_id' => $context->run->id,
            'decision' => 'excluded',
            'reason' => 'group_preserved',
        ]);

        $repository->replaceSync($context, (int) $playlist->id, [], [], []);
        $this->assertSame(0, DB::table(RunResultRepository::TABLE)->count());

        $repository->replaceSync($context, (int) $playlist->id, [[
            'channel_id' => $unknownChannel->id,
            'title' => 'Unknown IPTV',
            'decision' => 'unmatched',
        ]], [], []);
        $this->assertSame(1, DB::table(RunResultRepository::TABLE)->count());

        $context->run->delete();
        $this->assertSame(0, DB::table(RunResultRepository::TABLE)->count());
    }

    public function test_setup_checklist_persists_missing_setup_rows(): void
    {
        [$context] = $this->context(applyPluginSchema: true);

        $result = (new SetupChecklistAction)->handle([], $context);

        $this->assertTrue($result->success);
        $this->assertGreaterThan(0, $result->data['setup']['missing']);
        $this->assertDatabaseHas(RunResultRepository::TABLE, [
            'extension_plugin_run_id' => $context->run->id,
            'result_type' => 'setup_check',
            'decision' => 'missing',
        ]);
    }

    public function test_setup_checklist_reports_native_setup_drift_and_readiness_for_assigned_profile(): void
    {
        [$context, $playlist] = $this->context(applyPluginSchema: true, dryRun: false);
        $this->assignProfile($context, $playlist);

        $drift = (new SetupChecklistAction)->handle([], $context);
        $this->assertTrue($drift->success);

        $this->assertDatabaseHas(RunResultRepository::TABLE, [
            'extension_plugin_run_id' => $context->run->id,
            'playlist_id' => $playlist->id,
            'result_type' => 'setup_check',
            'decision' => 'warning',
            'title' => 'Native auto-merge setup: '.$playlist->name,
        ]);
        $this->assertDatabaseHas(RunResultRepository::TABLE, [
            'extension_plugin_run_id' => $context->run->id,
            'playlist_id' => $playlist->id,
            'result_type' => 'setup_check',
            'decision' => 'warning',
            'title' => 'Native scrubber setup: '.$playlist->name,
        ]);

        $this->assertTrue($this->runGuideLinkAction('apply_auto_merge_setup', [
            'playlist_id' => $playlist->id,
        ], $context)->success);
        $this->assertTrue($this->runGuideLinkAction('apply_scrubber_setup', [
            'playlist_id' => $playlist->id,
        ], $context)->success);

        $ready = (new SetupChecklistAction)->handle([], $context);
        $this->assertTrue($ready->success);

        $this->assertDatabaseHas(RunResultRepository::TABLE, [
            'extension_plugin_run_id' => $context->run->id,
            'playlist_id' => $playlist->id,
            'result_type' => 'setup_check',
            'decision' => 'ready',
            'title' => 'Native auto-merge setup: '.$playlist->name,
        ]);
        $this->assertDatabaseHas(RunResultRepository::TABLE, [
            'extension_plugin_run_id' => $context->run->id,
            'playlist_id' => $playlist->id,
            'result_type' => 'setup_check',
            'decision' => 'ready',
            'title' => 'Native scrubber setup: '.$playlist->name,
        ]);
    }

    public function test_setup_checklist_prepares_missing_scraper_cache(): void
    {
        Storage::fake('local');
        Http::fake([
            'https://viewersvault.com/Region_mapping_on_Sky_*' => Http::response($this->regionHtml(), 200),
            'https://viewersvault.com/List_of_channels_on_Sky_*' => Http::response(
                (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/Sources/Countries/UK/SkyUK/Sources/ViewersVault/basic.html'),
                200,
            ),
        ]);

        [$context, $playlist] = $this->context(applyPluginSchema: true, dryRun: false);
        $this->assignScraperProfile($context, $playlist);

        $result = (new SetupChecklistAction)->handle([], $context);

        $this->assertTrue($result->success);
        $this->assertSame(1, $result->data['source_refresh']['attempted']);
        $this->assertSame(1, $result->data['source_refresh']['refreshed']);
        $this->assertSame(0, $result->data['setup']['missing']);
        Storage::disk('local')->assertExists('plugin-data/guidelink/source-cache/parsed/sky_uk_viewersvault_region_1.json');
    }

    private function assignScraperProfile(PluginExecutionContext $context, Playlist $playlist): void
    {
        $now = now();
        $profileId = DB::table(ProfileRepository::PROFILES_TABLE)->insertGetId([
            'extension_plugin_id' => $context->plugin->id,
            'name' => 'Sky scraper cache',
            'enabled' => true,
            'settings' => json_encode([
                'source_mode' => 'scraper_cache',
                'country' => 'uk',
                'provider' => 'sky',
                'provider_source' => 'viewersvault',
                'region' => '1',
                'scraping_api' => 'direct_http',
                'scraping_api_key' => '',
                'scraping_timeout_seconds' => 1,
                'source_cache_days' => 2,
                'min_channels' => 1,
                'max_drop_percent' => 20,
                'max_retries' => 1,
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

    private function regionHtml(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/Regions/viewersvault-index.html');
    }
}
