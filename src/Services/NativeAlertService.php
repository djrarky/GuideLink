<?php

namespace AppLocalPlugins\GuideLink\Services;

use App\Models\User;
use App\Notifications\Notification;
use App\Plugins\Support\PluginActionResult;
use App\Plugins\Support\PluginExecutionContext;
use App\Services\AlertService;
use AppLocalPlugins\GuideLink\Sources\SourceRegistry;
use AppLocalPlugins\GuideLink\Support\Options;
use AppLocalPlugins\GuideLink\Support\SourceSettings;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Persistent incident suppression with delivery through the host's own channels.
 * Alerting is best effort: it must never turn a successful sync into a failure.
 */
class NativeAlertService
{
    public const TABLE = 'plugin_guidelink_alerts';

    public function recordResult(string $action, array $payload, PluginActionResult $result, PluginExecutionContext $context): void
    {
        $this->safely($context, function () use ($action, $payload, $result, $context): void {
            if ($result->status === 'cancelled' || ($result->data['skipped'] ?? false)) {
                return;
            }

            $selection = Options::bool($payload, [], 'assigned_playlists', false) ? 'assigned' : 'selection';
            $playlistId = Options::nullableInt($payload['playlist_id'] ?? $payload['target_playlist_id'] ?? null);
            if ($playlistId !== null && ! (new PlaylistResolver)->availableTo($context)->whereKey($playlistId)->exists()) {
                $playlistId = null;
            }
            $scope = $playlistId !== null ? 'playlist:'.$playlistId : $selection.':'.($context->user?->id ?? 'scheduled');
            $operation = ($action === 'playlist.synced' ? 'availability_report' : preg_replace('/^apply_/', '', $action))
                .':'.($context->dryRun ? 'preview' : 'apply');
            $label = $this->actionLabel($action).($context->dryRun ? ' (preview/report)' : '');

            if (isset($result->data['playlist_runs'])) {
                foreach ($result->data['playlist_runs'] as $row) {
                    $id = (int) $row['playlist']['id'];
                    $this->operation($operation, 'playlist:'.$id, $label, new PluginActionResult(
                        $row['status'], $row['success'], $row['summary'], $row['data'],
                    ), $context, $id);
                }
                // A completed batch can also resolve an earlier failure to resolve
                // its playlist selection. Individual failures already alerted above.
                if (! $result->success) {
                    return;
                }
            }

            $resolvedId = Options::nullableInt($result->data['playlist']['id'] ?? $result->data['playlist_id'] ?? null);
            if ($playlistId === null && $resolvedId !== null) {
                $this->operation($operation, 'playlist:'.$resolvedId, $label, $result, $context, $resolvedId);
                if (! $result->success) {
                    return;
                }
            }
            $this->operation($operation, $scope, $label, $result, $context, $playlistId);
        });
    }

    public function sourceFailed(array $config, Throwable $error, bool $hasFallback, PluginExecutionContext $context): void
    {
        $this->safely($context, function () use ($config, $error, $hasFallback, $context): void {
            $reason = match (true) {
                preg_match('/quota|monthly.*limit|(?:request|rate)[ -]?limit|credits? exhausted|HTTP[^\r\n]*\b429\b/i', $error->getMessage()) === 1 => 'The provider quota or request limit was reached.',
                preg_match('/HTTP[^\r\n]*\b(401|403)\b|API key|unauthori[sz]ed|forbidden/i', $error->getMessage()) === 1 => 'Check the scraper credentials and provider access.',
                preg_match('/minimum|channel count dropped|parsed only|unsupported/i', $error->getMessage()) === 1 => 'Source configuration or validation failed.',
                default => 'The source could not be fetched, parsed, validated or saved.',
            };
            $key = $this->sourceKey($config);
            $this->incident($key, $hasFallback ? 'warning' : 'danger',
                'GuideLink source refresh failed',
                $this->sourceLabel($config, $key).': '.$reason.' '.($hasFallback
                    ? 'GuideLink is using the last-good cache; channel data may be out of date.'
                    : 'No usable last-good cache is available.'),
                $context);
        });
    }

    /** Call only after a live fetch has been validated and saved successfully. */
    public function sourceRecovered(array $config, PluginExecutionContext $context): void
    {
        $this->safely($context, function () use ($config, $context): void {
            $key = $this->sourceKey($config);
            $this->incident($key, null, 'GuideLink source recovered',
                $this->sourceLabel($config, $key).' was fetched, validated and saved successfully.', $context);
        });
    }

    private function sourceKey(array $config): string
    {
        $identity = SourceSettings::profileCache($config);
        // Cache age does not change a live fetch's health. Fetch and validation
        // policies do: a more permissive profile must not clear another's failure.
        unset($identity['source_cache_days']);

        return hash('sha256', 'source:'.json_encode($identity, JSON_THROW_ON_ERROR));
    }

    private function sourceLabel(array $config, string $key): string
    {
        // Use catalogue labels, never arbitrary payload text or provider URLs.
        $definition = SourceRegistry::definitions()[$config['provider'] ?? ''] ?? [];
        $provider = $definition['label'] ?? 'Unknown provider';
        $source = $definition['sources'][$config['provider_source'] ?? '']['label'] ?? 'unknown source';
        $region = preg_match('/^[0-9]{1,4}$/', (string) ($config['region'] ?? '')) === 1
            ? ', region '.$config['region'] : '';

        return $provider.' / '.$source.$region.' (source '.substr($key, 0, 8).')';
    }

