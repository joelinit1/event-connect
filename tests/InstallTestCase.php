<?php

namespace Jma\Connect\Tests;

use Jma\Connect\Tests\Fixtures\PlainPanelProvider;

/**
 * Installed with no code at all: .env settings and a panel that never
 * mentions the plugin.
 */
abstract class InstallTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), PlainPanelProvider::class, ...$this->extraPanels()];
    }

    protected function extraPanels(): array
    {
        return [];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('connect.provider.enabled', true);
        $app['config']->set('connect.consumer.enabled', true);
        $app['config']->set('connect.admin_emails', 'admin@example.com');
    }
}
