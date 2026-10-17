<?php

namespace Quill\api\editor;

final class DefinitionValidation
{
    public static function key(string $key): void
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_.:-]*$/D', $key)) throw new \InvalidArgumentException('Invalid editor definition key: ' . $key);
    }

    public static function text(string $text, string $field): void
    {
        if (trim($text) === '') throw new \InvalidArgumentException($field . ' must not be empty.');
    }

    public static function module(?string $path): void
    {
        if ($path === null) return;
        $parts = parse_url($path);
        if ($parts === false || !preg_match('/\.m?js$/iD', $parts['path'] ?? '')
            || isset($parts['fragment']) || (isset($parts['scheme']) && !in_array(strtolower($parts['scheme']), ['http', 'https'], true))) {
            throw new \InvalidArgumentException('Expected a JavaScript module URL: ' . $path);
        }
    }
}
