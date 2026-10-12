<?php

namespace AppLocalPlugins\GuideLink\Services;

use App\Models\Playlist;
use App\Plugins\Support\PluginExecutionContext;
use AppLocalPlugins\GuideLink\Support\Options;

class PlaylistRunResolver
{
    public function __construct(
        private readonly PlaylistResolver $playlistResolver = new PlaylistResolver,
        private readonly ProfileRepository $profiles = new ProfileRepository,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, array{playlist: Playlist, payload: array<string, mixed>}>
     */
    public function runs(array $payload, PluginExecutionContext $context, string $purpose): array
    {
        $basePayload = $this->basePayload($payload);

        if (Options::bool($payload, [], 'assigned_playlists', false)) {
            $runs = $this->profiles->assignedRuns($basePayload, $context, $purpose);
            if ($runs === []) {
                throw new \RuntimeException('No enabled playlist profile assignments are available for this run.');
            }

            return array_map(
                fn (array $run): array => $this->withRunMetadata($run['playlist'], $run['payload']),
                $runs,
            );
        }

        $playlist = $this->playlistResolver->target($payload, $context);
        $profilePayload = $this->profiles->payloadForPlaylist($playlist, $basePayload, $context);

        return [
            $this->withRunMetadata($playlist, $profilePayload),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function basePayload(array $payload): array
    {
        $basePayload = $payload;
        unset(
            $basePayload['playlist_id'],
            $basePayload['target_playlist_id'],
            $basePayload['playlist_ids'],
            $basePayload['target_playlist_ids'],
            $basePayload['target_scope'],
            $basePayload['assigned_playlists'],
        );

        return $basePayload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{playlist: Playlist, payload: array<string, mixed>}
     */
    private function withRunMetadata(Playlist $playlist, array $payload): array
    {
        return [
            'playlist' => $playlist,
            'payload' => [
                ...$payload,
                'playlist_id' => (int) $playlist->id,
                'target_playlist_id' => (int) $playlist->id,
            ],
        ];
    }
}
