<?php

namespace Workbench\App\Jot;

use Phlisg\Jot\Section;
use Workbench\App\Enums\PaymentProvider;
use Workbench\App\Enums\PaymentStatus;

final class PaymentLog extends Section
{
    public ?PaymentProvider $provider = null;

    public ?string $intentId = null;

    public ?PaymentStatus $status = null;

    public int $attempts = 0;

    public ?int $latencyMs = null;
}
