<?php

namespace LumineServer\detections\movement;

use ethaniccc\Lumine\data\protocol\v428\PlayerAuthInputPacket;
use LumineServer\data\UserData;
use LumineServer\detections\DetectionModule;
use pocketmine\network\mcpe\protocol\DataPacket;
use pocketmine\utils\TextFormat;

final class MovementA extends DetectionModule {

	public function __construct(UserData $data) {
		parent::__construct($data, "Movement", "A", "Estimates the Y movement of the user when off the ground");
	}

	public function run(DataPacket $packet): void {
		$data = $this->data;
		if ($packet instanceof PlayerAuthInputPacket) {
			if ($data->ticksOffGround > 2 && !$data->isCollidedHorizontally) {
				$diff = min(abs($data->motion->y - $data->previousServerPredictedMotion->y), abs($data->motion->y - $data->serverPredictedMotion->y));
				if ($diff > 0.01) {
					if ($this->buff() > 5) {
						$data->message("bro stop flying ffs ({$this->buffer})");
					}
				} else {
					$this->buff(-0.02);
				}
			}
			$diffX = abs($data->motion->x - $data->previousServerPredictedMotion->x);
			$diffZ = abs($data->motion->z - $data->previousServerPredictedMotion->z);
			if ($diffX > 0.03 && $diffZ > 0.03) {
				$data->message("stop speeding ffs ($diffX, $diffZ)");
			}
		}
	}

}