<?php

namespace LumineServer\detections\invalidmovement;

use ethaniccc\Lumine\data\protocol\v428\PlayerAuthInputPacket;
use LumineServer\data\UserData;
use LumineServer\detections\DetectionModule;
use pocketmine\network\mcpe\protocol\DataPacket;

final class InvalidMovementA extends DetectionModule {

	public function __construct(UserData $data) {
		parent::__construct($data, "InvalidMovement", "A", "Checks if the user's XZ movement is close to the predicted movement");
	}

	public function run(DataPacket $packet, float $timestamp): void {
		$data = $this->data;
		if ($packet instanceof PlayerAuthInputPacket && $data->motion->lengthSquared() > 1E-10) {
			$diffVec = $data->motion->subtract($data->previousServerPredictedMotion)->abs();
			$max = 0.15;
			if ($data->isCollidedHorizontally) {
				$max = 0.25;
			}
			if (($diffVec->x > $max || $diffVec->z > $max) && !$data->isTeleporting && $data->ticksSinceInLiquid >= 10
			&& $data->ticksSinceInCobweb >= 10) {
				if ($this->buff() >= 2) {
					$this->flag([
						"xD" => round($diffVec->x, 5),
						"zD" => round($diffVec->z, 5)
					]);
				}
			} else {
				$this->buff(-0.02);
				$this->violations = max($this->violations - 0.01, 0);
			}
		}
	}

}