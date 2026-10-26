<?php

namespace Phlisg\WideEvents\Sections;

use Illuminate\Support\Str;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;
use OpenTelemetry\SemConv\Attributes\ExceptionAttributes;
use Phlisg\WideEvents\Attributes\Hide;
use Phlisg\WideEvents\Attributes\Key;
use Phlisg\WideEvents\Contracts\Retriable;
use Phlisg\WideEvents\Section;
use Throwable;

final class ErrorSection extends Section
{
    #[Key(ErrorAttributes::ERROR_TYPE)]
    public readonly string $type;

    #[Key(ExceptionAttributes::EXCEPTION_MESSAGE)]
    public readonly string $message;

    public readonly int|string $code;

    public readonly bool $retriable;

    // Reported exceptions in this event. The fields above describe the last one.
    public readonly int $count;

    #[Hide]
    public readonly Throwable $exception;

    public function __construct(Throwable $e, int $count = 1)
    {
        $this->exception = $e;
        $this->count = $count;
        $this->type = $e::class;
        // A query exception lists every binding, which can reach several MB.
        $this->message = Str::limit($e->getMessage(), 2000);
        $this->code = $e->getCode();
        $this->retriable = $e instanceof Retriable;
    }
}
