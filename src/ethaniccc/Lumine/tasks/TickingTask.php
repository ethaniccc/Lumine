<?php

namespace ethaniccc\Lumine\tasks;

use ethaniccc\Lumine\events\ConnectionErrorEvent;
use ethaniccc\Lumine\events\HeartbeatEvent;
use ethaniccc\Lumine\events\SendErrorEvent;
use ethaniccc\Lumine\events\SocketEvent;
use ethaniccc\Lumine\Lumine;
use pocketmine\scheduler\Task;
use pocketmine\Server;

final class TickingTask extends Task {

	public function onRun(int $currentTick) {
		if ($currentTick % 20 === 0) {
			Lumine::getInstance()->socketThread->send(new HeartbeatEvent());
		}
		foreach (Lumine::getInstance()->socketThread->receive() as $event) {
			/** @var SocketEvent $event */
			if ($event instanceof ConnectionErrorEvent) {
				Lumine::getInstance()->getLogger()->error($event->message);
				Server::getInstance()->getPluginManager()->disablePlugin(Lumine::getInstance());
				return;
			} elseif ($event instanceof SendErrorEvent) {
				Lumine::getInstance()->getLogger()->error("Unable to send an event to the remote server.");
				Server::getInstance()->getPluginManager()->disablePlugin(Lumine::getInstance());
				return;
			}
		}
	}

}