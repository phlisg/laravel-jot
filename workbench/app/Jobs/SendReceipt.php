<?php

namespace Workbench\App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendReceipt implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $eventId) {}

    public function handle(): void
    {
        jot()->add(['booking.event_id' => $this->eventId, 'receipt.channel' => 'mail']);
    }
}
