<?php

namespace LumineServer\data;

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

	public function get(string $identifier, string $socket): ?UserData {
		return $this->storage[$socket][$identifier] ?? null;
	}

	public function getAll(): array {
		return $this->storage;
	}

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