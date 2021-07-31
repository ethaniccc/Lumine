<?php

namespace LumineServer\utils;

final class MCMathHelper {

	private static array $SIN_TABLE = [];

	public static function init(): void {
		for ($i = 0; $i < 65536; ++$i) {
			self::$SIN_TABLE[$i] = sin($i * M_PI * 2 / 65536);
		}
	}

	public static function sin(float $var): float {
		return self::$SIN_TABLE[(int)($var * 10430.378) & 65535];
	}

	public static function cos(float $var): float {
		return self::$SIN_TABLE[(int)($var * 10430.378 + 16384.0) & 65535];
	}

}