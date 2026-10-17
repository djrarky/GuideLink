<?php

namespace AppLocalPlugins\GuideLink\Services;

use App\Models\Channel;
use App\Models\ChannelScrubberLogChannel;
use App\Models\Group;
use App\Models\Playlist;
use AppLocalPlugins\GuideLink\Support\ChannelAttributes;
use AppLocalPlugins\GuideLink\Support\CountryRulesRepository;
use AppLocalPlugins\GuideLink\Support\GroupIdentity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class ChannelRepository
{
    public function __construct(private readonly CountryRulesRepository $countryRules) {}

    /**
     * @return Collection<int, Channel>
     */
    public function liveChannels(Playlist $playlist, bool $includeDisabled, bool $includeFailovers = true): Collection
    {
        $query = Channel::query()
            ->where('playlist_id', $playlist->id)
            ->where('is_vod', false)
            ->with(['group' => fn ($query) => $query->withTrashed()])
            ->orderBy('group_internal')
            ->orderBy('channel')
            ->orderBy('name')
            ->orderBy('id');

        if ($includeFailovers) {
            $query->with('failovers.channelFailover');
        }

        if (! $includeDisabled) {
            $query->where('enabled', true);
        }

        return $query->get();
    }

    /**
     * @return array<int, true>
     */
    public function scrubberChannelIds(Playlist $playlist, bool $includeScrubberLogs): array
    {
        if (! $includeScrubberLogs) {
            return [];
        }

        return ChannelScrubberLogChannel::query()
            ->whereNotNull('channel_id')
            ->whereHas('channelScrubberLog', function (Builder $query) use ($playlist): void {
                $query->where('playlist_id', $playlist->id);
            })
            ->distinct()->pluck('channel_id')
            ->mapWithKeys(fn (mixed $id): array => [(int) $id => true])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, Group>
     */
    public function groupsByName(Playlist $playlist, array $rules): array
    {
        $groups = [];

        Group::withTrashed()
            ->where('playlist_id', $playlist->id)
            ->where('is_merged', false)
            ->where(function (Builder $query): void {
                $query->where(function (Builder $query): void {
                    $query->where('custom', true)->whereNull('deleted_at');
                })->orWhere(function (Builder $query): void {
                    $query->where('custom', false)->whereNull('import_batch_no')->whereNull('source_group_id');
                });
            })
            ->where(function (Builder $query): void {
                $query->whereNull('type')->orWhere('type', 'live');
            })
            ->orderByRaw('CASE WHEN deleted_at IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('custom')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->each(function (Group $group) use (&$groups): void {
                $key = GroupIdentity::key((string) $group->name);
                if ($key !== '' && ! isset($groups[$key])) {
                    $groups[$key] = $group;
                }
            });

        return $groups;
    }

    /**
     * @return array{total: int, enabled: int, enabled_ids: array<int, int>, enabled_titles: array<int, string>}
     */
    public function failoverSummary(Channel $channel): array
    {
        $failovers = $channel->relationLoaded('failovers')
            ? $channel->failovers
            : $channel->failovers()->with('channelFailover')->get();

        $channels = [];
        $enabledTitles = [];
        foreach ($failovers as $failover) {
            $backup = $failover->channelFailover;
            if (! $backup || $backup->is_vod || (int) $backup->user_id !== (int) $channel->user_id
                || (int) $backup->id === (int) $channel->id) {
                continue;
            }
            $channels[(int) $backup->id] = true;
            if ($backup->enabled) {
                $enabledTitles[(int) $backup->id] = ChannelAttributes::title($backup);
            }
        }

        return [
            'total' => count($channels),
            'enabled' => count($enabledTitles),
            'enabled_ids' => array_keys($enabledTitles),
            'enabled_titles' => array_values($enabledTitles),
        ];
    }
}
