<?php

namespace ethaniccc\Lumine\data;

use ethaniccc\Lumine\events\AddUserDataEvent;
use ethaniccc\Lumine\events\RemoveUserDataEvent;
use ethaniccc\Lumine\Lumine;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\player\Player;

final class DataCache {

	/** @var Player[] */
	public array $data = [];

	public function add(Player $player): void {
		$identifier = "{$player->getNetworkSession()->getIp()}:{$player->getNetworkSession()->getPort()}";
		unset($this->data[$identifier]);
		$this->data[$identifier] = &$player;
		Lumine::getInstance()->socketThread->send(new AddUserDataEvent([
			"identifier" => $identifier
		]));
	}

	public function get(NetworkSession $session): string {
		return "{$session->getIp()}:{$session->getPort()}";
	}

	public function identify(string $identifier): ?Player {
		return $this->data[$identifier] ?? null;
	}

	public function exists(NetworkSession $session): bool {
		return isset($this->data["{$session->getIp()}:{$session->getPort()}"]);
	}

	public function remove(?NetworkSession $session = null, string $identifier = null): void {
		if($session !== null) $identifier = "{$session->getIp()}:{$session->getPort()}";
		unset($this->data[$identifier]);
		Lumine::getInstance()->socketThread->send(new RemoveUserDataEvent([
			"identifier" => $identifier
		]));
	}

}