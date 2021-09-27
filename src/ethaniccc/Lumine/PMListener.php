<?php

namespace ethaniccc\Lumine;

use ethaniccc\Lumine\data\protocol\InputConstants;
use ethaniccc\Lumine\data\protocol\v428\PlayerAuthInputPacket;
use ethaniccc\Lumine\data\protocol\v428\PlayerBlockAction;
use ethaniccc\Lumine\packets\LagCompensationPacket;
use ethaniccc\Lumine\packets\ServerSendDataPacket;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerPreLoginEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\network\mcpe\protocol\BatchPacket;
use pocketmine\network\mcpe\protocol\MoveActorAbsolutePacket;
use pocketmine\network\mcpe\protocol\MovePlayerPacket;
use pocketmine\network\mcpe\protocol\NetworkStackLatencyPacket;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\network\mcpe\protocol\PlayerActionPacket;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\SetActorMotionPacket;
use pocketmine\network\mcpe\protocol\StartGamePacket;
use pocketmine\network\mcpe\protocol\types\PlayerMovementSettings;
use pocketmine\network\mcpe\protocol\types\PlayerMovementType;
use pocketmine\network\mcpe\protocol\UpdateBlockPacket;
use pocketmine\Server;
use pocketmine\tile\Spawnable;
use pocketmine\utils\Binary;

final class PMListener implements Listener {

	public bool $isLumineSentPacket = false;
	/** @var BatchPacket[] */
	public array $locationCompensation = [];

	private const USED_PACKETS = [
		ProtocolInfo::SET_ACTOR_MOTION_PACKET, ProtocolInfo::UPDATE_BLOCK_PACKET, ProtocolInfo::MOB_EFFECT_PACKET,
		ProtocolInfo::MOVE_PLAYER_PACKET, ProtocolInfo::SET_ACTOR_DATA_PACKET, ProtocolInfo::MOVE_ACTOR_ABSOLUTE_PACKET,
		ProtocolInfo::ADD_ACTOR_PACKET, ProtocolInfo::ADD_PLAYER_PACKET, ProtocolInfo::REMOVE_ACTOR_PACKET,
		ProtocolInfo::SET_PLAYER_GAME_TYPE_PACKET, ProtocolInfo::ADVENTURE_SETTINGS_PACKET, ProtocolInfo::UPDATE_ATTRIBUTES_PACKET,
		ProtocolInfo::RESPAWN_PACKET
	];

	/**
	 * @param PlayerPreLoginEvent $event
	 * @priority LOWEST
	 */
	public function prelog(PlayerPreLoginEvent $event): void {
		$entry = Server::getInstance()->getNameBans()->getEntry($event->getPlayer()->getName());
		if ($entry !== null && $entry->getSource() === 'Lumine AC') {
			$event->setCancelled();
			$event->setKickMessage($entry->getReason());
		}
		if ($event->isCancelled()) {
			Lumine::getInstance()->cache->remove($event->getPlayer());
		} else {
			Lumine::getInstance()->alertCooldowns[$event->getPlayer()->getName()] = 3;
			Lumine::getInstance()->lastAlertTimes[$event->getPlayer()->getName()] = 0;
		}
	}

