<?php

namespace Nyartcc\GroundCoordination\Support;

/**
 * Writes a FeatureCollection in the layout the package's data files use:
 * two-space indent, unescaped slashes and unicode, whole-number floats kept
 * as floats (40.0), no trailing newline. Stable output keeps a re-fetch's
 * diff down to the stands that actually changed.
 */
final class GeoJson
{
    /** @param list<array<string, mixed>> $features */
    public static function featureCollection(array $features): string
    {
        $json = json_encode(
            ['type' => 'FeatureCollection', 'features' => $features],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        );

        // JSON_PRETTY_PRINT indents by four; no string value here holds a newline, so halving leading runs is safe.
        return (string) preg_replace_callback('/^( +)/m', fn (array $match) => str_repeat(' ', intdiv(strlen($match[1]), 2)), $json);
    }
}
