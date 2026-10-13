<?php

namespace Jma\Connect\Tests;

use Jma\Connect\Tests\Fixtures\TestPanelProvider;

/**
 * Installed the way the README describes: env settings only, built-in
 * provider and sink, and a panel with the plugin.
 */
abstract class ScreensTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), TestPanelProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('connect.provider.enabled', true);
        $app['config']->set('connect.consumer.enabled', true);
        $app['config']->set('connect.admin_emails', 'admin@example.com');
    }
}
