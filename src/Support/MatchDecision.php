<?php

namespace AppLocalPlugins\GuideLink\Support;

final class MatchDecision
{
    public static function isConfirmed(string $decision): bool
    {
        return in_array($decision, ['auto_apply', 'fingerprint'], true);
    }

    /** @param array<string, mixed> $row */
    public static function isPreserved(array $row): bool
    {
        return in_array($row['exclusion_reason'] ?? '', ['group_preserved', 'title_preserved'], true);
    }

    /** @param array<string, mixed> $row */
    public static function shouldQuarantine(array $row): bool
    {
        return isset($row['decision']) && ! self::isConfirmed($row['decision']) && ! self::isPreserved($row);
    }
}
