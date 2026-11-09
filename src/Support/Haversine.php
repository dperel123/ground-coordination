<?php

namespace Nyartcc\GroundCoordination\Support;

/** Great-circle geometry on a spherical Earth (IUGG mean radius). */
final class Haversine
{
    public const EARTH_RADIUS_M = 6_371_008.8;

    /** Meters between two points. */
    public static function meters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $a = sin(($phi2 - $phi1) / 2) ** 2
            + cos($phi1) * cos($phi2) * sin(deg2rad($lon2 - $lon1) / 2) ** 2;

        return 2 * self::EARTH_RADIUS_M * asin(min(1.0, sqrt($a)));
    }

    /** Degrees of latitude spanned by a distance. */
    public static function latitudeDegrees(float $meters): float
    {
        return rad2deg($meters / self::EARTH_RADIUS_M);
    }

    /** Degrees of longitude spanned by a distance at a latitude. */
    public static function longitudeDegrees(float $meters, float $atLat): float
    {
        return rad2deg($meters / (self::EARTH_RADIUS_M * max(cos(deg2rad($atLat)), 1e-6)));
    }
}
