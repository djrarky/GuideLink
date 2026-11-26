<?php

namespace AppLocalPlugins\GuideLink\Services;

use App\Models\Group;
use App\Models\Playlist;
use AppLocalPlugins\GuideLink\Support\ChannelAttributes;
use AppLocalPlugins\GuideLink\Support\GroupIdentity;
use AppLocalPlugins\GuideLink\Support\MatchDecision;

/**
 * Translate confirmed matches into proposed metadata differences, without writes.
 * NativeMetadata uses database column names; MetadataView uses report labels.
 * native_changes contains changed fields and native_originals their raw before-state.
 * A missing_group requires group creation and replanning before ChannelWriter.
 *
 * @phpstan-import-type SourceEntry from ParsedSourceLoader
 * @phpstan-import-type MatchRow from ChannelMatcher
 * @phpstan-import-type ExcludedRow from ScopeFilter
 * @phpstan-import-type DecisionState from ChannelAttributes
 * @phpstan-import-type NativeMetadata from ChannelAttributes
 *
 * @phpstan-type MetadataView array{
 *     title: string, name: string, stream_id: string,
 *     channel_number: int|float|string, sort_order: int|float|string|null,
 *     group_id: int|null, group: string, logo_type: string|null
 * }
 * @phpstan-type WritePlanRow array{
 *     playlist_id: int, channel_id: int, channel_uuid: string|null,
 *     decision: string, tier: int|'', score: int,
 *     current: MetadataView, desired: MetadataView,
 *     native_changes: NativeMetadata,
 *     native_originals: NativeMetadata, decision_state: DecisionState,
 *     changes: array<string, array{from: mixed, to: mixed}>, missing_group: string|null
 * }
 */
