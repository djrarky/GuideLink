<?php

namespace AppLocalPlugins\GuideLink\Support;

final class RunProgress
{
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
