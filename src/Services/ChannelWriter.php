<?php

namespace AppLocalPlugins\GuideLink\Services;

use App\Models\Channel;
use App\Models\Playlist;
use App\Plugins\Support\PluginExecutionContext;
use AppLocalPlugins\GuideLink\Support\RunProgress;
use Illuminate\Database\Eloquent\Collection;

class ChannelWriter
{
    /**
     * @param  array<int, array<string, mixed>>  $writePlan
     * @return array{channels_written: int, channels_skipped: int, channels_failed: int, aborted: bool, written_ids: array<int, int>, errors: array<int, string>, unmanaged_channels_disabled: int}
     */
    public function apply(array $writePlan, PluginExecutionContext $context): array
    {
        $summary = [
            'channels_written' => 0,
            'channels_skipped' => 0,
            'channels_failed' => 0,
            'aborted' => false,
            'written_ids' => [],
            'errors' => [],
            'unmanaged_channels_disabled' => 0,
        ];

        $total = count($writePlan);
        foreach ($writePlan as $index => $row) {
            if ($context->cancellationRequested()) {
                $summary['aborted'] = true;
                $summary['errors'][] = 'Cancelled before channel writes completed.';

                return $summary;
            }

            if (RunProgress::shouldHeartbeat($index, $total)) {
                $context->heartbeat('Channel writes: processing '.($index + 1).' of '.$total.' planned row(s).', null, [
                    'channel_writes' => [
                        'position' => $index + 1,
                        'total' => $total,
                        'written' => $summary['channels_written'],
                        'skipped' => $summary['channels_skipped'],
                        'failed' => $summary['channels_failed'],
                    ],
                ]);
            }

            $updates = $row['native_changes'] ?? [];
            if (! is_array($updates) || $updates === []) {
                $summary['channels_skipped']++;

                continue;
            }

            try {
                $channel = Channel::query()->whereKey((int) $row['channel_id'])->firstOrFail();
                $channel->forceFill($updates)->save();
                $summary['channels_written']++;
                $summary['written_ids'][] = (int) $channel->id;
            } catch (\Throwable $exception) {
                $summary['channels_failed']++;
                $summary['errors'][] = $exception->getMessage();
            }
        }

        $summary['written_ids'] = array_values(array_unique($summary['written_ids']));

        return $summary;
    }

    /**
     * @param  Collection<int, Channel>  $channels
     * @param  array<int, int>  $managedGroupIds
     * @param  array<int, int>  $writtenChannelIds
     */
    public function disableOtherGroups(
        Playlist $playlist,
        Collection $channels,
        array $managedGroupIds,
        array $writtenChannelIds,
        PluginExecutionContext $context,
    ): int {
        $managed = array_fill_keys($managedGroupIds, true);
        $written = array_fill_keys($writtenChannelIds, true);
        $disabled = 0;
        $processed = 0;
        $total = $channels->count();

        foreach ($channels as $channel) {
            $processed++;
            if ($context->cancellationRequested()) {
                return $disabled;
            }

            if (RunProgress::shouldHeartbeat($processed - 1, $total, every: 50)) {
                $context->heartbeat('Disable unmanaged groups: checked '.$processed.' of '.$total.' channel(s).', null, [
                    'disable_unmanaged_groups' => [
                        'position' => $processed,
                        'total' => $total,
                        'disabled' => $disabled,
                    ],
                ]);
            }

            if (isset($written[(int) $channel->id]) || ! $channel->enabled || isset($managed[(int) $channel->group_id])) {
                continue;
            }

            $channel->forceFill(['enabled' => false])->save();
            $disabled++;
        }

        if ($disabled > 0) {
            $context->warning('Disabled channels from unmanaged live groups after explicit disable_other_groups request.', [
                'playlist_id' => $playlist->id,
                'channels_disabled' => $disabled,
            ]);
        }

        return $disabled;
    }
}
