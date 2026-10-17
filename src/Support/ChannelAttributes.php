<?php

namespace AppLocalPlugins\GuideLink\Support;

use App\Models\Channel;
use App\Models\Group;
use App\Models\Playlist;

class ChannelAttributes
{
    public const NATIVE_METADATA_FIELDS = [
        'title' => 'title_custom',
        'name' => 'name_custom',
        'channel_number' => 'channel',
        'sort_order' => 'sort',
        'group_id' => 'group_id',
        'stream_id' => 'stream_id_custom',
        'epg_channel_id' => 'epg_channel_id',
        'logo_type' => 'logo_type',
    ];

    public static function nativeMetadata(Channel $channel): array
    {
        return array_intersect_key($channel->getRawOriginal(), array_flip(self::NATIVE_METADATA_FIELDS));
    }

    public static function decisionState(Channel $channel): array
    {
        return [
            ...array_intersect_key($channel->getRawOriginal(), array_fill_keys([
                'user_id', 'name', 'title', 'name_custom', 'title_custom', 'stream_id',
                'stream_id_custom', 'channel', 'group_id', 'group_internal', 'is_vod',
            ], true)),
            'group_name' => self::groupName($channel),
        ];
    }

    public static function sameValue(string $field, mixed $left, mixed $right): bool
    {
        if (in_array($field, ['channel', 'sort'], true)
            && is_numeric($left) && is_numeric($right)) {
            return (float) $left === (float) $right;
        }

        if (in_array($field, ['group_id', 'user_id', 'epg_channel_id', 'is_vod'], true)
            && is_numeric($left) && is_numeric($right)) {
            return (string) $left === (string) $right;
        }

        return $left === $right;
    }

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
            'enabled' => (bool) $channel->enabled,
            'channel_number' => $channel->channel ?? '',
            'title' => self::title($channel),
            'name' => (string) ($channel->name_custom ?? $channel->name ?? ''),
            'group' => self::groupName($channel),
            'stream_id' => self::streamId($channel),
        ];
    }
}
