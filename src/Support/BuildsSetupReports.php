<?php

namespace AppLocalPlugins\GuideLink\Support;

trait BuildsSetupReports
{
    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, string>  $statuses
     * @return array<string, int>
     */
    protected function summaryFor(array $rows, array $statuses): array
    {
        $counts = array_count_values(array_map(
            fn (array $row): string => (string) ($row['status'] ?? 'unknown'),
            $rows,
        ));
        $summary = ['checks' => count($rows)];

        foreach ($statuses as $status) {
            $summary[$status] = (int) ($counts[$status] ?? 0);
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, array{from: mixed, to: mixed}>
     */
    protected function changedFields(array $before, array $after): array
    {
        $changes = [];
        foreach ($after as $key => $value) {
            if (($before[$key] ?? null) !== $value) {
                $changes[$key] = [
                    'from' => $before[$key] ?? null,
                    'to' => $value,
                ];
            }
        }

        return $changes;
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    protected function checkRow(string $key, string $title, mixed $current, mixed $desired, string $recommendation, array $details = []): array
    {
        $matches = $current === $desired;

        return $this->row(
            $matches ? 'ok' : 'change_required',
            $title,
            $matches ? 'Native setting already matches desired GuideLink setup.' : $recommendation,
            [
                ...$details,
                'key' => $key,
                'current' => $current,
                'desired' => $desired,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    protected function row(string $status, string $title, string $recommendation, array $details = []): array
    {
        return [
            'status' => $status,
            'title' => $title,
            'recommendation' => $recommendation,
            'details' => $details,
        ];
    }
}
