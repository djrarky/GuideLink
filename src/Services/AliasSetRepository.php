<?php

namespace AppLocalPlugins\GuideLink\Services;

use App\Plugins\Support\PluginExecutionContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class AliasSetRepository
{
    public function rows(int|array $setIds, PluginExecutionContext $context, bool $enabledOnly = false): array
    {
        $setIds = self::setIds(is_int($setIds) ? [$setIds] : $setIds);
        if ($setIds === []) {
            return [];
        }
        $query = DB::table(ProfileAliasResolver::ALIASES)->where('extension_plugin_id', $context->plugin->id)
            ->where(function (Builder $query) use ($setIds): void {
                foreach ($setIds as $setId) {
                    $query->orWhereJsonContains('alias_set_ids', $setId)
                        ->orWhereJsonContains('alias_set_ids', (string) $setId);
                }
            })
            ->when($enabledOnly, fn (Builder $query) => $query->where('enabled', true))
            ->orderBy('id');

        return $query->get(['id', 'alias_set_ids', 'target_stream_id', 'aliases', 'enabled'])->map(fn (object $row): array => [
            'id' => (int) $row->id,
            'alias_set_ids' => self::setIds($row->alias_set_ids),
            'target_stream_id' => $row->target_stream_id,
            'aliases' => is_array($row->aliases) ? $row->aliases : json_decode($row->aliases, true, 512, JSON_THROW_ON_ERROR),
            'enabled' => (bool) $row->enabled,
        ])->all();
    }

    public static function setIds(mixed $value): array
    {
        $ids = is_string($value) ? json_decode($value, true, 512, JSON_THROW_ON_ERROR) : $value;
        if (! is_array($ids) || ! array_is_list($ids)) {
            throw new \RuntimeException('Alias set selection must be a list of IDs.');
        }
        foreach ($ids as $id) {
            if ((! is_int($id) && ! is_string($id)) || ! ctype_digit((string) $id) || (int) $id < 1) {
                throw new \RuntimeException('Invalid alias set ID.');
            }
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }
}
