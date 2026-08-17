<?php

namespace App\Support;

class JsonMetadataSanitizer
{
    public static function sanitize(mixed $value): mixed
    {
        if (is_array($value)) {
            $sanitized = [];

            foreach ($value as $key => $item) {
                $sanitizedKey = is_string($key) ? self::sanitizeString($key) : $key;
                $sanitized[$sanitizedKey] = self::sanitize($item);
            }

            return $sanitized;
        }

        return is_string($value) ? self::sanitizeString($value) : $value;
    }

    public static function sanitizeString(string $value): string
    {
        $value = str_replace("\0", '', $value);

        if (! mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1252, ISO-8859-1');
        }

        return $value;
    }
}
