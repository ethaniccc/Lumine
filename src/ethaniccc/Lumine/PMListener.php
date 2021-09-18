<?php

namespace ethaniccc\Lumine;

use ethaniccc\Lumine\data\protocol\InputConstants;
use ethaniccc\Lumine\data\protocol\v428\PlayerAuthInputPacket;
use ethaniccc\Lumine\data\protocol\v428\PlayerBlockAction;
use ethaniccc\Lumine\events\LagCompensationEvent;
use ethaniccc\Lumine\events\PlayerSendPacketEvent;
use ethaniccc\Lumine\events\ServerSendPacketEvent;
use pocketmine\block\tile\Spawnable;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerPreLoginEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\network\mcpe\protocol\BlockActorDataPacket;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\MoveActorAbsolutePacket;
use pocketmine\network\mcpe\protocol\MovePlayerPacket;
use pocketmine\network\mcpe\protocol\NetworkStackLatencyPacket;
use pocketmine\network\mcpe\protocol\PlayerActionPacket;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\StartGamePacket;
use pocketmine\network\mcpe\protocol\types\PlayerMovementSettings;
use pocketmine\network\mcpe\protocol\types\PlayerMovementType;
use pocketmine\Server;
use function in_array;
use function microtime;
use function mt_rand;

final class PMListener implements Listener {

	public bool $isLumineSentPacket = false;
	/** @var array<string, ClientboundPacket[]> */
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
	 * @handleCancelled
	 */
	public function prelog(PlayerPreLoginEvent $event) : void {
		$name = $event->getPlayerInfo()->getUsername();
		$entry = Server::getInstance()->getNameBans()->getEntry($name);
		if ($entry !== null && $entry->getSource() === 'Lumine AC') {
			$event->setKickReason(PlayerPreLoginEvent::KICK_REASON_PLUGIN, $entry->getReason());
			//$event->setKickReason(PlayerPreLoginEvent::KICK_REASON_PLUGIN, str_replace(['{prefix}', '{code}', '{expires}'], [Lumine::getInstance()->settings->get('prefix'), $entry->getReason(), $entry->getExpires() !== null ? $entry->getExpires()->format("m/d/y h:i A T") : 'Never'], Lumine::getInstance()->settings->get('ban_message')));
		}
		if ($event->isCancelled()) {
			Lumine::getInstance()->cache->remove(null, "{$event->getIp()}:{$event->getPort()}");
		} else {
			Lumine::getInstance()->alertCooldowns[$name] = 3;
			Lumine::getInstance()->lastAlertTimes[$name] = 0;
		}
	}

