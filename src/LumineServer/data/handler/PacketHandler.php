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
use LumineServer\Server;
use LumineServer\utils\AABB;
use LumineServer\utils\LevelUtils;
use LumineServer\utils\PacketUtils;
use pocketmine\block\BlockBreakInfo;
use pocketmine\block\BlockFactory;
use pocketmine\block\Cobweb;
use pocketmine\block\Liquid;
use pocketmine\block\UnknownBlock;
use pocketmine\data\bedrock\EffectIds;
use pocketmine\entity\Attribute;
use pocketmine\entity\Location;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\convert\RuntimeBlockMapping;
use pocketmine\network\mcpe\protocol\AddActorPacket;
use pocketmine\network\mcpe\protocol\AddPlayerPacket;
use pocketmine\network\mcpe\protocol\AdventureSettingsPacket;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\InventoryTransactionPacket;
use pocketmine\network\mcpe\protocol\LevelChunkPacket;
use pocketmine\network\mcpe\protocol\LevelSoundEventPacket;
use pocketmine\network\mcpe\protocol\LoginPacket;
use pocketmine\network\mcpe\protocol\MobEffectPacket;
use pocketmine\network\mcpe\protocol\MoveActorAbsolutePacket;
use pocketmine\network\mcpe\protocol\MovePlayerPacket;
use pocketmine\network\mcpe\protocol\NetworkChunkPublisherUpdatePacket;
use pocketmine\network\mcpe\protocol\NetworkStackLatencyPacket;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\RemoveActorPacket;
use pocketmine\network\mcpe\protocol\RespawnPacket;
use pocketmine\network\mcpe\protocol\ServerboundPacket;
use pocketmine\network\mcpe\protocol\SetActorDataPacket;
use pocketmine\network\mcpe\protocol\SetActorMotionPacket;
use pocketmine\network\mcpe\protocol\SetLocalPlayerAsInitializedPacket;
use pocketmine\network\mcpe\protocol\SetPlayerGameTypePacket;
use pocketmine\network\mcpe\protocol\StartGamePacket;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\GameMode;
use pocketmine\network\mcpe\protocol\types\inventory\UseItemOnEntityTransactionData;
use pocketmine\network\mcpe\protocol\types\inventory\UseItemTransactionData;
use pocketmine\network\mcpe\protocol\UpdateAttributesPacket;
use pocketmine\network\mcpe\protocol\UpdateBlockPacket;
use pocketmine\world\World;
use function array_diff;
use function array_keys;
use function count;
use function floor;
use function in_array;
use function max;

final class PacketHandler {

    public ?UserData $data;

    private const USED_PACKETS = [
        ProtocolInfo::LEVEL_CHUNK_PACKET, ProtocolInfo::NETWORK_CHUNK_PUBLISHER_UPDATE_PACKET, ProtocolInfo::NETWORK_STACK_LATENCY_PACKET,
        ProtocolInfo::UPDATE_BLOCK_PACKET, ProtocolInfo::START_GAME_PACKET
    ];

    public function __construct(UserData $data) {
        $this->data = $data;
    }

