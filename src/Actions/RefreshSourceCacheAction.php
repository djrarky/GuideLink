<?php

namespace AppLocalPlugins\GuideLink\Actions;

use App\Plugins\Support\PluginActionResult;
use App\Plugins\Support\PluginExecutionContext;
use AppLocalPlugins\GuideLink\Services\ProfileRepository;
use AppLocalPlugins\GuideLink\Services\RunResultRepository;
use AppLocalPlugins\GuideLink\Services\SourceRefreshService;
use AppLocalPlugins\GuideLink\Support\Options;
use AppLocalPlugins\GuideLink\Support\RunProgress;
use AppLocalPlugins\GuideLink\Support\SourceSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RefreshSourceCacheAction extends GuideLinkAction
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload, PluginExecutionContext $context): PluginActionResult
    {
        $cache = $this->services->sourceCache();
        $refresh = $this->services->sourceRefresh(cache: $cache);
        $runResults = $this->services->runResults();

        $context->checkpoint(5, 'Preparing source cache refresh.', log: true);

        if (Options::bool($payload, [], 'profile_sources', true)) {
            return $this->handleProfiles($payload, $context, $refresh);
        }

        $result = $refresh->refresh($payload, $context);
        $summary = $result['summary'];

        $context->checkpoint(85, 'Finalizing source cache refresh.', [
            'channel_count' => $summary['source']['channel_count'] ?? 0,
            'stale_fallback' => $summary['source']['stale_fallback'] ?? false,
        ]);

        $resultDetails = $this->persistRows(
            [$this->sourceRefreshRow($payload, $summary)],
            $payload,
            $context,
            $runResults,
        );

        $context->checkpoint(100, 'Source cache refresh completed.', [
            'source' => $summary['source'],
            'run_results' => $resultDetails,
        ], log: true);

        return PluginActionResult::success(
            "Source cache refreshed: {$summary['source']['channel_count']} channel(s).",
            [
                ...$summary,
                'totals' => [
                    'channels' => (int) ($summary['source']['channel_count'] ?? 0),
                    'from_cache' => (bool) ($summary['source']['from_cache'] ?? false),
                    'stale_fallback' => (bool) ($summary['source']['stale_fallback'] ?? false),
                ],
                'run_results' => $resultDetails,
                'progress' => 100,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleProfiles(
        array $payload,
        PluginExecutionContext $context,
        SourceRefreshService $refresh,
    ): PluginActionResult {
        $profilePayloads = $this->services->profileRepository()->sourceRefreshPayloads($payload, $context);
        if ($profilePayloads === []) {
            return PluginActionResult::success('No enabled scraper-cache profiles require source refresh.', [
                'profiles' => [],
                'progress' => 100,
            ]);
        }

        $rows = [];
        $resultRows = [];
        foreach ($profilePayloads as $index => $profilePayload) {
            if ($context->cancellationRequested()) {
                return PluginActionResult::cancelled('Source cache refresh cancelled.', [
                    'profiles' => $rows,
                    'progress' => RunProgress::batchPercentage($index, count($profilePayloads)),
                ]);
            }

            $context->checkpoint(
                RunProgress::batchPercentage($index, count($profilePayloads)),
                'Refreshing source cache profile '.($index + 1).' of '.count($profilePayloads).'.',
                [
                    'profile_id' => $profilePayload['profile_id'] ?? null,
                    'profile_name' => $profilePayload['profile_name'] ?? null,
                ],
                log: true,
            );

            $result = $refresh->refresh($profilePayload, $context);
            $rows[] = [
                'profile_id' => $profilePayload['profile_id'] ?? null,
                'profile_name' => $profilePayload['profile_name'] ?? null,
                'summary' => $result['summary'],
            ];
            $resultRows[] = $this->sourceRefreshRow($profilePayload, $result['summary']);
        }

        $resultDetails = $this->persistRows($resultRows, $payload, $context, $this->services->runResults());

        return PluginActionResult::success('Source cache refresh completed for '.count($rows).' profile(s).', [
            'profiles' => $rows,
            'run_results' => $resultDetails,
            'progress' => 100,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function persistRows(
        array $rows,
        array $payload,
        PluginExecutionContext $context,
        RunResultRepository $runResults,
    ): ?array {
        $playlistId = $this->resultPlaylistId($payload, $context);
        if ($playlistId === null || ! Schema::hasTable(RunResultRepository::TABLE)) {
            return null;
        }

        return $runResults->replaceRows($context, $playlistId, $rows, 'source_refresh');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function sourceRefreshRow(array $payload, array $summary): array
    {
        $source = is_array($summary['source'] ?? null) ? $summary['source'] : [];
        $staleFallback = (bool) ($source['stale_fallback'] ?? false);
        $fromCache = (bool) ($source['from_cache'] ?? false);
        $identity = SourceSettings::identity([
            'country' => $source['country'] ?? $payload['country'] ?? null,
            'provider' => $source['provider'] ?? $payload['provider'] ?? null,
            'provider_source' => $source['provider_source'] ?? $payload['provider_source'] ?? null,
            'region' => $source['region'] ?? $payload['region'] ?? null,
        ]);

        return [
            'status' => match (true) {
                $staleFallback => 'stale_fallback',
                $fromCache => 'cached',
                default => 'refreshed',
            },
            'title' => (string) ($payload['profile_name'] ?? 'Manual source refresh'),
            'source_name' => "{$identity['country']}/{$identity['provider']}/{$identity['provider_source']}/{$identity['region']}",
            'recommendation' => $staleFallback
                ? 'Refresh failed and GuideLink used the parsed last-good cache.'
                : 'Source cache is ready for sync and availability checks.',
            'profile_id' => $payload['profile_id'] ?? null,
            'profile_name' => $payload['profile_name'] ?? null,
            'country' => $identity['country'],
            'provider' => $identity['provider'],
            'provider_source' => $identity['provider_source'],
            'region' => $identity['region'],
            'channel_count' => (int) ($source['channel_count'] ?? 0),
            'from_cache' => $fromCache,
            'stale_fallback' => $staleFallback,
            'last_good_cache_path' => $source['last_good_cache_path'] ?? null,
            'fetched_at' => $source['fetched_at'] ?? null,
            'refresh_error' => $source['refresh_error'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resultPlaylistId(array $payload, PluginExecutionContext $context): ?int
    {
        $playlistId = Options::nullableInt($payload['playlist_id'] ?? $payload['target_playlist_id'] ?? null);
        if ($playlistId !== null && $this->playlistAvailable($playlistId, $context)) {
            return $playlistId;
        }

        $profileId = Options::nullableInt($payload['profile_id'] ?? null);
        if ($profileId !== null && Schema::hasTable(ProfileRepository::ASSIGNMENTS_TABLE)) {
            $assignedPlaylistId = DB::table(ProfileRepository::ASSIGNMENTS_TABLE)
                ->where('extension_plugin_id', $context->plugin->id)
                ->where('extension_plugin_profile_id', $profileId)
                ->where('enabled', true)
                ->orderBy('playlist_id')
                ->value('playlist_id');

            if ($assignedPlaylistId !== null && $this->playlistAvailable((int) $assignedPlaylistId, $context)) {
                return (int) $assignedPlaylistId;
            }
        }

        $playlist = $this->services->playlistResolver()->availableTo($context)->orderBy('id')->first(['id']);

        return $playlist ? (int) $playlist->id : null;
    }

    private function playlistAvailable(int $playlistId, PluginExecutionContext $context): bool
    {
        return $this->services->playlistResolver()->availableTo($context)->whereKey($playlistId)->exists();
    }
}
