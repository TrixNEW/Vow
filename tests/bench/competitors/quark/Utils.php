<?php

declare(strict_types=1);

namespace quark\utils;

// same body as Quark's Utils::promoteKeys, kept here so Promise::all() runs unmodified
final class Utils
{
    /**
     * @template K of array-key
     * @template V
     * @param array<K, V> $array
     * @return array<K, V>
     */
    public static function promoteKeys(array $array): array
    {
        return $array;
    }
}
