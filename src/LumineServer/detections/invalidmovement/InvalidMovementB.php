<?php

namespace LumineServer\detections\invalidmovement;

use ethaniccc\Lumine\data\protocol\v428\PlayerAuthInputPacket;
use LumineServer\data\UserData;
use LumineServer\detections\DetectionModule;
use pocketmine\network\mcpe\protocol\DataPacket;

final class InvalidMovementB extends DetectionModule {

    public function __construct(UserData $data) {
        parent::__construct($data, "InvalidMovement", "B", "Checks if the user's Y movement is close to the predicted movement");
    }

    public function run(DataPacket $packet): void {
        $data = $this->data;
        if ($packet instanceof PlayerAuthInputPacket && $data->motion->lengthSquared() > 1E-10) {
            $diff = abs($data->motion->y - $data->previousServerPredictedMotion->y);
            if ($diff > 0.01 && !$data->isTeleporting && $data->ticksSinceInCobweb >= 10 && $data->ticksSinceInLiquid >= 10) {
                if ($this->buff() >= 3) {
                    $this->flag([
                        "pY" => round($data->previousServerPredictedMotion->y, 5),
                        "mY" => round($data->motion->y, 5)
                    ]);
                }
            } else {
                $this->buff(-0.02);
                $this->violations = max($this->violations - 0.02, 0);
            }
        }
    }

}