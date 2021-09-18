<?php

namespace LumineServer\data\world;

use pocketmine\level\format\Chunk;
use pocketmine\level\format\SubChunk;
use UnexpectedValueException;

final class NetworkChunkDeserializer {

	public static function chunkNetworkDeserialize(string $data, int $chunkX, int $chunkZ, int $subChunkCount): ?Chunk {
		$nextPos = 0;
		$subChunks = [];
		$tiles = [];
		$total = "";
		for ($i = 0; $i < $subChunkCount; ++$i) {
			$subInformation = substr($data, $nextPos + 1, 2048 + 4096);
			$subIDS = substr($subInformation, 0, 4096);
			$subData = substr($subInformation, 4096, 2048);
			if ($subData === false) {
				throw new UnexpectedValueException("Error while processing network chunk - unexpected data given (" . base64_encode($subInformation) . " current=" . count($subChunks) . " needed=$subChunkCount)");
			}
			$subChunks[] = new SubChunkOverride($subIDS, $subData);
			$total .= "\x00" . $subInformation;
			// strlen(chr(0)) + strlen(IDS) + strlen(DATA)
			$nextPos += 1 + 2048 + 4096;
		}
		$data = str_replace($total, "", $data);
		$biomeIds = substr($data, 0, 256);
		$data = str_replace($biomeIds . "\x00", "", $data);
		if ($data !== '') {
			// TODO: Tiles, however - we probably don't need Tiles for our use case right now
		}
		return new Chunk($chunkX, $chunkZ, $subChunks, [], $tiles, $biomeIds, []);
	}

}