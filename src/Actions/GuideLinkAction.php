<?php

namespace AppLocalPlugins\GuideLink\Actions;

use AppLocalPlugins\GuideLink\Services\GuideLinkServices;

abstract class GuideLinkAction
{
    protected readonly GuideLinkServices $services;

    public function __construct(?GuideLinkServices $services = null)
    {
        $this->services = $services ?? new GuideLinkServices;
    }
}
