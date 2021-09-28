<?php

namespace LumineServer\data\world;

use pocketmine\level\format\SubChunk;

if(!defined(__NAMESPACE__ . '\ZERO_NIBBLE_ARRAY')){
	define(__NAMESPACE__ . '\ZERO_NIBBLE_ARRAY', str_repeat("\x00", 2048));
}

final class SubChunkOverride extends SubChunk {

	private static function assignData(string $data, int $length, string $value = "\x00") : string{
		if(strlen($data) !== $length){
			assert($data === "", "Invalid non-zero length given, expected $length, got " . strlen($data));
			return str_repeat($value, $length);
		}
		return $data;
	}

	public function __construct(string $ids = "", string $data = "", string $skyLight = "", string $blockLight = "") {
		$this->ids = zlib_encode(self::assignData($ids, 4096), ZLIB_ENCODING_RAW, 7);
		$this->data = zlib_encode(self::assignData($data, 2048), ZLIB_ENCODING_RAW, 7);
		$this->skyLight = zlib_encode(self::assignData($skyLight, 2048, "\xff"), ZLIB_ENCODING_RAW, 7);
		$this->blockLight = zlib_encode(self::assignData($blockLight, 2048), ZLIB_ENCODING_RAW, 7);
		$this->collectGarbage();
	}

	public function isEmpty(bool $checkLight = true) : bool{
		return (
			substr_count(zlib_decode($this->ids), "\x00") === 4096 and
			(!$checkLight or (
					substr_count(zlib_decode($this->skyLight), "\xff") === 2048 and
					zlib_decode($this->blockLight) === ZERO_NIBBLE_ARRAY
				))
		);
	}

	public function getBlockId(int $x, int $y, int $z) : int{
		return ord(zlib_decode($this->ids)[($x << 8) | ($z << 4) | $y]);
	}

	public function setBlockId(int $x, int $y, int $z, int $id) : bool{
		$data = zlib_decode($this->ids);
		$data[($x << 8) | ($z << 4) | $y] = chr($id);
		$this->ids = zlib_encode($data, ZLIB_ENCODING_RAW, 7);
		return true;
	}

	public function getBlockData(int $x, int $y, int $z) : int{
		return (ord(zlib_decode($this->data)[($x << 7) | ($z << 3) | ($y >> 1)]) >> (($y & 1) << 2)) & 0xf;
	}

	public function setBlockData(int $x, int $y, int $z, int $data) : bool{
		$i = ($x << 7) | ($z << 3) | ($y >> 1);
		$data = zlib_decode($this->data);
		$shift = ($y & 1) << 2;
		$byte = ord($data[$i]);
		$data[$i] = chr(($byte & ~(0xf << $shift)) | (($data & 0xf) << $shift));
		$this->data = zlib_encode($data, ZLIB_ENCODING_RAW, 7);
		return true;
	}

	public function getFullBlock(int $x, int $y, int $z) : int{
		$i = ($x << 8) | ($z << 4) | $y;
		return (ord(zlib_decode($this->ids)[$i]) << 4) | ((ord(zlib_decode($this->data)[$i >> 1]) >> (($y & 1) << 2)) & 0xf);
	}

	public function setBlock(int $x, int $y, int $z, ?int $id = null, ?int $data = null) : bool{
		$i = ($x << 8) | ($z << 4) | $y;
		$ids = zlib_decode($this->ids);
		$changed = false;
		if($id !== null){
			$block = chr($id);
			if($ids[$i] !== $block){
				$ids[$i] = $block;
				$changed = true;
			}
		}
		$this->ids = zlib_encode($ids, ZLIB_ENCODING_RAW, 7);

		if($data !== null){
			$i >>= 1;
			$propData = zlib_decode($this->data);
			$shift = ($y & 1) << 2;
			$oldPair = ord($propData[$i]);
			$newPair = ($oldPair & ~(0xf << $shift)) | (($data & 0xf) << $shift);
			if($newPair !== $oldPair){
				$propData[$i] = chr($newPair);
				$changed = true;
			}
			$this->data = zlib_encode($propData, ZLIB_ENCODING_RAW, 7);
		}

		return $changed;
	}

