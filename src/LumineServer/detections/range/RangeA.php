<?php

namespace LumineServer\detections\range;

use LumineServer\data\UserData;
use LumineServer\detections\DetectionModule;
use LumineServer\utils\AABB;
use pocketmine\network\mcpe\protocol\DataPacket;
use pocketmine\network\mcpe\protocol\InventoryTransactionPacket;
use pocketmine\network\mcpe\protocol\types\inventory\UseItemOnEntityTransactionData;

final class RangeA extends DetectionModule {

	public function __construct(UserData $data) {
		parent::__construct($data, "Range", "A", "Checks if the player has an abnormal amount of range");
	}

	public function run(DataPacket $packet, float $timestamp): void {
		$data = $this->data;
		if ($packet instanceof InventoryTransactionPacket) {
			$trData = $packet->trData;
			if ($trData instanceof UseItemOnEntityTransactionData && $data->isSurvival && $trData->getActionType() === UseItemOnEntityTransactionData::ACTION_ATTACK) {
				$target = $data->locationMap->get($trData->getEntityRuntimeId());
				if ($target !== null && $target->teleportTicks >= 40) {
					$AABB = AABB::fromPosition($target->lastPos, $target->hitboxWidth, $target->hitboxHeight);
					$attackPos = $trData->getPlayerPos()->subtract(0, 1.62)->add(0, $data->isSneaking ? 1.54 : 1.62);
					$distance = $AABB->distanceFromVector($attackPos);
					$this->debug("dist=$distance");
					if ($distance > $this->settings->get("max_dist", 3.1)) {
						if ($this->buff() >= 5) {
							$this->flag([
								"dist" => round($distance, 4)
							]);
						}
					} else {
						$this->buff(-0.05);
						$this->violations = max($this->violations - 0.0075, 0);
					}
				}
			}
		}
	}

}