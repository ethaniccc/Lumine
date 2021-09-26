<?php

namespace LumineServer\data\world;

use pocketmine\block\BlockLegacyIds;
use pocketmine\utils\BinaryStream;
use pocketmine\world\format\BiomeArray;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\SubChunk;
use UnexpectedValueException;
use function base64_encode;
use function count;
use function str_replace;
use function substr;

final class NetworkChunkDeserializer {

    public static function chunkNetworkDeserialize(string $data, int $chunkX, int $chunkZ, int $subChunkCount): ?Chunk {  // todo
        $nextPos = 0;
        $subChunks = [];
        $total = "";
        for ($i = 0; $i < $subChunkCount; ++$i) {
            $subInformation = substr($data, $nextPos + 1, 2048 + 4096);
            $subData = substr($subInformation, 4096, 2048);
            if ($subData === false) {
                throw new UnexpectedValueException("Error while processing network chunk - unexpected data given (" . base64_encode($subInformation) . " current=" . count($subChunks) . " needed=$subChunkCount)");
            }
            $subChunks[] = new SubChunk(0, $subData);
            $total .= "\x00" . $subInformation;
            // strlen(chr(0)) + strlen(IDS) + strlen(DATA)
            $nextPos += 1 + 2048 + 4096;
        }
        $data = str_replace($total, "", $data);
        $biomeIds = substr($data, 0, 256);
        // TODO: Tiles, however - we probably don't need Tiles for our use case right now
        return new Chunk($subChunks, new BiomeArray($biomeIds));
    }

    public static function deserialize(string $payload, int $subChunkCount) : Chunk{
        $stream = new BinaryStream($payload);

        $subChunks = [];
        for($y = 0; $y < $subChunkCount; ++$y){
            $stream->getByte(); //version
            $layers = [];
            for($l = 0, $layerCount = $stream->getByte(); $l < $layerCount; ++$l){
                $layers[] = self::deserializePalettedBlockArray($stream);
            }
            $subChunks[$y] = new SubChunk(BlockLegacyIds::AIR << 4, $layers);
        }

        $biomeIdArray = $stream->get(256);

        $stream->getByte(); //border block array count

        // TODO: tiles

        return new Chunk($subChunks, null, null, new BiomeArray($biomeIdArray));
    }

    public static function deserializePalettedBlockArray(BinaryStream $stream) : PalettedBlockArray{
        $bitsPerBlock = $stream->getByte() >> 1;

        $wordArray = $stream->get(PalettedBlockArray::getExpectedWordArraySize($bitsPerBlock));

        $palette = [];
        for($i = 0, $paletteCount = $stream->getVarInt(); $i < $paletteCount; ++$i){
            $palette[$i] = $stream->getVarInt();
        }

        return PalettedBlockArray::fromData($bitsPerBlock, $wordArray, $palette);
    }


}