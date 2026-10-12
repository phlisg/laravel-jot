<?php

namespace MarvelousDigital\WideEvents\Sections;

use MarvelousDigital\WideEvents\Attributes\Key;
use MarvelousDigital\WideEvents\Contracts\Retriable;
use MarvelousDigital\WideEvents\Section;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;
use OpenTelemetry\SemConv\Attributes\ExceptionAttributes;
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
