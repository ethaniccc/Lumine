<?php

namespace ethaniccc\Lumine\tasks;

use ethaniccc\Lumine\events\AlertNotificationEvent;
use ethaniccc\Lumine\events\BanUserEvent;
use ethaniccc\Lumine\events\CommandResponseEvent;
use ethaniccc\Lumine\events\ConnectionErrorEvent;
use ethaniccc\Lumine\events\HeartbeatEvent;
use ethaniccc\Lumine\events\LagCompensationEvent;
use ethaniccc\Lumine\events\SendErrorEvent;
use ethaniccc\Lumine\events\ServerSendPacketEvent;
use ethaniccc\Lumine\events\SocketEvent;
use ethaniccc\Lumine\Lumine;
use pocketmine\network\mcpe\protocol\NetworkStackLatencyPacket;
use pocketmine\scheduler\Task;
use pocketmine\Server;

final class TickingTask extends Task {

	public function onRun(int $currentTick) {
		if ($currentTick % 20 === 0) {
			Lumine::getInstance()->socketThread->send(new HeartbeatEvent());
			foreach (Lumine::getInstance()->cache->data as $player) {
				if (!$player->isConnected()) {
					Lumine::getInstance()->cache->remove($player);
				}
			}
		}
		$keys = [];
		foreach (Lumine::getInstance()->listener->locationCompensation as $id => $packet) {
			$player = Server::getInstance()->getPlayer($id);
			$keys[] = $id;
			if ($player !== null) {
				$pk = new NetworkStackLatencyPacket();
				$pk->timestamp = mt_rand(1, 1000000000000) * 1000;
				$pk->needResponse = true;
				$packet->addPacket($pk);
				Lumine::getInstance()->listener->isLumineSentPacket = true;
				$player->dataPacket($packet);
				Lumine::getInstance()->socketThread->send(new LagCompensationEvent([
					"identifier" => Lumine::getInstance()->cache->get($player),
					"timestamp" => $pk->timestamp,
					"packet" => $packet
				]));
			}
		}
		foreach ($keys as $key) {
			unset(Lumine::getInstance()->listener->locationCompensation[$key]);
		}
		foreach (Lumine::getInstance()->socketThread->receive() as $event) {
			/** @var SocketEvent $event */
			if ($event instanceof ConnectionErrorEvent) {
				Lumine::getInstance()->getLogger()->error($event->message);
				Lumine::getInstance()->socketThread->quit();
				//Server::getInstance()->getPluginManager()->disablePlugin(Lumine::getInstance());
			} elseif ($event instanceof SendErrorEvent) {
				Lumine::getInstance()->getLogger()->error("Unable to send an event to the remote server.");
				Lumine::getInstance()->socketThread->quit();
				//Server::getInstance()->getPluginManager()->disablePlugin(Lumine::getInstance());
			} elseif ($event instanceof ServerSendPacketEvent) {
				$player = Lumine::getInstance()->cache->identify($event->identifier);
				if ($player !== null) {
					Lumine::getInstance()->listener->isLumineSentPacket = true;
					$player->dataPacket($event->packet);
				}
			} elseif ($event instanceof AlertNotificationEvent) {
				foreach (Server::getInstance()->getOnlinePlayers() as $player) {
					if ($player->hasPermission("ac.notifications")) {
						Lumine::getInstance()->listener->isLumineSentPacket = true;
						$player->dataPacket($event->alertPacket);
					}
				}
			} elseif ($event instanceof CommandResponseEvent) {
				if ($event->target === "CONSOLE") {
					Lumine::getInstance()->getLogger()->info($event->response);
				} else {
					$player = Lumine::getInstance()->cache->identify($event->target);
					if ($player !== null) {
						$player->sendMessage($event->response);
					}
				}
			} elseif ($event instanceof BanUserEvent) {
				Server::getInstance()->getNameBans()->addBan($event->username, $event->reason, $event->expiration, "Lumine AC");
			}
		}
	}

}