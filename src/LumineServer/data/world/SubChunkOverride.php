<?php

namespace LumineServer\data\world;

use pocketmine\level\format\SubChunk;

final class SubChunkOverride extends SubChunk {

	private array $attributes = [];

	private static function assignData(string $data, int $length, string $value = "\x00") : string{
		if(strlen($data) !== $length){
			assert($data === "", "Invalid non-zero length given, expected $length, got " . strlen($data));
			return str_repeat($value, $length);
		}
		return $data;
	}

	public function __construct(string $ids = "", string $data = "", string $skyLight = "", string $blockLight = "") {
		unset($this->ids, $this->data, $this->blockLight, $this->skyLight);
		$this->ids = self::assignData($ids, 4096);
		$this->data = self::assignData($data, 2048);
		$this->skyLight = self::assignData($skyLight, 2048, "\xff");
		$this->blockLight = self::assignData($blockLight, 2048);
		$this->collectGarbage();
	}

	public function __set(string $name, $value): void {
		$this->attributes[$name] = zlib_encode($value, ZLIB_ENCODING_RAW, 7);
	}

	public function __get(string $name) {
		$value = $this->attributes[$name] ?? null;
		if ($value !== null) {
			$value = zlib_decode($value);
		}
		return $value;
	}

}