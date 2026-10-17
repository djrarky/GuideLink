<?php

namespace AppLocalPlugins\GuideLink\Services;

use App\Models\Channel;
use App\Models\Group;
use App\Plugins\Support\PluginExecutionContext;
use AppLocalPlugins\GuideLink\Support\ChannelAttributes;
use AppLocalPlugins\GuideLink\Support\GroupIdentity;
use AppLocalPlugins\GuideLink\Support\RunProgress;
use Illuminate\Support\Facades\DB;

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
                $outcome = DB::transaction(function () use ($row, $updates, $context): string {
                    $channel = Channel::query()
                        ->where('playlist_id', $row['playlist_id'])
                        ->where('uuid', $row['channel_uuid'])
                        ->where('is_vod', false)
                        ->with(['group' => fn ($query) => $query->withTrashed()])
                        ->whereKey((int) $row['channel_id'])
                        ->lockForUpdate()->firstOrFail();

                    if ($context->cancellationRequested()) {
                        return 'cancelled';
                    }
                    $currentState = ChannelAttributes::decisionState($channel);
                    $channel->forceFill($updates);
                    if (! $channel->isDirty()) {
                        return 'skipped';
                    }

                    if (array_key_exists('group_id', $updates) && $updates['group_id'] !== null) {
                        $group = Group::query()->where('playlist_id', $row['playlist_id'])
                            ->where('user_id', $channel->user_id)->where('is_merged', false)
                            ->where(fn ($query) => $query->whereNull('type')->orWhere('type', 'live'))
                            ->whereKey($updates['group_id'])->lockForUpdate()->first();
                        if (! $group || GroupIdentity::key((string) $group->name) !== GroupIdentity::key((string) $row['desired']['group'])) {
                            throw new \RuntimeException("Channel [{$channel->id}] target group changed after planning; rerun sync.");
                        }
                    }

                    $originals = $row['native_originals'] ?? [];
                    foreach ($row['decision_state'] ?? [] as $field => $expected) {
                        if ($field === 'group_name' && array_key_exists('group_id', $updates)
                            && ChannelAttributes::sameValue('group_id', $currentState['group_id'], $updates['group_id'])) {
                            continue;
                        }
                        if (array_key_exists($field, $updates) && ChannelAttributes::sameValue($field, $currentState[$field] ?? null, $updates[$field])) {
                            continue;
                        }
                        if (! ChannelAttributes::sameValue($field, $currentState[$field] ?? null, $expected)) {
                            throw new \RuntimeException("Channel [{$channel->id}] identity or scope changed after planning; rerun sync before updating it.");
                        }
                    }
                    foreach (array_keys($channel->getDirty()) as $field) {
                        if (! array_key_exists($field, $originals) || $channel->getRawOriginal($field) !== $originals[$field]) {
                            throw new \RuntimeException("Channel [{$channel->id}] changed after planning; rerun sync before updating it.");
                        }
                    }
                    $channel->save();

                    return 'written';
                });
                if ($outcome === 'cancelled') {
                    $summary['aborted'] = true;
                    $summary['errors'][] = 'Cancelled before channel writes completed.';

                    return $summary;
                }
                $summary['channels_'.$outcome]++;
                if ($outcome === 'written') {
                    $summary['written_ids'][] = (int) $row['channel_id'];
                }
            } catch (\Throwable $exception) {
                $summary['channels_failed']++;
                $summary['errors'][] = $exception->getMessage();
            }
        }

        $summary['written_ids'] = array_values(array_unique($summary['written_ids']));

        return $summary;
    }
}
