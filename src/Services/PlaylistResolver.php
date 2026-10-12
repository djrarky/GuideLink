<?php

namespace AppLocalPlugins\GuideLink\Services;

use App\Models\Playlist;
use App\Plugins\Support\PluginExecutionContext;
use AppLocalPlugins\GuideLink\Support\Options;
use Illuminate\Database\Eloquent\Builder;

class PlaylistResolver
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function target(array $payload, PluginExecutionContext $context): Playlist
    {
        $playlistId = Options::nullableInt(
            $payload['playlist_id']
            ?? $payload['target_playlist_id']
            ?? null,
        );

        if ($playlistId !== null) {
            return $this->findAvailable($playlistId, $context);
        }

        $playlists = $this->availableTo($context)->limit(2)->get();
        if ($playlists->count() === 1) {
            return $playlists->first();
        }

        if ($playlists->isEmpty()) {
            throw new \RuntimeException('No playlists are available for this plugin run.');
        }

        throw new \RuntimeException('Choose a playlist before running GuideLink.');
    }

    public function availableTo(PluginExecutionContext $context): Builder
    {
        $query = Playlist::query();
        if ($context->user !== null && ! $context->user->isAdmin()) {
            $query->where('user_id', $context->user->id);
        }

        return $query;
    }

    public function findAvailable(int $playlistId, PluginExecutionContext $context, string $label = 'Playlist'): Playlist
    {
        $playlist = $this->availableTo($context)->whereKey($playlistId)->first();
        if (! $playlist) {
            throw new \RuntimeException("{$label} [{$playlistId}] was not found or is not available to this run.");
        }

        return $playlist;
    }
}
