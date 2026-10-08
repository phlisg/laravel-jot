<?php

namespace Phlisg\Jot\Sections;

use Illuminate\Contracts\Queue\Job;
use Phlisg\Jot\Section;

final class JobSection extends Section
{
    public readonly string $id;

    public readonly string $name;

    public readonly ?string $queue;

    public readonly int $attempt;

    // processed, failed or released (retried after an exception).
    public ?string $status = null;

    public function __construct(Job $job)
    {
        $this->id = (string) ($job->uuid() ?? $job->getJobId());
        $this->name = $job->resolveName();
        $this->queue = $job->getQueue();
        $this->attempt = $job->attempts();
    }
}
