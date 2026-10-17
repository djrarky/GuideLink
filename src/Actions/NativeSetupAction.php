<?php

namespace AppLocalPlugins\GuideLink\Actions;

use App\Plugins\Support\PluginActionResult;
use App\Plugins\Support\PluginExecutionContext;
use AppLocalPlugins\GuideLink\Services\GuideLinkServices;
use AppLocalPlugins\GuideLink\Support\RunProgress;
use Illuminate\Support\Facades\DB;

class NativeSetupAction extends GuideLinkAction
{
    private const DEFINITIONS = [
        'auto_merge' => [
            'title' => 'Auto-merge',
            'noun' => 'auto-merge',
            'service' => 'autoMergeSetup',
            'apply_target' => 'native playlist auto-merge settings',
            'warning' => 'Native auto-merge setup needs attention before apply/rebuild.',
        ],
        'scrubber' => [
            'title' => 'Scrubber',
            'noun' => 'scrubber',
            'service' => 'scrubberSetup',
            'apply_target' => 'native scrubber settings',
            'warning' => 'Native scrubber setup needs attention before relying on scrubber-driven recovery.',
        ],
    ];

    public function __construct(
        private readonly string $kind,
        private readonly string $mode,
        ?GuideLinkServices $services = null,
    ) {
        if (! isset(self::DEFINITIONS[$kind]) || ! in_array($mode, ['audit', 'apply'], true)) {
            throw new \InvalidArgumentException("Invalid native setup action [{$kind}:{$mode}].");
        }

        parent::__construct($services);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload, PluginExecutionContext $context): PluginActionResult
    {
        $definition = self::DEFINITIONS[$this->kind];

        return $this->services->playlistBatchRunner()->runResolved(
            $payload,
            $context,
            'sync',
            $this->mode === 'audit'
                ? $definition['title'].' setup audit'
                : 'Apply '.$definition['noun'].' setup',
            fn (array $runPayload): PluginActionResult => $this->runSingle($runPayload, $context, $definition),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $definition
     */
    private function runSingle(array $payload, PluginExecutionContext $context, array $definition): PluginActionResult
    {
        $audit = $this->mode === 'audit';
        $phase = $audit ? 'audit' : ($context->dryRun ? 'preview' : 'apply');
        $service = $this->services->{$definition['service']}();

        $context->checkpoint(5, 'Preparing '.($audit ? 'native ' : '').$definition['noun'].' setup '.$phase.'.', log: true);

        $playlist = $this->services->playlistResolver()->target($payload, $context);
        $desired = $service->desiredConfig($playlist, $payload, $context);

        $context->checkpoint($audit ? 35 : 50, ($audit ? 'Comparing ' : 'Applying ').$definition['apply_target'].'.', [
            'playlist_id' => (int) $playlist->id,
            ...$this->desiredDetails($desired, $audit),
            ...($audit ? [] : ['dry_run' => $context->dryRun]),
        ]);

        [$rows, $resultDetails] = DB::transaction(function () use ($playlist, $audit, $context, $service, $desired): array {
            if (! $audit && ! $context->dryRun) {
                $playlist = $playlist->newQuery()->where('user_id', $playlist->user_id)->whereKey($playlist->id)->lockForUpdate()->firstOrFail();
            }
            RunProgress::checkCancellation($context);
            $rows = $audit
                ? $service->auditRows($playlist, $desired)
                : [$service->apply($playlist, $desired, $context->dryRun)];
            $details = $this->services->runResults()->replaceRows($context, (int) $playlist->id, $rows, $this->kind.'_audit');

            return [$rows, $details];
        });
        $summary = $service->summary($rows);

        if ($audit && (($summary['change_required'] ?? 0) > 0 || ($summary['warning'] ?? 0) > 0)) {
            $context->warning($definition['warning'], [
                'change_required' => $summary['change_required'] ?? 0,
                'warnings' => $summary['warning'] ?? 0,
            ]);
        }

        $past = $audit ? 'audit completed' : ($context->dryRun ? 'preview completed' : 'applied');
        $context->checkpoint(100, $definition['title'].' setup '.$past.'.', [
            'summary' => $summary,
            'run_results' => $resultDetails,
        ], log: true);

        return PluginActionResult::success(
            $audit
                ? $definition['title']." audit completed for {$playlist->name}: ".($summary['change_required'] ?? 0).' change(s) required.'
                : $definition['title']." setup {$past} for {$playlist->name}.",
            [
                'playlist_id' => (int) $playlist->id,
                ...($audit ? ['desired' => $desired] : []),
                'totals' => $summary,
                'run_results' => $resultDetails,
                'progress' => 100,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $desired
     * @return array<string, mixed>
     */
    private function desiredDetails(array $desired, bool $includeAutoMerge = true): array
    {
        return match ($this->kind) {
            'auto_merge' => $includeAutoMerge ? ['desired_playlist_ids' => $desired['playlist_ids']] : [],
            'scrubber' => [
                'management' => $desired['management'],
                'selected_scrubber_id' => $desired['scrubber_id'],
            ],
            default => [],
        };
    }
}
