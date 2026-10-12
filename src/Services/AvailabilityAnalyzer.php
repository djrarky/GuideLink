<?php

namespace AppLocalPlugins\GuideLink\Services;

use App\Models\Channel;
use App\Models\Playlist;
use AppLocalPlugins\GuideLink\Support\ChannelAttributes;
use Illuminate\Database\Eloquent\Collection;

class AvailabilityAnalyzer
{
    public function __construct(
        private readonly ChannelRepository $channels,
        private readonly ChannelMatcher $matcher,
    ) {}

    /**
     * @param  Collection<int, Channel>  $channels
     * @param  array<int, true>  $scrubberChannelIds
     * @return array<int, array<string, mixed>>
     */
    public function channelRows(Playlist $playlist, Collection $channels, array $scrubberChannelIds): array
    {
        return $channels
            ->map(function (Channel $channel) use ($playlist, $scrubberChannelIds): array {
                $failovers = $this->channels->failoverSummary($channel);
                $scrubberFlagged = isset($scrubberChannelIds[(int) $channel->id]);

                return [
                    ...ChannelAttributes::reportRow($playlist, $channel),
                    'enabled' => (bool) $channel->enabled,
                    'scrubber_flagged' => $scrubberFlagged,
                    'failover_total' => $failovers['total'],
                    'failover_enabled' => $failovers['enabled'],
                    'enabled_failovers' => implode(' | ', $failovers['enabled_titles']),
                    'status' => $this->channelStatus($channel, $scrubberFlagged, $failovers['enabled']),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $channelRows
     * @return array<string, mixed>
     */
    public function summary(Playlist $playlist, array $channelRows): array
    {
        $statusCounts = array_count_values(array_map(
            fn (array $row): string => (string) $row['status'],
            $channelRows,
        ));

        return [
            'playlist' => [
                'id' => (int) $playlist->id,
                'uuid' => (string) $playlist->uuid,
                'name' => (string) $playlist->name,
            ],
            'availability' => [
                'total' => count($channelRows),
                'enabled' => $statusCounts['enabled'] ?? 0,
                'disabled' => count($channelRows) - ($statusCounts['enabled'] ?? 0),
                'primary_disabled_with_enabled_fallback' => $statusCounts['primary_disabled_with_enabled_fallback'] ?? 0,
                'scrubber_disabled_no_fallback' => $statusCounts['scrubber_disabled_no_fallback'] ?? 0,
                'disabled_no_fallback' => $statusCounts['disabled_no_fallback'] ?? 0,
            ],
        ];
    }

    /**
     * @param  array<int, array{number: int, name: string, category: string, aliases: array<int, string>, stream_id?: string}>  $sourceEntries
     * @param  Collection<int, Channel>  $channels
     * @param  array<int, true>  $scrubberChannelIds
     * @param  array{fuzzy_threshold: int, min_auto_score: int, min_auto_margin: int}  $thresholds
     * @param  array<string, mixed>  $rules
     * @return array<int, array<string, mixed>>
     */
    public function sourceCoverageRows(
        Playlist $targetPlaylist,
        array $sourceEntries,
        Collection $channels,
        array $scrubberChannelIds,
        array $thresholds,
        array $rules,
    ): array {
        $matchesBySourceNumber = $this->matchesBySourceNumber($sourceEntries, $channels, $thresholds, $rules);
        $rows = [];

        foreach ($sourceEntries as $source) {
            $sourceNumber = (int) $source['number'];
            $matches = $matchesBySourceNumber[$sourceNumber] ?? [];
            $coverage = $this->coverageStats($matches, $scrubberChannelIds);
            $status = $matches === [] ? 'no_iptv_match' : $this->sourceCoverageStatus($coverage);
            $bestMatch = $matches[0] ?? null;

            $rows[] = [
                'target_playlist_id' => (int) $targetPlaylist->id,
                'target_playlist_uuid' => (string) $targetPlaylist->uuid,
                'source_number' => $sourceNumber,
                'source_name' => $source['name'],
                'source_category' => $source['category'],
                'source_stream_id' => $source['stream_id'] ?? '',
                'status' => $status,
                'recommendation' => $this->sourceCoverageRecommendation($status),
                'matched_channel_ids' => $this->joinedChannelField($matches, 'id'),
                'matched_channel_titles' => $this->joinedChannelField($matches, 'title'),
                'enabled_matches' => $coverage['enabled'],
                'disabled_matches' => $coverage['disabled'],
                'scrubber_matches' => $coverage['scrubber'],
                'enabled_failovers' => count($coverage['failover_titles']),
                'failover_titles' => implode(' | ', $coverage['failover_titles']),
                'best_score' => $bestMatch['score'] ?? '',
                'best_decision' => $bestMatch['decision'] ?? '',
                'best_matched_on' => $bestMatch['matched_on'] ?? '',
            ];
        }

        return $rows;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function sourceCoverageSummary(array $rows): array
    {
        $statusCounts = array_count_values(array_map(
            fn (array $row): string => (string) $row['status'],
            $rows,
        ));
        $sourceTotal = count($rows);
        $enabled = $statusCounts['enabled_coverage'] ?? 0;

        return [
            'source_total' => $sourceTotal,
            'enabled_coverage' => $enabled,
            'disabled_only_with_enabled_fallback' => $statusCounts['disabled_only_with_enabled_fallback'] ?? 0,
            'scrubber_disabled_no_fallback' => $statusCounts['scrubber_disabled_no_fallback'] ?? 0,
            'disabled_only_no_fallback' => $statusCounts['disabled_only_no_fallback'] ?? 0,
            'no_iptv_match' => $statusCounts['no_iptv_match'] ?? 0,
            'coverage_percent' => $sourceTotal > 0 ? round(($enabled / $sourceTotal) * 100, 2) : 0.0,
        ];
    }

    private function channelStatus(Channel $channel, bool $scrubberFlagged, int $enabledFailovers): string
    {
        if ($channel->enabled) {
            return 'enabled';
        }

        if ($enabledFailovers > 0) {
            return 'primary_disabled_with_enabled_fallback';
        }

        if ($scrubberFlagged) {
            return 'scrubber_disabled_no_fallback';
        }

        return 'disabled_no_fallback';
    }

    /**
     * @param  array<int, array{number: int, name: string, category: string, aliases: array<int, string>, stream_id?: string}>  $sourceEntries
     * @param  Collection<int, Channel>  $channels
     * @param  array{fuzzy_threshold: int, min_auto_score: int, min_auto_margin: int}  $thresholds
     * @param  array<string, mixed>  $rules
     * @return array<int, array<int, array{channel: Channel, decision: string, score: int, matched_on: string}>>
     */
    private function matchesBySourceNumber(array $sourceEntries, Collection $channels, array $thresholds, array $rules): array
    {
        $sourceIndex = $this->matcher->sourceIndex($sourceEntries, $rules);
        $matches = [];

        foreach ($channels as $channel) {
            $result = $this->matcher->match($channel, $sourceIndex, $thresholds);
            $source = $result['source'];
            if (! is_array($source) || ! isset($source['number'])) {
                continue;
            }

            $matches[(int) $source['number']][] = [
                'channel' => $channel,
                'decision' => (string) $result['decision'],
                'score' => (int) $result['score'],
                'matched_on' => (string) ($result['matched_on'] ?? ''),
            ];
        }

        foreach ($matches as &$sourceMatches) {
            usort($sourceMatches, fn (array $left, array $right): int => [
                (int) $right['channel']->enabled,
                $right['score'],
                -((int) $right['channel']->id),
            ] <=> [
                (int) $left['channel']->enabled,
                $left['score'],
                -((int) $left['channel']->id),
            ]);
        }

        return $matches;
    }

    private function sourceCoverageStatus(array $coverage): string
    {
        return match (true) {
            $coverage['enabled'] > 0 => 'enabled_coverage',
            $coverage['failover_titles'] !== [] => 'disabled_only_with_enabled_fallback',
            $coverage['scrubber'] > 0 => 'scrubber_disabled_no_fallback',
            default => 'disabled_only_no_fallback',
        };
    }

    private function sourceCoverageRecommendation(string $status): string
    {
        return match ($status) {
            'enabled_coverage' => 'covered',
            'disabled_only_with_enabled_fallback' => 'review_disabled_primary',
            'scrubber_disabled_no_fallback' => 'review_scrubber_disablement',
            'disabled_only_no_fallback' => 'review_disabled_channel',
            default => 'add_or_map_channel',
        };
    }

    /**
     * @param  array<int, array{channel: Channel, decision: string, score: int, matched_on: string}>  $matches
     * @param  array<int, true>  $scrubberChannelIds
     * @return array{enabled: int, disabled: int, scrubber: int, failover_titles: array<int, string>}
     */
    private function coverageStats(array $matches, array $scrubberChannelIds): array
    {
        $coverage = [
            'enabled' => 0,
            'disabled' => 0,
            'scrubber' => 0,
            'failover_titles' => [],
        ];
        $failoverTitles = [];

        foreach ($matches as $match) {
            $channel = $match['channel'];
            $coverage[$channel->enabled ? 'enabled' : 'disabled']++;
            $coverage['scrubber'] += isset($scrubberChannelIds[(int) $channel->id]) ? 1 : 0;

            foreach ($this->channels->failoverSummary($channel)['enabled_titles'] as $title) {
                $failoverTitles[$title] = $title;
            }
        }

        $coverage['failover_titles'] = array_values($failoverTitles);

        return $coverage;
    }

    /**
     * @param  array<int, array{channel: Channel, decision: string, score: int, matched_on: string}>  $matches
     */
    private function joinedChannelField(array $matches, string $field): string
    {
        $values = [];

        foreach ($matches as $match) {
            $channel = $match['channel'];
            $values[] = match ($field) {
                'id' => (string) $channel->id,
                'title' => ChannelAttributes::title($channel),
                default => '',
            };
        }

        return implode(' | ', array_values(array_filter($values)));
    }
}
