<?php

namespace AppLocalPlugins\GuideLink\Actions;

use App\Plugins\Support\PluginActionResult;
use App\Plugins\Support\PluginExecutionContext;

class RebuildFailoversAction extends GuideLinkAction
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload, PluginExecutionContext $context): PluginActionResult
    {
        return $this->services->playlistBatchRunner()->runResolved(
            $payload,
            $context,
            'sync',
            'Rebuild native failovers',
            fn (array $runPayload): PluginActionResult => $this->handleSingle($runPayload, $context),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleSingle(array $payload, PluginExecutionContext $context): PluginActionResult
    {
        $service = $this->services->autoMergeSetup();

        $context->checkpoint(5, $context->dryRun ? 'Preparing native failover rebuild preview.' : 'Preparing native failover rebuild.', log: true);

        $playlist = $this->services->playlistResolver()->target($payload, $context);

        $context->checkpoint(40, 'Invoking m3u-editor native auto-merge rebuild.', [
            'playlist_id' => (int) $playlist->id,
            'dry_run' => $context->dryRun,
        ]);

        $rows = [$service->rebuildFailovers($playlist, $context, $context->dryRun)];
        $summary = $service->summary($rows);

        $resultDetails = $this->services->runResults()->replaceRows(
            $context,
            (int) $playlist->id,
            $rows,
            'auto_merge_audit',
        );

        $context->checkpoint(100, $context->dryRun ? 'Native failover rebuild preview completed.' : 'Native failover rebuild completed.', [
            'summary' => $summary,
            'run_results' => $resultDetails,
        ], log: true);

        return PluginActionResult::success(
            $context->dryRun
                ? "Native failover rebuild preview completed for {$playlist->name}."
                : "Native failovers rebuilt for {$playlist->name}.",
            [
                'playlist_id' => (int) $playlist->id,
                'totals' => $summary,
                'run_results' => $resultDetails,
                'progress' => 100,
            ],
        );
    }
}
