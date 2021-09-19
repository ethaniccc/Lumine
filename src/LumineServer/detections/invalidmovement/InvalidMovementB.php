<?php

namespace LumineServer\detections\invalidmovement;

use ethaniccc\Lumine\data\protocol\v428\PlayerAuthInputPacket;
use LumineServer\data\UserData;
use LumineServer\detections\DetectionModule;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\DataPacket;
use function abs;
use function max;
use function round;

final class InvalidMovementB extends DetectionModule {

    private Vector3 $lastPrediction;

    public function __construct(UserData $data) {
        parent::__construct($data, "InvalidMovement", "B", "Checks if the user's Y movement is close to the predicted movement");
        $this->lastPrediction = new Vector3(0, 0, 0);
    }

    public function run(DataPacket $packet, float $timestamp): void {
        $data = $this->data;
        if ($packet instanceof PlayerAuthInputPacket) {
            $diff = abs($data->motion->y - $data->previousServerPredictedMotion->y);
            $lastDiff = abs($data->motion->y - $this->lastPrediction->y);
            if ($diff > 0.01 && $lastDiff > 0.01 && !$data->isTeleporting && $data->ticksSinceInCobweb >= 10 && $data->ticksSinceInLiquid >= 10) {
                if ($this->buff() >= 10) {
                    $this->flag([
                        "pY" => round($data->previousServerPredictedMotion->y, 5),
                        "mY" => round($data->motion->y, 5)
                    ]);
                }
            } else {
                $this->buff(-0.02);
                $this->violations = max($this->violations - 0.02, 0);
            }
            $this->lastPrediction = clone $data->previousServerPredictedMotion;
        }
    }

}