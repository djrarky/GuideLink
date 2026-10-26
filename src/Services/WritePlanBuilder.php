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
 * native_columns is the intended state, native_changes is only changed fields,
 * and native_originals is the raw before-state for those changed fields.
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
 *     group_id: int|null, group: string, epg_channel_id: int|string|null, logo_type: string|null
 * }
 * @phpstan-type WritePlanRow array{
 *     playlist_id: int, channel_id: int, channel_uuid: string|null,
 *     decision: string, tier: int|'', score: int,
 *     current: MetadataView, desired: MetadataView,
 *     native_columns: NativeMetadata, native_changes: NativeMetadata,
 *     native_originals: NativeMetadata, decision_state: DecisionState,
 *     changes: array<string, array{from: mixed, to: mixed}>, missing_group: string|null
 * }
 */
class WritePlanBuilder
{
    /**
     * @param  list<MatchRow>  $matchRows
     * @param  array<string, Group>  $groupsByName
     * @param  array<int, int>  $guideChannelIds
     * @param  array<string, true>  $preservedGroupKeys
     * @return list<WritePlanRow>
     */
    public function rows(array $matchRows, array $groupsByName, array $guideChannelIds = [], string $logoSource = 'preserve', array $preservedGroupKeys = []): array
    {
        $rows = [];

        foreach ($matchRows as $matchRow) {
            if (! MatchDecision::isConfirmed($matchRow['decision'])) {
                continue;
            }

            if ($matchRow['source_number'] === '' || $matchRow['source_name'] === '') {
                continue;
            }

            $changes = [];
            $desiredNumber = (int) $matchRow['source_number'];
            $desiredName = (string) $matchRow['source_name'];
            $desiredCategory = (string) $matchRow['source_category'];
            $groupKey = GroupIdentity::key($desiredCategory);
            $preserveGroup = $groupKey === '' || isset($preservedGroupKeys[$groupKey]);
            $desiredStreamId = (string) ($matchRow['source_stream_id'] ?? '');
            $desiredGroup = $groupsByName[$groupKey] ?? null;
            $writeLogo = $logoSource === 'channel' || ($logoSource === 'epg' && isset($guideChannelIds[$desiredNumber]));
            $current = [
                'title' => $matchRow['title'],
                'name' => $matchRow['name'],
                'stream_id' => $matchRow['stream_id'],
                'channel_number' => $matchRow['channel_number'],
                'sort_order' => $matchRow['_sort'],
                'group_id' => $matchRow['_group_id'],
                'group' => $matchRow['group'],
                'epg_channel_id' => $matchRow['_epg_channel_id'] ?? null,
                'logo_type' => $matchRow['_logo_type'] ?? null,
            ];
            $desired = [
                'title' => $desiredName,
                'name' => $desiredName,
                'stream_id' => $desiredStreamId,
                'channel_number' => $desiredNumber,
                'sort_order' => $desiredNumber,
                'group_id' => $preserveGroup ? $current['group_id'] : $desiredGroup?->id,
                'group' => $preserveGroup ? $current['group'] : $desiredCategory,
                'epg_channel_id' => $guideChannelIds[$desiredNumber] ?? $current['epg_channel_id'],
                'logo_type' => $writeLogo ? $logoSource : $current['logo_type'],
            ];

            foreach (['title', 'name', 'channel_number', 'sort_order'] as $field) {
                $this->fieldChange($changes, $field, $current[$field], $desired[$field]);
            }
            if ($desiredStreamId !== '') {
                $this->fieldChange($changes, 'stream_id', $current['stream_id'], $desired['stream_id']);
            }
            if (isset($guideChannelIds[$desiredNumber])) {
                $this->fieldChange($changes, 'epg_channel_id', $current['epg_channel_id'], $desired['epg_channel_id']);
            }
            if ($desiredGroup) {
                $this->fieldChange($changes, 'group_id', $current['group_id'], $desired['group_id']);
            }
            if ($writeLogo) {
                $this->fieldChange($changes, 'logo_type', $current['logo_type'], $desired['logo_type']);
            }

            $missingGroup = $desiredGroup || $preserveGroup ? null : $desiredCategory;
            if ($changes === [] && $missingGroup === null) {
                continue;
            }

            $nativeColumns = [];
            foreach (ChannelAttributes::NATIVE_METADATA_FIELDS as $field => $column) {
                if (($field === 'stream_id' && $desiredStreamId === '')
                    || ($field === 'epg_channel_id' && ! isset($guideChannelIds[$desiredNumber]))
                    || ($field === 'logo_type' && ! $writeLogo)) {
                    continue;
                }
                $nativeColumns[$column] = $desired[$field];
            }
            $nativeChanges = [];
            foreach ($changes as $field => $change) {
                $nativeChanges[ChannelAttributes::NATIVE_METADATA_FIELDS[$field]] = $change['to'];
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
                'native_columns' => $nativeColumns,
                'native_changes' => $nativeChanges,
                'native_originals' => array_intersect_key($matchRow['_native_originals'] ?? [], $nativeChanges),
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

    /**
     * @param  array<string, array{from: mixed, to: mixed}>  $changes
     */
    private function fieldChange(array &$changes, string $field, mixed $current, mixed $desired): void
    {
        if (in_array($field, ['channel_number', 'sort_order'], true) && is_numeric($current) && is_numeric($desired)) {
            if ((float) $current === (float) $desired) {
                return;
            }
        } elseif ((string) $current === (string) $desired) {
            return;
        }

        $changes[$field] = [
            'from' => $current,
            'to' => $desired,
        ];
    }
}
