<?php

namespace AppLocalPlugins\GuideLink\Actions;

use App\Plugins\Support\PluginActionResult;
use App\Plugins\Support\PluginExecutionContext;
use AppLocalPlugins\GuideLink\Services\AliasSetRepository;
use AppLocalPlugins\GuideLink\Services\BundledAliasService;
use AppLocalPlugins\GuideLink\Services\GuideMapService;
use AppLocalPlugins\GuideLink\Services\ProfileAliasResolver;
use Illuminate\Support\Facades\DB;

final class PreviewAliasSetAction
{
    public function handle(array $payload, PluginExecutionContext $context): PluginActionResult
    {
        $set = DB::table(ProfileAliasResolver::SETS)->where('extension_plugin_id', $context->plugin->id)
            ->where('id', $payload['alias_set_id'] ?? null)->first();
        if (! $set) {
            throw new \RuntimeException('Select an available alias set to preview.');
        }
        $bundle = $set->bundle_id ? (new BundledAliasService)->load($set->bundle_id) : null;
        $rows = $bundle['entries'] ?? (new AliasSetRepository)->rows((int) $set->id, $context);
        $names = (new GuideMapService)->targetNames((int) $set->guide_epg_id, array_column($rows, 'target_stream_id'), $context);
        $entries = [];
        foreach ($rows as $row) {
            $entry = [
                'guide_channel' => $row['target_name'] ?? implode(' / ', $names[$row['target_stream_id']] ?? []),
                'guide_id' => $row['target_stream_id'], 'alternative_names' => $row['aliases'],
                'enabled' => $row['enabled'] ?? true,
            ];
            $entries[] = $entry;
            $context->info(($entry['enabled'] ? '' : '[Disabled] ').$entry['guide_channel'].' ['.$entry['guide_id'].']: '.implode(', ', $entry['alternative_names']));
        }
        $count = array_sum(array_map('count', array_column($entries, 'alternative_names')));

        return PluginActionResult::success("{$set->name}: ".count($entries)." guide entries, {$count} alternative names. Preview only; no aliases or settings changed.", [
            'set_id' => (int) $set->id, 'set_name' => $set->name, 'set_enabled' => (bool) $set->enabled,
            'source' => $bundle ? 'Read-only bundled defaults' : 'Custom aliases',
            'bundle_id' => $bundle['id'] ?? null, 'revision' => $bundle['revision'] ?? null,
            'totals' => ['guide_entries' => count($entries), 'alternative_names' => $count],
            'entries' => $entries,
        ]);
    }
}
