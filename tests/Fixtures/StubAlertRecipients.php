<?php

namespace Jma\Connect\Tests\Fixtures;

use Jma\Connect\Contracts\SyncAlertRecipients;

class StubAlertRecipients implements SyncAlertRecipients
{
    public function recipients(): iterable
    {
        return User::query()->where('name', 'like', 'Admin%')->get();
    }
}