	public function receive(DataPacketReceiveEvent $event): void {
		$player = $event->getPlayer();
		$packet = $event->getPacket();
		if (!$player->isConnected()) {
			Lumine::getInstance()->cache->remove($event->getPlayer());
			return;
		}
		if (!Lumine::getInstance()->cache->exists($player)) {
			Lumine::getInstance()->cache->add($player);
		}
		$identifier = Lumine::getInstance()->cache->get($player);
		if ($packet instanceof PlayerAuthInputPacket) {
			$event->setCancelled();
			if (InputConstants::hasFlag($packet, InputConstants::START_SPRINTING)) {
				$pk = new PlayerActionPacket();
				$pk->entityRuntimeId = $player->getId();
				$pk->action = PlayerActionPacket::ACTION_START_SPRINT;
				$pk->x = $player->x;
				$pk->y = $player->y;
				$pk->z = $player->z;
				$pk->face = $player->getDirection();
				$player->handlePlayerAction($pk);
			}
			if (InputConstants::hasFlag($packet, InputConstants::STOP_SPRINTING)) {
				$pk = new PlayerActionPacket();
				$pk->entityRuntimeId = $player->getId();
				$pk->action = PlayerActionPacket::ACTION_STOP_SPRINT;
				$pk->x = $player->x;
				$pk->y = $player->y;
				$pk->z = $player->z;
				$pk->face = $player->getDirection();
				$player->handlePlayerAction($pk);
			}
			if (InputConstants::hasFlag($packet, InputConstants::START_SNEAKING)) {
				$pk = new PlayerActionPacket();
				$pk->entityRuntimeId = $player->getId();
				$pk->action = PlayerActionPacket::ACTION_START_SNEAK;
				$pk->x = $player->x;
				$pk->y = $player->y;
				$pk->z = $player->z;
				$pk->face = $player->getDirection();
				$player->handlePlayerAction($pk);
			}
			if (InputConstants::hasFlag($packet, InputConstants::STOP_SNEAKING)) {
				$pk = new PlayerActionPacket();
				$pk->entityRuntimeId = $player->getId();
				$pk->action = PlayerActionPacket::ACTION_STOP_SNEAK;
				$pk->x = $player->x;
				$pk->y = $player->y;
				$pk->z = $player->z;
				$pk->face = $player->getDirection();
				$player->handlePlayerAction($pk);
			}
			if (InputConstants::hasFlag($packet, InputConstants::START_JUMPING)) {
				$pk = new PlayerActionPacket();
				$pk->entityRuntimeId = $player->getId();
				$pk->action = PlayerActionPacket::ACTION_JUMP;
				$pk->x = $player->x;
				$pk->y = $player->y;
				$pk->z = $player->z;
				$pk->face = $player->getDirection();
				$player->handlePlayerAction($pk);
			}
			if ($packet->blockActions !== null) {
				foreach ($packet->blockActions as $action) {
					$pk = new PlayerActionPacket();
					$pk->entityRuntimeId = $player->getId();
					switch ($action->actionType) {
						case PlayerBlockAction::START_BREAK:
							$pk->action = PlayerActionPacket::ACTION_START_BREAK;
							$pk->x = $action->blockPos->x;
							$pk->y = $action->blockPos->y;
							$pk->z = $action->blockPos->z;
							$pk->face = $player->getDirection();
							$player->handlePlayerAction($pk);
							break;
						case PlayerBlockAction::CONTINUE:
						case PlayerBlockAction::CRACK_BREAK:
							$pk->action = PlayerActionPacket::ACTION_CRACK_BREAK;
							$pk->x = $action->blockPos->x;
							$pk->y = $action->blockPos->y;
							$pk->z = $action->blockPos->z;
							$pk->face = $player->getDirection();
							$player->handlePlayerAction($pk);
							break;
						case PlayerBlockAction::ABORT_BREAK:
							$pk->action = PlayerActionPacket::ACTION_ABORT_BREAK;
							$pk->x = $action->blockPos->x;
							$pk->y = $action->blockPos->y;
							$pk->z = $action->blockPos->z;
							$pk->face = $player->getDirection();
							$player->handlePlayerAction($pk);
							break;
						case PlayerBlockAction::STOP_BREAK:
							$pk->action = PlayerActionPacket::ACTION_STOP_BREAK;
							$position = $packet->getPosition()->subtract(0, 1.62);
							$pk->x = $position->x;
							$pk->y = $position->y;
							$pk->z = $position->z;
							$pk->face = $player->getDirection();
							$player->handlePlayerAction($pk);
							break;
						case PlayerBlockAction::PREDICT_DESTROY:
							break;
					}
				}
			}

			if ($packet->itemInteractionData !== null) {
				// maybe if :microjang: didn't make the block breaking server-side option redundant, I wouldn't be doing this... you know who to blame !
				// hahaha... skidding PMMP go brrrt
				$player->doCloseInventory();
				$item = $player->getInventory()->getItemInHand();
				$oldItem = clone $item;
				$currentBlock = $player->getLevel()->getBlock($packet->itemInteractionData->blockPos);
				$canInteract = $player->canInteract($packet->itemInteractionData->blockPos->add(0.5, 0.5, 0.5), $player->isCreative() ? 13 : 7);
				$useBreakOn = $player->getLevel()->useBreakOn($packet->itemInteractionData->blockPos, $item, $player, true);
				if ($canInteract and $useBreakOn) {
					if ($player->isSurvival()) {
						if (!$item->equalsExact($oldItem) and $oldItem->equalsExact($player->getInventory()->getItemInHand())) {
							$player->getInventory()->setItemInHand($item);
							$player->getInventory()->sendHeldItem($player->getViewers());
						}
					}
				} else {
					$player->getInventory()->sendContents($player);
					$player->getInventory()->sendHeldItem($player);
					$target = $player->getLevel()->getBlock($packet->itemInteractionData->blockPos);
					$blocks = $target->getAllSides();
					$blocks[] = $target;
					$player->getLevel()->sendBlocks([$player], $blocks, UpdateBlockPacket::FLAG_ALL_PRIORITY);
					foreach ($blocks as $b) {
						$tile = $player->getLevel()->getTile($b);
						if ($tile instanceof Spawnable) {
							$tile->spawnTo($player);
						}
					}
				}
			}

			if ($player->isOnline()) {
				$pk = new MovePlayerPacket();
				$pk->entityRuntimeId = $player->getId();
				$pk->position = $packet->getPosition();
				$pk->yaw = $packet->getYaw();
				$pk->headYaw = $packet->getHeadYaw();
				$pk->pitch = $packet->getPitch();
				$pk->mode = MovePlayerPacket::MODE_NORMAL;
				$pk->onGround = true;
				$pk->tick = $packet->getTick();
				$player->handleMovePlayer($pk);
			}
		}
		if (!$packet instanceof BatchPacket) {
			$pk = new ServerSendDataPacket();
			$pk->eventType = ServerSendDataPacket::PLAYER_SEND_PACKET;
			$pk->packetBuffer = $packet->getBuffer();
			$pk->identifier = $identifier;
			$pk->timestamp = microtime(true);
			Lumine::getInstance()->socketThread->send($pk);
		}
	}

