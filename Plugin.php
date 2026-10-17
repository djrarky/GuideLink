<?php

namespace AppLocalPlugins\GuideLink;

use App\Plugins\Contracts\ChannelProcessorPluginInterface;
use App\Plugins\Contracts\HookablePluginInterface;
use App\Plugins\Contracts\LifecyclePluginInterface;
use App\Plugins\Contracts\PluginInterface;
use App\Plugins\Contracts\PluginSelectOptionsProviderInterface;
use App\Plugins\Contracts\ScheduledPluginInterface;
use App\Plugins\Support\PluginActionResult;
use App\Plugins\Support\PluginExecutionContext;
use App\Plugins\Support\PluginSelectOptionsContext;
use App\Plugins\Support\PluginUninstallContext;
use AppLocalPlugins\GuideLink\Actions\AvailabilityReportAction;
use AppLocalPlugins\GuideLink\Actions\HealthCheckAction;
use AppLocalPlugins\GuideLink\Actions\NativeSetupAction;
use AppLocalPlugins\GuideLink\Actions\PreviewAliasSetAction;
use AppLocalPlugins\GuideLink\Actions\RebuildFailoversAction;
use AppLocalPlugins\GuideLink\Actions\RefreshSourceCacheAction;
use AppLocalPlugins\GuideLink\Actions\RepairAvailabilityAction;
use AppLocalPlugins\GuideLink\Actions\SetupChecklistAction;
use AppLocalPlugins\GuideLink\Actions\SyncPlaylistAction;
use AppLocalPlugins\GuideLink\Services\ChannelQuarantineService;
use AppLocalPlugins\GuideLink\Services\NativeAlertService;
use AppLocalPlugins\GuideLink\Services\ProfileRepository;
use AppLocalPlugins\GuideLink\Support\DynamicOptionProviders;
use AppLocalPlugins\GuideLink\Support\Options;
use AppLocalPlugins\GuideLink\Support\PlaylistOperationFailed;
use AppLocalPlugins\GuideLink\Support\RunCancelled;
use AppLocalPlugins\GuideLink\Support\RunProgress;
use AppLocalPlugins\GuideLink\Support\SourceRefreshFailed;
use Carbon\CarbonInterface;
use Cron\CronExpression;
use Throwable;

class Plugin implements ChannelProcessorPluginInterface, HookablePluginInterface, LifecyclePluginInterface, PluginInterface, PluginSelectOptionsProviderInterface, ScheduledPluginInterface
{
    private static bool $autoloadRegistered = false;

    public function __construct()
    {
        self::registerAutoloader();
    }

    /**
     * Handle manual plugin actions queued by m3u-editor.
     *
     * @param  array<string, mixed>  $payload
     */
    public function runAction(string $action, array $payload, PluginExecutionContext $context): PluginActionResult
    {
        $result = $this->executeAction($action, $payload, $context);
        (new NativeAlertService)->recordResult($action, $payload, $result, $context);

        return $result;
    }

