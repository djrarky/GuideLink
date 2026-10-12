<?php

namespace AppLocalPlugins\GuideLink\Services;

use App\Models\Channel;
use App\Models\Playlist;
use App\Plugins\Support\PluginExecutionContext;
use AppLocalPlugins\GuideLink\Support\ChannelAttributes;
use AppLocalPlugins\GuideLink\Support\CountryRulesRepository;
use AppLocalPlugins\GuideLink\Support\Options;
use AppLocalPlugins\GuideLink\Support\ProfileDefaults;
use AppLocalPlugins\GuideLink\Support\RunProgress;
use Illuminate\Database\Eloquent\Collection;

/**
 * Matches playlist channels to canonical source rows with Python-reference guardrails.
 *
 * Exact matches stay intentionally simple. The fuzzy path is stricter so sibling
 * regional channels and near-name collisions land in review unless the evidence
 * is strong enough to apply automatically.
 */
class ChannelMatcher
{
    public function __construct(private readonly CountryRulesRepository $countryRules) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $settings
     * @return array<string, int|float>
     */
    public function thresholds(array $payload, array $settings): array
    {
        $boundedInt = fn (string $key): int => max(0, min(100, Options::number($payload, $settings, $key, ProfileDefaults::int($key))));
        $boundedFloat = fn (string $key): float => max(0.0, min(1.0, Options::float($payload, $settings, $key, ProfileDefaults::float($key))));
        $fuzzyThreshold = $boundedInt('fuzzy_threshold');
        $sortFloorOffset = $boundedInt('sort_floor_offset');

        return [
            'fuzzy_threshold' => $fuzzyThreshold,
            'min_auto_score' => $boundedInt('min_auto_score'),
            'min_auto_margin' => $boundedInt('min_auto_margin'),
            'sort_floor_offset' => $sortFloorOffset,
            'sort_floor' => max($fuzzyThreshold - $sortFloorOffset, 0),
            'min_alias_words' => max(1, Options::number($payload, $settings, 'min_alias_words', ProfileDefaults::int('min_alias_words'))),
            'min_alias_chars' => max(1, Options::number($payload, $settings, 'min_alias_chars', ProfileDefaults::int('min_alias_chars'))),
            'min_set_tokens' => max(1, Options::number($payload, $settings, 'min_set_tokens', ProfileDefaults::int('min_set_tokens'))),
            'min_ratio_len_ratio' => $boundedFloat('min_ratio_len_ratio'),
            'min_ratio_score' => $boundedInt('min_ratio_score'),
            'min_partial_len_ratio' => $boundedFloat('min_partial_len_ratio'),
            'min_partial_len' => max(1, Options::number($payload, $settings, 'min_partial_len', ProfileDefaults::int('min_partial_len'))),
            'min_partial_ratio_score' => $boundedInt('min_partial_ratio_score'),
            'max_short_token_len' => max(1, Options::number($payload, $settings, 'max_short_token_len', ProfileDefaults::int('max_short_token_len'))),
            'min_token_similarity' => $boundedInt('min_token_similarity'),
        ];
    }

