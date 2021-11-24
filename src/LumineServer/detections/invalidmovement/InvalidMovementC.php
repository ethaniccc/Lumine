<?php

namespace LumineServer\detections\invalidmovement;

use ethaniccc\Lumine\data\protocol\InputConstants;
use ethaniccc\Lumine\data\protocol\v428\PlayerAuthInputPacket;
use LumineServer\data\UserData;
use LumineServer\detections\DetectionModule;
use pocketmine\network\mcpe\protocol\DataPacket;

class InvalidMovementC extends DetectionModule {

	private int $jumpTicks = 0;

    public function __construct(UserData $data) {
        parent::__construct($data, "InvalidMovement", "C", "Checks if the delay between the user's jumps are invalid");
    }

	public function run(DataPacket $packet, float $timestamp): void {
		$data = $this->data;
		if ($packet instanceof PlayerAuthInputPacket) {
			$this->jumpTicks--;
			$isHoldingJump = InputConstants::hasFlag($packet, InputConstants::JUMPING);
			if (!$isHoldingJump) {
				$this->jumpTicks = 0;
			}
			if ($data->isJumping) {
				if ($this->jumpTicks > 0 || !$isHoldingJump) {
					$this->flag([
						"jT" => $this->jumpTicks,
						"jumping" => var_export($isHoldingJump, true)
					]);
				}
				$this->jumpTicks = 10;
			}
			$this->debug("jumpTicks={$this->jumpTicks} isHoldingJump=" . var_export($isHoldingJump, true));
		}
	}

}