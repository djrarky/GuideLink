<?php

namespace AppLocalPlugins\GuideLink\Support;

use App\Plugins\Support\PluginExecutionContext;

final class RunProgress
{
    public static function checkCancellation(?PluginExecutionContext $context): void
    {
        if ($context?->cancellationRequested()) {
            throw new RunCancelled('GuideLink run cancelled; completed changes have been retained.');
        }
    }

    public static function batchPercentage(int $completed, int $total): int
    {
        return $total <= 0 ? 100 : min(95, 5 + (int) floor(($completed / $total) * 90));
    }

    public static function shouldHeartbeat(int $index, int $total, int $every = 25): bool
    {
        $position = $index + 1;

        return $position === 1 || $position === $total || $position % $every === 0;
    }
}
