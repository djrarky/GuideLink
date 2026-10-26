<?php

namespace AppLocalPlugins\GuideLink\Services;

use App\Listeners\SyncListener;
use App\Models\Channel;
use App\Models\Playlist;
use App\Plugins\Support\PluginExecutionContext;
use AppLocalPlugins\GuideLink\Support\ChannelAttributes;
use AppLocalPlugins\GuideLink\Support\MatchDecision;
use AppLocalPlugins\GuideLink\Support\RunCancelled;
use AppLocalPlugins\GuideLink\Support\RunProgress;
use Illuminate\Support\Facades\DB;

/**
 * @phpstan-import-type MatchRow from ChannelMatcher
 * @phpstan-import-type WritePlanRow from WritePlanBuilder
 */
class MatchedChannelRecoveryService
{
    /**
     * Recheck matches after metadata and quarantine succeed. Merge eligibility,
     * visible primaries and native failovers commit together, without temporarily
     * enabling every backup. Failed recovery leaves this entire stage unchanged.
     *
     * @param  list<MatchRow>  $matches
     * @param  list<WritePlanRow>  $writePlan
     * @return array<string, mixed>
     */
    public function apply(Playlist $playlist, array $matches, array $writePlan, PluginExecutionContext $context): array
    {
        $matches = array_column(array_filter($matches, fn (array $row): bool => MatchDecision::isConfirmed($row['decision'])), null, 'channel_id');
        $result = ['candidates' => count($matches), 'merge_restored' => 0, 'enabled' => 0, 'disabled' => 0, 'failed' => 0, 'aborted' => false, 'errors' => []];
        if ($matches === [] || $context->dryRun) {
            return $result;
        }
        $writes = array_column($writePlan, null, 'channel_id');

        try {
            $context->heartbeat('Selecting primaries and backups for confirmed matches.');
            $changes = DB::transaction(function () use ($playlist, $matches, $writes, $context): array {
                RunProgress::checkCancellation($context);
                $channels = Channel::query()->where('playlist_id', $playlist->id)
                    ->where('user_id', $playlist->user_id)->where('is_vod', false)
                    ->whereIn('id', array_keys($matches))->orderBy('id')
                    ->with(['group' => fn ($query) => $query->withTrashed()])
                    ->lockForUpdate()->get(ChannelAttributes::readColumns());
                if ($channels->count() !== count($matches)) {
                    throw new \RuntimeException('Matched channels changed playlists or disappeared after planning; rerun sync.');
                }
                $before = [];
                foreach ($channels as $channel) {
                    $match = $matches[$channel->id];
                    if ($channel->uuid !== $match['channel_uuid']) {
                        throw new \RuntimeException("Channel [{$channel->id}] UUID changed after planning; rerun sync.");
                    }
                    ChannelAttributes::assertDecisionUnchanged($channel, $match['_decision_state'], $writes[$channel->id] ?? []);
                    $before[$channel->id] = ['enabled' => (bool) $channel->enabled, 'can_merge' => (bool) $channel->can_merge];
                }
                $native = SyncListener::getMergeJob($playlist->fresh());
                if (! $native) {
                    throw new \RuntimeException('Native auto-merge is unavailable; matched channels could not be recovered.');
                }
                Channel::withoutEvents(function () use ($channels, $native, $playlist, $context): void {
                    foreach ($channels as $channel) {
                        if (! $channel->can_merge) {
                            $channel->update(['can_merge' => true]);
                        }
                    }
                    (new MatchedChannelMerge($native, (int) $playlist->id, $channels, $context))->handle();
                });
                RunProgress::checkCancellation($context);
                $changes = ['merge_restored' => 0, 'enabled' => 0, 'disabled' => 0];
                foreach (Channel::whereIn('id', array_keys($matches))->get(['id', 'enabled', 'can_merge']) as $channel) {
                    $original = $before[$channel->id];
                    $changes['merge_restored'] += (int) (! $original['can_merge'] && $channel->can_merge);
                    $changes['enabled'] += (int) (! $original['enabled'] && $channel->enabled);
                    $changes['disabled'] += (int) ($original['enabled'] && ! $channel->enabled);
                }

                return $changes;
            });

            return [...$result, ...$changes];
        } catch (RunCancelled) {
            return [...$result, 'aborted' => true];
        } catch (\Throwable $exception) {
            return [...$result, 'failed' => 1, 'errors' => [$exception->getMessage()]];
        }
    }
}
