<?php

namespace AppLocalPlugins\GuideLink\Support;

final class GroupIdentity
{
    /** Group identity must retain provider prefixes and quality labels. */
    public static function key(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)), 'UTF-8');
    }
}
