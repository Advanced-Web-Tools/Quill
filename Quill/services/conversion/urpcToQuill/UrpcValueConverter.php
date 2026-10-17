<?php

namespace Quill\services\conversion\urpcToQuill;

use Quill\services\conversion\shared\ConversionException;

/** Copies PHP API values into JSON-safe data without losing nested object/list types. */
final class UrpcValueConverter
{
    public function text(string $value, string $path): string
    {
        if (preg_match('//u', $value) !== 1) throw ConversionException::at($path, 'Expected valid UTF-8 text.');
        return $value;
    }

    /** @param array<array-key, mixed> $values */
    public function object(array $values, string $path): \stdClass
    {
        $result = new \stdClass();
        $active = new \SplObjectStorage();
        foreach ($values as $key => $value) {
            $this->text((string) $key, $path . '/key');
            $result->{(string) $key} = $this->copy($value, $path . '/' . $key, $active, 0);
        }
        return $result;
    }

    /** @param \SplObjectStorage<\stdClass, string> $active */
    private function copy(mixed $value, string $path, \SplObjectStorage $active, int $depth): mixed
    {
        if ($depth > 128) throw ConversionException::at($path, 'Maximum JSON value depth exceeded.');
        if (is_string($value)) return $this->text($value, $path);
        if ($value === null || is_bool($value) || is_int($value)) return $value;
        if (is_float($value) && is_finite($value)) return $value;
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $child) {
                $this->text((string) $key, $path . '/key');
                $result[$key] = $this->copy($child, $path . '/' . $key, $active, $depth + 1);
            }
            return $result;
        }
        if (!$value instanceof \stdClass) {
            throw ConversionException::at($path, 'Expected JSON data; unsupported PHP value.');
        }
        if ($active->contains($value)) {
            throw ConversionException::at($path, 'Cyclic JSON object; already active at ' . $active[$value] . '.');
        }
        $active[$value] = $path;
        try {
            $result = new \stdClass();
            foreach (get_object_vars($value) as $key => $child) {
                $this->text((string) $key, $path . '/key');
                $result->{$key} = $this->copy($child, $path . '/' . $key, $active, $depth + 1);
            }
            return $result;
        } finally {
            $active->detach($value);
        }
    }
}
