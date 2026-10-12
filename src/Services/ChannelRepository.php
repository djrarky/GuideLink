<?php

namespace AppLocalPlugins\GuideLink\Services;

use App\Models\Channel;
use App\Models\ChannelScrubberLogChannel;
use App\Models\Group;
use App\Models\Playlist;
use AppLocalPlugins\GuideLink\Support\ChannelAttributes;
use AppLocalPlugins\GuideLink\Support\CountryRulesRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class ChannelRepository
{
    public function __construct(private readonly CountryRulesRepository $countryRules) {}

    /**
     * @return Collection<int, Channel>
     */
    public function liveChannels(Playlist $playlist, bool $includeDisabled): Collection
    {
        $query = Channel::query()
            ->where('playlist_id', $playlist->id)
            ->where('is_vod', false)
            ->with(['group', 'failovers.channelFailover'])
            ->orderBy('group_internal')
            ->orderBy('channel')
            ->orderBy('name');

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
            ->pluck('channel_id')
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

        Group::query()
            ->where('playlist_id', $playlist->id)
            ->where(function (Builder $query): void {
                $query->whereNull('type')->orWhere('type', 'live');
            })
            ->orderBy('sort_order')
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->each(function (Group $group) use (&$groups, $rules): void {
                $key = $this->countryRules->normalize((string) $group->name, $rules);
                if ($key !== '' && ! isset($groups[$key])) {
                    $groups[$key] = $group;
                }
            });

        return $groups;
    }

    /**
     * @return array{total: int, enabled: int, enabled_titles: array<int, string>}
     */
    public function failoverSummary(Channel $channel): array
    {
        $failovers = $channel->relationLoaded('failovers')
            ? $channel->failovers
            : $channel->failovers()->with('channelFailover')->get();

        $enabledTitles = [];
        foreach ($failovers as $failover) {
            $failoverChannel = $failover->channelFailover;
            if ($failoverChannel && $failoverChannel->enabled) {
                $enabledTitles[] = ChannelAttributes::title($failoverChannel);
            }
        }

        return [
            'total' => $failovers->count(),
            'enabled' => count($enabledTitles),
            'enabled_titles' => $enabledTitles,
        ];
    }
}
