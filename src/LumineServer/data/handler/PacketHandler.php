<?php

namespace LumineServer\data\handler;

use ethaniccc\Lumine\data\protocol\InputConstants;
use ethaniccc\Lumine\data\protocol\v428\PlayerAuthInputPacket;
use LumineServer\data\effect\EffectData;
use LumineServer\data\effect\ExtraEffectIds;
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
use pocketmine\entity\Effect;
use pocketmine\level\Level;
use pocketmine\level\Location;
use pocketmine\level\Position;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\convert\RuntimeBlockMapping;
use pocketmine\network\mcpe\protocol\BatchPacket;
use pocketmine\network\mcpe\protocol\DataPacket;
use pocketmine\network\mcpe\protocol\InventoryTransactionPacket;
use pocketmine\network\mcpe\protocol\LevelChunkPacket;
use pocketmine\network\mcpe\protocol\MobEffectPacket;
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
		ProtocolInfo::UPDATE_BLOCK_PACKET,
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

			if (InputConstants::hasFlag($packet, InputConstants::START_SNEAKING)) {
				$data->isSneaking = true;
			} elseif (InputConstants::hasFlag($packet, InputConstants::STOP_SNEAKING)) {
				$data->isSneaking = false;
			}

			if (InputConstants::hasFlag($packet, InputConstants::START_SPRINTING)) {
				$data->isSprinting = true;
			} elseif (InputConstants::hasFlag($packet, InputConstants::STOP_SPRINTING)) {
				$data->isSprinting = false;
			}

			if (InputConstants::hasFlag($packet, InputConstants::START_JUMPING)) {
				$data->isJumping = true;
			} else {
				$data->isJumping = false;
			}

			foreach ($data->effects as $effectData) {
				$effectData->ticks--;
				if ($effectData->ticks <= 0) {
					unset($data->effects[$effectData->effectId]);
				} else {
					switch ($effectData->effectId) {
						case Effect::JUMP_BOOST:
							$data->jumpVelocity = MovementConstants::DEFAULT_JUMP_MOTION + ($effectData->amplifier / 10);
							break;
						case ExtraEffectIds::SLOW_FALLING:
							$data->gravity = MovementConstants::SLOW_FALLING_GRAVITY;
							break;
					}
				}
			}

			$data->lastPos = clone $data->currentPos;
			unset($data->currentPos);
			$data->currentPos = Location::fromObject($packet->getPosition()->subtract(0, 1.62), null, $packet->getYaw(), $packet->getPitch());
			$data->lastMotion = clone $data->motion;
			unset($data->motion);
			$data->motion = $data->currentPos->subtract($data->lastPos)->asVector3();
			unset($data->boundingBox);
			$data->boundingBox = AABB::fromPosition($data->currentPos, $data->hitboxWidth, $data->hitboxHeight);
			$data->isInLoadedChunk = $data->world->isValidChunk(Level::chunkHash(floor($data->currentPos->x) >> 4, floor($data->currentPos->z) >> 4));

			$data->isInVoid = $data->currentPos->y <= -35;
			$data->ghostBlockHandler->updateSuspected();

			$data->moveForward = $packet->getMoveVecZ() * 0.98;
			$data->moveStrafe = $packet->getMoveVecX() * 0.98;
			$data->movementSpeed = 0.1;
			$speed = $data->effects[Effect::SPEED] ?? null;
			if ($speed !== null) {
				$data->movementSpeed += (0.02 * $speed->amplifier);
			}
			$slowness = $data->effects[Effect::SLOWNESS] ?? null;
			if ($slowness !== null) {
				$data->movementSpeed -= (0.015 * $slowness->amplifier); // TODO: Correctly account when both slowness and speed effects are applied
			}
			if ($data->isSprinting) {
				$data->movementSpeed *= 1.3;
			}
			$data->movementSpeed = max(0, $data->movementSpeed); // TODO: Account for de-sync that seems to happen in PMMP after removing slowness effect and having 0 movement speed

			$data->movementPredictionHandler->execute();

			unset($data->clientPrediction);
			$data->clientPrediction = $packet->getDelta();

			$data->isTeleporting = false;

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

		foreach ($data->detections as $detection) {
			if ($detection->enabled) {
				$detection->run($packet);
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
						$toRemove = $data->world->getAllChunks();
						$centerX = $pk->x >> 4;
						$centerZ = $pk->z >> 4;
						$radius = $pk->radius / 16;
						for ($x = 0; $x < $radius; ++$x) {
							for ($z = 0; $z <= $x; ++$z) {
								if (($x ** 2 + $z ** 2) > $radius ** 2) {
									break;
								}
								$index = Level::chunkHash($centerX + $x, $centerZ + $z);
								if ($data->world->isValidChunk($index)) {
									unset($toRemove[$index]);
								}
								$index = Level::chunkHash($centerX - $x - 1, $centerZ + $z);
								if ($data->world->isValidChunk($index)) {
									unset($toRemove[$index]);
								}
								$index = Level::chunkHash($centerX + $x, $centerZ - $z - 1);
								if ($data->world->isValidChunk($index)) {
									unset($toRemove[$index]);
								}
								$index = Level::chunkHash($centerX - $x - 1, $centerZ - $z - 1);
								if ($data->world->isValidChunk($index)) {
									unset($toRemove[$index]);
								}
								if ($x !== $z) {
									$index = Level::chunkHash($centerX + $z, $centerZ + $x);
									if ($data->world->isValidChunk($index)) {
										unset($toRemove[$index]);
									}
									$index = Level::chunkHash($centerX - $z - 1, $centerZ + $x);
									if ($data->world->isValidChunk($index)) {
										unset($toRemove[$index]);
									}
									$index = Level::chunkHash($centerX + $z, $centerZ - $x - 1);
									if ($data->world->isValidChunk($index)) {
										unset($toRemove[$index]);
									}
									$index = Level::chunkHash($centerX - $z - 1, $centerZ - $x - 1);
									if ($data->world->isValidChunk($index)) {
										unset($toRemove[$index]);
									}
								}
							}
						}
						foreach (array_keys($toRemove) as $hash) {
							$data->world->removeChunkByHash($hash);
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
		if ($packet instanceof SetActorMotionPacket && $packet->entityRuntimeId === $data->entityRuntimeId) {
			$data->latencyManager->add($event->timestamp, function () use ($data, $packet): void {
				$data->serverSentMotion = $packet->motion;
				$data->ticksSinceMotion = 0;
			});
		} elseif ($packet instanceof UpdateBlockPacket) {
			$data->latencyManager->add($event->timestamp, function () use ($data, $packet): void {
				$real = RuntimeBlockMapping::fromStaticRuntimeId($packet->blockRuntimeId);
				$data->world->setBlock(new Vector3($packet->x, $packet->y, $packet->z), $real[0], 0);
			});
		} elseif ($packet instanceof MobEffectPacket && $packet->entityRuntimeId === $data->entityRuntimeId) {
			$data->latencyManager->add($event->timestamp, function () use ($data, $packet): void {
				switch ($packet->eventId) {
					case MobEffectPacket::EVENT_ADD:
						$effectData = new EffectData();
						$effectData->effectId = $packet->effectId;
						$effectData->ticks = $packet->duration;
						$effectData->amplifier = $packet->amplifier + 1;
						$data->effects[$packet->effectId] = $effectData;
						break;
					case MobEffectPacket::EVENT_MODIFY:
						$effectData = $data->effects[$packet->effectId] ?? null;
						if ($effectData !== null) {
							$effectData->amplifier = $packet->amplifier + 1;
							$effectData->ticks = $packet->duration;
						}
						break;
					case MobEffectPacket::EVENT_REMOVE:
						unset($data->effects[$packet->effectId]);
						break;
				}
			});
		} elseif ($packet instanceof MovePlayerPacket && $packet->entityRuntimeId === $data->entityRuntimeId && $packet->mode === MovePlayerPacket::MODE_TELEPORT) {
			$data->latencyManager->add($event->timestamp, function () use ($data): void {
				$data->isTeleporting = true;
			});
		}
	}

	public function destroy(): void {
		$this->data = null;
	}

}