    private function executeAction(string $action, array $payload, PluginExecutionContext $context): PluginActionResult
    {
        try {
            RunProgress::checkCancellation($context);
            $result = match ($action) {
                'preview_alias_set' => (new PreviewAliasSetAction)->handle($payload, $context),
                'health_check' => (new HealthCheckAction)->handle($payload, $context),
                'setup_checklist' => (new SetupChecklistAction)->handle($payload, $context),
                'audit_auto_merge_setup' => (new NativeSetupAction('auto_merge', 'audit'))->handle($payload, $context),
                'apply_auto_merge_setup' => (new NativeSetupAction('auto_merge', 'apply'))->handle($payload, $context),
                'rebuild_failovers' => (new RebuildFailoversAction)->handle($payload, $context),
                'audit_scrubber_setup' => (new NativeSetupAction('scrubber', 'audit'))->handle($payload, $context),
                'apply_scrubber_setup' => (new NativeSetupAction('scrubber', 'apply'))->handle($payload, $context),
                'availability_report' => (new AvailabilityReportAction)->handle($payload, $context),
                'sync_playlist', 'apply_sync_playlist' => (new SyncPlaylistAction)->handle($payload, $context),
                'repair_availability', 'apply_repair_availability' => (new RepairAvailabilityAction)->handle($payload, $context),
                'refresh_source_cache' => (new RefreshSourceCacheAction)->handle($payload, $context),
                default => PluginActionResult::failure("Unsupported action [{$action}]"),
            };

            return $context->cancellationRequested() && $result->status !== 'cancelled'
                ? PluginActionResult::cancelled('GuideLink run cancelled; completed changes and results have been retained.', $result->data)
                : $result;
        } catch (RunCancelled $exception) {
            return PluginActionResult::cancelled($exception->getMessage());
        } catch (Throwable $exception) {
            $context->error('Plugin action failed.', [
                'action' => $action,
                'error' => $exception->getMessage(),
            ]);

            return PluginActionResult::failure($exception->getMessage(), [
                'playlist_id' => $exception instanceof PlaylistOperationFailed ? $exception->playlistId : null,
                'source_failure' => $exception instanceof SourceRefreshFailed
                    || ($exception instanceof PlaylistOperationFailed && $exception->sourceFailure),
            ]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function selectOptions(string $provider, PluginSelectOptionsContext $context): array
    {
        return DynamicOptionProviders::options($provider, $context);
    }

    /**
     * Handle host lifecycle hooks.
     *
     * @param  array<string, mixed>  $payload
     */
    public function runHook(string $hook, array $payload, PluginExecutionContext $context): PluginActionResult
    {
        $result = $this->executeHook($hook, $payload, $context);
        (new NativeAlertService)->recordResult($hook, $payload, $result, $context);

        return $result;
    }

    private function executeHook(string $hook, array $payload, PluginExecutionContext $context): PluginActionResult
    {
        try {
            RunProgress::checkCancellation($context);
            if ($hook !== 'playlist.synced') {
                return PluginActionResult::success("Hook [{$hook}] received but no work is configured.", [
                    'hook' => $hook,
                ]);
            }

            if (($payload['playlist_id'] ?? null) === null) {
                $context->info('Playlist sync hook skipped because no playlist id was supplied.', [
                    'hook' => $hook,
                ]);

                return PluginActionResult::success('Playlist sync hook skipped.', [
                    'hook' => $hook,
                    'skipped' => true,
                ]);
            }

            if (! (new ProfileRepository)->allowsAvailabilityHook((int) $payload['playlist_id'], $context)) {
                $context->info('Playlist sync hook skipped because automatic availability is not enabled for this assignment.');

                return PluginActionResult::success('Playlist sync hook skipped.', [
                    'hook' => $hook, 'playlist_id' => $payload['playlist_id'], 'skipped' => true,
                ]);
            }

            try {
                return (new AvailabilityReportAction)->handle($payload, $context);
            } catch (\RuntimeException $exception) {
                if (! str_contains($exception->getMessage(), 'GuideLink profile')
                    && ! str_contains($exception->getMessage(), 'GuideLink is disabled')) {
                    throw $exception;
                }

                $context->info('Playlist sync hook skipped because the playlist has no enabled GuideLink profile assignment.', [
                    'hook' => $hook,
                    'playlist_id' => $payload['playlist_id'],
                    'reason' => $exception->getMessage(),
                ]);

                return PluginActionResult::success('Playlist sync hook skipped.', [
                    'hook' => $hook,
                    'playlist_id' => $payload['playlist_id'],
                    'skipped' => true,
                ]);
            }
        } catch (RunCancelled $exception) {
            return PluginActionResult::cancelled($exception->getMessage());
        } catch (Throwable $exception) {
            $context->error('Plugin hook failed.', [
                'hook' => $hook,
                'error' => $exception->getMessage(),
            ]);

            return PluginActionResult::failure($exception->getMessage(), [
                'playlist_id' => $exception instanceof PlaylistOperationFailed ? $exception->playlistId : null,
            ]);
        }
    }

    /**
     * Return scheduled invocations due at the current minute.
     *
     * @param  array<string, mixed>  $settings
     * @return array<int, array<string, mixed>>
     */
    public function scheduledActions(CarbonInterface $now, array $settings): array
    {
        $actions = [];
        if ($this->scheduleDue($settings, 'schedule_enabled', 'schedule_cron', $now)) {
            $actions[] = [
                'type' => 'action',
                'name' => 'availability_report',
                'payload' => [
                    'assigned_playlists' => true,
                ],
                'dry_run' => true,
            ];
        }

        if ($this->scheduleDue($settings, 'sync_schedule_enabled', 'sync_cron', $now)) {
            $apply = Options::string([], $settings, 'sync_schedule_mode', 'preview') === 'apply';
            $actions[] = [
                'type' => 'action',
                'name' => $apply ? 'apply_sync_playlist' : 'sync_playlist',
                'payload' => [
                    'assigned_playlists' => true,
                ],
                'dry_run' => ! $apply,
            ];
        }

        if ($this->scheduleDue($settings, 'source_refresh_schedule_enabled', 'source_refresh_cron', $now)) {
            $actions[] = [
                'type' => 'action',
                'name' => 'refresh_source_cache',
                'payload' => [
                    'profile_sources' => true,
                    'force_refresh' => false,
                ],
                'dry_run' => false,
            ];
        }

        return $actions;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function scheduleDue(array $settings, string $enabledKey, string $cronKey, CarbonInterface $now): bool
    {
        $cron = (string) ($settings[$cronKey] ?? '');

        return Options::bool([], $settings, $enabledKey, false)
            && $cron !== ''
            && CronExpression::isValidExpression($cron)
            && (new CronExpression($cron))->isDue($now);
    }

    public function uninstall(PluginUninstallContext $context): void
    {
        if ($context->shouldPurge()) {
            (new ChannelQuarantineService)->releaseForPurge((int) $context->plugin->id);
        }
    }

    private static function registerAutoloader(): void
    {
        if (self::$autoloadRegistered) {
            return;
        }

        spl_autoload_register(function (string $class): void {
            $prefix = __NAMESPACE__.'\\';
            if (! str_starts_with($class, $prefix)) {
                return;
            }

            $relativeClass = substr($class, strlen($prefix));
            $path = __DIR__.'/src/'.str_replace('\\', '/', $relativeClass).'.php';
            if (is_file($path)) {
                require_once $path;
            }
        });

        self::$autoloadRegistered = true;
    }
}
