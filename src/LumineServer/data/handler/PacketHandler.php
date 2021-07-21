<?php

namespace LumineServer\data\handler;

use ethaniccc\Lumine\data\protocol\v428\PlayerAuthInputPacket;
use LumineServer\data\movement\MovementConstants;
use LumineServer\data\UserData;
use LumineServer\data\world\NetworkChunkDeserializer;
use LumineServer\events\LagCompensationEvent;
use LumineServer\events\SocketEvent;
use LumineServer\Server;
use LumineServer\utils\AABB;
use LumineServer\utils\LevelUtils;
use pocketmine\block\Block;
use pocketmine\block\BlockFactory;
use pocketmine\block\BlockIds;
use pocketmine\block\Cobweb;
use pocketmine\block\Ladder;
use pocketmine\block\Liquid;
use pocketmine\block\UnknownBlock;
use pocketmine\block\Vine;
use pocketmine\level\Location;
use pocketmine\level\Position;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\convert\RuntimeBlockMapping;
use pocketmine\network\mcpe\protocol\BatchPacket;
use pocketmine\network\mcpe\protocol\DataPacket;
use pocketmine\network\mcpe\protocol\InventoryTransactionPacket;
use pocketmine\network\mcpe\protocol\LevelChunkPacket;
use pocketmine\network\mcpe\protocol\MovePlayerPacket;
use pocketmine\network\mcpe\protocol\NetworkChunkPublisherUpdatePacket;
use pocketmine\network\mcpe\protocol\NetworkStackLatencyPacket;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\SetActorMotionPacket;
use pocketmine\network\mcpe\protocol\SetLocalPlayerAsInitializedPacket;
use pocketmine\network\mcpe\protocol\types\inventory\UseItemTransactionData;
use pocketmine\network\mcpe\protocol\UpdateBlockPacket;

final class PacketHandler {

	public ?UserData $data;

	private const USED_PACKETS = [
		ProtocolInfo::LEVEL_CHUNK_PACKET, ProtocolInfo::NETWORK_CHUNK_PUBLISHER_UPDATE_PACKET, ProtocolInfo::NETWORK_STACK_LATENCY_PACKET,
		ProtocolInfo::UPDATE_BLOCK_PACKET
	];

	public function __construct(UserData $data) {
		$this->data = $data;
	}

