<?php

namespace LumineServer\data;

final class DataStorage {

	/** @var UserData[] */
	private array $storage = [];

	public function add(string $identifier): UserData {
		if (isset($this->storage[$identifier])) {
			return $this->storage[$identifier];
		}
		$data = new UserData($identifier);
		$this->storage[$identifier] = $data;
		return $data;
	}

	public function get(string $identifier): ?UserData {
		return $this->storage[$identifier] ?? null;
	}

	public function getAll(): array {
		return $this->storage;
	}

	public function remove(string $identifier): void {
		if (isset($this->storage[$identifier])) {
			$this->storage[$identifier]->destroy();
		}
		unset($this->storage[$identifier]);
	}

	public function reset(): void {
		$keys = array_keys($this->storage);
		foreach ($keys as $key) {
			$this->storage[$key]->destroy();
			unset($this->storage[$key]);
		}
	}

}