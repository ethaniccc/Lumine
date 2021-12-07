<?php

namespace LumineServer\data\world;

use pocketmine\level\format\SubChunk;

if(!defined(__NAMESPACE__ . '\ZERO_NIBBLE_ARRAY')){
	define(__NAMESPACE__ . '\ZERO_NIBBLE_ARRAY', str_repeat("\x00", 2048));
}

final class SubChunkOverride extends SubChunk {

	private bool $isDataEncoded = false;

	private static function assignData(string $data, int $length, string $value = "\x00") : string{
		if(strlen($data) !== $length){
			assert($data === "", "Invalid non-zero length given, expected $length, got " . strlen($data));
			return str_repeat($value, $length);
		}
		return $data;
	}

	public function decodeData(): void {
		if ($this->isDataEncoded) {
			$this->ids = zlib_decode($this->ids);
			$this->data = zlib_decode($this->data);
			$this->isDataEncoded = false;
		}
	}

	public function encodeData(): void {
		if (!$this->isDataEncoded) {
			$this->ids = zlib_encode($this->ids, ZLIB_ENCODING_RAW, 4);
			$this->data = zlib_encode($this->data, ZLIB_ENCODING_RAW, 4);
			$this->isDataEncoded = true;
		}
	}

	public function __construct(string $ids = "", string $data = "", string $skyLight = "", string $blockLight = "") {
		$this->ids = self::assignData($ids, 4096);
		$this->data = self::assignData($data, 2048);
		$this->skyLight = "";
		$this->blockLight = "";
		$this->encodeData();
		$this->collectGarbage();
	}

	public function getBlockId(int $x, int $y, int $z) : int{
		$this->decodeData();
		return parent::getBlockId($x, $y, $z);
	}

	public function setBlockId(int $x, int $y, int $z, int $id) : bool{
		$this->decodeData();
		return parent::setBlockId($x, $y, $z, $id);
	}

	public function getBlockData(int $x, int $y, int $z) : int{
		$this->decodeData();
		return parent::getBlockData($x, $y, $z);
	}

	public function setBlockData(int $x, int $y, int $z, int $data) : bool{
		$this->decodeData();
		return parent::setBlockData($x, $y, $z, $data);
	}

	public function getFullBlock(int $x, int $y, int $z) : int{
		$this->decodeData();
		return parent::getFullBlock($x, $y, $z);
	}

	public function setBlock(int $x, int $y, int $z, ?int $id = null, ?int $data = null) : bool{
		$this->decodeData();
		return parent::setBlock($x, $y, $z, $id, $data);
	}

	public function getHighestBlockAt(int $x, int $z) : int{
		$this->decodeData();
		return parent::getHighestBlockAt($x, $z);
	}

	public function getBlockIdColumn(int $x, int $z) : string{
		$this->decodeData();
		return parent::getBlockIdColumn($x, $z);
	}

	public function getBlockDataColumn(int $x, int $z) : string{
		$this->decodeData();
		return parent::getBlockDataColumn($x, $z);
	}

	public function getBlockIdArray() : string{
		assert(strlen($this->ids) === 4096, "Wrong length of ID array, expecting 4096 bytes, got " . strlen($this->ids));
		$this->decodeData();
		return $this->ids;
	}

	public function getBlockDataArray() : string{
		assert(strlen($this->data) === 2048, "Wrong length of data array, expecting 2048 bytes, got " . strlen($this->data));
		$this->decodeData();
		return $this->data;
	}

}