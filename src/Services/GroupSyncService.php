<?php

namespace AppLocalPlugins\GuideLink\Services;

use App\Models\Group;
use App\Models\Playlist;
use App\Plugins\Support\PluginExecutionContext;
use AppLocalPlugins\GuideLink\Support\CountryRulesRepository;
use AppLocalPlugins\GuideLink\Support\GroupIdentity;
use AppLocalPlugins\GuideLink\Support\Options;
use AppLocalPlugins\GuideLink\Support\ProfileDefaults;
use AppLocalPlugins\GuideLink\Support\RunCancelled;
use AppLocalPlugins\GuideLink\Support\RunProgress;
use Illuminate\Support\Facades\DB;

class GroupSyncService
{
    public function __construct(private readonly CountryRulesRepository $countryRules) {}

    /**
     * @param  array<int, array{number: int, name: string, category: string, aliases: array<int, string>, stream_id?: string}>  $sourceEntries
     * @param  array<string, mixed>  $rules
     * @param  array<string, Group>  $groupsByName
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    public function plan(array $sourceEntries, array $rules, array $groupsByName, array $payload = []): array
    {
        $rows = [];
        $scope = new ScopeFilter($this->countryRules);
        $preserveNames = Options::list($payload, [], 'preserve_groups', []);
        $preservePatterns = Options::patterns($payload, [], 'preserve_group_name_regex', []);

        foreach ($this->categoryOrder($sourceEntries) as $category => $sortOrder) {
            $groupKey = GroupIdentity::key($category);
            $group = $groupsByName[$groupKey] ?? null;
            $currentSort = Options::nullableInt($group?->sort_order);
            $preserved = ! Options::bool($payload, [], 'include_managed_groups', ProfileDefaults::bool('include_managed_groups'))
                || $scope->preservesGroup($category, $preserveNames, $preservePatterns)
                || ($group && $scope->preservesGroup((string) $group->name, $preserveNames, $preservePatterns));
            $action = match (true) {
                $preserved => 'preserve',
                ! $group => 'create',
                $group->trashed() => 'restore',
                ! $group->custom => 'protect',
                $currentSort !== $sortOrder => 'update_sort',
                default => 'exists',
            };

            $rows[] = [
                'category' => $category,
                'group_key' => $groupKey,
                'action' => $action,
                'group_id' => $group?->id,
                'current_sort_order' => $currentSort,
                'desired_sort_order' => $preserved ? $currentSort : $sortOrder,
                'current_custom' => $group ? (bool) $group->custom : null,
                'desired_custom' => $preserved ? ($group ? (bool) $group->custom : null) : true,
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
                RunProgress::checkCancellation($context);
                if ($row['action'] === 'preserve') {
                    continue;
                }
                $result = $this->applyRow($playlist, $row, $context);
                $group = $result['group'];
                $summary['managed_group_ids'][] = (int) $group->id;
                $summary['groups_by_name'][$row['group_key']] = $group;
                $summary['groups_created'] += (int) $result['created'];
                $summary['groups_updated'] += (int) $result['updated'];
            } catch (RunCancelled) {
                $summary['aborted'] = true;

                return $summary;
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
        $seen = [];
        foreach ($sourceEntries as $entry) {
            $category = (string) $entry['category'];
            $key = GroupIdentity::key($category);
            if ($key !== '' && ! isset($seen[$key])) {
                $order[$category] = count($order) + 1;
                $seen[$key] = true;
            }
        }

        return $order;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{group: Group, created: bool, updated: bool}
     */
    private function applyRow(Playlist $playlist, array $row, PluginExecutionContext $context): array
    {
        return DB::transaction(function () use ($playlist, $row, $context): array {
            if ($row['action'] === 'create') {
                // Serialize creates per playlist, then recheck the same managed-group
                // identity rules used during planning before inserting anything.
                Playlist::query()->where('user_id', $playlist->user_id)->whereKey($playlist->id)->lockForUpdate()->firstOrFail();
                $groups = (new ChannelRepository($this->countryRules))->groupsByName($playlist, []);
                $group = $groups[$row['group_key']] ?? new Group;
                if ($group->exists) {
                    $group = Group::withTrashed()->whereKey($group->id)->lockForUpdate()->firstOrFail();
                }
            } else {
                $group = Group::withTrashed()->where('playlist_id', $playlist->id)
                    ->whereKey($row['group_id'])->lockForUpdate()->firstOrFail();
            }

            RunProgress::checkCancellation($context);
            if ($group->exists && ($group->is_merged || ! in_array($group->type, [null, 'live'], true)
                || (int) $group->user_id !== (int) $playlist->user_id)) {
                throw new \RuntimeException("Managed group [{$group->id}] scope changed after planning; rerun sync.");
            }
            if ($group->exists && GroupIdentity::key((string) $group->name) !== $row['group_key']) {
                throw new \RuntimeException("Managed group [{$group->id}] was renamed after planning; rerun sync.");
            }
            if ($row['action'] === 'exists') {
                if ($group->trashed() || ! $group->custom) {
                    throw new \RuntimeException("Managed group [{$group->id}] protection changed after planning; rerun sync.");
                }

                return ['group' => $group, 'created' => false, 'updated' => false];
            }
            if ($row['action'] !== 'create' && $group->exists
                && Options::nullableInt($group->sort_order) !== $row['current_sort_order']
                && Options::nullableInt($group->sort_order) !== $row['desired_sort_order']) {
                throw new \RuntimeException("Managed group [{$group->id}] order changed after planning; rerun sync.");
            }

            $values = [
                'sort_order' => (int) $row['desired_sort_order'],
            ];
            if (! $group->custom) {
                $values['custom'] = true;
            }
            if (! $group->exists) {
                $values += [
                    'name' => (string) $row['category'],
                    'name_internal' => (string) $row['category'],
                    'user_id' => (int) $playlist->user_id,
                    'playlist_id' => (int) $playlist->id,
                    'type' => 'live',
                    'enabled' => true,
                ];
            }
            if ($group->trashed()) {
                $values['deleted_at'] = null;
            }

            $group->forceFill($values);
            $created = ! $group->exists;
            $updated = ! $created && $group->isDirty();
            if ($created || $updated) {
                $group->save();
            }

            return ['group' => $group, 'created' => $created, 'updated' => $updated];
        });
    }
}
