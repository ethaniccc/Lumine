<?php

namespace LumineServer\data\location;

use pocketmine\math\Vector3;

final class LocationMap {

    /** @var LocationData[] */
    public array $map = [];

    public function tick(): void {
        foreach ($this->map as $data) {
            $data->tick();
        }
    }

    public function add(int $entityRuntimeId, Vector3 $pos, Vector3 $velocity, bool $isPlayer): void {
        $data = new LocationData($entityRuntimeId, $pos, $isPlayer);
        if ($velocity->lengthSquared() > 0) {
            $data->set($pos->addVector($velocity));
        }
        $this->map[$entityRuntimeId] = $data;
    }

    public function get(int $entityRuntimeId): ?LocationData {
        return $this->map[$entityRuntimeId] ?? null;
    }

    public function remove(int $entityRuntimeId): void {
        unset($this->map[$entityRuntimeId]);
    }

}