    /**
     * @param  Collection<int, Channel>  $channels
     * @param  array<int, array{number: int, name: string, category: string, aliases: array<int, string>, stream_id?: string, guide_valid?: bool}>  $sourceEntries
     * @param  array<string, int|float>  $thresholds
     * @param  array<string, mixed>  $rules
     * @return array<int, array<string, mixed>>
     */
    public function rows(
        Playlist $playlist,
        Collection $channels,
        array $sourceEntries,
        array $thresholds,
        array $rules,
        ?PluginExecutionContext $context = null,
    ): array {
        $sourceIndex = $this->sourceIndex($sourceEntries, $rules, $thresholds);
        $rows = [];
        $total = $channels->count();

        foreach ($channels->values() as $index => $channel) {
            if (RunProgress::shouldHeartbeat($index, $total, every: 50)) {
                $context?->heartbeat('Matching channels: processing '.($index + 1).' of '.$total.' in-scope channel(s).', null, [
                    'matching' => [
                        'position' => $index + 1,
                        'total' => $total,
                    ],
                ]);
            }

            $result = $this->match($channel, $sourceIndex, $thresholds);
            $source = $result['source'];

            $rows[] = [
                ...ChannelAttributes::reportRow($playlist, $channel),
                'decision' => $result['decision'],
                'tier' => $result['tier'] ?? '',
                'score' => $result['score'],
                'matched_on' => $result['matched_on'] ?? '',
                'source_number' => $source['number'] ?? '',
                'source_name' => $source['name'] ?? '',
                'source_category' => $source['category'] ?? '',
                'source_stream_id' => $source['stream_id'] ?? '',
                'candidate_summary' => $this->candidateSummary($result['candidates'] ?? []),
                'review_reason' => $result['review_reason'] ?? '',
                'alias_sets' => $result['alias_sets'] ?? [],
                'exclusion_reason' => '',
                '_group_id' => $channel->group_id,
                '_sort' => $channel->sort,
                '_epg_channel_id' => $channel->epg_channel_id,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<int, array{number: int, name: string, category: string, aliases: array<int, string>, stream_id?: string, guide_valid?: bool}>  $sourceEntries
     * @param  array<string, mixed>  $rules
     * @param  array<string, int|float>  $thresholds
     * @return array<string, mixed>
     */
    public function sourceIndex(array $sourceEntries, array $rules, array $thresholds = []): array
    {
        $index = [
            'rules' => $rules,
            'by_stream_id' => [],
            'by_name' => [],
            'by_alias' => [],
            'by_profile_alias' => [],
            'by_profile_compact' => [],
            'profile_keys' => [],
            'by_regional_alias' => [],
            'by_compact' => [],
            'by_label' => [],
            'by_initials' => [],
            'label_keys' => [],
            'fuzzy' => [],
            'tokens' => [],
            'norm_by_number' => [],
            'prefixes' => [],
            'region_pattern' => $this->regionPattern($rules),
            'regional_bases' => [],
        ];
        $guideEntries = [];

        foreach ($sourceEntries as $source) {
            if ($source['guide_only'] ?? false) {
                $source['guide_only'] = false;
                $guideEntries[] = $source;

                continue;
            }
            $number = (int) $source['number'];
            $nameKey = $this->countryRules->normalize($source['name'], $rules);

            $index['norm_by_number'][$number] = $nameKey;
            $regionalBase = $this->withoutRegion($nameKey, $index['region_pattern']);
            if ($regionalBase !== null) {
                $index['regional_bases'][CountryRulesRepository::compactKey($regionalBase)] = true;
            }
            $words = $this->tokens($nameKey);
            if (count($words) > 1) {
                $index['prefixes'][$words[0]][$number] = true;
            }

            $streamId = trim((string) ($source['stream_id'] ?? ''));
            if ($streamId !== '') {
                $index['by_stream_id'][$streamId] ??= $source;
            }

            $this->indexValue($index, 'by_name', $nameKey, $source);

            foreach (array_keys($source['profile_aliases'] ?? []) as $key) {
                $index['by_profile_alias'][$key][$number] = $source;
                $compact = CountryRulesRepository::compactKey($key);
                $index['by_profile_compact'][$compact][$number] = $source;
                $index['profile_keys'][$number][$compact] = $key;
            }

            foreach ($source['aliases'] as $alias) {
                $this->indexValue($index, 'by_alias', $this->countryRules->normalize((string) $alias, $rules), $source);
            }
            if ($regionalBase !== null
                && (count($this->tokens($regionalBase)) >= ($thresholds['min_alias_words'] ?? 2)
                    || mb_strlen($regionalBase, 'UTF-8') >= ($thresholds['min_alias_chars'] ?? 4))) {
                $key = CountryRulesRepository::compactKey($regionalBase);
                $index['by_regional_alias'][$key][$number] = $source;
            }
        }
        $index['guide_only'] = $guideEntries === [] ? null : $this->sourceIndex($guideEntries, $rules, $thresholds);

        return $index;
    }

    /**
     * @param  array<string, mixed>  $sourceIndex
     * @param  array<string, int|float>  $thresholds
     * @return array{decision: string, tier: int|null, score: int, matched_on: string|null, source: array<string, mixed>|null, candidates?: array<int, array<string, mixed>>}
     */
    public function match(Channel $channel, array $sourceIndex, array $thresholds): array
    {
        $rules = $sourceIndex['rules'];
        $result = $this->matchName($channel, $sourceIndex, $thresholds, $rules);
        if (! in_array($result['decision'], ['auto_apply', 'fingerprint'], true) && $sourceIndex['guide_only'] !== null) {
            $guide = $this->matchName($channel, $sourceIndex['guide_only'], $thresholds, $rules);
            // A guide-only fallback needs an exact or structured name match.
            // Fuzzy similarity cannot introduce a service outside the lineup.
            if (in_array($guide['decision'], ['auto_apply', 'fingerprint'], true) && $guide['tier'] <= 3) {
                $guide['matched_on'] = 'guide:'.$guide['matched_on'];

                $result = $guide;
            }
        }

        $source = $result['source'];
        if (isset($result['profile_alias_key'])) {
            $key = $result['profile_alias_key'];
            $result['alias_sets'] = $source['profile_aliases'][$key] ?? [];
            // Explicit aliases choose the service; timeshifts still identify a separate feed.
            $sourceNorm = $this->countryRules->normalize($source['name'], $rules);
            [, $aliasShift] = $this->identityMarkers($key);
            [, $sourceShift] = $this->identityMarkers($sourceNorm);
            if ($aliasShift !== $sourceShift) {
                $result['decision'] = 'needs_review';
                $result['review_reason'] = 'Alias changes a timeshift identity.';
            }
        }

        return $result;
    }

    private function matchName(Channel $channel, array $sourceIndex, array $thresholds, array $rules): array
    {
        $title = ChannelAttributes::title($channel);
        $name = (string) ($channel->name_custom ?? $channel->name ?? '');
        $keys = array_values(array_unique(array_filter([
            $this->countryRules->normalize($title, $rules),
            $this->countryRules->normalize($name, $rules),
        ])));

        if ($keys === []) {
            return $this->unmatched();
        }

        $channelNumber = Options::nullableInt($channel->channel);
        $streamId = ChannelAttributes::streamId($channel);
        if (
            $channelNumber !== null
            && $streamId !== ''
            && isset($sourceIndex['by_stream_id'][$streamId])
            && (int) $sourceIndex['by_stream_id'][$streamId]['number'] === $channelNumber
        ) {
            return $this->matched('fingerprint', 1, $streamId, $sourceIndex['by_stream_id'][$streamId]);
        }

        foreach ([['by_name', 'name', 2], ['by_profile_alias', 'profile_alias', 3], ['by_alias', 'alias', 3]] as [$bucket, $label, $tier]) {
            if ($result = $this->select($this->lookup($keys, $sourceIndex, $bucket), $label, $tier)) {
                return $result;
            }
        }
        if (preg_match('/\p{L}/u', implode('', $keys)) !== 1) {
            return $this->unmatched(reason: 'Name contains no identifying letters and is not an exact source name.');
        }

        // Resolve whitespace-only differences from the current source names
        // and aliases, without keeping a list of individual channel spellings.
        foreach (['by_compact' => 'spacing', 'by_profile_compact' => 'profile_alias', 'by_label' => 'label'] as $bucket => $label) {
            if ($result = $this->select($this->lookup($keys, $sourceIndex, $bucket), $label)) {
                return $result;
            }
        }
        // Derived region-free aliases are fallbacks, not competing identities
        // for an explicit national source name (including its +1 service).
        $regionalHits = $this->lookup($keys, $sourceIndex, 'by_regional_alias');
        $alternateCategories = $rules['regional_alternate_categories'] ?? [];
        if (count($regionalHits) > 1 && $alternateCategories !== []
            && count(array_unique(array_column($regionalHits, 'key'))) === 1) {
            // Sky's selected region lives in the main lineup; the other regional
            // feeds are explicitly classified as alternates. Never choose by LCN.
            $primaryHits = array_filter($regionalHits, fn (array $hit): bool => ($hit['source']['category'] ?? '') !== ''
                && ! in_array($hit['source']['category'], $alternateCategories, true));
            $alternateHits = array_filter($regionalHits, fn (array $hit): bool => in_array($hit['source']['category'] ?? '', $alternateCategories, true));
            if (count($primaryHits) === 1 && count($alternateHits) === count($regionalHits) - 1) {
                $regionalHits = $primaryHits;
            }
        }
        if ($result = $this->select($regionalHits, 'regional_alias')) {
            return $result;
        }

        // Providers sometimes add a broadcaster label to a standalone name.
        // Learn those labels from repeated prefixes in the current catalogue.
        foreach ($keys as $key) {
            $words = $this->tokens($key);
            if (count($words) !== 2
                || count($sourceIndex['prefixes'][$words[0]] ?? []) < 2
                || mb_strlen($words[1], 'UTF-8') < (int) $thresholds['min_alias_chars']
                || preg_match('/\p{L}/u', $words[1]) !== 1) {
                continue;
            }
            $sources = $sourceIndex['by_compact'][$words[1]] ?? [];
            if (count($sources) === 1) {
                return $this->matched('auto_apply', 3, 'catalogue_prefix:'.$key, reset($sources));
            }
        }

        return $this->regionalLabelResult($keys, $sourceIndex)
            ?? $this->compositeResult([$title, $name], $sourceIndex, $thresholds, $rules)
            ?? $this->select($this->lookup(
                array_filter(array_map(fn (string $key): string => preg_match($rules['presentation_pattern'] ?? '~(?!)~', $key, $m) === 1 ? $m[1] : '', $keys)),
                $sourceIndex,
                'by_compact',
            ), 'presentation')
            ?? $this->initialsResult($keys, $sourceIndex, $thresholds)
            ?? $this->abbreviationResult($keys, $sourceIndex, $thresholds)
            // A written guide ID must not replace the provider's original
            // identity evidence when corroborating a primary-service number.
            ?? $this->fuzzyResult($keys, $sourceIndex, $thresholds, (string) ($channel->stream_id ?: $streamId));
    }

    /** Collect distinct services without choosing a winner during lookup. */
    private function lookup(array $keys, array $index, string $bucket): array
    {
        $hits = [];
        foreach ($keys as $key) {
            $lookup = match ($bucket) {
                'by_label' => preg_match('/^\p{Nd}+$/u', $key) === 1 ? '' : $this->labelKey($key, $index['rules']['label_pattern'] ?? '~(?!)~'),
                'by_compact', 'by_profile_compact', 'by_initials', 'by_regional_alias' => CountryRulesRepository::compactKey($key),
                default => $key,
            };
            if ($lookup === '') {
                continue;
            }
            foreach ($index[$bucket][$lookup] ?? [] as $number => $source) {
                if ($bucket !== 'by_label' || $this->compatibleLabels($key, $index['label_keys'][$lookup][$number], $index['rules']['label_pattern'] ?? '~(?!)~')) {
                    $hits[$number] = ['source' => $source, 'key' => $key, 'score' => 100];
                    if (in_array($bucket, ['by_profile_alias', 'by_profile_compact'], true)) {
                        $hits[$number]['profile_alias_key'] = $index['profile_keys'][$number][CountryRulesRepository::compactKey($key)];
                    }
                }
            }
        }

        return $hits;
    }

    /** All structured matches share the same ambiguity and confidence rules. */
    private function select(array $hits, string $label, int $tier = 3, int $score = 100, int $minimum = 0, string $reason = ''): ?array
    {
        if ($hits === []) {
            return null;
        }
        ksort($hits);
        $candidates = array_values(array_map(fn (array $hit): array => [...$hit, 'score' => $score], $hits));
        $best = $candidates[0];

        $result = $this->matched(
            count($hits) === 1 && $score >= $minimum && $reason === '' ? 'auto_apply' : 'needs_review',
            $tier, $label.':'.$best['key'], $best['source'], $score, array_slice($candidates, 0, 5), $reason,
        );
        if (isset($best['profile_alias_key'])) {
            $result['profile_alias_key'] = $best['profile_alias_key'];
        }

        return $result;
    }

    /**
     * A regional label can use a national service only when the catalogue has
     * that exact base name and provides no regional versions of that service.
     */
    private function regionalLabelResult(array $keys, array $sourceIndex): ?array
    {
        $hits = [];
        foreach ($keys as $key) {
            $base = $this->withoutRegion($key, $sourceIndex['region_pattern']);
            if ($base === null) {
                continue;
            }
            $base = $this->countryRules->normalize($base, $sourceIndex['rules']);
            $compact = CountryRulesRepository::compactKey($base);
            if (isset($sourceIndex['regional_bases'][$compact])) {
                continue;
            }
            $baseHits = $this->lookup([$base], $sourceIndex, 'by_compact')
                + $this->lookup([$base], $sourceIndex, 'by_profile_compact');
            foreach ($baseHits as $number => $hit) {
                $source = $hit['source'];
                // A derived region-free alias must not choose a different
                // region from the one explicitly requested by the provider.
                if ($this->withoutRegion($sourceIndex['norm_by_number'][$number], $sourceIndex['region_pattern']) === null) {
                    $hits[$number] = [...$hit, 'key' => $key];
                }
            }
        }

        return $this->select($hits, 'regional_label');
    }

    private function regionPattern(array $rules): ?string
    {
        $regions = [];
        foreach (array_keys($rules['region_abbrevs'] ?? []) as $region) {
            $name = str_replace('_', ' ', mb_strtoupper((string) $region, 'UTF-8'));
            $regions[] = $name;
            // Compass subdivisions can also appear as broad provider labels.
            // Do not turn a name such as "Northern Ireland" into "Ireland".
            if (preg_match($rules['direction_pattern'] ?? '~(?!)~', $name, $parts) === 1) {
                $regions[] = $parts[1];
                $regions[] = $parts[2];
            }
        }
        foreach ($rules['country_tokens'] ?? [] as $region) {
            $regions[] = $this->countryRules->normalize((string) $region, $rules);
        }
        $regions = array_values(array_unique(array_filter($regions)));
        usort($regions, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $regions === [] ? null : '/\s+(?:'.implode('|', array_map(fn (string $region): string => preg_quote($region, '/'), $regions)).')$/';
    }

    private function withoutRegion(string $key, ?string $pattern): ?string
    {
        if ($pattern === null) {
            return null;
        }
        $suffix = '';
        if (preg_match('/\s*(\+\d+)$/', $key, $matches) === 1) {
            $suffix = ' '.$matches[1];
            $key = substr($key, 0, -strlen($matches[0]));
        }
        $base = preg_replace($pattern, '', $key, 1, $count);

        return $count === 1 && $base !== '' ? $base.$suffix : null;
    }

    /**
     * A provider may supply alternative labels separated by a slash or a
     * spaced dash. Keep every recognised alternative; two different services
     * require review even when the first label happens to match exactly.
     */
    private function compositeResult(array $names, array $sourceIndex, array $thresholds, array $rules): ?array
    {
        $hits = [];
        foreach (array_unique($names) as $name) {
            $parts = preg_split('~(?<!\d)/(?!\d)|\s+[-|]\s+~u', $name) ?: [];
            if (count($parts) < 2 || count($parts) > 4) {
                continue;
            }
            foreach ($parts as $part) {
                $key = $this->countryRules->normalize($part, $rules);
                if (mb_strlen(CountryRulesRepository::compactKey($key), 'UTF-8') < (int) $thresholds['min_alias_chars']
                    && count($this->tokens($key)) < (int) $thresholds['min_alias_words']) {
                    continue;
                }
                $hits += $this->lookup([$key], $sourceIndex, 'by_compact')
                    + $this->lookup([$key], $sourceIndex, 'by_label')
                    + $this->lookup([$key], $sourceIndex, 'by_profile_compact');
            }
        }
        if ($hits === []) {
            return null;
        }
        $reason = '';
        // A suffix on the whole label still belongs to the stream. Splitting
        // must never erase a timeshift or a numbered service.
        foreach (array_unique($names) as $name) {
            $query = $this->countryRules->normalize($name, $rules);
            if (count($hits) === 1 && $this->identityMarkers($query) !== $this->identityMarkers(reset($hits)['key'])) {
                $reason = 'Composite label contains additional channel numbers or timeshifts.';
            }
        }

        return $this->select($hits, 'composite', reason: $reason);
    }

    private function initialsResult(array $keys, array $sourceIndex, array $thresholds): ?array
    {
        $hits = $this->lookup($keys, $sourceIndex, 'by_initials');
        foreach ($keys as $key) {
            $hits += $this->lookup($this->initialVariants($key), $sourceIndex, 'by_compact');
        }

        return $this->select($hits, 'initials', score: 95, minimum: (int) $thresholds['min_auto_score']);
    }

    /**
     * Recognise unambiguous shortened words from the current catalogue, such
     * as a provider truncating a word or omitting a plural suffix.
     */
    private function abbreviationResult(array $keys, array $sourceIndex, array $thresholds): ?array
    {
        $hits = [];
        foreach ($keys as $query) {
            $queryWords = $this->tokens($query);
            foreach ($sourceIndex['fuzzy'] as $candidate) {
                $words = $sourceIndex['tokens'][$candidate['key']];
                if (count($words) !== count($queryWords)) {
                    continue;
                }
                $exact = 0;
                $shortened = [];
                $expanded = false;
                foreach ($words as $offset => $word) {
                    $queryWord = $queryWords[$offset];
                    if ($word === $queryWord) {
                        $exact++;

                        continue;
                    }
                    if (preg_match('/[^\p{L}\p{M}]/u', $word.$queryWord) === 1
                        || min(mb_strlen($word, 'UTF-8'), mb_strlen($queryWord, 'UTF-8')) < 2
                        || (! str_starts_with($word, $queryWord) && ! str_starts_with($queryWord, $word))) {
                        continue 2;
                    }
                    $shortened[] = min(mb_strlen($word, 'UTF-8'), mb_strlen($queryWord, 'UTF-8'));
                    $expanded = $expanded || (str_starts_with($queryWord, $word)
                        && ! in_array(substr($queryWord, strlen($word)), $sourceIndex['rules']['plural_suffixes'] ?? [], true));
                }
                // Extra word endings and short abbreviations need two
                // unchanged words; they may identify a different service.
                if ($exact === 0 || ($exact < 2 && ($expanded || ($shortened !== [] && min($shortened) <= (int) $thresholds['max_short_token_len'])))) {
                    continue;
                }
                $hits[(int) $candidate['source']['number']] = [
                    ...$candidate,
                    'score' => 95,
                ];
            }
        }

        return $this->select($hits, 'abbreviation', score: 95, minimum: (int) $thresholds['min_auto_score']);
    }

    /**
     * @param  array<int, string>  $keys
     * @param  array<string, mixed>  $sourceIndex
     * @param  array<string, int|float>  $thresholds
     * @return array{decision: string, tier: int|null, score: int, matched_on: string|null, source: array<string, mixed>|null, candidates?: array<int, array<string, mixed>>}
     */
    private function fuzzyResult(array $keys, array $sourceIndex, array $thresholds, string $streamId): array
    {
        $candidatesByNumber = [];
        $queryWords = array_map($this->tokens(...), $keys);
        foreach ($sourceIndex['fuzzy'] as $candidate) {
            $best = null;
            foreach ($keys as $offset => $key) {
                $hit = $this->fuzzyScore($key, $candidate['key'], $thresholds, $queryWords[$offset], $sourceIndex['tokens'][$candidate['key']]);
                if (! $hit) {
                    continue;
                }

                if ($best === null || [$hit['sort_score'], $hit['score']] > [$best['sort_score'], $best['score']]) {
                    $best = [
                        ...$hit,
                        'query' => $key,
                    ];
                }
            }

            if ($best === null) {
                continue;
            }

            $source = $candidate['source'];
            $number = (int) $source['number'];
            if (! isset($candidatesByNumber[$number]) || [$best['sort_score'], $best['score']] > [$candidatesByNumber[$number]['sort_score'], $candidatesByNumber[$number]['score']]) {
                $candidatesByNumber[$number] = [
                    'source' => $source,
                    'key' => $candidate['key'],
                    'query' => $best['query'],
                    'score' => $best['score'],
                    'sort_score' => $best['sort_score'],
                ];
            }
        }

        $candidates = array_values($candidatesByNumber);
        usort($candidates, fn (array $left, array $right): int => [$right['sort_score'], $right['score'], -$right['source']['number']]
            <=> [$left['sort_score'], $left['score'], -$left['source']['number']]);

        $best = $candidates[0] ?? null;
        if (! $best) {
            return $this->unmatched();
        }

        $runner = $candidates[1] ?? null;
        $runnerSortScore = (int) ($runner['sort_score'] ?? 0);
        $margin = (int) $best['sort_score'] - $runnerSortScore;
        $decision = $best['score'] >= (int) $thresholds['min_auto_score'] && $margin >= (int) $thresholds['min_auto_margin']
            ? 'auto_apply'
            : 'needs_review';

        if ($decision === 'needs_review' && $runner && $best['score'] >= (int) $thresholds['min_auto_score']) {
            $decision = $this->siblingMarginAllowsAuto($best, $runner, $sourceIndex) ? 'auto_apply' : 'needs_review';
        }

        $reason = $this->fuzzyReviewReason((string) $best['query'], $best['source'], $sourceIndex, $thresholds, $streamId);
        if ($reason !== '') {
            $decision = 'needs_review';
        }
        if ($decision === 'needs_review' && $reason === '') {
            $reason = $best['score'] < (int) $thresholds['min_auto_score']
                ? 'Candidate score is below the automatic matching threshold.'
                : 'Candidate does not have a sufficient lead over other source channels.';
        }

        return $this->matched($decision, 4, 'fuzzy:'.$best['key'], $best['source'], (int) $best['score'], array_slice($candidates, 0, 5), $reason);
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array{decision: string, tier: int, score: int, matched_on: string, source: array<string, mixed>, candidates: array<int, array<string, mixed>>}
     */
    private function matched(string $decision, int $tier, string $matchedOn, array $source, int $score = 100, array $candidates = [], string $reason = ''): array
    {
        if (($source['guide_valid'] ?? true) === false) {
            $decision = 'needs_review';
            $reason = 'Source channel number is absent from the selected guide.';
        }
        if ($decision === 'needs_review' && $reason === '') {
            $reason = count($candidates) > 1
                ? 'Name identifies more than one source channel.'
                : 'Name variant needs confirmation before automatic matching.';
        }

        return [
            'decision' => $decision,
            'tier' => $tier,
            'score' => $score,
            'matched_on' => $matchedOn,
            'source' => $source,
            'candidates' => $candidates,
            'review_reason' => $reason,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $candidates
     */
    private function candidateSummary(array $candidates): string
    {
        return implode(' | ', array_map(
            fn (array $candidate): string => "{$candidate['source']['name']} ({$candidate['source']['number']}) score={$candidate['score']}",
            $candidates,
        ));
    }

    /**
     * @param  array<string, mixed>  $index
     * @param  array<string, mixed>  $source
     */
    private function indexValue(array &$index, string $bucket, string $key, array $source): void
    {
        if ($key === '') {
            return;
        }

        $index[$bucket][$key][(int) $source['number']] = $source;
        $index['by_compact'][CountryRulesRepository::compactKey($key)][(int) $source['number']] = $source;
        // The same normalized alias can appear in both the source and guide.
        // Index its variants once while preserving the exact lookup buckets.
        $variantId = $source['number'].':'.$key;
        if (isset($index['fuzzy'][$variantId])) {
            return;
        }
        foreach ($this->initialVariants($key) as $variant) {
            $index['by_initials'][$variant][(int) $source['number']] = $source;
        }
        $labelKey = $this->labelKey($key, $index['rules']['label_pattern'] ?? '~(?!)~');
        if ($labelKey !== '') {
            $index['by_label'][$labelKey][(int) $source['number']] = $source;
            $index['label_keys'][$labelKey][(int) $source['number']][] = $key;
        }
        $index['fuzzy'][$variantId] = [
            'key' => $key,
            'source' => $source,
        ];
        $index['tokens'][$key] ??= $this->tokens($key);
    }

    /** Initials retain an unchanged identifying word and all service numbers. */
    private function initialVariants(string $key): array
    {
        $words = preg_split('/\s+|(?<=\p{L})(?=\d)|(?<=\d)(?=\p{L})/u', $key) ?: [];
        $variants = [];
        for ($start = 0; $start < count($words) - 1; $start++) {
            for ($length = 2; $length <= min(4, count($words) - $start); $length++) {
                $span = array_slice($words, $start, $length);
                $context = [...array_slice($words, 0, $start), ...array_slice($words, $start + $length)];
                if ($context === [] || preg_match('/^[\p{L}\p{M} ]+$/u', implode(' ', $span)) !== 1
                    || ! array_filter($context, fn (string $word): bool => mb_strlen($word, 'UTF-8') > 2 && preg_match('/^[\p{L}\p{M}]+$/u', $word) === 1)) {
                    continue;
                }
                $initials = implode('', array_map(fn (string $word): string => mb_substr($word, 0, 1, 'UTF-8'), $span));
                $variant = implode('', [...array_slice($words, 0, $start), $initials, ...array_slice($words, $start + $length)]);
                $variants[] = $variant;
            }
        }

        return array_values(array_unique($variants));
    }

    private function labelKey(string $value, string $pattern): string
    {
        return CountryRulesRepository::compactKey((string) preg_replace($pattern, '', $value));
    }

    private function compatibleLabels(string $query, array $candidates, string $pattern): bool
    {
        preg_match_all($pattern, $query, $queryLabels);
        foreach ($candidates as $candidate) {
            preg_match_all($pattern, $candidate, $sourceLabels);
            // A label may be omitted or added; replacing one label with a
            // different one can identify a different service.
            if (array_diff($queryLabels[0], $sourceLabels[0]) === []
                || array_diff($sourceLabels[0], $queryLabels[0]) === []) {
                return true;
            }
        }

        return false;
    }

    private function identityMarkers(string $value): array
    {
        preg_match_all('/\p{Nd}+/u', $value, $numbers);
        preg_match_all('/\+\p{Nd}*/u', $value, $timeshifts);

        return [$numbers[0], $timeshifts[0]];
    }

    /**
     * @param  array<string, int|float>  $thresholds
     * @param  array<int, string>  $queryWords
     * @param  array<int, string>  $candidateWords
     * @return array{score: int, sort_score: int}|null
     */
    private function fuzzyScore(string $query, string $candidate, array $thresholds, array $queryWords, array $candidateWords): ?array
    {
        // Score with token sort/set, full-ratio, and partial-ratio paths to mirror the Python reference.
        $threshold = (int) $thresholds['fuzzy_threshold'];
        sort($queryWords);
        sort($candidateWords);
        $sortScore = $this->similarityRatio(implode(' ', $queryWords), implode(' ', $candidateWords));
        $bestScore = $sortScore >= $threshold ? $sortScore : null;
        $sortFloor = (int) $thresholds['sort_floor'];
        $queryTokens = count($queryWords);
        $candidateTokens = count($candidateWords);

        if (
            $queryTokens >= 2
            && $candidateTokens >= (int) $thresholds['min_set_tokens']
            && ($queryTokens <= $candidateTokens || $sortScore >= $sortFloor)
            && ($setScore = $this->tokenSetRatio($queryWords, $candidateWords)) >= $threshold
        ) {
            $bestScore = max($bestScore ?? 0, $setScore);
        }

        $ratioScore = $this->similarityRatio($query, $candidate);
        $lengthRatio = $this->lengthRatio($query, $candidate);
        if (
            $ratioScore >= $threshold
            && $lengthRatio >= (float) $thresholds['min_ratio_len_ratio']
            && ($ratioScore >= (int) $thresholds['min_ratio_score'] || $sortScore >= $sortFloor)
        ) {
            $bestScore = max($bestScore ?? 0, $ratioScore);
        }

        if (
            $lengthRatio >= (float) $thresholds['min_partial_len_ratio']
            && min(mb_strlen($query, 'UTF-8'), mb_strlen($candidate, 'UTF-8')) >= (int) $thresholds['min_partial_len']
            && $ratioScore >= (int) $thresholds['min_partial_ratio_score']
            && $sortScore >= $sortFloor
            && ($partialScore = $this->partialRatio($query, $candidate)) >= $threshold
        ) {
            $bestScore = max($bestScore ?? 0, $partialScore);
        }

        if ($bestScore === null) {
            return null;
        }

        return [
            'score' => (int) $bestScore,
            'sort_score' => $sortScore,
        ];
    }

    /**
     * @param  array<string, mixed>  $best
     * @param  array<string, mixed>  $runner
     * @param  array<string, mixed>  $sourceIndex
     */
    private function siblingMarginAllowsAuto(array $best, array $runner, array $sourceIndex): bool
    {
        // Closely named sibling channels may auto-apply only when the query contains the distinguishing tail token.
        $bestNorm = (string) ($sourceIndex['norm_by_number'][(int) $best['source']['number']] ?? '');
        $runnerNorm = (string) ($sourceIndex['norm_by_number'][(int) $runner['source']['number']] ?? '');
        $bestWords = $this->tokens($bestNorm);
        $runnerWords = $this->tokens($runnerNorm);
        $sharedPrefix = 0;

        foreach ($bestWords as $index => $word) {
            if (($runnerWords[$index] ?? null) !== $word) {
                break;
            }

            $sharedPrefix++;
        }

        $queryWords = array_fill_keys($this->tokens((string) $best['query']), true);
        $bestTail = array_slice($bestWords, $sharedPrefix);

        if ($sharedPrefix < max(count($bestWords) - 1, 1) || $bestTail === []) {
            return false;
        }

        foreach ($bestTail as $word) {
            if (isset($queryWords[$word])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $sourceIndex
     * @param  array<string, int|float>  $thresholds
     */
    private function fuzzyReviewReason(string $query, array $source, array $sourceIndex, array $thresholds, string $streamId): string
    {
        // Strong scores can still be unsafe when both sides carry unrelated extra tokens.
        $sourceNorm = (string) ($sourceIndex['norm_by_number'][(int) $source['number']] ?? '');
        // Digits identify distinct channels even when the remaining name is
        // almost identical (History2/History, TNT Sports 1/2, and +1 feeds).
        [$queryNumbers, $queryPlus] = $this->identityMarkers($query);
        [$sourceNumbers, $sourcePlus] = $this->identityMarkers($sourceNorm);
        if ($queryPlus !== $sourcePlus) {
            return 'Plus or timeshift suffix differs from the source channel.';
        }
        if ($queryNumbers !== $sourceNumbers) {
            // Some providers retain or omit a primary-service "1". Accept
            // only when the rest of both names AND the provider ID agree.
            $bare = fn (string $name): string => CountryRulesRepository::compactKey((string) preg_replace('/(?<![0-9+])1\b/', '', $name));
            $identifier = preg_replace('/\.[a-z]{2,3}$/i', '', $streamId) ?? $streamId;
            $identifier = preg_replace('/([A-Z]+)([A-Z][a-z])|([a-z])([A-Z])/', '$1$3 $2$4', $identifier) ?? $identifier;
            $identifier = $this->countryRules->normalize($identifier, $sourceIndex['rules']);
            if (! (($queryNumbers === ['1'] && $sourceNumbers === []) || ($sourceNumbers === ['1'] && $queryNumbers === []))
                || $bare($query) !== $bare($sourceNorm)
                || CountryRulesRepository::compactKey($identifier) !== $bare($query)) {
                return 'Channel numbers or numbered service names differ.';
            }
        }

        $queryTokens = array_fill_keys($this->tokens($query), true);
        $sourceTokens = array_fill_keys($this->tokens($sourceNorm), true);
        $shared = array_intersect_key($queryTokens, $sourceTokens);
        $queryOnly = array_keys(array_diff_key($queryTokens, $shared));
        $sourceOnly = array_keys(array_diff_key($sourceTokens, $shared));

        if ($queryOnly !== [] && $sourceOnly !== [] && ! $this->hasRelatedToken($queryOnly, $sourceOnly, $thresholds)) {
            return 'Name contains distinguishing words not supported by the candidate.';
        }

        return '';
    }

    /**
     * @param  array<int, string>  $queryOnly
     * @param  array<int, string>  $sourceOnly
     * @param  array<string, int|float>  $thresholds
     */
    private function hasRelatedToken(array $queryOnly, array $sourceOnly, array $thresholds): bool
    {
        foreach ($queryOnly as $queryToken) {
            foreach ($sourceOnly as $sourceToken) {
                // A one-letter change in a short identifier can name a
                // completely different broadcaster, even with shared words.
                if (preg_match('/^[\p{L}\p{M}]+$/u', $queryToken.$sourceToken) === 1
                    && min(mb_strlen($queryToken, 'UTF-8'), mb_strlen($sourceToken, 'UTF-8')) <= max(3, (int) $thresholds['max_short_token_len'])) {
                    continue;
                }

                if ($this->editDistance($queryToken, $sourceToken) <= 1
                    && $this->similarityRatio($queryToken, $sourceToken) >= (int) $thresholds['min_token_similarity']) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $leftTokens  Sorted words, including duplicates.
     * @param  array<int, string>  $rightTokens  Sorted words, including duplicates.
     */
    private function tokenSetRatio(array $leftTokens, array $rightTokens): int
    {
        $leftTokens = array_unique($leftTokens);
        $rightTokens = array_unique($rightTokens);

        $shared = array_values(array_intersect($leftTokens, $rightTokens));
        $leftDiff = array_values(array_diff($leftTokens, $shared));
        $rightDiff = array_values(array_diff($rightTokens, $shared));

        if ($shared === []) {
            return 0;
        }

        $sharedText = implode(' ', $shared);
        $leftText = implode(' ', [...$shared, ...$leftDiff]);
        $rightText = implode(' ', [...$shared, ...$rightDiff]);

        return max(
            $this->similarityRatio($sharedText, $leftText),
            $this->similarityRatio($sharedText, $rightText),
            $this->similarityRatio($leftText, $rightText),
        );
    }

    private function partialRatio(string $left, string $right): int
    {
        [$short, $long] = mb_strlen($left, 'UTF-8') <= mb_strlen($right, 'UTF-8') ? [$left, $right] : [$right, $left];
        $length = mb_strlen($short, 'UTF-8');

        if ($length === 0) {
            return 0;
        }

        if (str_contains($long, $short)) {
            return 100;
        }

        $best = 0;
        $lastOffset = mb_strlen($long, 'UTF-8') - $length;
        for ($offset = 0; $offset <= $lastOffset; $offset++) {
            $best = max($best, $this->similarityRatio($short, mb_substr($long, $offset, $length, 'UTF-8')));
        }

        return $best;
    }

    private function lengthRatio(string $left, string $right): float
    {
        $leftLength = mb_strlen($left, 'UTF-8');
        $rightLength = mb_strlen($right, 'UTF-8');

        return min($leftLength, $rightLength) / max($leftLength, $rightLength, 1);
    }

    /**
     * @return array<int, string>
     */
    private function tokens(string $value): array
    {
        return array_values(array_filter(explode(' ', $value), fn (string $token): bool => $token !== ''));
    }

    private function similarityRatio(string $left, string $right): int
    {
        if ($left === '' && $right === '') {
            return 100;
        }

        if ($left === '' || $right === '') {
            return 0;
        }

        $maxLength = max(mb_strlen($left, 'UTF-8'), mb_strlen($right, 'UTF-8'));
        $score = (int) round((1 - ($this->editDistance($left, $right) / $maxLength)) * 100);

        return max(0, min(100, $score));
    }

    /** Count character edits; UTF-8 bytes must not inflate similarity. */
    private function editDistance(string $left, string $right): int
    {
        if (mb_check_encoding($left.$right, 'ASCII')) {
            return levenshtein($left, $right);
        }

        $right = mb_str_split($right, 1, 'UTF-8');
        $previous = range(0, count($right));
        foreach (mb_str_split($left, 1, 'UTF-8') as $i => $character) {
            $row = [$i + 1];
            foreach ($right as $j => $other) {
                $row[] = min($row[$j] + 1, $previous[$j + 1] + 1, $previous[$j] + ($character !== $other));
            }
            $previous = $row;
        }

        return $previous[count($right)];
    }

    /**
     * @return array{decision: string, tier: null, score: int, matched_on: null, source: null, candidates: array<int, mixed>}
     */
    private function unmatched(int $score = 0, string $reason = 'No confident name or alias match in the selected source.'): array
    {
        return [
            'decision' => 'unmatched',
            'tier' => null,
            'score' => $score,
            'matched_on' => null,
            'source' => null,
            'candidates' => [],
            'review_reason' => $reason,
        ];
    }
}
