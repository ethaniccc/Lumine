<?php

namespace LumineServer\utils;

use pocketmine\math\Vector3;

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
		$var = ($variance * $variance * $variance);
		if ($var == 0) {
			return 0;
		}
		return $sum / $var;
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
		$var = ($variance * $stdDev * $stdDev);
		if ($var == 0) {
			return 0;
		}
		return $sum / $var;
	}

	public static function getStandardDeviation(array $numbers): float {
		$mean = self::getMean($numbers);
		$variance = self::getVariance($numbers, $mean);
		return sqrt($variance);
	}

	public static function getMedian(array $data): float {
		$count = count($data);
		if ($count === 0) {
			return 0.0;
		}
		sort($data);
		return ($count % 2 === 0) ? ($data[$count * 0.5] + $data[$count * 0.5 - 1]) * 0.5 : $data[$count * 0.5];
	}

	public static function getMode(array $numbers): float {
		$counts = array_count_values($numbers);
		$max = max($counts);
		$modes = array();
		foreach ($counts as $number => $count) {
			if ($count == $max) {
				$modes[] = $number;
			}
		}
		sort($modes);
		return $modes[0];
	}

	public static function getRange(array $numbers): float {
		sort($numbers);
		return $numbers[count($numbers) - 1] - $numbers[0];
	}

	public static function getPercentile(array $numbers, float $percentile): float {
		sort($numbers);
		$index = (count($numbers) - 1) * $percentile;
		$lower = floor($index);
		$upper = ceil($index);
		if ($upper == $lower) {
			return $numbers[$lower];
		} else {
			return $numbers[$lower] + ($index - $lower) * ($numbers[$upper] - $numbers[$lower]);
		}
	}

	public static function getCovariance(array $x, array $y): float {
		$xMean = self::getMean($x);
		$yMean = self::getMean($y);
		$sum = 0;
		for ($i = 0; $i < count($x); $i++) {
			$sum += ($x[$i] - $xMean) * ($y[$i] - $yMean);
		}
		return $sum / count($x);
	}

	public static function getStandardError(array $numbers): float {
		return self::getStandardDeviation($numbers) / sqrt(count($numbers));
	}

	public static function getOutliers(array $collection): int {
		$count = count($collection);
		$q1 = self::getMedian(array_splice($collection, 0, (int) ceil($count * 0.5)));
		$q3 = self::getMedian(array_splice($collection, (int) ceil($count * 0.5), $count));

		$iqr = abs($q1 - $q3);
		$lowThreshold = $q1 - 1.5 * $iqr;
		$highThreshold = $q3 + 1.5 * $iqr;

		$x = [];
		$y = [];

		foreach ($collection as $value) {
			if ($value < $lowThreshold) {
				$x[] = $value;
			} elseif ($value > $highThreshold) {
				$y[] = $value;
			}
		}

		return count($x) + count($y);
	}

	public static function getAverage(array $numbers): float {
		return array_sum($numbers) / count($numbers);
	}

	public static function getCorrelationCoefficient(array $x, array $y): float {
		$xStdDev = self::getStandardDeviation($x);
		$yStdDev = self::getStandardDeviation($y);
		$covariance = self::getCovariance($x, $y);
		if ($xStdDev * $yStdDev == 0) {
			return 0;
		}
		return $covariance / ($xStdDev * $yStdDev);
	}

	public static function directionVectorFromValues(float $yaw, float $pitch): Vector3 {
		$var2 = cos(-$yaw * 0.017453292 - M_PI);
		$var3 = sin(-$yaw * 0.017453292 - M_PI);
		$var4 = -(cos(-$pitch * 0.017453292));
		$var5 = sin(-$pitch * 0.017453292);
		return new Vector3($var3 * $var4, $var5, $var2 * $var4);
	}

}