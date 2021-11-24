<?php

namespace LumineServer\detections\killaura;

use ethaniccc\Lumine\data\protocol\v428\PlayerAuthInputPacket;
use LumineServer\data\location\LocationData;
use LumineServer\data\UserData;
use LumineServer\detections\DetectionModule;
use LumineServer\utils\AABB;
use pocketmine\network\mcpe\protocol\DataPacket;
use pocketmine\network\mcpe\protocol\InventoryTransactionPacket;
use pocketmine\network\mcpe\protocol\types\inventory\UseItemOnEntityTransactionData;

final class KillauraB extends DetectionModule {

	/** @var LocationData[] */
	private array $entities = [];

	public function __construct(UserData $data) {
		parent::__construct($data, "Killaura", "B", "Checks if the user is hitting too many entities in a single instance", true);
	}

	public function run(DataPacket $packet, float $timestamp): void {
		if ($packet instanceof InventoryTransactionPacket && $packet->trData instanceof UseItemOnEntityTransactionData && $packet->trData->getActionType() === UseItemOnEntityTransactionData::ACTION_ATTACK) {
			$locationData = $this->data->locationMap->get($packet->trData->getEntityRuntimeId());
			if ($locationData !== null && !isset($this->entities[$packet->trData->getEntityRuntimeId()])) {
				$this->entities[$packet->trData->getEntityRuntimeId()] = $locationData;
			}
		} elseif ($packet instanceof PlayerAuthInputPacket) {
			if (count($this->entities) > 1) {
				$minDist = PHP_INT_MAX;
				foreach ($this->entities as $id => $data) {
					foreach ($this->entities as $subId => $subData) {
						if ($subId !== $id) {
							$minDist = min($minDist, AABB::fromPosition($data->lastPos, $data->hitboxWidth + 0.1, $data->hitboxHeight + 0.1)->distanceFromVector($subData->lastPos));
						}
					}
				}
				if ($minDist !== PHP_INT_MAX && $minDist > 1.5) {
					$this->flag([
						"mD" => round($minDist, 2),
						"entities" => count($this->entities)
					]);
				} else {
					$this->buff(-0.01);
				}
				$this->debug("entities=" . count($this->entities) . " minDist=" . ($minDist ?? "N/A"));
			}
			unset($this->entities);
			$this->entities = [];
		}
	}

}