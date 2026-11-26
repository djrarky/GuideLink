<?php

namespace AppLocalPlugins\GuideLink\Tests\Feature;

use App\Jobs\MapEpgToChannels;
use App\Jobs\MapPlaylistChannelsToEpg;
use App\Jobs\MapPlaylistChannelsToEpgChunk;
use App\Models\EpgMap;
use App\Models\Job;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

trait RunsNativeEpgMapping
{
    /** Run the real native matching and association jobs using an isolated job store. */
    protected function runNativeMap(EpgMap $map): void
    {
        $old = config('database.connections.jobs');
        config(['database.connections.jobs' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('jobs');
        try {
            (require database_path('migrations/2025_02_13_215803_create_jobs_table.php'))->up();
            (new MapPlaylistChannelsToEpg(epg: $map->epg_id, playlist: $map->playlist_id, epgMapId: $map->id))->handle();
            $map->refresh();
            // Execute the chunks selected by the native parent, including its
            // playlist-wide scope and EPG opt-outs, without reconstructing them.
            $first = Bus::dispatched(MapPlaylistChannelsToEpgChunk::class, fn ($job) => $job->batchNo === $map->uuid)->last();
            if ($first) {
                foreach ([$first, ...array_map('unserialize', $first->chained)] as $job) {
                    if ($job instanceof MapPlaylistChannelsToEpgChunk) {
                        $job->handle();
                    }
                }
            }
            $jobs = Job::where('batch_no', $map->uuid)->pluck('id')->all();
            if ($jobs !== []) {
                (new MapEpgToChannels($jobs, count($jobs), $map->uuid))->handle();
            }
            $map->update(['processing' => false, 'status' => 'completed']);
        } finally {
            DB::purge('jobs');
            config(['database.connections.jobs' => $old]);
        }
    }
}
