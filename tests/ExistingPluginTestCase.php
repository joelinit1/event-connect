<?php

namespace Jma\Connect\Tests;

use Jma\Connect\Tests\Fixtures\SecondPanelProvider;

/**
 * A 0.1.x host: it registered the plugin on a panel of its own choosing.
 */
abstract class ExistingPluginTestCase extends InstallTestCase
{
    protected function extraPanels(): array
    {
        return [SecondPanelProvider::class];
    }
}
