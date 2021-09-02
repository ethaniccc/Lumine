<?php

namespace ethaniccc\Lumine\data;

use ethaniccc\Lumine\events\AddUserDataEvent;
use ethaniccc\Lumine\events\RemoveUserDataEvent;
use ethaniccc\Lumine\Lumine;
use pocketmine\Player;

final class DataCache {

	/** @var Player[] */
	public array $data = [];

	public function add(Player $player): void {
		$identifier = "{$player->getAddress()}:{$player->getPort()}";
		unset($this->data[$identifier]);
		$this->data[$identifier] = &$player;
		Lumine::getInstance()->socketThread->send(new AddUserDataEvent([
			"identifier" => $identifier
		]));
	}

	public function get(Player $player): string {
		return "{$player->getAddress()}:{$player->getPort()}";
	}

	public function identify(string $identifier): ?Player {
		return $this->data[$identifier] ?? null;
	}

	public function exists(Player $player): bool {
		return isset($this->data["{$player->getAddress()}:{$player->getPort()}"]);
	}

	public function remove(Player $player): void {
		$identifier = "{$player->getAddress()}:{$player->getPort()}";
		unset($this->data[$identifier]);
		Lumine::getInstance()->socketThread->send(new RemoveUserDataEvent([
			"identifier" => $identifier
		]));
	}

}