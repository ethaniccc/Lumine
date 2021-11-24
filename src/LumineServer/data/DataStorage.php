<?php

namespace LumineServer\data;

use LumineServer\Server;

final class DataStorage {

	private array $storage = [];

	public function add(string $identifier, string $socket): UserData {
		if (isset($this->storage[$socket][$identifier])) {
			return $this->storage[$socket][$identifier];
		}
		$data = new UserData($identifier, $socket);
		$this->storage[$socket][$identifier] = $data;
		return $data;
	}

	/**
	 * @param string $username
	 * @return UserData[]
	 */
	public function find(string $username, string $host = null): array {
		/** @var string|null $found */
		$found = null;
		$name = strtolower($username);
		$delta = PHP_INT_MAX;
		foreach (Server::getInstance()->dataStorage->getAll() as $queue) {
			foreach ($queue as $otherData) {
				/** @var UserData $otherData */
				$username = $otherData->authData->username;
				if(stripos($username, $name) === 0){
					$curDelta = strlen($username) - strlen($name);
					if($curDelta < $delta){
						$found = $username;
						$delta = $curDelta;
					}
					if($curDelta === 0){
						break;
					}
				}
			}
		}
		if ($found !== null) {
			if ($host === null) {
				$list = [];
				foreach ($this->getAll() as $queue) {
					/** @var UserData[] $queue */
					foreach ($queue as $data) {
						if (strtolower($data->authData->username) === strtolower($username)) {
							$list[] = $data;
						}
					}
				}
				return $list;
			} else {
				foreach ($this->getFromSocket($host) as $data) {
					if (strtolower($data->authData->username) === strtolower($username)) {
						return [$data];
					}
				}
			}
		}
		return [];
	}

	public function get(string $identifier, string $socket): ?UserData {
		return $this->storage[$socket][$identifier] ?? null;
	}

	public function getAll(): array {
		return $this->storage;
	}

	/**
	 * @param string $socket
	 * @return UserData[]
	 */
	public function getFromSocket(string $socket): array {
		return $this->storage[$socket] ?? [];
	}

	public function remove(string $identifier, string $socket): void {
		if (isset($this->storage[$socket][$identifier])) {
			$this->storage[$socket][$identifier]->destroy();
		}
		unset($this->storage[$socket][$identifier]);
	}

	public function reset(string $socket): void {
		if (!isset($this->storage[$socket])) {
			return;
		}
		$keys = array_keys($this->storage[$socket]);
		foreach ($keys as $key) {
			$this->storage[$socket][$key]->destroy();
			unset($this->storage[$socket][$key]);
		}
		unset($this->storage[$socket]);
	}

}