    private function operation(string $operation, string $scope, string $label, PluginActionResult $result, PluginExecutionContext $context, ?int $playlistId = null): void
    {
        if ($result->status === 'cancelled' || ($result->data['source_failure'] ?? false)) {
            return;
        }
        $target = $playlistId === null ? '' : ' for playlist #'.$playlistId;
        $this->incident(hash('sha256', 'operation:'.$operation.':'.$scope), $result->success ? null : 'danger',
            $result->success ? 'GuideLink operation recovered' : 'GuideLink operation failed',
            $label.$target.($result->success
                ? ' completed successfully after an earlier failure.'
                : ' could not complete. Check configuration and write results; any completed changes have been retained.'),
            $context, $playlistId);
    }

    private function actionLabel(string $action): string
    {
        return match ($action) {
            'sync_playlist', 'apply_sync_playlist' => 'Playlist sync',
            'availability_report', 'playlist.synced' => 'Availability report',
            'repair_availability', 'apply_repair_availability' => 'Availability repair',
            'refresh_source_cache' => 'Source cache refresh',
            'audit_auto_merge_setup', 'apply_auto_merge_setup' => 'Auto-merge setup',
            'audit_scrubber_setup', 'apply_scrubber_setup' => 'Scrubber setup',
            'setup_checklist' => 'Setup checklist',
            'rebuild_failovers' => 'Failover rebuild',
            default => 'Plugin action',
        };
    }

    /**
     * Atomically claim first failures, escalations, reminders and recoveries.
     * Only hashes and health state are persisted, never payloads or credentials.
     */
    private function incident(string $key, ?string $severity, string $title, string $body, PluginExecutionContext $context, ?int $playlistId = null): void
    {
        $query = DB::table(self::TABLE)->where('extension_plugin_id', $context->plugin->id)->where('incident_key', $key);
        $reminderHours = max(1, min(168, Options::number([], $context->settings, 'alert_reminder_hours', 24)));
        $claimed = DB::transaction(function () use ($query, $key, $severity, $context, $reminderHours): bool {
            $now = now();
            if ($severity !== null) {
                DB::table(self::TABLE)->insertOrIgnore([
                    'extension_plugin_id' => $context->plugin->id, 'incident_key' => $key,
                    'active' => false, 'severity' => $severity, 'occurrences' => 0,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            $state = (clone $query)->lockForUpdate()->first();
            if ($state === null || ($severity === null && ! $state->active)) {
                return false;
            }
            $notify = $severity === null || ! $state->active
                || ($severity === 'danger' && $state->severity !== 'danger')
                || $state->last_notified_at === null
                || Carbon::parse($state->last_notified_at)->addHours($reminderHours)->lte($now);
            $query->update([
                'active' => $severity !== null,
                'severity' => $severity ?? $state->severity,
                'occurrences' => $severity === null ? $state->occurrences : ($state->active ? $state->occurrences + 1 : 1),
                'last_seen_at' => $now,
                'last_notified_at' => $notify ? $now : $state->last_notified_at,
                'resolved_at' => $severity === null ? $now : null,
                'updated_at' => $now,
            ]);

            return $notify;
        }, attempts: 3);

        if ($claimed) {
            $this->deliver($title, $body, $severity ?? 'success', $context, $playlistId);
        }
    }

    private function deliver(string $title, string $body, string $severity, PluginExecutionContext $context, ?int $playlistId): void
    {
        $body .= ' Review GuideLink run #'.(int) $context->run->id.' in the plugin run history (administrators can view scheduled runs).';
        // Owners receive only incidents for playlists this invocation may access.
        $ownerId = $playlistId === null ? null : (new PlaylistResolver)->availableTo($context)->whereKey($playlistId)->value('user_id');
        $recipientIds = array_filter([$ownerId, $context->user?->id]);
        $users = User::query()->where(function ($query) use ($recipientIds): void {
            $query->where('is_admin', true)->orWhereIn('id', $recipientIds);
        })->cursor();
        foreach ($users as $user) {
            $notification = Notification::make()->title($title)->body($body)->status($severity);
            $this->deliveryAttempt($context, fn () => $notification->sendToDatabase($user));
            $this->deliveryAttempt($context, fn () => $notification->broadcast($user));
        }
        $this->deliveryAttempt($context, function () use ($title, $body): void {
            $alerts = app(AlertService::class);
            if ($alerts->isEnabled()) {
                $alerts->send($title."\n".$body);
            }
        });
    }

    private function safely(PluginExecutionContext $context, callable $callback): void
    {
        try {
            if (! Options::bool([], $context->settings, 'native_alerts_enabled', true)
                || $context->cancellationRequested()) {
                return;
            }
            if (! Schema::hasTable(self::TABLE)) {
                return;
            }
            $callback();
        } catch (Throwable) {
            $this->logFailure($context);
        }
    }

    private function deliveryAttempt(PluginExecutionContext $context, callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable) {
            $this->logFailure($context);
        }
    }

    private function logFailure(PluginExecutionContext $context): void
    {
        try {
            $context->warning('GuideLink could not record or queue a native alert. Check host notification services.');
        } catch (Throwable) {
            // A failing database or logger must not mask the original outcome.
        }
    }
}
