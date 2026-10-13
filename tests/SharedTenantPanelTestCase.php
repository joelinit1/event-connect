<?php

namespace Jma\Connect\Tests;

use Jma\Connect\Tests\Fixtures\SharedTenantPanel;

abstract class SharedTenantPanelTestCase extends InstallTestCase
{
    protected function getPackageProviders($app): array
    {
        return [...TestCase::getPackageProviders($app), SharedTenantPanel::class];
    }
}
