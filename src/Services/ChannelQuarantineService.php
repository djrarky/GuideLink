<?php

namespace AppLocalPlugins\GuideLink\Services;

use App\Models\Channel;
use App\Models\Playlist;
use App\Plugins\Support\PluginExecutionContext;
use AppLocalPlugins\GuideLink\Support\ChannelAttributes;
use AppLocalPlugins\GuideLink\Support\MatchDecision;
use AppLocalPlugins\GuideLink\Support\RunCancelled;
use AppLocalPlugins\GuideLink\Support\RunProgress;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @phpstan-import-type MatchRow from ChannelMatcher
 * @phpstan-import-type ExcludedRow from ScopeFilter
 * @phpstan-import-type WritePlanRow from WritePlanBuilder
 */
class ChannelQuarantineService
{
    public const TABLE = 'plugin_guidelink_quarantines';

    /**
     * Plan from matching decisions, never from whether metadata happened to change.
     *
     * @param  Collection<int, Channel>  $channels
     * @param  list<MatchRow|ExcludedRow>  $decisions
     * @return array<int, array<string, mixed>>
     */
    public function plan(Playlist $playlist, Collection $channels, array $decisions, bool $enabled, PluginExecutionContext $context): array
    {
        if (! Schema::hasTable(self::TABLE)) {
            throw new \RuntimeException('GuideLink quarantine schema is missing. Approve and trust the updated plugin through the host install flow before syncing.');
        }

        $states = DB::table(self::TABLE)
            ->where('extension_plugin_id', $context->plugin->id)
            ->where('playlist_id', $playlist->id)
            ->get()->keyBy('channel_id');
        $channels = $channels->keyBy('id');
        $linked = [];
        if ($enabled && $states->isNotEmpty()) {
            foreach ($this->failoverLinks($playlist, $states->keys()->all())->get(['channel_id', 'channel_failover_id']) as $link) {
                $linked[$link->channel_id] = $linked[$link->channel_failover_id] = true;
            }
        }
        $rows = [];

        foreach ($decisions as $decision) {
            $channel = $channels->get($decision['channel_id']);
            if (! $channel || MatchDecision::isPreserved($decision)) {
                continue;
            }

            $state = $states->get($channel->id);
            $quarantine = $enabled && MatchDecision::shouldQuarantine($decision);
            $release = $state && (! $enabled || MatchDecision::isConfirmed($decision['decision']));
            if ((! $quarantine || ($state && ! $channel->enabled && ! $channel->can_merge && ! isset($linked[$channel->id]))) && ! $release) {
                continue;
            }

            $rows[] = [
                'channel_id' => (int) $channel->id,
                'channel_uuid' => $channel->uuid,
                'state_id' => $state?->id,
                'decision_state' => ChannelAttributes::decisionState($channel),
                'action' => $quarantine ? 'quarantine' : 'release',
                'reason' => ($decision['exclusion_reason'] ?? '') ?: $decision['decision'],
                'current' => ['enabled' => (bool) $channel->enabled, 'can_merge' => (bool) $channel->can_merge],
                'desired' => [
                    'enabled' => $quarantine ? false : (bool) $channel->enabled,
                    'can_merge' => $quarantine ? false : ((bool) $channel->can_merge || (bool) $state->original_can_merge),
                ],
            ];
        }

        return $rows;
    }

