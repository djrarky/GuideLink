<?php

namespace AppLocalPlugins\GuideLink\Actions;

use App\Plugins\Support\PluginActionResult;
use App\Plugins\Support\PluginExecutionContext;
use AppLocalPlugins\GuideLink\Services\ProfileRepository;
use AppLocalPlugins\GuideLink\Services\RunResultRepository;
use AppLocalPlugins\GuideLink\Support\RunCancelled;
use AppLocalPlugins\GuideLink\Support\RunProgress;
use AppLocalPlugins\GuideLink\Support\SourceSettings;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SetupChecklistAction extends GuideLinkAction
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload, PluginExecutionContext $context): PluginActionResult
    {
        $service = $this->services->setupChecklist();

        $context->checkpoint(10, 'Checking GuideLink setup.', log: true);
        RunProgress::checkCancellation($context);

        $rows = $service->rows($context);
        $summary = $service->summary($rows);
        $sourceRefresh = $this->prepareSourceCaches($payload, $context);
        RunProgress::checkCancellation($context);

        if (($sourceRefresh['attempted'] ?? 0) > 0) {
            $context->checkpoint(55, 'Rechecking setup after source cache preparation.');
            $rows = $service->rows($context);
            $summary = $service->summary($rows);
        }

        if (($summary['missing'] ?? 0) > 0 || ($summary['warning'] ?? 0) > 0) {
            $context->warning('GuideLink setup needs attention.', [
                'setup' => $summary,
                'source_refresh' => $sourceRefresh,
            ]);
        }

        $context->checkpoint(75, 'Saving setup checklist results.');
        RunProgress::checkCancellation($context);

        $runResults = null;
        $playlistId = $this->resultPlaylistId($context);
        if ($playlistId !== null && Schema::hasTable(RunResultRepository::TABLE)) {
            $runResults = $this->services->runResults()->replaceRows($context, $playlistId, $rows, 'setup_check');
        }

        $context->checkpoint(100, 'Setup checklist completed.', [
            'setup' => $summary,
            'source_refresh' => $sourceRefresh,
            'run_results' => $runResults,
        ], log: true);

        return PluginActionResult::success(
            'Setup checklist completed: '.($summary['ready'] ?? 0).' ready, '.($summary['missing'] ?? 0).' missing.',
            [
                'setup' => $summary,
                'source_refresh' => $sourceRefresh,
                'checks' => $rows,
                'run_results' => $runResults,
                'progress' => 100,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{attempted: int, refreshed: int, cached: int, failed: int, profiles: array<int, array<string, mixed>>}
     */
    private function prepareSourceCaches(array $payload, PluginExecutionContext $context): array
    {
        $summary = [
            'attempted' => 0,
            'refreshed' => 0,
            'cached' => 0,
            'failed' => 0,
            'profiles' => [],
        ];

        if (! Schema::hasTable(ProfileRepository::PROFILES_TABLE) || ! Schema::hasTable(ProfileRepository::ASSIGNMENTS_TABLE)) {
            return $summary;
        }

        $cache = $this->services->sourceCache();
        $refresh = $this->services->sourceRefresh(cache: $cache);
        $outcomes = [];

        foreach ($this->services->profileRepository()->sourceRefreshPayloads($payload, $context) as $profilePayload) {
            RunProgress::checkCancellation($context);
            $config = $cache->config($profilePayload, []);
            if ($cache->isFresh($config)) {
                continue;
            }

            $summary['attempted']++;
            $profile = [
                'profile_id' => $profilePayload['profile_id'] ?? null,
                'profile_name' => $profilePayload['profile_name'] ?? null,
                'provider' => $config['provider'],
                'provider_source' => $config['provider_source'],
                'region' => $config['region'],
            ];

            try {
                $context->checkpoint(25, 'Preparing source cache for setup.', $profile, log: true);
                RunProgress::checkCancellation($context);
                $key = hash('sha256', json_encode(SourceSettings::profileCache($profilePayload), JSON_THROW_ON_ERROR));
                if (! array_key_exists($key, $outcomes)) {
                    try {
                        $outcomes[$key] = $refresh->refresh([...$profilePayload, 'force_refresh' => false], $context);
                    } catch (RunCancelled $exception) {
                        throw $exception;
                    } catch (Throwable $exception) {
                        $outcomes[$key] = $exception;
                    }
                }
                if ($outcomes[$key] instanceof Throwable) {
                    throw $outcomes[$key];
                }
                $result = $outcomes[$key];
                $source = is_array($result['summary']['source'] ?? null) ? $result['summary']['source'] : [];
                $fromCache = (bool) ($source['from_cache'] ?? false);

                $summary[$fromCache ? 'cached' : 'refreshed']++;
                $summary['profiles'][] = [
                    ...$profile,
                    'status' => $fromCache ? 'cached' : 'refreshed',
                    'channel_count' => (int) ($source['channel_count'] ?? 0),
                    'last_good_cache_path' => $source['last_good_cache_path'] ?? null,
                    'stale_fallback' => (bool) ($source['stale_fallback'] ?? false),
                ];
            } catch (RunCancelled $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                $summary['failed']++;
                $summary['profiles'][] = [
                    ...$profile,
                    'status' => 'failed',
                    'error' => $exception->getMessage(),
                ];

                $context->warning('Setup checklist could not prepare source cache.', [
                    ...$profile,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $summary;
    }

    private function resultPlaylistId(PluginExecutionContext $context): ?int
    {
        $playlist = $this->services->playlistResolver()->availableTo($context)->orderBy('id')->first(['id']);

        return $playlist ? (int) $playlist->id : null;
    }
}
