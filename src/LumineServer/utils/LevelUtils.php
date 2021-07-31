<?php

namespace LumineServer\utils;

use LumineServer\data\world\VirtualWorld;
use pocketmine\block\UnknownBlock;
use pocketmine\math\AxisAlignedBB;

final class LevelUtils {

	public const SEARCH_ALL = 0;
	public const SEARCH_TRANSPARENT = 1;
	public const SEARCH_SOLID = 2;

	public static function checkBlocksInAABB(AxisAlignedBB $AABB, VirtualWorld $world, int $searchOption, float $epsilonXZ = 1, float $epsilonY = 1, bool $first = false): array {
		$blocks = [];
		$minX = floor($AABB->minX - 1);
		$maxX = ceil($AABB->maxX + 1);
		$minY = floor($AABB->minY - 1);
		$maxY = ceil($AABB->maxY + 1);
		$minZ = floor($AABB->minZ - 1);
		$maxZ = ceil($AABB->maxZ + 1);
		$curr = $world->getBlockAt($minX, $minY, $minZ);
		switch ($searchOption) {
			case self::SEARCH_ALL:
				if ($first) {
					return [$curr];
				}
				for ($x = $minX; $x <= $maxX; $x += $epsilonXZ) {
					for ($y = $minY; $y <= $maxY; $y += $epsilonY) {
						for ($z = $minZ; $z <= $maxZ; $z += $epsilonXZ) {
							$blocks[] = $world->getBlockAt($x, $y, $z);
						}
					}
				}
				break;
			case self::SEARCH_TRANSPARENT:
				if ($curr->hasEntityCollision()) {
					if ($first) {
						return [$curr];
					}
				}
				for ($x = $minX; $x <= $maxX; $x += $epsilonXZ) {
					for ($y = $minY; $y <= $maxY; $y += $epsilonY) {
						for ($z = $minZ; $z <= $maxZ; $z += $epsilonXZ) {
							$block = $world->getBlockAt($x, $y, $z);
							if ($block->hasEntityCollision()) {
								if ($first) {
									return [$block];
								}
								$blocks[] = $block;
							}
						}
					}
				}
				break;
			case self::SEARCH_SOLID:
				if (!$curr->canPassThrough() || $curr instanceof UnknownBlock) {
					if ($first) {
						return [$curr];
					}
				}
				for ($x = $minX; $x <= $maxX; $x += $epsilonXZ) {
					for ($y = $minY; $y <= $maxY; $y += $epsilonY) {
						for ($z = $minZ; $z <= $maxZ; $z += $epsilonXZ) {
							$block = $world->getBlockAt($x, $y, $z);
							if (!$block->canPassThrough() || $block instanceof UnknownBlock) {
								if ($first) {
									return [$block];
								}
								$blocks[] = $block;
							}
						}
					}
				}
				break; // don't you dare mention this line
		}
		return $blocks;
	}

	public static function getCollisionBBList(AxisAlignedBB $AABB, VirtualWorld $world): array {
		$list = [];
		$AABB = $AABB->expandedCopy(0.0001, 0, 0.0001);
		$minX = floor($AABB->minX - 1);
		$maxX = ceil($AABB->maxX + 1);
		$minY = floor($AABB->minY - 1);
		$maxY = ceil($AABB->maxY + 1);
		$minZ = floor($AABB->minZ - 1);
		$maxZ = ceil($AABB->maxZ + 1);
		for ($z = $minZ; $z <= $maxZ; ++$z) {
			for ($x = $minX; $x <= $maxX; ++$x) {
				for ($y = $minY; $y <= $maxY; ++$y) {
					$block = $world->getBlockAt($x, $y, $z);
					if (!$block->canPassThrough() || $block instanceof UnknownBlock) {
						foreach ($block->getCollisionBoxes() as $bb) {
							if ($bb->intersectsWith($AABB)) {
								$list[] = $bb;
							}
						}
					}
				}
			}
		}
		return $list;
	}

}