	public function inbound(DataPacket $packet, float $timestamp): void {
		$data = $this->data;
		if ($packet instanceof PlayerAuthInputPacket) {
			if ($packet->itemInteractionData !== null) {
				$data->world->setBlock($packet->itemInteractionData->blockPos, 0, 0);
			}
			$data->lastPos = clone $data->currentPos;
			unset($data->currentPos);
			$data->currentPos = Location::fromObject($packet->getPosition()->subtract(0, 1.62), null, $packet->getYaw(), $packet->getPitch());
			$data->lastMotion = clone $data->motion;
			unset($data->motion);
			$data->motion = $data->currentPos->subtract($data->lastPos)->asVector3();
			unset($data->boundingBox);
			$data->boundingBox = AABB::fromPosition($data->currentPos, $data->hitboxWidth, $data->hitboxHeight);
			$data->isInLoadedChunk = $data->world->isValidChunk(floor($data->currentPos->x) >> 4, floor($data->currentPos->z) >> 4);

			$data->isInVoid = $data->currentPos->y <= -35;
			$data->ghostBlockHandler->updateSuspected();

			if (!$data->isInLoadedChunk || $data->isInVoid) {
				$data->onGround = true;
				$data->expectedOnGround = true;
				$data->isCollidedVertically = false;
				$data->isCollidedVertically = false;
			} else {
				$data->expectedOnGround = false;
				$data->isCollidedVertically = false;
				$data->isCollidedVertically = false;
				$data->lastBlocksBelow = $data->blocksBelow;
				unset($data->blocksBelow);
				$data->blocksBelow = [];

				$liquids = 0;
				$climbable = 0;
				$cobweb = 0;

				$floorPos = $data->currentPos->floor();
				$AABB = $data->boundingBox->expandedCopy(0.2, MovementConstants::GROUND_MODULO, 0.2);
				$verticalAABB = $data->boundingBox->expandedCopy(0, MovementConstants::GROUND_MODULO, 0);
				$horizontalAABB = $data->boundingBox->expandedCopy(0.2, 0, 0.2);
				$blocks = LevelUtils::checkBlocksInAABB($AABB, $data->world, LevelUtils::SEARCH_ALL);
				foreach ($blocks as $block) {
					/** @var Block $block */
					if (!$data->isCollidedHorizontally) {
						// snow layers are evil
						$data->isCollidedHorizontally = $block->getId() !== BlockIds::AIR && (count($block->getCollisionBoxes()) === 0 ? AABB::fromBlock($block)->intersectsWith($horizontalAABB) : $block->collidesWithBB($horizontalAABB));
					}
					if ($block->getId() !== BlockIds::AIR && (count($block->getCollisionBoxes()) === 0 ? AABB::fromBlock($block)->intersectsWith($verticalAABB) : $block->collidesWithBB($verticalAABB))) {
						$data->isCollidedVertically = true;
						if (floor($block->y) <= $floorPos->y) {
							$data->expectedOnGround = true;
							$data->blocksBelow[] = $block;
						} else {
							$hasAbove = true;
						}
					}
					if ($block instanceof Liquid) {
						$liquids++;
					} elseif ($block instanceof Cobweb) {
						$cobweb++;
					} elseif ($block instanceof Ladder || $block instanceof Vine) {
						$climbable++;
					}
				}

				$liquids > 0 ?
					$data->ticksSinceInLiquid = 0 :
					$data->ticksSinceInLiquid++;
				$cobweb > 0 ?
					$data->ticksSinceInCobweb = 0 :
					$data->ticksSinceInCobweb++;
				$climbable > 0 ?
					$data->ticksSinceInClimbable = 0 :
					$data->ticksSinceInClimbable++;

				$predictedY = $data->clientPrediction->y;
				$var1 = abs($data->motion->y - $predictedY) > 0.001;
				$var2 = $predictedY < 0 || $data->isCollidedHorizontally;
				$data->hasCollisionAbove = $var1 && $predictedY > 0 && abs($predictedY) > 0.005 && isset($hasAbove);
				$data->onGround = $var1 && $var2 && $data->expectedOnGround;
				if (!$data->onGround && ($determinedGhostBlocks = $data->ghostBlockHandler->determine()) !== null) {
					foreach ($determinedGhostBlocks as $block) {
						$pk = new UpdateBlockPacket();
						$pk->blockRuntimeId = $block->getRuntimeId();
						$pk->x = $block->x;
						$pk->y = $block->y;
						$pk->z = $block->z;
						$pk->flags = UpdateBlockPacket::FLAG_ALL;
						$pk->dataLayerId = ($block instanceof Liquid ? UpdateBlockPacket::DATA_LAYER_LIQUID : UpdateBlockPacket::DATA_LAYER_NORMAL);
						if (floor($block->y) <= $floorPos->y) {
							$data->teleport($block->add(0.5, 0, 0.5));
						}
						$data->latencyManager->sandwich(function () use ($data, $block): void {
							$data->world->setBlock($block->asVector3(), $block->getId(), $block->getDamage());
							$data->ghostBlockHandler->unsuspect($block);
						}, $pk);
					}
				}
			}

			unset($data->clientPrediction);
			$data->clientPrediction = $packet->getDelta();

			$data->moveForward = $packet->getMoveVecZ() * 0.98;
			$data->moveStrafe = $packet->getMoveVecX() * 0.98;

			$data->ticksSinceMotion++;
			if ($data->onGround) {
				$data->ticksOnGround++;
				$data->ticksOffGround = 0;
			} else {
				$data->ticksOffGround++;
				$data->ticksOnGround = 0;
			}
			$data->currentTick++;
		} elseif ($packet instanceof SetLocalPlayerAsInitializedPacket) {
			$data->loggedIn = true;
			$data->entityRuntimeId = $packet->entityRuntimeId;
		} elseif ($packet instanceof NetworkStackLatencyPacket) {
			$data->latencyManager->execute($packet->timestamp);
		} elseif ($packet instanceof InventoryTransactionPacket) {
			$trData = $packet->trData;
			if ($trData instanceof UseItemTransactionData) {
				if ($trData->getActionType() === UseItemTransactionData::ACTION_CLICK_BLOCK) {
					$clickedBlockPos = $trData->getBlockPos();
					$newBlockPos = $clickedBlockPos->getSide($trData->getFace());
					$blockToReplace = $data->world->getBlock($newBlockPos);
					$block = $trData->getItemInHand()->getItemStack()->getBlock();
					if ($trData->getItemInHand()->getItemStack()->getId() < 0) {
						$block = new UnknownBlock($trData->getItemInHand()->getItemStack()->getId(), 0);
						$block->position($blockToReplace->asPosition());
						if ($block->collidesWithBB($data->boundingBox)) {
							return;
						}
					}
					$block->position($blockToReplace->asPosition());
					// placement before ticking?
					if ($blockToReplace->canBeReplaced() && ($block instanceof UnknownBlock || $block->canBePlaced()) && !$block->collidesWithBB($data->boundingBox)) {
						if (($block->canBePlaced() || $block instanceof UnknownBlock)) {
							$data->world->setBlock($blockToReplace->asVector3(), $block->getId(), $block->getDamage());
						}
					}
				}
			}
		}
	}

