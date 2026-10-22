<?php

namespace AppLocalPlugins\GuideLink\Services;

use App\Plugins\Support\PluginExecutionContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\LazyCollection;

/**
 * @phpstan-import-type MatchRow from ChannelMatcher
 * @phpstan-import-type ExcludedRow from ScopeFilter
 * @phpstan-import-type WritePlanRow from WritePlanBuilder
 */
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
        $rows = $this->mapRows($channelRows, 'availability', 'status')
            ->concat($this->mapRows($sourceCoverageRows ?? [], 'source_coverage', 'status'));

        return $this->replace($context, $playlistId, ['availability', 'source_coverage'], $rows);
    }

    /**
     * @param  list<MatchRow>  $matchRows
     * @param  list<ExcludedRow>  $excludedRows
     * @param  list<WritePlanRow>  $writePlan
     * @return array<string, mixed>
     */
    public function replaceSync(
        PluginExecutionContext $context,
        int $playlistId,
        array $matchRows,
        array $excludedRows,
        array $writePlan,
        array $quarantinePlan = [],
        array $groupDisablePlan = [],
    ): array {
        $plansByChannel = [];
        foreach ($writePlan as $plan) {
            if (isset($plan['channel_id'])) {
                $plansByChannel[(int) $plan['channel_id']] = $plan;
            }
        }

        $quarantinesByChannel = array_column($quarantinePlan, null, 'channel_id');
        $withPlans = function (array $row) use ($plansByChannel, $quarantinesByChannel): array {
            $channelId = (int) ($row['channel_id'] ?? 0);
            if (isset($plansByChannel[$channelId]) && ($row['decision'] ?? '') !== 'excluded') {
                $row['write_plan'] = $plansByChannel[$channelId];
            }
            if (isset($quarantinesByChannel[$channelId])) {
                $row['quarantine_plan'] = $quarantinesByChannel[$channelId];
            }

            return $row;
        };
        $rows = $this->mapRows($matchRows, 'sync_match', 'decision', $withPlans)
            ->concat($this->mapRows($excludedRows, 'sync_excluded', 'decision', $withPlans))
            ->concat($this->mapRows($groupDisablePlan, 'sync_group_disable', 'status'));

        return $this->replace($context, $playlistId, ['sync_match', 'sync_excluded', 'sync_group_disable'], $rows);
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
     * @param  iterable<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function replace(
        PluginExecutionContext $context,
        int $playlistId,
        array $resultTypes,
        iterable $rows,
    ): array {
        if (! Schema::hasTable(self::TABLE)) {
            throw new \RuntimeException('GuideLink run-results table is missing; reinstall or repair the plugin schema.');
        }

        $now = now();
        [$typeCounts, $decisionCounts] = DB::transaction(function () use ($context, $playlistId, $resultTypes, $rows, $now): array {
            DB::table(self::TABLE)
                ->where('extension_plugin_id', $context->plugin->id)
                ->where('extension_plugin_run_id', $context->run->id)
                ->where('playlist_id', $playlistId)
                ->whereIn('result_type', $resultTypes)
                ->delete();

            $chunk = [];
            $typeCounts = [];
            $decisionCounts = [];
            foreach ($rows as $row) {
                $typeCounts[$row['result_type']] = ($typeCounts[$row['result_type']] ?? 0) + 1;
                if ($row['decision'] !== null && $row['decision'] !== '') {
                    $decisionCounts[$row['decision']] = ($decisionCounts[$row['decision']] ?? 0) + 1;
                }
                $chunk[] = [
                    'extension_plugin_id' => (int) $context->plugin->id,
                    'extension_plugin_run_id' => (int) $context->run->id,
                    'playlist_id' => $playlistId,
                    ...$row,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                if (count($chunk) === 250) {
                    DB::table(self::TABLE)->insert($chunk);
                    $chunk = [];
                }
            }
            if ($chunk !== []) {
                DB::table(self::TABLE)->insert($chunk);
            }

            return [$typeCounts, $decisionCounts];
        });

        ksort($decisionCounts);
        ksort($typeCounts);

        return [
            'table' => 'run_results',
            'run_id' => (int) $context->run->id,
            'playlist_id' => $playlistId,
            'rows' => array_sum($typeCounts),
            'by_type' => $typeCounts,
            'by_decision' => $decisionCounts,
            'retention' => 'plugin_run',
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  (\Closure(array<string, mixed>): array<string, mixed>)|null  $enrich
     * @return LazyCollection<int, array<string, mixed>>
     */
    private function mapRows(array $rows, string $resultType, string $decisionKey, ?\Closure $enrich = null): LazyCollection
    {
        // Enrich and serialize only as the transaction consumes each insert batch.
        return (new LazyCollection($rows))->map(function (array $row) use ($resultType, $decisionKey, $enrich): array {
            $row = $enrich ? $enrich($row) : $row;
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
        });
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