	public function receive(DataPacketReceiveEvent $event): void {
		$session = $event->getOrigin();
		$player = $session->getPlayer();
		$packet = $event->getPacket();
		if (!$player->isConnected()) {
			Lumine::getInstance()->cache->remove($session);
			return;
		}
		if (!Lumine::getInstance()->cache->exists($session)) {
			Lumine::getInstance()->cache->add($player);
		}
		$identifier = Lumine::getInstance()->cache->get($session);
		if ($packet instanceof PlayerAuthInputPacket) {
			$pos = $player->getPosition();
			$event->cancel();
			if (InputConstants::hasFlag($packet, InputConstants::START_SPRINTING)) {
				$pk = new PlayerActionPacket();
				$pk->entityRuntimeId = $player->getId();
				$pk->action = PlayerActionPacket::ACTION_START_SPRINT;
				$pk->x = $pos->x;
				$pk->y = $pos->y;
				$pk->z = $pos->z;
				$pk->face = $player->getHorizontalFacing();
				$player->getNetworkSession()->getHandler()->handlePlayerAction($pk);
			}
			if (InputConstants::hasFlag($packet, InputConstants::STOP_SPRINTING)) {
				$pk = new PlayerActionPacket();
				$pk->entityRuntimeId = $player->getId();
				$pk->action = PlayerActionPacket::ACTION_STOP_SPRINT;
				$pk->x = $pos->x;
				$pk->y = $pos->y;
				$pk->z = $pos->z;
				$pk->face = $player->getHorizontalFacing();
				$player->getNetworkSession()->getHandler()->handlePlayerAction($pk);
			}
			if (InputConstants::hasFlag($packet, InputConstants::START_SNEAKING)) {
				$pk = new PlayerActionPacket();
				$pk->entityRuntimeId = $player->getId();
				$pk->action = PlayerActionPacket::ACTION_START_SNEAK;
				$pk->x = $pos->x;
				$pk->y = $pos->y;
				$pk->z = $pos->z;
				$pk->face = $player->getHorizontalFacing();
				$player->getNetworkSession()->getHandler()->handlePlayerAction($pk);
			}
			if (InputConstants::hasFlag($packet, InputConstants::STOP_SNEAKING)) {
				$pk = new PlayerActionPacket();
				$pk->entityRuntimeId = $player->getId();
				$pk->action = PlayerActionPacket::ACTION_STOP_SNEAK;
				$pk->x = $pos->x;
				$pk->y = $pos->y;
				$pk->z = $pos->z;
				$pk->face = $player->getHorizontalFacing();
				$player->getNetworkSession()->getHandler()->handlePlayerAction($pk);
			}
			if (InputConstants::hasFlag($packet, InputConstants::START_JUMPING)) {
				$pk = new PlayerActionPacket();
				$pk->entityRuntimeId = $player->getId();
				$pk->action = PlayerActionPacket::ACTION_JUMP;
				$pk->x = $pos->x;
				$pk->y = $pos->y;
				$pk->z = $pos->z;
				$pk->face = $player->getHorizontalFacing();
				$player->getNetworkSession()->getHandler()->handlePlayerAction($pk);
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
							$pk->face = $player->getHorizontalFacing();
							$player->getNetworkSession()->getHandler()->handlePlayerAction($pk);
							break;
						case PlayerBlockAction::CONTINUE:
						case PlayerBlockAction::CRACK_BREAK:
							$pk->action = PlayerActionPacket::ACTION_CRACK_BREAK;
							$pk->x = $action->blockPos->x;
							$pk->y = $action->blockPos->y;
							$pk->z = $action->blockPos->z;
							$pk->face = $player->getHorizontalFacing();
							$player->getNetworkSession()->getHandler()->handlePlayerAction($pk);
							break;
						case PlayerBlockAction::ABORT_BREAK:
							$pk->action = PlayerActionPacket::ACTION_ABORT_BREAK;
							$pk->x = $action->blockPos->x;
							$pk->y = $action->blockPos->y;
							$pk->z = $action->blockPos->z;
							$pk->face = $player->getHorizontalFacing();
							$player->getNetworkSession()->getHandler()->handlePlayerAction($pk);
							break;
						case PlayerBlockAction::STOP_BREAK:
							$pk->action = PlayerActionPacket::ACTION_STOP_BREAK;
							$position = $packet->getPosition()->subtract(0, 1.62, 0);
							$pk->x = $position->x;
							$pk->y = $position->y;
							$pk->z = $position->z;
							$pk->face = $player->getHorizontalFacing();
							$player->getNetworkSession()->getHandler()->handlePlayerAction($pk);
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
				// $currentBlock = $player->getWorld()->getBlock($packet->itemInteractionData->blockPos);
				$canInteract = $player->canInteract($packet->itemInteractionData->blockPos->add(0.5, 0.5, 0.5), $player->isCreative() ? 13 : 7);
				$useBreakOn = $player->getWorld()->useBreakOn($packet->itemInteractionData->blockPos, $item, $player, true);
				if ($canInteract and $useBreakOn) {
					if ($player->isSurvival()) {
						if (!$item->equalsExact($oldItem) and $oldItem->equalsExact($player->getInventory()->getItemInHand())) {
							$player->getInventory()->setItemInHand($item);
						}
					}
				} else {
					$player->getNetworkSession()->getInvManager()->syncAll();
					$player->getNetworkSession()->getInvManager()->syncSelectedHotbarSlot();
					$target = $player->getWorld()->getBlock($packet->itemInteractionData->blockPos);
					$blocks = $target->getAllSides();
					$blocks[] = $target;
					foreach($player->getWorld()->createBlockUpdatePackets($blocks) as $updatePacket){
						$player->getNetworkSession()->sendDataPacket($updatePacket);
					}
					foreach ($blocks as $b) {
						$tile = $player->getWorld()->getTile($b);
						if ($tile instanceof Spawnable) {
							$tilePos = $tile->getPos();
							$player->getNetworkSession()->sendDataPacket(BlockActorDataPacket::create($tilePos->x, $tilePos->y, $tilePos->z, $tile->getSerializedSpawnCompound()));
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
				$player->getNetworkSession()->getHandler()->handleMovePlayer($pk);
			}
		}
		Lumine::getInstance()->socketThread->send(new PlayerSendPacketEvent([
			"identifier" => $identifier,
			"packet" => $packet,
			"timestamp" => microtime(true)
		]));
	}

	public function send(DataPacketSendEvent $event): void {
		foreach($event->getTargets() as $session){
			$player = $session->getPlayer();
			foreach($event->getPackets() as $packet){
				if (!$player->isConnected()) {
					Lumine::getInstance()->cache->remove($session);
					return;
				}
				if (!Lumine::getInstance()->cache->exists($session)) {
					Lumine::getInstance()->cache->add($player);
				}
				$identifier = Lumine::getInstance()->cache->get($session);
				if ($this->isLumineSentPacket) {
					$this->isLumineSentPacket = false;
					return;
				}

				if ($packet instanceof StartGamePacket) {
					$packet->playerMovementSettings = new PlayerMovementSettings(
						PlayerMovementType::SERVER_AUTHORITATIVE_V2_REWIND,
						0,
						false // as if this even matters *cough*
					);
				} elseif(in_array($packet->pid(), self::USED_PACKETS, true)) {
					if (($packet instanceof MovePlayerPacket || $packet instanceof MoveActorAbsolutePacket) && $packet->entityRuntimeId !== $player->getId()) {
						if (!isset($this->locationCompensation[$player->getName()])) {
							$this->locationCompensation[$player->getName()] = [];
						}
						$this->locationCompensation[$player->getName()][] = $packet;
						/*if (count($event->getPackets()) === 1) {
							$event->cancel();
						} else {
							$packet->buffer = str_replace(zlib_encode(Binary::writeUnsignedVarInt(strlen($pk->buffer)) . $pk->buffer, ZLIB_ENCODING_RAW, $packet->getCompressionLevel()), "", $packet->buffer);
							$packet->payload = str_replace(Binary::writeUnsignedVarInt(strlen($pk->buffer)) . $pk->buffer, "", $packet->payload);
						}*/
						continue;
					}

					$latency = new NetworkStackLatencyPacket();
					$latency->timestamp = mt_rand(1, 10000000000) * 1000;
					$latency->needResponse = true;
					Lumine::getInstance()->socketThread->send(new LagCompensationEvent([
						"identifier" => $identifier,
						"timestamp" => $latency->timestamp,
						"packet" => $packet
					]));
					$this->isLumineSentPacket = true;
					$player->getNetworkSession()->sendDataPacket($latency);
					$player->getNetworkSession()->sendDataPacket($packet);
				}
				Lumine::getInstance()->socketThread->send(new ServerSendPacketEvent([
					"identifier" => $identifier,
					"packet" => $packet,
					"timestamp" => microtime(true)
				]));
			}
		}
	}

	public function quit(PlayerQuitEvent $event): void {
		Lumine::getInstance()->cache->remove($event->getPlayer()->getNetworkSession());
		unset(Lumine::getInstance()->alertCooldowns[$event->getPlayer()->getName()]);
		unset(Lumine::getInstance()->lastAlertTimes[$event->getPlayer()->getName()]);
	}

}