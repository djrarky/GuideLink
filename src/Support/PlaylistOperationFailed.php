<?php

namespace AppLocalPlugins\GuideLink\Support;

use RuntimeException;
use Throwable;

/** Keep the resolved playlist identity when a single-playlist handler throws. */
class PlaylistOperationFailed extends RuntimeException
{
    public readonly bool $sourceFailure;

    public function __construct(public readonly int $playlistId, Throwable $error)
    {
        $this->sourceFailure = $error instanceof SourceRefreshFailed;
        parent::__construct($error->getMessage());
    }
}
