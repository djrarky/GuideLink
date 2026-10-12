<?php

namespace AppLocalPlugins\GuideLink\Actions;

use App\Models\Playlist;
use App\Plugins\Support\PluginActionResult;
use App\Plugins\Support\PluginExecutionContext;

class HealthCheckAction extends GuideLinkAction
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload, PluginExecutionContext $context): PluginActionResult
    {
        $context->checkpoint(10, 'Checking m3u-editor data access.', log: true);

        $playlistCount = Playlist::query()->count();
        $setup = $this->services->setupChecklist();
        $setupSummary = $setup->summary($setup->rows($context));

        $context->checkpoint(100, 'GuideLink health check completed.', [
            'playlists' => $playlistCount,
            'setup' => $setupSummary,
        ], log: true);

        return PluginActionResult::success('GuideLink can read m3u-editor playlists.', [
            'playlists' => $playlistCount,
            'setup' => $setupSummary,
            'progress' => 100,
        ]);
    }
}
