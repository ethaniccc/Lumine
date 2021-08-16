<?php

namespace LumineServer\detections\badpackets;

use LumineServer\data\UserData;
use LumineServer\detections\DetectionModule;
use pocketmine\network\mcpe\protocol\DataPacket;
use pocketmine\network\mcpe\protocol\MovePlayerPacket;

final class BadPacketsA extends DetectionModule {

    private int $lastTick = 0;

    public function __construct(UserData $data) {
        parent::__construct($data, "BadPackets", "A", "Checks if the player is sending the wrong movement packet");
    }

    public function run(DataPacket $packet): void {
        $data = $this->data;
        if ($packet instanceof MovePlayerPacket) {
            $diff = $data->currentTick - $this->lastTick;
            if ($diff < 2) {
                $this->flag([
                    "diff" => $diff
                ]);
            }
            $this->lastTick = $data->currentTick;
        }
    }

}