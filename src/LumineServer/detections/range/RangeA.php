<?php

namespace LumineServer\detections\range;

use LumineServer\data\UserData;
use LumineServer\detections\DetectionModule;
use LumineServer\utils\AABB;
use LumineServer\utils\MathUtils;
use pocketmine\network\mcpe\protocol\DataPacket;
use pocketmine\network\mcpe\protocol\InventoryTransactionPacket;
use pocketmine\network\mcpe\protocol\PlayerAuthInputPacket;
use pocketmine\network\mcpe\protocol\types\InputMode;
use pocketmine\network\mcpe\protocol\types\inventory\UseItemOnEntityTransactionData;
use pocketmine\utils\TextFormat;

final class RangeA extends DetectionModule {

	private bool $awaitingTick = false;

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
					$attackPos = $data->attackData->attackPos->add(0, $data->isSneaking ? 1.54 : 1.62);
					if ($data->inputMode === InputMode::TOUCHSCREEN) {
						$distance = $AABB->distanceFromVector($attackPos);
						$this->debug("dist=$distance buff={$this->buffer}");
						if ($distance > $this->settings->get("max_dist_touchscreen", 3.1)) {
							if ($this->buff($this->updateAndGetViolationAfterTicks($data->currentTick, 300)) >= 5) {
								$this->flag([
									"dist" => round($distance, 4)
								]);
							}
						} else {
							$this->buff(-0.05);
							$this->violations = max($this->violations - 0.01, 0);
						}
					} else {
						$this->awaitingTick = true;
					}
				}
			}
		} elseif ($packet instanceof PlayerAuthInputPacket && $this->awaitingTick) {
			$directionVector = MathUtils::directionVectorFromValues($data->currentPos->yaw, $data->currentPos->pitch);
			$attackPos = $data->attackData->attackPos->add(0, $data->isSneaking ? 1.54 : 1.62);
			$target = $data->locationMap->get($data->attackData->attackedEntity);
			if ($target !== null) {
				$AABB = AABB::fromPosition($target->lastPos, $target->hitboxWidth + 0.1, $target->hitboxHeight + 0.1);
				if (!$AABB->intersectsWith($data->boundingBox)) {
					$raycast = $AABB->calculateIntercept($attackPos, $attackPos->add($directionVector->multiply(20)));
					if ($raycast !== null) {
						$distance = $raycast->getHitVector()->distance($attackPos);
						$this->debug("dist=$distance buff={$this->buffer}");
						if ($distance > $this->settings->get("max_dist_normal", 3.04)) {
							if ($this->buff($this->updateAndGetViolationAfterTicks($data->currentTick, 100), 3.1) >= 3) {
								$this->flag(["dist" => round($distance, 2)]);
							}
						} else {
							$this->buff(-0.01);
							$this->violations = max($this->violations - 0.0075, 0);
						}
					}
				}
			}

			$this->awaitingTick = false;
		}
	}

}