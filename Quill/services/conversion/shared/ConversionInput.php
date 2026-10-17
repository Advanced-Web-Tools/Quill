<?php

namespace Quill\services\conversion\shared;

final class ConversionInput
{
    public static function field(\stdClass $input, string $name, mixed $default): mixed
    {
        return property_exists($input, $name) ? $input->{$name} : $default;
    }

    public static function decode(string $json, string $path): \stdClass
    {
        try {
            $value = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw ConversionException::at($path, 'Invalid JSON: ' . $error->getMessage(), $error);
        }
        return self::object($value, $path);
    }

    public static function object(mixed $value, string $path): \stdClass
    {
        if (!$value instanceof \stdClass) {
            throw ConversionException::at($path, 'Expected a JSON object.');
        }
        return $value;
    }

    /** @return list<mixed> */
    public static function orderedList(mixed $value, string $path): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw ConversionException::at($path, 'Expected an ordered JSON array.');
        }
        return $value;
    }

    public static function id(mixed $value, string $path): int
    {
        if (!is_int($value) || $value < 1) {
            throw ConversionException::at($path, 'Expected a positive integer ID.');
        }
        return $value;
    }

    public static function schema(\stdClass $value, string $path): void
    {
        if (($value->schema_version ?? null) !== 1) {
            throw ConversionException::at($path, 'Missing or unsupported schema_version (expected 1).');
        }
    }

    /** Copy JSON values without collapsing nested objects into arrays. */
    public static function copy(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $copy = new \stdClass();
            foreach (get_object_vars($value) as $key => $child) $copy->{$key} = self::copy($child);
            return $copy;
        }
        if (is_array($value)) return array_map(self::copy(...), $value);
        return $value;
    }
}
