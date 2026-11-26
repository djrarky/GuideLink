<?php

namespace AppLocalPlugins\GuideLink\Support;

use App\Models\Channel;
use App\Models\Group;
use App\Models\Playlist;

/**
 * Shared channel views. DecisionState and NativeMetadata retain raw database
 * values for stale-plan checks; ChannelReportRow contains display values.
 * Optional raw fields may be absent on partially selected models.
 *
 * @phpstan-type DecisionState array{
 *     user_id?: int|string, source_id?: string|null, name?: string|null, title?: string|null,
 *     name_custom?: string|null, title_custom?: string|null,
 *     stream_id?: string|null, stream_id_custom?: string|null,
 *     channel?: int|float|string|null, group_id?: int|string|null,
 *     group_internal?: string|null, is_vod?: bool|int|string|null, group_name: string
 * }
 * @phpstan-type NativeMetadata array{
 *     title_custom?: string|null, name_custom?: string|null,
 *     channel?: int|float|string|null, sort?: int|float|string|null,
 *     group_id?: int|string|null, stream_id_custom?: string|null,
 *     logo_type?: string|null
 * }
 * @phpstan-type ChannelReportRow array{
 *     playlist_id: int, playlist_uuid: string, channel_id: int, channel_uuid: string|null,
 *     enabled: bool, channel_number: int|float|string, title: string,
 *     name: string, group: string, stream_id: string
 * }
 */
class ChannelAttributes
{
    public const NAME_FIELDS = ['title', 'title_custom', 'name', 'name_custom'];

    private const DECISION_FIELDS = [
        'user_id', 'source_id', 'name', 'title', 'name_custom', 'title_custom', 'stream_id',
        'stream_id_custom', 'channel', 'group_id', 'group_internal', 'is_vod',
    ];

    public const NATIVE_METADATA_FIELDS = [
        'title' => 'title_custom',
        'name' => 'name_custom',
        'channel_number' => 'channel',
        'sort_order' => 'sort',
        'group_id' => 'group_id',
        'stream_id' => 'stream_id_custom',
        'logo_type' => 'logo_type',
    ];

    /** @return NativeMetadata */
    public static function nativeMetadata(Channel $channel): array
    {
        return array_intersect_key($channel->getRawOriginal(), array_flip(self::NATIVE_METADATA_FIELDS));
    }

    /**
     * Read preparation must retain every field used by the concurrent-edit checks.
     *
     * @return array<int, string>
     */
    public static function readColumns(): array
    {
        return array_values(array_unique([
            'id', 'uuid', 'playlist_id', 'enabled', 'can_merge', 'group',
            ...self::DECISION_FIELDS, ...array_values(self::NATIVE_METADATA_FIELDS),
        ]));
    }

    /** @return DecisionState */
    public static function decisionState(Channel $channel): array
    {
        return [
            ...array_intersect_key($channel->getRawOriginal(), array_fill_keys(self::DECISION_FIELDS, true)),
            'group_name' => self::groupName($channel),
        ];
    }

    public static function sameValue(string $field, mixed $left, mixed $right): bool
    {
        if (in_array($field, ['channel', 'sort'], true)
            && is_numeric($left) && is_numeric($right)) {
            return (float) $left === (float) $right;
        }

        if (in_array($field, ['group_id', 'user_id', 'is_vod'], true)
            && is_numeric($left) && is_numeric($right)) {
            return (string) $left === (string) $right;
        }

        return $left === $right;
    }

    /**
     * Check a decision after this sync's metadata writes, while the caller holds
     * the channel lock. A write may legitimately move it to a newly created group.
     *
     * @param  DecisionState  $snapshot
     * @param  array<string, mixed>  $write
     */
    public static function assertDecisionUnchanged(Channel $channel, array $snapshot, array $write = []): void
    {
        $current = self::decisionState($channel);
        $changes = $write['native_changes'] ?? [];
        foreach ($snapshot as $field => $before) {
            if ($field === 'group_name' && array_key_exists('group_id', $changes)) {
                if (GroupIdentity::key($current['group_name']) !== GroupIdentity::key($write['desired']['group'])) {
                    throw new \RuntimeException("Channel [{$channel->id}] target group changed after its metadata write; rerun sync.");
                }

                continue;
            }
            $expected = array_key_exists($field, $changes) ? $changes[$field] : $before;
            if (! self::sameValue($field, $current[$field] ?? null, $expected)) {
                throw new \RuntimeException("Channel [{$channel->id}] identity or scope changed after planning; rerun sync.");
            }
        }
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
     * @return ChannelReportRow
     */
    public static function reportRow(Playlist $playlist, Channel $channel): array
    {
        return [
            'playlist_id' => (int) $playlist->id,
            'playlist_uuid' => (string) $playlist->uuid,
            'channel_id' => (int) $channel->id,
            // Provider imports may leave the internal UUID null. Preserve it for
            // write guards; source_id is the separate provider stream identity.
            'channel_uuid' => $channel->uuid,
            'enabled' => (bool) $channel->enabled,
            'channel_number' => $channel->channel ?? '',
            'title' => self::title($channel),
            'name' => (string) ($channel->name_custom ?? $channel->name ?? ''),
            'group' => self::groupName($channel),
            'stream_id' => self::streamId($channel),
        ];
    }
}
