<?php

namespace LumineServer\detections\combat;

use LumineServer\data\UserData;
use LumineServer\detections\DetectionModule;
use LumineServer\utils\AABB;
use pocketmine\network\mcpe\protocol\DataPacket;
use pocketmine\network\mcpe\protocol\InventoryTransactionPacket;
use pocketmine\network\mcpe\protocol\types\inventory\UseItemOnEntityTransactionData;

final class CombatB extends DetectionModule {

	public function __construct(UserData $data) {
		parent::__construct($data, "Combat", "B", "Checks if the player has an abnormal amount of range");
	}

	public function run(DataPacket $packet): void {
		$data = $this->data;
		if ($packet instanceof InventoryTransactionPacket) {
			$trData = $packet->trData;
			if ($trData instanceof UseItemOnEntityTransactionData && $trData->getActionType() === UseItemOnEntityTransactionData::ACTION_ATTACK) {
				$target = $data->locationMap->get($trData->getEntityRuntimeId());
				if ($target !== null) {
					$AABB = AABB::fromPosition($target->lastPos, $target->hitboxWidth, $target->hitboxHeight);
					$attackPos = $trData->getPlayerPos()->subtract(0, 1.62)->add(0, $data->isSneaking ? 1.54 : 1.62);
					$distance = $AABB->distanceFromVector($attackPos);
					if ($distance > $this->settings->get("max_dist", 3)) {
						if ($this->buff() >= 2) {
							$this->flag([
								"dist" => round($distance, 4)
							]);
						}
					} else {
						$this->buff(-0.035);
						$this->violations = max($this->violations - 0.0075, 0);
					}
				}
			}
		}
	}

}