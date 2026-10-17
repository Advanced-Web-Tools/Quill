<?php

namespace Quill\services\conversion\shared;

final class ConversionException extends \RuntimeException
{
    public static function at(string $path, string $message, ?\Throwable $previous = null): self
    {
        return new self($path . ': ' . $message, 0, $previous);
    }
}
