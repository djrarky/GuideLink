<?php

namespace AppLocalPlugins\GuideLink\Services;

use App\Models\Playlist;
use App\Plugins\Support\PluginActionResult;
use App\Plugins\Support\PluginExecutionContext;
use AppLocalPlugins\GuideLink\Support\PlaylistOperationFailed;
use AppLocalPlugins\GuideLink\Support\RunCancelled;
use AppLocalPlugins\GuideLink\Support\RunProgress;
use AppLocalPlugins\GuideLink\Support\SourceRefreshFailed;
use Throwable;

class PlaylistBatchRunner
{
    /**
     * Resolve target playlist runs and use native PluginActionResult batch shape
     * for multi-playlist invocations while retaining playlist identity in
     * exceptions from a single playlist action.
     *
     * @param  array<string, mixed>  $payload
     * @param  callable(array<string, mixed>): PluginActionResult  $handler
     */
    public function runResolved(
        array $payload,
        PluginExecutionContext $context,
        string $purpose,
        string $label,
        callable $handler,
        ?PlaylistResolver $playlistResolver = null,
    ): PluginActionResult {
        $runs = (new PlaylistRunResolver($playlistResolver ?? new PlaylistResolver))->runs($payload, $context, $purpose);
        if ($runs === []) {
            return PluginActionResult::success('No enabled playlist assignments are selected for this action.', [
                'skipped' => true, 'playlist_runs' => [], 'progress' => 100,
            ]);
        }

        if (count($runs) > 1) {
            return $this->run($runs, $context, $label, $handler);
        }

        try {
            return $handler($runs[0]['payload']);
        } catch (RunCancelled $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new PlaylistOperationFailed((int) $runs[0]['playlist']->id, $exception);
        }
    }

    /**
     * @param  array<int, array{playlist: Playlist, payload: array<string, mixed>}>  $runs
     * @param  callable(array<string, mixed>): PluginActionResult  $handler
     */
    public function run(array $runs, PluginExecutionContext $context, string $label, callable $handler): PluginActionResult
    {
        $rows = [];
        $total = count($runs);

        foreach ($runs as $index => $run) {
            $position = $index + 1;
            $playlist = $run['playlist'];

            if ($context->cancellationRequested()) {
                return PluginActionResult::cancelled("{$label} cancelled after {$index} of {$total} playlist(s).", [
                    'batch' => $this->summary($rows, $total),
                    'playlist_runs' => $rows,
                    'progress' => RunProgress::batchPercentage($index, $total),
                ]);
            }

            $context->checkpoint(RunProgress::batchPercentage($index, $total), "{$label}: processing playlist {$position} of {$total}.", [
                'playlist_id' => $playlist->id,
                'playlist_name' => $playlist->name,
            ], log: true);

            try {
                RunProgress::checkCancellation($context);
                $result = $handler($run['payload']);
            } catch (RunCancelled $exception) {
                $result = PluginActionResult::cancelled($exception->getMessage());
            } catch (Throwable $exception) {
                $context->error("{$label}: playlist failed.", [
                    'playlist_id' => $playlist->id,
                    'playlist_name' => $playlist->name,
                    'error' => $exception->getMessage(),
                ]);

                $result = PluginActionResult::failure($exception->getMessage(), [
                    'source_failure' => $exception instanceof SourceRefreshFailed,
                ]);
            }

            $rows[] = $this->row($playlist, $result);

            if ($result->status === 'cancelled') {
                return PluginActionResult::cancelled("{$label} cancelled after {$position} of {$total} playlist(s).", [
                    'batch' => $this->summary($rows, $total),
                    'playlist_runs' => $rows,
                    'progress' => RunProgress::batchPercentage($position, $total),
                ]);
            }
        }

        $summary = $this->summary($rows, $total);
        $message = "{$label} completed for {$summary['completed']} of {$total} playlist(s)";
        $data = [
            'batch' => $summary,
            'playlist_runs' => $rows,
            'progress' => 100,
        ];

        if ($summary['failed'] > 0) {
            return PluginActionResult::failure("{$message}; {$summary['failed']} failed.", $data);
        }

        return PluginActionResult::success($message.'.', $data);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{total: int, completed: int, failed: int, cancelled: int}
     */
    private function summary(array $rows, int $total): array
    {
        $statuses = array_count_values(array_map(
            fn (array $row): string => (string) $row['status'],
            $rows,
        ));

        return [
            'total' => $total,
            'completed' => $statuses['completed'] ?? 0,
            'failed' => $statuses['failed'] ?? 0,
            'cancelled' => $statuses['cancelled'] ?? 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Playlist $playlist, PluginActionResult $result): array
    {
        return [
            'playlist' => [
                'id' => (int) $playlist->id,
                'uuid' => (string) $playlist->uuid,
                'name' => (string) $playlist->name,
            ],
            'status' => $result->status,
            'success' => $result->success,
            'summary' => $result->summary,
            'data' => $result->data,
        ];
    }
}