	public function send(DataPacketSendEvent $event): void {
		$packet = $event->getPacket();
		$player = $event->getPlayer();
		if (!$player->isConnected()) {
			Lumine::getInstance()->cache->remove($event->getPlayer());
			return;
		}
		if (!Lumine::getInstance()->cache->exists($player)) {
			Lumine::getInstance()->cache->add($player);
		}
		$identifier = Lumine::getInstance()->cache->get($player);
		if ($packet instanceof StartGamePacket) {
			$packet->playerMovementSettings = new PlayerMovementSettings(
				PlayerMovementType::SERVER_AUTHORITATIVE_V2_REWIND,
				0,
				false // as if this even matters *cough*
			);
		} elseif ($packet instanceof BatchPacket) {
			if ($this->isLumineSentPacket) {
				$this->isLumineSentPacket = false;
				return;
			}
			if ($packet->getCompressionLevel() > 0) {
				$packet->decode();
			}
			$gen = $this->getAllInBatch($packet);
			foreach ($gen as $buffer) {
				$pk = PacketPool::getPacket($buffer);
				if (in_array($pk->pid(), self::USED_PACKETS, true)) {
					$pk->decode();
					if (($pk instanceof MovePlayerPacket || $pk instanceof MoveActorAbsolutePacket)) {
						if ($pk->entityRuntimeId !== $player->getId()) {
							continue;
						}
						if (!isset($this->locationCompensation[$player->getName()])) {
							$this->locationCompensation[$player->getName()] = new BatchPacket();
							$this->locationCompensation[$player->getName()]->setCompressionLevel(7);
						}
						$this->locationCompensation[$player->getName()]->addPacket($pk);
						if (count($gen) === 1) {
							$event->setCancelled();
						} else {
							$packet->buffer = str_replace(zlib_encode(Binary::writeUnsignedVarInt(strlen($pk->buffer)) . $pk->buffer, ZLIB_ENCODING_RAW, $packet->getCompressionLevel()), "", $packet->buffer);
							$packet->payload = str_replace(Binary::writeUnsignedVarInt(strlen($pk->buffer)) . $pk->buffer, "", $packet->payload);
						}
						continue;
					} elseif ($pk instanceof SetActorMotionPacket && $pk->entityRuntimeId !== $player->getId()) {
						continue;
					}
					$compensation = new BatchPacket();
					$compensation->setCompressionLevel(0);
					$var1 = new NetworkStackLatencyPacket();
					$var1->timestamp = mt_rand(1, 100000) * 1000;
					$var1->needResponse = true;
					$compensation->addPacket($var1);
					$compensation->addPacket($pk);
					$cpk = new LagCompensationPacket();
					$cpk->identifier = $identifier;
					$cpk->timestamp = $var1->timestamp;
					$cpk->packetBuffer = $pk->getBuffer();
					Lumine::getInstance()->socketThread->send($cpk);
					$this->isLumineSentPacket = true;
					$player->dataPacket($compensation);
				}
			}
			$sspk = new ServerSendDataPacket();
			$sspk->identifier = $identifier;
			$sspk->eventType = ServerSendDataPacket::SERVER_SEND_PACKET;
			$sspk->packetBuffer = $packet->getBuffer();
			$sspk->timestamp = microtime(true);
			Lumine::getInstance()->socketThread->send($sspk);
		}
	}

	public function quit(PlayerQuitEvent $event): void {
		Lumine::getInstance()->cache->remove($event->getPlayer());
		unset(Lumine::getInstance()->alertCooldowns[$event->getPlayer()->getName()]);
		unset(Lumine::getInstance()->lastAlertTimes[$event->getPlayer()->getName()]);
	}

	private function getAllInBatch(BatchPacket $packet): array {
		$arr = [];
		foreach ($packet->getPackets() as $buff) {
			$arr[] = $buff;
		}
		return $arr;
	}

}