<?php

namespace LumineServer\data\world;

use LumineServer\Server;
use pocketmine\block\Block;
use pocketmine\block\BlockFactory;
use pocketmine\block\VanillaBlocks;
use pocketmine\math\Vector3;
use pocketmine\world\format\Chunk;
use pocketmine\world\World;
use function array_keys;
use function is_null;

final class VirtualWorld {

    /** @var Chunk[] */
    private array $chunks = [];

    public function addChunk(Chunk $chunk, float $chunkX, float $chunkZ): void {
        if (isset($this->chunks[World::chunkHash($chunkX, $chunkZ)])) {
            unset($this->chunks[World::chunkHash($chunkX, $chunkZ)]);
        }
        $this->chunks[World::chunkHash($chunkX, $chunkZ)] = $chunk;
    }

    public function getChunkByHash(int $hash): ?Chunk {
        return $this->chunks[$hash] ?? null;
    }

    /**
     * @return Chunk[]
     */
    public function getAllChunks(): array {
        return $this->chunks;
    }

    public function removeChunkByHash(int $hash): void {
        unset($this->chunks[$hash]);
    }

    public function getBlock(Vector3 $pos): Block {
        $pos = $pos->floor();
        return $this->getBlockAt($pos->x, $pos->y, $pos->z);
    }

    public function getBlockAt(int $x, int $y, int $z): Block {
        $chunkHash = World::chunkHash($x >> 4, $z >> 4);
        $chunk = $this->chunks[$chunkHash] ?? null;
        if (is_null($chunk)) {
            $air = VanillaBlocks::AIR();
            $air->getPosition()->x = $x;
            $air->getPosition()->y = $y;
            $air->getPosition()->z = $z;
            return $air;
        }
        $block = clone BlockFactory::getInstance()->fromFullBlock($chunk->getFullBlock($x & 0x0f, $y, $z & 0x0f));
        $block->getPosition()->x = $x;
        $block->getPosition()->y = $y;
        $block->getPosition()->z = $z;
        return $block;
    }

    public function setBlock(Vector3 $pos, int $fullID): void {
        $pos = $pos->floor();
        $chunk = $this->getChunk($pos->x >> 4, $pos->z >> 4);
        if (is_null($chunk)) {
            Server::getInstance()->logger->log("Unexpected null chunk when setting block");
            return;
        }
        $chunk->setFullBlock($pos->x & 0x0f, $pos->y, $pos->z & 0x0f, $fullID);
    }

    public function getChunk(int $chunkX, int $chunkZ): ?Chunk {
        return $this->chunks[World::chunkHash($chunkX, $chunkZ)] ?? null;
    }

    public function isValidChunk(int $hash): bool {
        return isset($this->chunks[$hash]);
    }

    public function destroy(): void {
        foreach (array_keys($this->chunks) as $hash) {
            unset($this->chunks[$hash]);
        }
    }

}