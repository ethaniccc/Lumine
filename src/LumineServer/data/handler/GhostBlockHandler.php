<?php

namespace LumineServer\data\handler;

use LumineServer\data\UserData;
use LumineServer\utils\AABB;
use pocketmine\block\Block;
use function count;

final class GhostBlockHandler {

    private ?UserData $data;

    /** @var Block[] */
    private array $possible = [];
    /** @var float[] */
    private array $count = [];

    public function __construct(UserData $data) {
        $this->data = $data;
    }

    /**
     * @return Block[]|null
     */
    public function determine(): ?array {
        $list = [];
        foreach ($this->possible as $block) {
            $bbList = count($block->getCollisionBoxes()) === 0 ? [AABB::fromBlock($block)] : $block->getCollisionBoxes();
            foreach ($bbList as $bb) {
                if ($bb->intersectsWith($this->data->boundingBox->expandedCopy(1, 1, 1))) {
                    $list[] = $block;
                    break;
                }
            }
        }
        return count($list) > 0 ? $list : null;
    }

    public function suspect(Block $block): void {
        if (!isset($this->count["{$block->getPosition()}"])) {
            $this->count["{$block->getPosition()}"] = 0;
        }
        $this->count["{$block->getPosition()}"] += 1;
        if ($this->count["{$block->getPosition()}"] > 1) {
            $this->possible["{$block->getPosition()}"] = $block;
        }
    }

    public function updateSuspected(): void {
        foreach ($this->count as $vecKey => $count) {
            $this->count[$vecKey] -= 0.5;
            if ($count <= 0) {
                unset($this->count[$vecKey]);
            }
        }
    }

    public function unsuspect(Block $block): void {
        unset($this->possible["{$block->getPosition()}"]);
        unset($this->count["{$block->getPosition()}"]);
    }

    public function isSuspected(Block $block): bool {
        return isset($this->possible["{$block->getPosition()}"]);
    }

    public function destroy(): void {
        $this->data = null;
    }

}