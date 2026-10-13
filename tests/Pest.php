<?php

use Jma\Connect\Tests\ScreensTestCase;
use Jma\Connect\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit');
pest()->extend(ScreensTestCase::class)->in('Screens');