	public function getBlockLight(int $x, int $y, int $z) : int{
		return (ord(zlib_decode($this->blockLight)[($x << 7) | ($z << 3) | ($y >> 1)]) >> (($y & 1) << 2)) & 0xf;
	}

	public function setBlockLight(int $x, int $y, int $z, int $level) : bool{
		$i = ($x << 7) | ($z << 3) | ($y >> 1);
		$blockLight = zlib_decode($this->blockLight);
		$shift = ($y & 1) << 2;
		$byte = ord($blockLight[$i]);
		$blockLight[$i] = chr(($byte & ~(0xf << $shift)) | (($level & 0xf) << $shift));
		$this->blockLight = zlib_encode($blockLight, ZLIB_ENCODING_RAW, 7);

		return true;
	}

	public function getBlockSkyLight(int $x, int $y, int $z) : int{
		return (ord(zlib_decode($this->skyLight)[($x << 7) | ($z << 3) | ($y >> 1)]) >> (($y & 1) << 2)) & 0xf;
	}

	public function setBlockSkyLight(int $x, int $y, int $z, int $level) : bool{
		$i = ($x << 7) | ($z << 3) | ($y >> 1);
		$skyLight = zlib_decode($this->skyLight);
		$shift = ($y & 1) << 2;
		$byte = ord($skyLight[$i]);
		$skyLight[$i] = chr(($byte & ~(0xf << $shift)) | (($level & 0xf) << $shift));
		$this->skyLight = zlib_encode($skyLight, ZLIB_ENCODING_RAW, 7);

		return true;
	}

	public function getHighestBlockAt(int $x, int $z) : int{
		$low = ($x << 8) | ($z << 4);
		$i = $low | 0x0f;
		for(; $i >= $low; --$i){
			if(zlib_decode($this->ids)[$i] !== "\x00"){
				return $i & 0x0f;
			}
		}

		return -1; //highest block not in this subchunk
	}

	public function getBlockIdColumn(int $x, int $z) : string{
		return substr(zlib_decode($this->ids), ($x << 8) | ($z << 4), 16);
	}

	public function getBlockDataColumn(int $x, int $z) : string{
		return substr(zlib_decode($this->data), ($x << 7) | ($z << 3), 8);
	}

	public function getBlockLightColumn(int $x, int $z) : string{
		return substr(zlib_decode($this->blockLight), ($x << 7) | ($z << 3), 8);
	}

	public function getBlockSkyLightColumn(int $x, int $z) : string{
		return substr(zlib_decode($this->skyLight), ($x << 7) | ($z << 3), 8);
	}

	public function getBlockIdArray() : string{
		assert(strlen($this->ids) === 4096, "Wrong length of ID array, expecting 4096 bytes, got " . strlen($this->ids));
		return zlib_decode($this->ids);
	}

	public function getBlockDataArray() : string{
		assert(strlen($this->data) === 2048, "Wrong length of data array, expecting 2048 bytes, got " . strlen($this->data));
		return zlib_decode($this->data);
	}

	public function getBlockSkyLightArray() : string{
		assert(strlen($this->skyLight) === 2048, "Wrong length of skylight array, expecting 2048 bytes, got " . strlen($this->skyLight));
		return zlib_decode($this->skyLight);
	}

	public function setBlockSkyLightArray(string $data){
		assert(strlen($data) === 2048, "Wrong length of skylight array, expecting 2048 bytes, got " . strlen($data));
		$this->skyLight = zlib_encode($data, ZLIB_ENCODING_RAW, 7);
	}

	public function getBlockLightArray() : string{
		assert(strlen($this->blockLight) === 2048, "Wrong length of light array, expecting 2048 bytes, got " . strlen($this->blockLight));
		return zlib_decode($this->blockLight);
	}

	public function setBlockLightArray(string $data){
		assert(strlen($data) === 2048, "Wrong length of light array, expecting 2048 bytes, got " . strlen($data));
		$this->blockLight = zlib_encode($data, ZLIB_ENCODING_RAW, 7);
	}

}