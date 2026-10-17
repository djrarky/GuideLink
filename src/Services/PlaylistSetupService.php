<?php

namespace AppLocalPlugins\GuideLink\Services;

use App\Models\Playlist;
use App\Plugins\Support\PluginExecutionContext;
use AppLocalPlugins\GuideLink\Support\BuildsSetupReports;
use AppLocalPlugins\GuideLink\Support\ProfileSettings;

class PlaylistSetupService
{
    use BuildsSetupReports;

    private const FIELDS = [
        'auto_sort' => ['Channel Auto Sort', 'Retain GuideLink channel order when importing provider updates.'],
        'auto_sort_groups' => ['Group Auto Sort', 'Retain GuideLink group order when importing provider updates.'],
        'force_channel_numbering' => ['Force Channel Number Override', 'Output the saved guide numbers instead of replacing them with sequential numbers.'],
        'sort_by_channel_number' => ['Sort by Channel Number', 'Use the profile output order: flat guide numbers or group order followed by channel order.'],
        'auto_channel_increment' => ['Auto Channel Number Increment', 'Follow the profile preference for filling missing channel numbers; existing guide numbers remain intact.'],
    ];

    /** @return array<int, string> */
    public function requiredWarningTitles(): array
    {
        return ['Conflicting playlist automation'];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{managed: bool, fields: array<string, bool>}
     */
    public function desiredConfig(Playlist $playlist, array $payload, PluginExecutionContext $context): array
    {
        $settings = ProfileSettings::playlistLayout($payload);
        if (! $settings['playlist_layout_managed']) {
            return ['managed' => false, 'fields' => []];
        }

        $fields = [
            'auto_sort' => false,
            'auto_sort_groups' => false,
            'force_channel_numbering' => false,
            'sort_by_channel_number' => $settings['playlist_output_order'] === 'number',
        ];
        if ($settings['playlist_missing_numbers'] !== 'preserve') {
            $fields['auto_channel_increment'] = $settings['playlist_missing_numbers'] === 'enabled';
        }

        return ['managed' => true, 'fields' => $fields];
    }

    /**
     * @param  array{managed: bool, fields: array<string, bool>}  $desired
     * @return array<int, array<string, mixed>>
     */
    public function auditRows(Playlist $playlist, array $desired): array
    {
        if (! $desired['managed']) {
            return [$this->row('info', 'Playlist layout management', 'Layout settings are unchanged. Enable Let GuideLink Manage Playlist Layout in the assigned profile to protect guide order and numbers.')];
        }

        $rows = [];
        foreach ($desired['fields'] as $key => $value) {
            [$title, $reason] = self::FIELDS[$key];
            $rows[] = $this->checkRow($key, $title, (bool) $playlist->getAttribute($key), $value, $reason, ['reason' => $reason]);
        }

        foreach (array_values($playlist->sort_alpha_config ?? []) as $index => $rule) {
            if (! ($rule['enabled'] ?? false) || ($rule['target'] ?? 'live_groups') !== 'live_groups') {
                continue;
            }

            $rows[] = $this->row('warning', 'Conflicting playlist automation',
                'Review Sort Alpha rule #'.($index + 1).' in the playlist settings. This live-channel rule can overwrite GuideLink order after import. Disable or adjust it in m3u-editor; GuideLink leaves the rule unchanged.', [
                    'setting' => 'sort_alpha_config',
                    'rule_number' => $index + 1,
                    'groups' => $rule['group'] ?? ['all'],
                    'column' => $rule['column'] ?? 'title',
                    'direction' => $rule['sort'] ?? 'ASC',
                ]);
        }

        return $rows;
    }

    /**
     * When deferring, the caller owns the transaction and saves the staged attributes.
     *
     * @param  array{managed: bool, fields: array<string, bool>}  $desired
     * @return array<string, mixed>
     */
    public function apply(Playlist $playlist, array $desired, bool $dryRun, bool $deferSave = false): array
    {
        $before = [];
        foreach ($desired['fields'] as $key => $value) {
            $before[$key] = (bool) $playlist->getAttribute($key);
        }
        $changes = $this->changedFields($before, $desired['fields']);
        $checks = $this->auditRows($playlist, $desired);

        if (! $dryRun && $changes !== []) {
            $playlist->forceFill($desired['fields']);
            if (! $deferSave) {
                $playlist->save();
                $playlist->refresh();
            }
            foreach ($checks as $index => $check) {
                if ($check['status'] === 'change_required') {
                    $checks[$index]['status'] = 'applied';
                }
            }
        }

        $warnings = $this->summary($checks)['warning'];

        return $this->row(
            match (true) {
                ! $desired['managed'] => 'info',
                $changes !== [] => $dryRun ? 'change_required' : 'applied',
                $warnings > 0 => 'warning',
                default => 'ok',
            },
            'Native playlist layout setup',
            ! $desired['managed']
                ? 'Playlist layout remains user-managed.'
                : 'The profile manages these playlist-wide settings, including their effect on VOD output. Run Sync restores managed values; disabling management leaves the last applied values in place.',
            [
                'playlist_id' => (int) $playlist->id,
                'managed' => $desired['managed'],
                'dry_run' => $dryRun,
                'changed_fields' => array_keys($changes),
                'changes' => $changes,
                'before' => $before,
                'desired' => $desired['fields'],
                'checks' => $checks,
                'warnings' => $warnings,
            ],
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, int>
     */
    public function summary(array $rows): array
    {
        return $this->summaryFor($rows, ['ok', 'change_required', 'warning', 'applied', 'info']);
    }
}
