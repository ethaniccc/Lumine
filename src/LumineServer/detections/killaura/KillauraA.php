<?php

namespace LumineServer\detections\killaura;

use LumineServer\data\UserData;
use LumineServer\detections\DetectionModule;
use pocketmine\network\mcpe\protocol\AnimatePacket;
use pocketmine\network\mcpe\protocol\DataPacket;
use pocketmine\network\mcpe\protocol\InventoryTransactionPacket;
use pocketmine\network\mcpe\protocol\types\inventory\UseItemOnEntityTransactionData;

final class KillauraA extends DetectionModule {

    private int $lastSwingTick = 0;

    public function __construct(UserData $data) {
        parent::__construct($data, "Killaura", "A", "Checks if the player is swinging their arm while attacking an entity");
    }

    public function run(DataPacket $packet): void {
        $data = $this->data;
        if ($packet instanceof AnimatePacket && $packet->action === AnimatePacket::ACTION_SWING_ARM) {
            $tickDiff = $data->currentTick - $this->lastSwingTick;
            if ($tickDiff !== 0 && $tickDiff < 4) {
                $this->flag(["tDS" => $tickDiff]);
            }
            $this->lastSwingTick = $data->currentTick;
        } elseif ($packet instanceof InventoryTransactionPacket && $packet->trData instanceof UseItemOnEntityTransactionData && $packet->trData->getActionType() === UseItemOnEntityTransactionData::ACTION_ATTACK) {
            $tickDiff = $data->currentTick - $this->lastSwingTick;
            if ($tickDiff > $this->settings->get("diff", 4)) {
                $this->flag(["tDSS" => $tickDiff]);
            }
        }
    }

}