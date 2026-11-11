<?php

declare(strict_types=1);

namespace LiveNetworks\BuilderConnector\Converter;

final class Revision
{
    /**
     * Compute `r_` + first 12 hex of sha256(canonical JSON of stored area).
     *
     * @param array<int|string, mixed> $nativeData
     */
    public static function compute(array $nativeData): string
    {
        $canonical = self::canonicalJson($nativeData);
        return 'r_' . substr(hash('sha256', $canonical), 0, 12);
    }

    /**
     * Produce canonical JSON with associative keys sorted, list order preserved,
     * root parent normalized, and deterministic float serialization.
     *
     * @param mixed $data
     */
    public static function canonicalJson($data): string
    {
        $prevPrecision = ini_get('serialize_precision');
        ini_set('serialize_precision', '-1');
        try {
            $normalized = self::normalize($data);
            $json = json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            return is_string($json) ? $json : '[]';
        } finally {
            if ($prevPrecision !== false) {
                ini_set('serialize_precision', (string) $prevPrecision);
            }
        }
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function normalize($value, ?string $parentKey = null)
    {
        if (!is_array($value)) {
            if ($parentKey === 'parent' && ($value === '0' || $value === 0)) {
                return 0;
            }
            if (is_float($value) && floor($value) === $value && !is_infinite($value)) {
                return (int) $value;
            }
            return $value;
        }

        if ($value === []) {
            return [];
        }

        if (array_is_list($value)) {
            $list = [];
            foreach ($value as $item) {
                $list[] = self::normalize($item, null);
            }
            return $list;
        }

        ksort($value, SORT_STRING);
        $assoc = [];
        foreach ($value as $k => $v) {
            $keyStr = (string) $k;
            $assoc[$keyStr] = self::normalize($v, $keyStr);
        }
        return $assoc;
    }
}
