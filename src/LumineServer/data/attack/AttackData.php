<?php

namespace LumineServer\data\attack;

use pocketmine\math\Vector3;

final class AttackData {

	public function __construct(
		public int $attackedEntity,
		public Vector3 $attackPos,
	) {}

}