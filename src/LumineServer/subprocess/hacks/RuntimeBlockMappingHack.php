<?php

namespace LumineServer\subprocess\hacks;

use pocketmine\block\BlockIds;
use function LumineServer\subprocess\getLegacyToRuntimeMap;
use function LumineServer\subprocess\getRuntimeToLegacyMap;

final class RuntimeBlockMappingHack {

	private function __construct(){
		//NOOP
	}

	public static function toStaticRuntimeId(int $id, int $meta = 0) : int{
		return getLegacyToRuntimeMap()[($id << 4) | $meta] ?? getLegacyToRuntimeMap()[$id << 4] ?? getLegacyToRuntimeMap()[BlockIds::INFO_UPDATE << 4];
	}

	/**
	 * @return int[] [id, meta]
	 */
	public static function fromStaticRuntimeId(int $runtimeId) : array{
		$v = getRuntimeToLegacyMap()[$runtimeId];
		return [$v >> 4, $v & 0xf];
	}

}