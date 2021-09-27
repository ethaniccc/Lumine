<?php

namespace ethaniccc\Lumine\tasks;

use ethaniccc\Lumine\events\AlertNotificationEvent;
use ethaniccc\Lumine\events\BanUserEvent;
use ethaniccc\Lumine\events\CommandResponseEvent;
use ethaniccc\Lumine\events\ConnectionErrorEvent;
use ethaniccc\Lumine\events\SendErrorEvent;
use ethaniccc\Lumine\events\ServerSendPacketEvent;
use ethaniccc\Lumine\events\SocketEvent;
use ethaniccc\Lumine\Lumine;
use ethaniccc\Lumine\packets\AlertNotificationPacket;
use ethaniccc\Lumine\packets\CommandResponsePacket;
use ethaniccc\Lumine\packets\HeartbeatPacket;
use ethaniccc\Lumine\packets\LagCompensationPacket;
use ethaniccc\Lumine\packets\Packet;
use ethaniccc\Lumine\packets\RequestPunishmentPacket;
use ethaniccc\Lumine\packets\ServerSendDataPacket;
use pocketmine\network\mcpe\protocol\BatchPacket;
use pocketmine\network\mcpe\protocol\NetworkStackLatencyPacket;
use pocketmine\network\mcpe\protocol\TextPacket;
use pocketmine\scheduler\Task;
use pocketmine\Server;

final class TickingTask extends Task {

	public function onRun(int $currentTick) {
		if ($currentTick % 20 === 0) {
			Lumine::getInstance()->socketThread->send(new HeartbeatPacket());
			foreach (Lumine::getInstance()->cache->data as $player) {
				if (!$player->isConnected()) {
					Lumine::getInstance()->cache->remove($player);
				}
			}
		}
		$keys = [];
		foreach (Lumine::getInstance()->listener->locationCompensation as $id => $bpk) {
			$player = Server::getInstance()->getPlayer($id);
			$keys[] = $id;
			if ($player !== null) {
				$pkK = new NetworkStackLatencyPacket();
				$pkK->timestamp = mt_rand(1, 100000) * 1000;
				$pkK->needResponse = true;
				$bpk->addPacket($pkK);
				$bpk->encode();
				Lumine::getInstance()->listener->isLumineSentPacket = true;
				$player->dataPacket($bpk);
				$spk = new LagCompensationPacket();
				$spk->identifier = Lumine::getInstance()->cache->get($player);
				$spk->timestamp = $pkK->timestamp;
				$spk->packetBuffer = $bpk->getBuffer();
				$spk->isBatch = true;
				Lumine::getInstance()->socketThread->send($spk);
			}
		}
		foreach ($keys as $key) {
			unset(Lumine::getInstance()->listener->locationCompensation[$key]);
		}
		foreach (Lumine::getInstance()->socketThread->receive() as $packet) {
			if ($packet instanceof ServerSendDataPacket && $packet->eventType === ServerSendDataPacket::SERVER_SEND_PACKET) {
				$player = Lumine::getInstance()->cache->identify($packet->identifier);
				if ($player !== null) {
					Lumine::getInstance()->listener->isLumineSentPacket = true;
					$batch = new BatchPacket($packet->packetBuffer);
					$batch->isEncoded = true;
					$player->dataPacket($batch);
				}
			} elseif ($packet instanceof AlertNotificationPacket) {
				foreach (Server::getInstance()->getOnlinePlayers() as $player) {
					if ($player->hasPermission("ac.notifications")) {
						if ($packet->type !== AlertNotificationPacket::PUNISHMENT) {
							$diff = microtime(true) - Lumine::getInstance()->lastAlertTimes[$player->getName()];
							$cooldown = Lumine::getInstance()->alertCooldowns[$player->getName()];
							if ($diff >= $cooldown) {
								Lumine::getInstance()->lastAlertTimes[$player->getName()] = microtime(true);
							} else {
								continue;
							}
;						}
						$player->sendMessage($packet->message);
					}
				}
			} elseif ($packet instanceof CommandResponsePacket) {
				if ($packet->target === "CONSOLE") {
					Lumine::getInstance()->getLogger()->info($packet->response);
				} else {
					$player = Lumine::getInstance()->cache->identify($packet->target);
					if ($player !== null) {
						$player->sendMessage($packet->response);
					}
				}
			} elseif ($packet instanceof RequestPunishmentPacket) {
				$player = Lumine::getInstance()->cache->identify($packet->identifier);
				if ($player !== null) {
					if ($packet->type === RequestPunishmentPacket::TYPE_KICK) {
						$player->kick($packet->message, false);
					} elseif ($packet->type === RequestPunishmentPacket::TYPE_BAN) {
						$player->kick($packet->message, false);
						$date = new \DateTime();
						$date->setTimestamp($packet->expiration);
						Server::getInstance()->getNameBans()->addBan($player->getName(), $packet->message, $date, "Lumine AC");
					} else {
						Lumine::getInstance()->getLogger()->error("Unknown punishment type {$packet->type}");
					}
				}
			}
		}
	}

}