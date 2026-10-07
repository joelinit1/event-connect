<?php

namespace Jma\Connect\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Hosts decide who hears about failed syncs, because only they know which
 * users can act on one (usually whoever passes the connect.manage Gate).
 */
interface SyncAlertRecipients
{
    /**
     * Notifiable users (Laravel's Notifiable trait) to alert.
     *
     * @return iterable<Model>
     */
    public function recipients(): iterable;
}
