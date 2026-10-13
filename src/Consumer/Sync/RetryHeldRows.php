<?php

namespace Jma\Connect\Consumer\Sync;

use Illuminate\Support\Collection;
use Jma\Connect\Consumer\Models\SyncIssue;
use Jma\Connect\Consumer\Models\SyncMapping;
use Jma\Connect\Contracts\AttendeeSink;
use Jma\Connect\Support\LocalTables;

/**
 * Re-writes held rows from the payload stored when they failed, using the
 * mapping as it is now. Runs in the request: held rows are few, and whoever
 * clicked wants to see straight away whether their fix worked.
 */
final class RetryHeldRows
{
    public const LIMIT = 1000;

    public function __construct(private readonly AttendeeSink $sink) {}

    /**
     * @param  Collection<int, SyncIssue>|null  $issues  Defaults to all the mapping's open ones
     * @return array{fixed: int, failed: int}
     */
    public function retry(SyncMapping $mapping, ?Collection $issues = null): array
    {
        LocalTables::flush();

        $issues ??= $mapping->openIssues()->limit(self::LIMIT)->get();
        $writer = new RowWriter($this->sink, $mapping->fresh());
        $result = ['fixed' => 0, 'failed' => 0];

        foreach ($issues->filter->isOpen() as $issue) {
            $result[$writer->write($issue->attendee()) ? 'fixed' : 'failed']++;
        }

        return $result;
    }
}
