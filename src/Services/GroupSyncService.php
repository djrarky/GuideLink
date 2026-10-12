<?php

namespace AppLocalPlugins\GuideLink\Services;

use App\Models\Group;
use App\Models\Playlist;
use App\Plugins\Support\PluginExecutionContext;
use AppLocalPlugins\GuideLink\Support\CountryRulesRepository;
use AppLocalPlugins\GuideLink\Support\Options;
use AppLocalPlugins\GuideLink\Support\RunProgress;

class GroupSyncService
{
    public function __construct(private readonly CountryRulesRepository $countryRules) {}

    /**
     * @param  array<int, array{number: int, name: string, category: string, aliases: array<int, string>, stream_id?: string}>  $sourceEntries
     * @param  array<string, mixed>  $rules
     * @param  array<string, Group>  $groupsByName
     * @return array<int, array<string, mixed>>
     */
    public function plan(array $sourceEntries, array $rules, array $groupsByName): array
    {
        $rows = [];

        foreach ($this->categoryOrder($sourceEntries) as $category => $sortOrder) {
            $groupKey = $this->countryRules->normalize($category, $rules);
            $group = $groupsByName[$groupKey] ?? null;
            $currentSort = Options::nullableInt($group?->sort_order);
            $action = match (true) {
                ! $group => 'create',
                $currentSort !== $sortOrder => 'update_sort',
                default => 'exists',
            };

            $rows[] = [
                'category' => $category,
                'group_key' => $groupKey,
                'action' => $action,
                'group_id' => $group?->id,
                'current_sort_order' => $currentSort,
                'desired_sort_order' => $sortOrder,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<int, array<string, mixed>>  $groupPlan
     * @return array{groups_created: int, groups_updated: int, groups_failed: int, aborted: bool, managed_group_ids: array<int, int>, groups_by_name: array<string, Group>, errors: array<int, string>}
     */
    public function apply(Playlist $playlist, array $groupPlan, PluginExecutionContext $context): array
    {
        $summary = [
            'groups_created' => 0,
            'groups_updated' => 0,
            'groups_failed' => 0,
            'aborted' => false,
            'managed_group_ids' => [],
            'groups_by_name' => [],
            'errors' => [],
        ];

        $total = count($groupPlan);
        foreach ($groupPlan as $index => $row) {
            if ($context->cancellationRequested()) {
                $summary['aborted'] = true;
                $summary['errors'][] = 'Cancelled before group setup completed.';

                return $summary;
            }

            if (RunProgress::shouldHeartbeat($index, $total)) {
                $context->heartbeat('Group setup: processing '.($index + 1).' of '.$total.' managed group row(s).', null, [
                    'group_setup' => [
                        'position' => $index + 1,
                        'total' => $total,
                        'created' => $summary['groups_created'],
                        'updated' => $summary['groups_updated'],
                        'failed' => $summary['groups_failed'],
                    ],
                ]);
            }

            try {
                $group = $this->applyRow($playlist, $row);
                $summary['managed_group_ids'][] = (int) $group->id;
                $summary['groups_by_name'][$row['group_key']] = $group;
                if ($row['action'] === 'create') {
                    $summary['groups_created']++;
                } elseif ($row['action'] === 'update_sort') {
                    $summary['groups_updated']++;
                }
            } catch (\Throwable $exception) {
                $summary['groups_failed']++;
                $summary['aborted'] = true;
                $summary['errors'][] = $exception->getMessage();

                return $summary;
            }
        }

        $summary['managed_group_ids'] = array_values(array_unique($summary['managed_group_ids']));

        return $summary;
    }

    /**
     * @param  array<int, array{number: int, name: string, category: string, aliases: array<int, string>, stream_id?: string}>  $sourceEntries
     * @return array<string, int>
     */
    private function categoryOrder(array $sourceEntries): array
    {
        $order = [];
        foreach ($sourceEntries as $entry) {
            $category = (string) $entry['category'];
            if ($category !== '' && ! isset($order[$category])) {
                $order[$category] = count($order) + 1;
            }
        }

        return $order;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function applyRow(Playlist $playlist, array $row): Group
    {
        if ($row['action'] === 'exists') {
            return Group::query()->whereKey($row['group_id'])->firstOrFail();
        }

        $group = $row['action'] === 'create'
            ? new Group
            : Group::query()->whereKey($row['group_id'])->firstOrFail();

        $values = [
            'name' => (string) $row['category'],
            'name_internal' => (string) $row['category'],
            'user_id' => (int) $playlist->user_id,
            'playlist_id' => (int) $playlist->id,
            'type' => 'live',
            'enabled' => true,
            'sort_order' => (int) $row['desired_sort_order'],
        ];

        $group->forceFill($values)->save();

        return $group;
    }
}
