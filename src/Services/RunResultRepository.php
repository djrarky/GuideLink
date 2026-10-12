<?php

namespace AppLocalPlugins\GuideLink\Services;

use App\Plugins\Support\PluginExecutionContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RunResultRepository
{
    public const TABLE = 'plugin_guidelink_results';

    /**
     * @param  array<int, array<string, mixed>>  $channelRows
     * @param  array<int, array<string, mixed>>|null  $sourceCoverageRows
     * @return array<string, mixed>
     */
    public function replaceAvailability(
        PluginExecutionContext $context,
        int $playlistId,
        array $channelRows,
        ?array $sourceCoverageRows = null,
    ): array {
        $rows = [
            ...$this->mapRows($channelRows, 'availability', 'status'),
            ...$this->mapRows($sourceCoverageRows ?? [], 'source_coverage', 'status'),
        ];

        return $this->replace($context, $playlistId, ['availability', 'source_coverage'], $rows);
    }

    /**
     * @param  array<int, array<string, mixed>>  $matchRows
     * @param  array<int, array<string, mixed>>  $excludedRows
     * @param  array<int, array<string, mixed>>  $writePlan
     * @return array<string, mixed>
     */
    public function replaceSync(
        PluginExecutionContext $context,
        int $playlistId,
        array $matchRows,
        array $excludedRows,
        array $writePlan,
    ): array {
        $plansByChannel = [];
        foreach ($writePlan as $plan) {
            if (isset($plan['channel_id'])) {
                $plansByChannel[(int) $plan['channel_id']] = $plan;
            }
        }

        $matchRows = array_map(function (array $row) use ($plansByChannel): array {
            $channelId = (int) ($row['channel_id'] ?? 0);

            return isset($plansByChannel[$channelId])
                ? [...$row, 'write_plan' => $plansByChannel[$channelId]]
                : $row;
        }, $matchRows);

        $rows = [
            ...$this->mapRows($matchRows, 'sync_match', 'decision'),
            ...$this->mapRows($excludedRows, 'sync_excluded', 'decision'),
        ];

        return $this->replace($context, $playlistId, ['sync_match', 'sync_excluded'], $rows);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function replaceRows(PluginExecutionContext $context, int $playlistId, array $rows, string $resultType): array
    {
        return $this->replace(
            $context,
            $playlistId,
            [$resultType],
            $this->mapRows($rows, $resultType, 'status'),
        );
    }

    /**
     * @param  array<int, string>  $resultTypes
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function replace(
        PluginExecutionContext $context,
        int $playlistId,
        array $resultTypes,
        array $rows,
    ): array {
        if (! Schema::hasTable(self::TABLE)) {
            throw new \RuntimeException('GuideLink run-results table is missing; reinstall or repair the plugin schema.');
        }

        $now = now();
        $records = array_map(fn (array $row): array => [
            'extension_plugin_id' => (int) $context->plugin->id,
            'extension_plugin_run_id' => (int) $context->run->id,
            'playlist_id' => $playlistId,
            ...$row,
            'created_at' => $now,
            'updated_at' => $now,
        ], $rows);

        DB::transaction(function () use ($context, $playlistId, $resultTypes, $records): void {
            DB::table(self::TABLE)
                ->where('extension_plugin_id', $context->plugin->id)
                ->where('extension_plugin_run_id', $context->run->id)
                ->where('playlist_id', $playlistId)
                ->whereIn('result_type', $resultTypes)
                ->delete();

            foreach (array_chunk($records, 250) as $chunk) {
                DB::table(self::TABLE)->insert($chunk);
            }
        });

        $typeCounts = array_count_values(array_column($rows, 'result_type'));
        $decisionCounts = array_count_values(array_filter(
            array_column($rows, 'decision'),
            fn (?string $decision): bool => $decision !== null && $decision !== '',
        ));

        ksort($decisionCounts);
        ksort($typeCounts);

        return [
            'table' => 'run_results',
            'run_id' => (int) $context->run->id,
            'playlist_id' => $playlistId,
            'rows' => count($rows),
            'by_type' => $typeCounts,
            'by_decision' => $decisionCounts,
            'retention' => 'plugin_run',
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function mapRows(array $rows, string $resultType, string $decisionKey): array
    {
        return array_map(function (array $row) use ($resultType, $decisionKey): array {
            $details = array_filter(
                $row,
                fn (string|int $key): bool => ! is_string($key) || ! str_starts_with($key, '_'),
                ARRAY_FILTER_USE_KEY,
            );

            return [
                'result_type' => $resultType,
                'decision' => $this->nullableString($row[$decisionKey] ?? null),
                'channel_id' => $this->nullableInt($row['channel_id'] ?? null),
                'channel_number' => $this->nullableString($row['channel_number'] ?? null),
                'title' => $this->nullableString($row['title'] ?? $row['matched_channel_titles'] ?? null),
                'source_number' => $this->nullableInt($row['source_number'] ?? null),
                'source_name' => $this->nullableString($row['source_name'] ?? null),
                'score' => $this->nullableInt($row['score'] ?? $row['best_score'] ?? null),
                'reason' => $this->nullableString(
                    ($row['exclusion_reason'] ?? '')
                        ?: ($row['review_reason'] ?? '')
                        ?: ($row['recommendation'] ?? '')
                        ?: ($row['repair_action'] ?? '')
                        ?: ($row['matched_on'] ?? null),
                ),
                'details' => json_encode($details, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ];
        }, $rows);
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return mb_substr((string) $value, 0, 255);
    }
}
