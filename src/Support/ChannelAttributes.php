<?php

namespace AppLocalPlugins\GuideLink\Support;

use App\Models\Channel;
use App\Models\Group;
use App\Models\Playlist;

class ChannelAttributes
{
    public static function title(Channel $channel): string
    {
        return (string) ($channel->title_custom
            ?? $channel->title
            ?? $channel->name_custom
            ?? $channel->name
            ?? '');
    }

    public static function groupName(Channel $channel): string
    {
        $group = $channel->relationLoaded('group') ? $channel->getRelation('group') : null;

        if ($group instanceof Group && $group->name) {
            return (string) $group->name;
        }

        return (string) ($channel->group_internal
            ?? $channel->getAttribute('group')
            ?? '');
    }

    public static function streamId(Channel $channel): string
    {
        return (string) ($channel->stream_id_custom ?? $channel->stream_id ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    public static function reportRow(Playlist $playlist, Channel $channel): array
    {
        return [
            'playlist_id' => (int) $playlist->id,
            'playlist_uuid' => (string) $playlist->uuid,
            'channel_id' => (int) $channel->id,
            'channel_uuid' => (string) ($channel->uuid ?? ''),
            'channel_number' => $channel->channel ?? '',
            'title' => self::title($channel),
            'name' => (string) ($channel->name_custom ?? $channel->name ?? ''),
            'group' => self::groupName($channel),
            'stream_id' => self::streamId($channel),
        ];
    }
}
