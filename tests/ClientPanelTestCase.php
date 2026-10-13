<?php

namespace Jma\Connect\Tests;

use Jma\Connect\Tests\Fixtures\ClientAndPlatformPanels;

abstract class ClientPanelTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), ClientAndPlatformPanels::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('connect.provider.enabled', true);
        $app['config']->set('connect.consumer.enabled', true);
    }
}
