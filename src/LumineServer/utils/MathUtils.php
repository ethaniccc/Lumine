<?php

/**
 * @author Github Copilot - An absoulte madlad.
 */
final class MathUtils {

    public static function getKurtosis(array $numbers): float {
        $mean = self::getMean($numbers);
        $variance = self::getVariance($numbers, $mean);
        $sum = 0;
        foreach ($numbers as $number) {
            $sum += pow($number - $mean, 4);
        }
		return $sum / ($variance * $variance * $variance);
    }

    public static function getMean(array $numbers): float {
        $sum = 0;
        foreach ($numbers as $number) {
            $sum += $number;
        }
        return $sum / count($numbers);
    }

    public static function getVariance(array $numbers, float $mean): float {
        $sum = 0;
        foreach ($numbers as $number) {
            $sum += pow($number - $mean, 2);
        }
        return $sum / count($numbers);
    }

    public static function getSkewness(array $numbers): float {
        $mean = self::getMean($numbers);
        $variance = self::getVariance($numbers, $mean);
        $stdDev = sqrt($variance);
        $sum = 0;
        foreach ($numbers as $number) {
            $sum += pow($number - $mean, 3);
        }
		return $sum / ($variance * $stdDev * $stdDev);
    }

}