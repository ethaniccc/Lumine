<?php

namespace LumineServer\data\location;

use pocketmine\math\Vector3;

final class LocationData {

    public Vector3 $currentPos;
    public Vector3 $lastPos;
    public int $newPosRotationIncrements = 0;
    public int $teleportTicks = 0;
    public float $hitboxWidth = 0.3;
    public float $hitboxHeight = 1.8;

    public function __construct(
        public int $entityRuntimeId,
        public Vector3 $receivedPos,
        public bool $isPlayer
    ) {
        $this->currentPos = clone $this->receivedPos;
        $this->lastPos = clone $this->currentPos;
    }

    public function set(Vector3 $newPos, bool $teleport = false): void {
        $this->newPosRotationIncrements = 3;
        if ($teleport) {
            $this->teleportTicks = 0;
        }
        $this->receivedPos = $newPos;
    }

    public function tick(): void {
        ++$this->teleportTicks;
        if ($this->newPosRotationIncrements > 0) {
            $lastPos = clone $this->currentPos;
            $this->currentPos->x += ($this->receivedPos->x - $this->lastPos->x) / $this->newPosRotationIncrements;
            $this->currentPos->y += ($this->receivedPos->y - $this->lastPos->y) / $this->newPosRotationIncrements;
            $this->currentPos->z += ($this->receivedPos->z - $this->lastPos->z) / $this->newPosRotationIncrements;
            $this->lastPos = $lastPos;
        }
        $this->newPosRotationIncrements--;
    }

}