    /** Release temporary merge restrictions before their ownership records are purged. */
    public function releaseForPurge(int $pluginId): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }
        DB::table(self::TABLE)->where('extension_plugin_id', $pluginId)->orderBy('id')->chunkById(250, function ($states) use ($pluginId): void {
            foreach ($states as $snapshot) {
                DB::transaction(function () use ($snapshot, $pluginId): void {
                    $channel = Channel::query()->where('playlist_id', $snapshot->playlist_id)
                        ->whereKey($snapshot->channel_id)->lockForUpdate()->first();
                    $query = DB::table(self::TABLE)->where('extension_plugin_id', $pluginId)->where('id', $snapshot->id);
                    $state = (clone $query)->lockForUpdate()->first();
                    if (! $state) {
                        return;
                    }
                    if ($channel && ! $channel->can_merge && (bool) $state->original_can_merge) {
                        $channel->forceFill(['can_merge' => true])->save();
                    }
                    $query->delete();
                });
            }
        });
    }

    /**
     * Keep the merge-policy snapshot and native write atomic for each channel.
     *
     * @param  array<int, array<string, mixed>>  $plan
     * @param  list<WritePlanRow>  $writePlan  Successful metadata plans used to account for this run's earlier writes.
     * @return array<string, mixed>
     */
    public function apply(Playlist $playlist, array $plan, PluginExecutionContext $context, array $writePlan = []): array
    {
        $writes = array_column($writePlan, null, 'channel_id');
        $result = ['disabled' => 0, 'quarantined' => 0, 'released' => 0, 'failover_links_removed' => 0, 'skipped' => 0, 'failed' => 0, 'aborted' => false, 'errors' => []];
        foreach ($plan as $index => $row) {
            if ($context->cancellationRequested()) {
                $result['aborted'] = true;

                break;
            }
            if (RunProgress::shouldHeartbeat($index, count($plan))) {
                $context->heartbeat('Applying channel quarantine policy: '.($index + 1).' of '.count($plan).'.');
            }

            try {
                $outcome = DB::transaction(function () use ($playlist, $row, $context, $writes): array {
                    $channel = Channel::query()->where('playlist_id', $playlist->id)
                        ->where('user_id', $playlist->user_id)->where('is_vod', false)
                        ->where('uuid', $row['channel_uuid'])
                        ->with(['group' => fn ($query) => $query->withTrashed()])
                        ->whereKey($row['channel_id'])->lockForUpdate()->firstOrFail();
                    RunProgress::checkCancellation($context);
                    $stateQuery = DB::table(self::TABLE)
                        ->where('extension_plugin_id', $context->plugin->id)
                        ->where('playlist_id', $playlist->id)
                        ->where('channel_id', $channel->id);
                    $state = (clone $stateQuery)->lockForUpdate()->first();
                    $disabled = false;
                    $linksRemoved = 0;

                    if ($row['action'] === 'release' && ! $state) {
                        return ['disabled' => false, 'skipped' => true, 'links_removed' => 0];
                    }
                    if ($row['state_id'] !== null && $row['state_id'] !== $state?->id) {
                        throw new \RuntimeException("Channel [{$channel->id}] quarantine changed after planning; rerun sync.");
                    }
                    ChannelAttributes::assertDecisionUnchanged($channel, $row['decision_state'], $writes[$channel->id] ?? []);

                    if ($row['action'] === 'quarantine') {
                        // Disabled backups remain playable through existing links.
                        // Remove only this quarantined channel's relationships;
                        // a later confirmed match rebuilds valid native failovers.
                        $linksRemoved = $this->failoverLinks($playlist, [(int) $channel->id])->delete();
                        if ($state && ! $channel->enabled && ! $channel->can_merge && $linksRemoved === 0) {
                            return ['disabled' => false, 'skipped' => true, 'links_removed' => 0];
                        }
                        if (! $state) {
                            DB::table(self::TABLE)->insert([
                                'extension_plugin_id' => $context->plugin->id,
                                'playlist_id' => $playlist->id,
                                'channel_id' => $channel->id,
                                'original_can_merge' => (bool) $channel->can_merge,
                                'created_at' => now(), 'updated_at' => now(),
                            ]);
                        }
                        $disabled = (bool) $channel->enabled;
                        $channel->forceFill(['enabled' => false, 'can_merge' => false]);
                    } elseif ($state) {
                        if (! $channel->can_merge) {
                            $channel->forceFill(['can_merge' => (bool) $state->original_can_merge]);
                        }
                        $stateQuery->delete();
                    }
                    if ($channel->isDirty()) {
                        $channel->save();
                    }

                    return ['disabled' => $disabled, 'skipped' => false, 'links_removed' => $linksRemoved];
                });
                $result['disabled'] += (int) $outcome['disabled'];
                $result['failover_links_removed'] += $outcome['links_removed'];
                $result[$outcome['skipped'] ? 'skipped' : ($row['action'] === 'quarantine' ? 'quarantined' : 'released')]++;
            } catch (RunCancelled) {
                $result['aborted'] = true;
                break;
            } catch (\Throwable $exception) {
                $result['failed']++;
                $result['errors'][] = $exception->getMessage();
            }
        }

        return $result;
    }

    /** @param array<int, int> $channelIds */
    private function failoverLinks(Playlist $playlist, array $channelIds): Builder
    {
        return DB::table('channel_failovers')->where('user_id', $playlist->user_id)
            ->where(fn (Builder $query) => $query->whereIntegerInRaw('channel_id', $channelIds)
                ->orWhereIntegerInRaw('channel_failover_id', $channelIds));
    }
}
