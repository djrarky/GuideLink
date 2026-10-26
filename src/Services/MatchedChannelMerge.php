<?php

namespace AppLocalPlugins\GuideLink\Services;

use App\Jobs\MergeChannels;
use App\Models\Channel;
use App\Plugins\Support\PluginExecutionContext;
use AppLocalPlugins\GuideLink\Support\RunProgress;
use Illuminate\Support\Collection;

/**
 * Native ranking and failover persistence, with eligibility for confirmed matches.
 * Used synchronously by recovery, inside its transaction; never queued directly.
 */
class MatchedChannelMerge extends MergeChannels
{
    /** @var array<int, true> */
    private array $matchedIds;

    /** @var array<string, true> */
    private array $matchedKeys = [];

    /** @param Collection<int, Channel> $matchedChannels */
    public function __construct(
        MergeChannels $native,
        private readonly int $managedPlaylistId,
        Collection $matchedChannels,
        private readonly PluginExecutionContext $context,
    ) {
        parent::__construct(
            user: $native->user,
            playlists: $native->playlists,
            playlistId: $native->playlistId,
            checkResolution: $native->checkResolution,
            deactivateFailoverChannels: $native->deactivateFailoverChannels,
            forceCompleteRemerge: true,
            preferCatchupAsPrimary: $native->preferCatchupAsPrimary,
            weightedConfig: $native->weightedConfig,
            newChannelsOnly: false,
            // Matching already established the guide identity. Do not widen it
            // through native regex or fallback-name merging during recovery.
            regexPatterns: [],
            fallbackMergeConfig: ['enabled' => false],
            scrubberAwareMasterSelection: true,
        );
        $this->matchedIds = array_fill_keys($matchedChannels->pluck('id')->all(), true);
        foreach ($matchedChannels as $channel) {
            $key = $this->getChannelMergeKey($channel);
            if ($key !== null) {
                $this->matchedKeys[$key] = true;
            }
        }
    }

    protected function applyMergeKeyPresenceScope($query): void
    {
        parent::applyMergeKeyPresenceScope($query);
        $candidateIds = array_keys($this->matchedIds);
        $otherPlaylistIds = $this->playlists->map(fn ($item) => is_array($item) ? $item['playlist_failover_id'] : $item)
            ->push($this->playlistId)->unique()->reject(fn ($id) => (int) $id === $this->managedPlaylistId)->values()->all();

        if ($otherPlaylistIds !== []) {
            // Stream only IDs from backup playlists first. Reuse the host's key
            // normalization exactly, then load ranking data only for actual peers.
            $others = Channel::query()->where('user_id', $this->user->id)->eligibleForMerge()
                ->whereIn('playlist_id', $otherPlaylistIds)->select(['id', 'stream_id', 'stream_id_custom']);
            $this->applyContentTypeScope($others);
            foreach ($others->cursor() as $channel) {
                if (isset($this->matchedKeys[$this->getChannelMergeKey($channel)])) {
                    $candidateIds[] = (int) $channel->id;
                }
            }
        }

        $query->whereIntegerInRaw('id', $candidateIds)->select([
            'id', 'user_id', 'playlist_id', 'group_id', 'title', 'name',
            'stream_id', 'stream_id_custom', 'url', 'url_custom', 'sort',
            'catchup', 'enabled', 'stream_stats', 'last_scrubber_live',
        ]);
    }

    protected function processGroupedChannels(iterable $groupedChannels, array $playlistPriority): array
    {
        $result = ['processed' => 0, 'deactivated' => 0];
        foreach ($groupedChannels as $group) {
            if (! $group->contains(fn (Channel $channel): bool => isset($this->matchedIds[$channel->id]))) {
                continue;
            }
            RunProgress::checkCancellation($this->context);
            // Explicitly preserved and unmatched target channels are not owned
            // by this recovery pass, even when quarantine is turned off.
            $group = $group->filter(fn (Channel $channel): bool => (int) $channel->playlist_id !== $this->managedPlaylistId || isset($this->matchedIds[$channel->id]))
                ->sortBy('id')->values(); // Stable tie-break when native scores and sort values are equal.
            // Native merge skips singletons; a confirmed standalone channel must
            // also recover without requiring an old failover relationship.
            if ($group->count() === 1) {
                $master = $this->selectMasterChannel($group, $playlistPriority);
                if ($master) {
                    $this->prepareSelectedMaster($master);
                }

                continue;
            }
            $merged = parent::processGroupedChannels([$group], $playlistPriority);
            $result['processed'] += $merged['processed'];
            $result['deactivated'] += $merged['deactivated'];
        }

        return $result;
    }

    protected function selectMasterChannel(Collection $group, array $playlistPriority): ?Channel
    {
        $master = parent::selectMasterChannel($group, $playlistPriority);
        foreach ($group as $channel) {
            if (isset($this->matchedIds[$channel->id]) && $channel->enabled
                && ($channel->last_scrubber_live === false
                    || ($this->deactivateFailoverChannels && $channel->id !== $master?->id))) {
                $channel->update(['enabled' => false]);
            }
        }

        return $master;
    }

    protected function filterMasterCandidates(Collection $group): Collection
    {
        $nativeCandidates = parent::filterMasterCandidates($group)->keyBy('id');

        return $group->filter(fn (Channel $channel): bool => $channel->last_scrubber_live !== false
            && (isset($this->matchedIds[$channel->id]) || $nativeCandidates->has($channel->id)));
    }

    protected function shouldEnableSelectedMaster(Channel $master): bool
    {
        return isset($this->matchedIds[$master->id])
            ? ! $master->enabled && $master->last_scrubber_live !== false
                && ! in_array($master->group_id, $this->disabledGroupIds, true)
            : parent::shouldEnableSelectedMaster($master);
    }

    // Sync uses the last stored probe results. Network probes belong to the
    // scrubber/probe jobs and must never run while recovery holds database locks.
    protected function getResolution(Channel $channel): int
    {
        $video = $this->storedVideo($channel);

        return (int) ($video['width'] ?? 0) * (int) ($video['height'] ?? 0);
    }

    protected function getCodec(Channel $channel): ?string
    {
        return $this->storedVideo($channel)['codec_name'] ?? null;
    }

    private function storedVideo(Channel $channel): array
    {
        foreach ($channel->stream_stats ?? [] as $entry) {
            if (($entry['stream']['codec_type'] ?? null) === 'video') {
                return $entry['stream'];
            }
        }

        return [];
    }

    protected function sendCompletionNotification(int $processed, int $deactivatedCount = 0): void
    {
        // The enclosing sync reports recovery once, after the transaction commits.
    }
}
