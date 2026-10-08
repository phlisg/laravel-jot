<?php

namespace Workbench\App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

class ChargeCard implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $eventId) {}

    public function handle(): void
    {
        jot()->add('booking.event_id', $this->eventId);

        throw new RuntimeException('Card network timeout');
    }
}
