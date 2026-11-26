<?php

namespace AppLocalPlugins\GuideLink\Services;

use App\Jobs\MapPlaylistChannelsToEpg;
use App\Jobs\Middleware\SkipIfSyncReset;
use App\Models\EpgMap;
use App\Models\Playlist;
use App\Plugins\Support\PluginExecutionContext;
use AppLocalPlugins\GuideLink\Support\BuildsSetupReports;
use AppLocalPlugins\GuideLink\Support\RunProgress;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Configure and dispatch the host mapper; never write channel EPG associations. */
class EpgMappingSetupService
{
    use BuildsSetupReports;

    /**
     * Configure one playlist-wide map after successful channel policy writes.
     * Native jobs own channel eligibility, matching and association updates.
     *
     * @param  array{id: int, name: string}  $epg
     */
    public function apply(Playlist $playlist, array $epg, bool $setEpgIcon, PluginExecutionContext $context): array
    {
        $row = DB::transaction(function () use ($playlist, $epg, $setEpgIcon, $context): array {
            RunProgress::checkCancellation($context);
            if (! $context->dryRun) {
                $playlist = Playlist::whereKey($playlist->id)
                    ->where('user_id', $playlist->user_id)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            $maps = EpgMap::where('playlist_id', $playlist->id)
                ->where('user_id', $playlist->user_id)
                ->when(! $context->dryRun, fn ($query) => $query->lockForUpdate())
                ->get()
                ->filter(fn (EpgMap $map): bool => ($map->settings['guidelink_plugin_id'] ?? null) === (int) $context->plugin->id);
            if ($maps->count() > 1) {
                throw new \RuntimeException('Multiple GuideLink-managed EPG maps exist for this playlist. Resolve the duplicate maps before syncing.');
            }
            $map = $maps->first();

            $desiredAttributes = [
                'name' => EpgMap::buildName($epg['name'], $playlist->name),
                'playlist_id' => (int) $playlist->id,
                'user_id' => (int) $playlist->user_id,
                'epg_id' => $epg['id'],
                // Native null scopes include the entire playlist and later imports.
                'channels' => null,
                'group_ids' => null,
                'recurring' => true,
                'override' => true,
                'settings' => [
                    ...($map?->settings ?? []),
                    'guidelink_plugin_id' => (int) $context->plugin->id,
                    'skip_missing' => false,
                    'prioritize_name_match' => false,
                    'set_epg_icon' => $setEpgIcon,
                ],
            ];
            $currentAttributes = $map?->only(array_keys($desiredAttributes)) ?? [];
            // The host model does not cast these two stored flags to booleans.
            foreach (['recurring', 'override'] as $key) {
                $currentAttributes[$key] = $map ? (bool) $currentAttributes[$key] : null;
            }
            $changes = $this->changedFields($currentAttributes, $desiredAttributes);
            $hasChanges = $changes !== [];
            $isProcessing = (bool) $map?->processing;

            if (! $context->dryRun && $isProcessing && $hasChanges) {
                throw new \RuntimeException('Native EPG mapping is running with different settings. Retry GuideLink after that mapping finishes.');
            }
            if (! $context->dryRun && $hasChanges) {
                if ($map) {
                    // Use the host's reset guard to discard already queued work
                    // for the previous guide/settings before dispatching anew.
                    SkipIfSyncReset::recordReset($map);
                }
                $map ??= (new EpgMap)->forceFill([
                    'uuid' => (string) Str::orderedUuid(),
                    'status' => 'pending',
                    'processing' => false,
                    'progress' => 0,
                ]);
                $map->forceFill($desiredAttributes)->save();
            }

            if ($context->dryRun) {
                $status = $hasChanges ? 'change_required' : 'ok';
            } else {
                $status = $isProcessing ? 'processing' : 'queued';
            }

            return $this->row(
                $status,
                'Native EPG mapping setup',
                'm3u-editor maps the saved guide IDs first and maintains the association after EPG refresh. Mapping completion and errors are reported in EPG Maps.',
                [
                    'playlist_id' => (int) $playlist->id,
                    'epg_map_id' => $map?->id,
                    'dry_run' => $context->dryRun,
                    'changes' => $changes,
                    'desired' => $desiredAttributes,
                    'dispatch' => ! $context->dryRun && ! $isProcessing,
                    'mapping_status' => $map?->status?->value,
                ],
            );
        });

        if (! $context->dryRun && $row['details']['dispatch']) {
            RunProgress::checkCancellation($context);
            Bus::dispatch((new MapPlaylistChannelsToEpg(
                epg: $epg['id'],
                playlist: (int) $playlist->id,
                epgMapId: $row['details']['epg_map_id'],
            ))->onConnection('redis')->onQueue('import')->afterCommit());
        }

        return $row;
    }
}