    public function inbound(ClientboundPacket $packet, float $timestamp): void {
        $data = $this->data;
        if ($data->isClosed) {
            return;
        }
        $data->clickData->isClicking = false;
        if ($packet instanceof PlayerAuthInputPacket) {
            if ($packet->itemInteractionData !== null) {
                $data->world->setBlock($packet->itemInteractionData->blockPos, 0);
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

            $data->jumpVelocity = MovementConstants::DEFAULT_JUMP_MOTION;
            $data->gravity = MovementConstants::NORMAL_GRAVITY;

            foreach ($data->effects as $effectData) {
                $effectData->ticks--;
                if ($effectData->ticks <= 0) {
                    unset($data->effects[$effectData->effectId]);
                } else {
                    switch ($effectData->effectId) {
                        case EffectIds::JUMP_BOOST:
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
            $data->currentPos = Location::fromObject($packet->getPosition()->subtract(0, 1.62, 0), null, $packet->getYaw(), $packet->getPitch());
            $data->lastMotion = clone $data->motion;
            unset($data->motion);
            $data->motion = $data->currentPos->subtractVector($data->lastPos)->asVector3();
            unset($data->boundingBox);
            $data->boundingBox = AABB::fromPosition($data->currentPos, $data->hitboxWidth, $data->hitboxHeight);
            $data->isInLoadedChunk = $data->world->isValidChunk(World::chunkHash(floor($data->currentPos->x) >> 4, floor($data->currentPos->z) >> 4));

            $data->isInVoid = $data->currentPos->y <= -35;
            $data->ghostBlockHandler->updateSuspected();

            $data->moveForward = $packet->getMoveVecZ() * 0.98;
            $data->moveStrafe = $packet->getMoveVecX() * 0.98;
            $data->movementSpeed = 0.1;
            $speed = $data->effects[EffectIds::SPEED] ?? null;
            if ($speed !== null) {
                $data->movementSpeed += (0.02 * $speed->amplifier);
            }
            $slowness = $data->effects[EffectIds::SLOWNESS] ?? null;
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

            $liquids = $cobweb = $climbable = 0;
            $surroundingBlocks = LevelUtils::checkBlocksInAABB($data->boundingBox->expandedCopy(0.2, 0.2, 0.2), $data->world, LevelUtils::SEARCH_ALL);
            foreach ($surroundingBlocks as $block) {
                if ($block instanceof Liquid) {
                    $liquids++;
                } elseif ($block instanceof Cobweb) {
                    $cobweb++;
                } elseif ($block->canClimb()) {
                    $climbable++;
                }
            }

            $liquids === 0 ?
                $data->ticksSinceInLiquid++ :
                $data->ticksSinceInLiquid = 0;

            $cobweb === 0 ?
                $data->ticksSinceInCobweb++ :
                $data->ticksSinceInCobweb = 0;

            $climbable === 0 ?
                $data->ticksSinceInClimbable++ :
                $data->ticksSinceInCobweb = 0;

            $data->ticksSinceMotion++;
            if ($data->onGround) {
                $data->ticksOnGround++;
                $data->ticksOffGround = 0;
            } else {
                $data->ticksOffGround++;
                $data->ticksOnGround = 0;
            }

            $data->isAlive ?
                $data->ticksSinceSpawn++ :
                $data->ticksSinceSpawn = 0;

            $data->locationMap->tick();
            $data->currentTick++;

            if ($data->lastACKTick === -1 && $data->loggedIn) {
                $data->lastACKTick = $data->currentTick;
            } elseif ($data->lastACKTick !== -1) {
                if ($data->waitingACKCount > 0) {
                    $tickDiff = $data->currentTick - $data->lastACKTick;
                    if ($tickDiff > 300 && $data->waitingACKCount >= 40) {
                        $data->kick(Server::getInstance()->settings->get(
                            "timeout_message",
                            "NetworkStackLatency timeout (d=$tickDiff c=$data->waitingACKCount)\nPlease contact a staff member if this issue persists"
                        ));
                    }
                } else {
                    $data->lastACKTick = $data->currentTick;
                }
            }
        } elseif ($packet instanceof SetLocalPlayerAsInitializedPacket) {
            $data->loggedIn = true;
            $data->isAlive = true;
            $data->entityRuntimeId = $packet->entityRuntimeId;
        } elseif ($packet instanceof NetworkStackLatencyPacket) {
            $data->latencyManager->execute($packet->timestamp, $timestamp);
        } elseif ($packet instanceof InventoryTransactionPacket) {
            $trData = $packet->trData;
            if ($trData instanceof UseItemTransactionData) {
                if ($trData->getActionType() === UseItemTransactionData::ACTION_CLICK_BLOCK) {
                    $clickedBlockPos = $trData->getBlockPos();
                    $newBlockPos = $clickedBlockPos->getSide($trData->getFace());
                    $blockToReplace = $data->world->getBlock($newBlockPos);
                    $btrPos = $blockToReplace->getPosition();
                    $stack = $trData->getItemInHand()->getItemStack();
                    if ($stack->getId() < 0) {
                        $block = new UnknownBlock($stack->getId(), new BlockBreakInfo(0));
                        $block->getPosition()->x = $btrPos->x;
                        $block->getPosition()->y = $btrPos->y;
                        $block->getPosition()->z = $btrPos->z;
                        if ($block->collidesWithBB($data->boundingBox)) return;
                    } else {
                        $block = BlockFactory::getInstance()->fromFullBlock($stack->getBlockRuntimeId());
                    }
                    $block->getPosition()->x = $btrPos->x;
                    $block->getPosition()->y = $btrPos->y;
                    $block->getPosition()->z = $btrPos->z;
                    // placement before ticking?
                    if ($blockToReplace->canBeReplaced() && ($block instanceof UnknownBlock || $block->canBePlaced()) && !$block->collidesWithBB($data->boundingBox)) {
                        if (($block->canBePlaced() || $block instanceof UnknownBlock)) {
                            $data->world->setBlock($blockToReplace->getPosition(), $block->getFullId());
                        }
                    }
                }
            } elseif ($trData instanceof UseItemOnEntityTransactionData) {
                $data->clickData->add($data->currentTick);
                $data->attackPos = $trData->getPlayerPos();
            }
        } elseif ($packet instanceof LoginPacket) {
            $clientData = PacketUtils::parseClientData($packet->clientDataJwt);
            $data->authData = PacketUtils::fetchAuthData($packet->chainDataJwt, $clientData);
            $data->playerOS = $clientData->DeviceOS;
        } elseif ($packet instanceof AdventureSettingsPacket) {
            $data->isFlying = $packet->getFlag(AdventureSettingsPacket::FLYING);
        } elseif ($packet instanceof LevelSoundEventPacket && $packet->sound === LevelSoundEventPacket::SOUND_ATTACK_NODAMAGE) {
            $data->clickData->add($data->currentTick);
        } elseif ($packet instanceof RespawnPacket && $packet->entityRuntimeId === $data->entityRuntimeId && $packet->respawnState === RespawnPacket::CLIENT_READY_TO_SPAWN) {
            $data->isAlive = true;
        }

        foreach ($data->detections as $detection) {
            if ($detection->enabled) {
                $detection->run($packet, $timestamp);
            }
        }
    }

    /**
     * @param ServerboundPacket[] $packets
     * @param float $timestamp
     */
    public function outbound(array $packets, float $timestamp): void {
        $data = $this->data;
        if ($data->isClosed) return;
        foreach ($packets as $pk) {
            if (in_array($pk->pid(), self::USED_PACKETS, true)) {
                if ($pk instanceof NetworkChunkPublisherUpdatePacket) {
                    $data->latencyManager->sandwich(function () use ($data, $pk): void {
                        $toRemove = array_keys($data->world->getAllChunks());
                        $removeList = [];
                        $centerX = $pk->x >> 4;
                        $centerZ = $pk->z >> 4;
                        $radius = $pk->radius / 16;
                        for ($x = 0; $x < $radius; ++$x) {
                            for ($z = 0; $z <= $x; ++$z) {
                                if (($x ** 2 + $z ** 2) > $radius ** 2) {
                                    break;
                                }
                                $index = World::chunkHash($centerX + $x, $centerZ + $z);
                                if ($data->world->isValidChunk($index)) {
                                    $removeList[] = $index;
                                }
                                $index = World::chunkHash($centerX - $x - 1, $centerZ + $z);
                                if ($data->world->isValidChunk($index)) {
                                    $removeList[] = $index;
                                }
                                $index = World::chunkHash($centerX + $x, $centerZ - $z - 1);
                                if ($data->world->isValidChunk($index)) {
                                    $removeList[] = $index;
                                }
                                $index = World::chunkHash($centerX - $x - 1, $centerZ - $z - 1);
                                if ($data->world->isValidChunk($index)) {
                                    $removeList[] = $index;
                                }
                                if ($x !== $z) {
                                    $index = World::chunkHash($centerX + $z, $centerZ + $x);
                                    if ($data->world->isValidChunk($index)) {
                                        $removeList[] = $index;
                                    }
                                    $index = World::chunkHash($centerX - $z - 1, $centerZ + $x);
                                    if ($data->world->isValidChunk($index)) {
                                        $removeList[] = $index;
                                    }
                                    $index = World::chunkHash($centerX + $z, $centerZ - $x - 1);
                                    if ($data->world->isValidChunk($index)) {
                                        $removeList[] = $index;
                                    }
                                    $index = World::chunkHash($centerX - $z - 1, $centerZ - $x - 1);
                                    if ($data->world->isValidChunk($index)) {
                                        $removeList[] = $index;
                                    }
                                }
                            }
                        }
                        $toRemove = array_diff($toRemove, $removeList);
                        foreach ($toRemove as $hash) {
                            $data->world->removeChunkByHash($hash);
                        }
                    }, $pk);
                } elseif ($pk instanceof UpdateBlockPacket) {
                    $blockId = RuntimeBlockMapping::getInstance()->fromRuntimeId($pk->blockRuntimeId);
                    $position = new Vector3($pk->x, $pk->y, $pk->z);
                    $realBlock = $data->world->getBlock($position);
                    if ($realBlock->getFullId() !== $blockId) {
                        $block = BlockFactory::getInstance()->fromFullBlock($blockId);
                        $block->getPosition()->x = $position->x;
                        $block->getPosition()->y = $position->y;
                        $block->getPosition()->z = $position->z;
                        $data->ghostBlockHandler->suspect($block);
                    }
                } elseif ($pk instanceof StartGamePacket) {
                    $data->entityRuntimeId = $pk->entityRuntimeId;
                    $data->isSurvival = ($pk->playerGamemode === GameMode::SURVIVAL || $pk->playerGamemode !== GameMode::CREATIVE);
                }
            }
        }
    }

    public function compensate(LagCompensationEvent $event): void {
        $data = $this->data;
        if ($data->isClosed) return;
        $timestamp = $event->timestamp;
        foreach($event->packets as $packet){
            if ($packet instanceof SetActorMotionPacket && $packet->entityRuntimeId === $data->entityRuntimeId) {
                $data->latencyManager->add($timestamp, function () use ($data, $packet): void {
                    $data->serverSentMotion = $packet->motion;
                    $data->ticksSinceMotion = 0;
                });
            } elseif ($packet instanceof UpdateBlockPacket) {
                $data->latencyManager->add($timestamp, function () use ($data, $packet): void {
                    $data->world->setBlock(new Vector3($packet->x, $packet->y, $packet->z), RuntimeBlockMapping::getInstance()->fromRuntimeId($packet->blockRuntimeId));
                });
            } elseif ($packet instanceof MobEffectPacket && $packet->entityRuntimeId === $data->entityRuntimeId) {
                $data->latencyManager->add($timestamp, function () use ($data, $packet): void {
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
                $data->latencyManager->add($timestamp, function () use ($data): void {
                    $data->isTeleporting = true;
                });
            } elseif ($packet instanceof SetActorDataPacket) {
                $isFlagTrueInPropertyMess = function (int $targetFlag, int $data): bool {
                    $flagID = $targetFlag % 64;
                    return ($data & (1 << ($flagID))) > 0;
                };
                if ($packet->entityRuntimeId === $data->entityRuntimeId) {
                    if ($data->loggedIn) {
                        $data->latencyManager->add($timestamp, function () use ($data, $packet, $isFlagTrueInPropertyMess): void {
                            $hitboxWidth = isset($packet->metadata[EntityMetadataProperties::BOUNDING_BOX_WIDTH]) ? ($packet->metadata[EntityMetadataProperties::BOUNDING_BOX_WIDTH][1] / 2) : $data->hitboxWidth;
                            $hitboxHeight = $packet->metadata[EntityMetadataProperties::BOUNDING_BOX_HEIGHT][1] ?? $data->hitboxHeight;
                            $data->hitboxWidth = $hitboxWidth;
                            $data->hitboxHeight = $hitboxHeight;
                            if (isset($packet->metadata[0])) {
                                $data->isImmobile = $isFlagTrueInPropertyMess(EntityMetadataFlags::IMMOBILE, $packet->metadata[0][1]);
                            }
                        });
                    } else {
                        $hitboxWidth = isset($packet->metadata[EntityMetadataProperties::BOUNDING_BOX_WIDTH]) ? ($packet->metadata[EntityMetadataProperties::BOUNDING_BOX_WIDTH][1] / 2) : $data->hitboxWidth;
                        $hitboxHeight = $packet->metadata[EntityMetadataProperties::BOUNDING_BOX_HEIGHT][1] ?? $data->hitboxHeight;
                        $data->hitboxWidth = $hitboxWidth;
                        $data->hitboxHeight = $hitboxHeight;
                        if (isset($packet->metadata[0])) {
                            $data->isImmobile = $isFlagTrueInPropertyMess(EntityMetadataFlags::IMMOBILE, $packet->metadata[0][1]);
                        }
                    }
                } else {
                    if ($data->loggedIn) {
                        $target = $data->locationMap->get($packet->entityRuntimeId);
                        if ($target !== null) {
                            $target->hitboxWidth = isset($packet->metadata[EntityMetadataProperties::BOUNDING_BOX_WIDTH]) ? ($packet->metadata[EntityMetadataProperties::BOUNDING_BOX_WIDTH][1] / 2) : $target->hitboxWidth;
                            $target->hitboxHeight = $packet->metadata[EntityMetadataProperties::BOUNDING_BOX_HEIGHT][1] ?? $data->hitboxHeight;
                        }
                    }
                }
            } elseif ($packet instanceof AddActorPacket || $packet instanceof AddPlayerPacket) {
                $data->latencyManager->add($timestamp, function () use ($data, $packet): void {
                    $data->locationMap->add($packet->entityRuntimeId, $packet->position, $packet->motion, $packet instanceof AddPlayerPacket);
                });
            } elseif ($packet instanceof RemoveActorPacket) {
                $data->latencyManager->add($timestamp, function () use ($data, $packet): void {
                    $data->locationMap->remove($packet->entityUniqueId);
                });
            } elseif ($packet instanceof SetPlayerGameTypePacket) {
                $data->latencyManager->add($timestamp, function () use ($data, $packet): void {
                    $data->isSurvival = ($packet->gamemode === GameMode::SURVIVAL || $packet->gamemode !== GameMode::CREATIVE);
                });
            } elseif ($packet instanceof AdventureSettingsPacket) {
                $data->latencyManager->add($timestamp, function () use ($data, $packet): void {
                    // TODO?
                });
            } elseif ($packet instanceof UpdateAttributesPacket && $packet->entityRuntimeId === $data->entityRuntimeId) {
                $data->latencyManager->add($timestamp, function () use ($data, $packet): void {
                    foreach ($packet->entries as $attribute) {
                        if ($attribute->getId() === Attribute::HEALTH && $attribute->getValue() <= 0) {
                            $data->isAlive = false;
                        }
                    }
                });
            } elseif (count($event->packets) > 1) {
                // The only batch packet that will ever be here is a packet full of entity locations.
                $data->latencyManager->add($timestamp, function () use ($data, $packet): void {
                    if ($packet instanceof MoveActorAbsolutePacket || $packet instanceof MovePlayerPacket) {
                        $target = $data->locationMap->get($packet->entityRuntimeId);
                        if ($target !== null) {
                            if ($packet instanceof MoveActorAbsolutePacket) {
                                $teleport = $packet->flags >= 2;
                            } else {
                                $teleport = $packet->mode = MovePlayerPacket::MODE_TELEPORT;
                            }
                            $target->set($packet->position->subtract(0, 1.62, 0), $teleport);
                        }
                    }
                });
            }
        }
    }

    public function destroy(): void {
        $this->data = null;
    }

}