<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

class Checksum
{
    public static function fromFields(array $fields): string
    {
        return self::hashFields($fields, 'UTC');
    }

    public static function fromFieldsLegacy(array $fields): string
    {
        return self::hashFields($fields, null);
    }

    public static function fromFieldsWithTimezone(array $fields, string $timezone): string
    {
        return self::hashFields($fields, $timezone);
    }

    private static function hashFields(array $fields, ?string $timezone): string
    {
        $normalized = [];
        foreach ($fields as $key => $value) {
            $normalized[$key] = self::normalizeValue($value, $timezone);
        }

        ksort($normalized);
        return hash('sha256', json_encode($normalized));
    }

    private static function normalizeValue(mixed $value, ?string $timezone): mixed
    {
        if ($value instanceof DateTimeInterface) {
            $dt = CarbonImmutable::instance($value);
            if ($timezone !== null) {
                $dt = $dt->setTimezone($timezone);
            }
            return $dt->format(DateTimeInterface::ATOM);
        }

        if (is_string($value)) {
            return trim($value);
        }

        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[$key] = self::normalizeValue($item, $timezone);
            }

            if (self::isAssoc($normalized)) {
                ksort($normalized);
            } else {
                $normalized = array_values($normalized);
            }

            return $normalized;
        }

        return $value;
    }

    private static function isAssoc(array $value): bool
    {
        $keys = array_keys($value);
        return $keys !== array_keys($keys);
    }
}