class WritePlanBuilder
{
    /**
     * @param  list<MatchRow>  $matchRows
     * @param  array<string, Group>  $groupsByName
     * @param  array<string, true>  $preservedGroupKeys
     * @return list<WritePlanRow>
     */
    public function rows(array $matchRows, array $groupsByName, string $logoSource = 'preserve', array $preservedGroupKeys = []): array
    {
        $rows = [];

        foreach ($matchRows as $matchRow) {
            if (! MatchDecision::isConfirmed($matchRow['decision'])) {
                continue;
            }

            if ($matchRow['source_number'] === '' || $matchRow['source_name'] === '') {
                continue;
            }

            $desiredNumber = (int) $matchRow['source_number'];
            $desiredName = (string) $matchRow['source_name'];
            $desiredCategory = (string) $matchRow['source_category'];
            $groupKey = GroupIdentity::key($desiredCategory);
            $preserveGroup = $groupKey === '' || isset($preservedGroupKeys[$groupKey]);
            $desiredStreamId = (string) ($matchRow['source_stream_id'] ?? '');
            $desiredGroup = $groupsByName[$groupKey] ?? null;
            $originals = $matchRow['_native_originals'];
            $current = [
                'title' => $matchRow['title'],
                'name' => $matchRow['name'],
                'stream_id' => $matchRow['stream_id'],
                'channel_number' => $matchRow['channel_number'],
                'sort_order' => $originals['sort'] ?? null,
                'group_id' => isset($originals['group_id']) ? (int) $originals['group_id'] : null,
                'group' => $matchRow['group'],
                'logo_type' => $originals['logo_type'] ?? null,
            ];
            $desired = [
                'title' => $desiredName,
                'name' => $desiredName,
                'stream_id' => $desiredStreamId !== '' ? $desiredStreamId : $current['stream_id'],
                'channel_number' => $desiredNumber,
                'sort_order' => $desiredNumber,
                'group_id' => $preserveGroup ? $current['group_id'] : $desiredGroup?->id,
                'group' => $preserveGroup ? $current['group'] : $desiredCategory,
                // The native map sets EPG preference; only an explicit provider
                // reset belongs in the metadata plan.
                'logo_type' => $logoSource === 'channel' ? 'channel' : $current['logo_type'],
            ];

            $changes = [];
            $nativeChanges = [];
            foreach (ChannelAttributes::NATIVE_METADATA_FIELDS as $field => $column) {
                if ($field === 'group_id' && ! $desiredGroup) {
                    // Preserve the existing group until a destination exists.
                    // Sync creates missing groups and then rebuilds the plan.
                    continue;
                }
                if ($this->sameDisplayValue($field, $current[$field], $desired[$field])) {
                    continue;
                }

                $changes[$field] = ['from' => $current[$field], 'to' => $desired[$field]];
                $nativeChanges[$column] = $desired[$field];
            }

            $missingGroup = $desiredGroup || $preserveGroup ? null : $desiredCategory;
            if ($changes === [] && $missingGroup === null) {
                continue;
            }

            $rows[] = [
                'playlist_id' => (int) $matchRow['playlist_id'],
                'channel_id' => (int) $matchRow['channel_id'],
                'channel_uuid' => $matchRow['channel_uuid'],
                'decision' => (string) $matchRow['decision'],
                'tier' => $matchRow['tier'],
                'score' => (int) $matchRow['score'],
                'current' => $current,
                'desired' => $desired,
                'native_changes' => $nativeChanges,
                'native_originals' => array_intersect_key($originals, $nativeChanges),
                'decision_state' => $matchRow['_decision_state'] ?? [],
                'changes' => $changes,
                'missing_group' => $missingGroup,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<MatchRow>  $matchRows
     * @param  list<WritePlanRow>  $writePlan
     * @param  list<SourceEntry>  $sourceEntries
     * @param  list<ExcludedRow>  $excludedRows
     * @return array<string, mixed>
     */
    public function summary(
        Playlist $playlist,
        array $matchRows,
        array $writePlan,
        array $sourceEntries,
        array $excludedRows,
        bool $writesEnabled = false,
        ?array $groupPlan = null,
        ?array $groupResult = null,
        ?array $writeResult = null,
    ): array {
        $decisionCounts = array_count_values(array_column($matchRows, 'decision'));
        $enabledMatches = 0;
        $matchedSourceNumbers = [];
        $reviewSourceNumbers = [];
        foreach ($matchRows as $row) {
            $enabledMatches += (int) ($row['enabled'] ?? true);
            $sourceNumber = $row['source_number'] ?? '';
            if ($sourceNumber !== '' && $sourceNumber !== null) {
                if (MatchDecision::isConfirmed($row['decision'])) {
                    $matchedSourceNumbers[(string) $sourceNumber] = true;
                } elseif ($row['decision'] === 'needs_review') {
                    $reviewSourceNumbers[(string) $sourceNumber] = true;
                }
            }
        }
        $reviewSourceNumbers = array_diff_key($reviewSourceNumbers, $matchedSourceNumbers);

        $exclusionCounts = array_count_values(array_column($excludedRows, 'exclusion_reason'));

        $missingGroups = [];
        $writePlanFieldChanges = 0;
        foreach ($writePlan as $row) {
            $writePlanFieldChanges += count($row['changes']);

            $missingGroup = (string) ($row['missing_group'] ?? '');
            if ($missingGroup !== '') {
                $missingGroups[$missingGroup] = true;
            }
        }

        $groupActionCounts = array_count_values(array_column($groupPlan ?? [], 'action'));

        return [
            'playlist' => [
                'id' => (int) $playlist->id,
                'uuid' => (string) $playlist->uuid,
                'name' => (string) $playlist->name,
            ],
            'matching' => [
                'enabled_live_channels' => $enabledMatches,
                'total_enabled_live_channels' => $enabledMatches + count(array_filter($excludedRows, fn (array $row): bool => $row['enabled'] ?? true)),
                'total_live_channels' => count($matchRows) + count($excludedRows),
                'in_scope_channels' => count($matchRows),
                'excluded_channels' => count($excludedRows),
                'excluded_by_reason' => $exclusionCounts,
                'source_total' => count($sourceEntries),
                'fingerprint' => $decisionCounts['fingerprint'] ?? 0,
                'auto_apply' => $decisionCounts['auto_apply'] ?? 0,
                'needs_review' => $decisionCounts['needs_review'] ?? 0,
                'unmatched' => $decisionCounts['unmatched'] ?? 0,
                'source_matched' => count($matchedSourceNumbers),
                'source_needs_review' => count($reviewSourceNumbers),
                'source_no_iptv_match' => max(count($sourceEntries) - count($matchedSourceNumbers) - count($reviewSourceNumbers), 0),
            ],
            'writes' => [
                'writes_enabled' => $writesEnabled,
                'write_plan_channels' => count($writePlan),
                'write_plan_field_changes' => $writePlanFieldChanges,
                'missing_groups' => count($missingGroups),
                'missing_group_names' => array_keys($missingGroups),
                'groups_to_create' => $groupActionCounts['create'] ?? 0,
                'groups_preserved' => $groupActionCounts['preserve'] ?? 0,
                'groups_to_update' => ($groupActionCounts['update_sort'] ?? 0) + ($groupActionCounts['protect'] ?? 0) + ($groupActionCounts['restore'] ?? 0),
                'groups_created' => $groupResult['groups_created'] ?? 0,
                'groups_updated' => $groupResult['groups_updated'] ?? 0,
                'groups_failed' => $groupResult['groups_failed'] ?? 0,
                'channels_written' => $writeResult['channels_written'] ?? 0,
                'channels_skipped' => $writeResult['channels_skipped'] ?? 0,
                'channels_failed' => $writeResult['channels_failed'] ?? 0,
                'unmanaged_channels_disabled' => $writeResult['unmanaged_channels_disabled'] ?? 0,
                'aborted' => (bool) (($groupResult['aborted'] ?? false) || ($writeResult['aborted'] ?? false)),
                'errors' => [...($groupResult['errors'] ?? []), ...($writeResult['errors'] ?? [])],
            ],
        ];
    }

    /** Compare report values; locked writes separately check raw database values. */
    private function sameDisplayValue(string $field, mixed $current, mixed $desired): bool
    {
        if (in_array($field, ['channel_number', 'sort_order'], true) && is_numeric($current) && is_numeric($desired)) {
            return (float) $current === (float) $desired;
        }

        return (string) $current === (string) $desired;
    }
}
