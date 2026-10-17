<?php

namespace AppLocalPlugins\GuideLink\Services;

use App\Models\Channel;
use App\Models\Playlist;
use AppLocalPlugins\GuideLink\Support\ChannelAttributes;
use AppLocalPlugins\GuideLink\Support\CountryRulesRepository;
use AppLocalPlugins\GuideLink\Support\GroupIdentity;
use AppLocalPlugins\GuideLink\Support\Options;
use AppLocalPlugins\GuideLink\Support\ProfileDefaults;
use Illuminate\Database\Eloquent\Collection;

class ScopeFilter
{
    private const LIST_KEYS = [
        'include_prefixes' => 'include_group_prefixes',
        'exclude_group_patterns' => 'exclude_group_regex',
        'preserve_groups' => 'preserve_groups',
        'preserve_group_patterns' => 'preserve_group_name_regex',
        'preserve_title_patterns' => 'preserve_channel_title_regex',
    ];

    public function __construct(private readonly CountryRulesRepository $countryRules) {}

    /**
     * @param  Collection<int, Channel>  $channels
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $rules
     * @return array{included: Collection<int, Channel>, excluded: array<int, array<string, mixed>>}
     */
    public function split(Playlist $playlist, Collection $channels, array $payload, array $settings, array $rules): array
    {
        $scope = $this->scopeSettings($payload, $settings);
        $included = [];
        $excluded = [];

        foreach ($channels as $channel) {
            $reason = $this->exclusionReason($channel, $scope, $rules);

            if ($reason === null) {
                $included[] = $channel;

                continue;
            }

            $excluded[] = $this->excludedRow($playlist, $channel, $reason);
        }

        return [
            'included' => new Collection($included),
            'excluded' => $excluded,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $settings
     * @return array<string, array<int, string>>
     */
    private function scopeSettings(array $payload, array $settings): array
    {
        $scope = [];
        foreach (self::LIST_KEYS as $name => $key) {
            $patterns = str_ends_with($name, '_patterns');
            $scope[$name] = $patterns
                ? Options::patterns($payload, $settings, $key, ProfileDefaults::list($key))
                : Options::list($payload, $settings, $key, ProfileDefaults::list($key));
            if ($patterns) {
                foreach ($scope[$name] as $pattern) {
                    if (@preg_match($this->countryRules->regex($pattern, 'iu'), '') === false) {
                        throw new \RuntimeException("Invalid regex in [{$key}]. Correct this rule before syncing.");
                    }
                }
            }
        }

        $scope['managed_group_keys'] = array_fill_keys($payload['_managed_group_keys'] ?? [], true);

        return $scope;
    }

    /**
     * @param  array<string, array<int, string>>  $scope
     * @param  array<string, mixed>  $rules
     */
    private function exclusionReason(Channel $channel, array $scope, array $rules): ?string
    {
        $group = ChannelAttributes::groupName($channel);
        $title = ChannelAttributes::title($channel);

        if ($this->preservesGroup($group, $scope['preserve_groups'], $scope['preserve_group_patterns'])) {
            return 'group_preserved';
        }

        if ($this->countryRules->matchesAny($title, $scope['preserve_title_patterns'])) {
            return 'title_preserved';
        }

        if ($scope['include_prefixes'] !== []
            && ! $this->countryRules->startsWithAny($group, $scope['include_prefixes'])
            && ! isset($scope['managed_group_keys'][GroupIdentity::key($group)])) {
            return 'group_prefix';
        }

        if ($this->countryRules->matchesAny($group, $scope['exclude_group_patterns'])) {
            return 'group_excluded';
        }

        // Decorative separators identify playlist headings across providers.
        if (preg_match('/^\s*([#*=~_-])\1+\s*.+?\s*\1{2,}\s*$/u', $title) === 1) {
            return 'provider_heading';
        }

        if ($this->countryRules->matchesAny($group, $rules['non_linear_group_regex'] ?? [])) {
            return 'non_linear_group';
        }

        if ($this->countryRules->matchesAny($title, $rules['non_linear_title_regex'] ?? [])) {
            return 'non_linear_title';
        }

        return null;
    }

    /**
     * Shared by channel scope and group planning so preservation has one meaning.
     *
     * @param  array<int, string>  $names
     * @param  array<int, string>  $patterns
     */
    public function preservesGroup(string $group, array $names, array $patterns): bool
    {
        return in_array($group, $names, true) || $this->countryRules->matchesAny($group, $patterns);
    }

    private function excludedRow(Playlist $playlist, Channel $channel, string $reason): array
    {
        return [
            ...ChannelAttributes::reportRow($playlist, $channel),
            'decision' => 'excluded',
            'tier' => '',
            'score' => '',
            'matched_on' => '',
            'source_number' => '',
            'source_name' => '',
            'source_category' => '',
            'source_stream_id' => '',
            'candidate_summary' => '',
            'exclusion_reason' => $reason,
        ];
    }
}