	public function outbound(BatchPacket $packet, float $timestamp): void {
		$data = $this->data;
		$packet->decode();
		foreach ($packet->getPackets() as $buffer) {
			$pk = PacketPool::getPacket($buffer);
			if (in_array($pk->pid(), self::USED_PACKETS, true)) {
				$pk->decode();
				if ($pk instanceof LevelChunkPacket) {
					$chunk = NetworkChunkDeserializer::chunkNetworkDeserialize($pk->getExtraPayload(), $pk->getChunkX(), $pk->getChunkZ(), $pk->getSubChunkCount());
					if ($data->loggedIn) {
						$data->latencyManager->sandwich(function () use ($data, $chunk): void {
							$data->world->addChunk($chunk);
						}, $pk);
					} else {
						$data->world->addChunk($chunk);
					}
				} elseif ($pk instanceof NetworkChunkPublisherUpdatePacket) {
					$data->latencyManager->sandwich(function () use ($data, $pk): void {
						$chunkSendPosition = new Vector3($pk->x, $pk->y, $pk->z);
						$radius = $pk->radius >> 4;
						$chunkX = $chunkSendPosition->x >> 4;
						$chunkZ = $chunkSendPosition->z >> 4;
						$toRemove = [];
						foreach ($data->world->getAllChunks() as $hash => $chunk) {
							if (abs($chunk->getX() - $chunkX) >= $radius || abs($chunk->getZ() - $chunkZ) >= $radius) {
								$toRemove[] = $hash;
							}
						}
						// remove chunks after there are no more references to them
						foreach ($toRemove as $chunkHash) {
							$data->world->removeChunkByHash($chunkHash);
						}
					}, $pk);
				} elseif ($pk instanceof UpdateBlockPacket) {
					$blockId = RuntimeBlockMapping::fromStaticRuntimeId($pk->blockRuntimeId)[0];
					$position = new Vector3($pk->x, $pk->y, $pk->z);
					$realBlock = $data->world->getBlock($position);
					if ($realBlock->getId() !== $blockId) {
						$data->ghostBlockHandler->suspect(BlockFactory::get($blockId, 0, Position::fromObject($position)));
					}
				}
			}
		}
	}

	public function compensate(LagCompensationEvent $event): void {
		$data = $this->data;
		$packet = $event->packet;
		if ($packet instanceof SetActorMotionPacket) {
			$data->latencyManager->add($event->timestamp, function () use ($data, $packet): void {
				$data->serverSentMotion = $packet->motion;
				$data->ticksSinceMotion = 0;
			});
		} elseif ($packet instanceof UpdateBlockPacket) {
			$data->latencyManager->add($event->timestamp, function () use ($data, $packet): void {
				$real = RuntimeBlockMapping::fromStaticRuntimeId($packet->blockRuntimeId);
				$data->world->setBlock(new Vector3($packet->x, $packet->y, $packet->z), $real[0], 0);
			});
		}
	}

	public function destroy(): void {
		$this->data = null;
	}

}