<?php

namespace AppLocalPlugins\GuideLink\Services;

use App\Models\Epg;
use App\Models\EpgChannel;
use App\Plugins\Support\PluginExecutionContext;
use AppLocalPlugins\GuideLink\Support\Options;

class GuideMapService
{
    /**
     * Resolve the required m3u-editor EPG into the Python-compatible
     * channel-number => stream-id guide map.
     *
     * @param  array<string, mixed>  $payload
     * @return array{epg: array{id: int, uuid: string, name: string}, map: array<int, string>, aliases: array<int, array<int, string>>, channel_ids: array<int, int>}
     */
    public function resolve(array $payload, PluginExecutionContext $context): array
    {
        $epgId = Options::nullableInt(
            $payload['guide_epg_id']
            ?? $payload['epg_id']
            ?? null,
        );

        if ($epgId === null) {
            throw new \RuntimeException('Choose a Guide EPG in Data → Profiles before running GuideLink.');
        }

        $epg = $this->epgOrFail($context, $epgId);
        $aliases = [];
        $channelIds = [];
        $map = $this->mapForEpg($epg, $aliases, $channelIds);
        if ($map === []) {
            throw new \RuntimeException('The selected Guide EPG has no usable channel-number-to-stream-ID mappings. Import a numbered guide before running GuideLink.');
        }

        $context->info('Resolved guide EPG channel map.', [
            'epg_id' => $epg->id,
            'guide_channels' => count($map),
        ]);

        return [
            'epg' => [
                'id' => (int) $epg->id,
                'uuid' => (string) ($epg->uuid ?? ''),
                'name' => (string) $epg->name,
            ],
            'map' => $map,
            'aliases' => $aliases,
            'channel_ids' => $channelIds,
        ];
    }

    /** Read identity evidence from a guide, excluding number-only labels. */
    public function targetNames(int $epgId, array $ids, PluginExecutionContext $context): array
    {
        $epg = $this->epgOrFail($context, $epgId);
        $names = [];
        foreach (EpgChannel::query()->where('epg_id', $epg->id)->whereIn('channel_id', $ids)->get() as $channel) {
            $id = $channel->channel_id;
            if (isset($names[$id])) {
                throw new \RuntimeException("Guide EPG target [{$id}] is ambiguous.");
            }
            $names[$id] = self::identityNames($channel);
        }

        return $names;
    }

    /** Guide identity excludes number-only labels used to identify guide slots. */
    public static function identityNames(EpgChannel $channel): array
    {
        return array_values(array_filter(
            [$channel->name, $channel->display_name, ...($channel->additional_display_names ?? [])],
            fn (mixed $name): bool => is_string($name) && preg_match('/\p{L}/u', $name) === 1,
        ));
    }

    /**
     * @param  array<int, array<int, string>>  $aliases
     * @param  array<int, int>  $channelIds
     * @return array<int, string>
     */
    private function mapForEpg(Epg $epg, array &$aliases, array &$channelIds): array
    {
        $map = [];
        $streamIds = [];

        EpgChannel::query()
            ->where('epg_id', $epg->id)
            ->orderBy('display_name')
            ->orderBy('channel_id')
            ->orderBy('id')
            ->get(['id', 'channel_id', 'display_name', 'name', 'additional_display_names'])
            ->each(function (EpgChannel $channel) use (&$map, &$streamIds, &$aliases, &$channelIds): void {
                $names = array_map(
                    fn (mixed $name): string => trim((string) $name),
                    [$channel->display_name, $channel->name, ...($channel->additional_display_names ?? [])],
                );
                $number = $this->channelNumber($names);
                $streamId = trim((string) $channel->channel_id);

                if ($number === null || $streamId === '') {
                    return;
                }

                if (isset($map[$number]) && $map[$number] !== $streamId) {
                    throw new \RuntimeException("Guide EPG maps channel number [{$number}] to more than one stream id.");
                }

                if (isset($streamIds[$streamId]) && $streamIds[$streamId] !== $number) {
                    throw new \RuntimeException("Guide EPG stream id [{$streamId}] appears against more than one channel number.");
                }

                if (isset($channelIds[$number])) {
                    throw new \RuntimeException("Guide EPG has duplicate records for channel number [{$number}] and stream id [{$streamId}]. Remove the ambiguous guide record before syncing.");
                }

                $map[$number] = $streamId;
                $channelIds[$number] = (int) $channel->id;
                $streamIds[$streamId] = $number;
                foreach ($names as $name) {
                    // Number-only display names identify the guide slot, not
                    // a channel alias (for example, "101" must not match a name).
                    if ($name !== '' && preg_match('/\p{L}/u', $name) === 1) {
                        $aliases[$number][] = $name;
                    }
                }
            });

        $aliases = array_map(fn (array $names): array => array_values(array_unique($names)), $aliases);
        ksort($map);

        return $map;
    }

    /** @param array<int, string> $values */
    private function channelNumber(array $values): ?int
    {
        // Python reads the XMLTV number from a display-name that is only digits.
        // Prefer that exact signal before falling back to m3u-editor display names
        // like "101 BBC One".
        $numbers = array_values(array_unique(array_filter($values, fn (string $value): bool => $value !== '' && ctype_digit($value))));
        if (count($numbers) > 1) {
            throw new \RuntimeException('Guide EPG contains conflicting channel-number labels. Resolve the ambiguous guide record before syncing.');
        }
        if ($numbers !== []) {
            return str_starts_with($numbers[0], '0') ? null : (int) $numbers[0];
        }

        foreach ($values as $value) {
            if (preg_match('/^([1-9][0-9]{0,4})\s+\p{L}/u', $value, $matches) === 1) {
                $numbers[] = (int) $matches[1];
            }
        }
        $numbers = array_values(array_unique($numbers));
        if (count($numbers) > 1) {
            throw new \RuntimeException('Guide EPG contains conflicting channel-number labels. Resolve the ambiguous guide record before syncing.');
        }
        if ($numbers !== []) {
            return $numbers[0];
        }

        return null;
    }

    private function epgOrFail(PluginExecutionContext $context, int $epgId): Epg
    {
        $query = Epg::query();
        if ($context->user !== null && ! $context->user->isAdmin()) {
            $query->where('user_id', $context->user->id);
        }

        $epg = $query->whereKey($epgId)->first();
        if (! $epg) {
            throw new \RuntimeException("Guide EPG [{$epgId}] was not found or is not available to this run.");
        }

        return $epg;
    }
}
