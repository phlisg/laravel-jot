<?php

namespace Phlisg\Jot\Sections;

use OpenTelemetry\SemConv\Attributes\ErrorAttributes;
use OpenTelemetry\SemConv\Attributes\ExceptionAttributes;
use Phlisg\Jot\Attributes\Key;
use Phlisg\Jot\Contracts\Retriable;
use Phlisg\Jot\Section;
use Throwable;

final class ErrorSection extends Section
{
    #[Key(ErrorAttributes::ERROR_TYPE)]
    public readonly string $type;

    #[Key(ExceptionAttributes::EXCEPTION_MESSAGE)]
    public readonly string $message;

    public readonly int|string $code;

    public readonly bool $retriable;

    public function __construct(Throwable $e)
    {
        $this->type = $e::class;
        $this->message = $e->getMessage();
        $this->code = $e->getCode();
        $this->retriable = $e instanceof Retriable;
    }
}
