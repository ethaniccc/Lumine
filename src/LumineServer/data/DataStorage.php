<?php

namespace LumineServer\data;

use LumineServer\subprocess\LumineSubprocess;

final class DataStorage {

	private array $storage = [];

	public function add(string $identifier, string $socket): LumineSubprocess {
		if (isset($this->storage[$socket][$identifier])) {
			return $this->storage[$socket][$identifier];
		}
		$process = new LumineSubprocess($socket, $identifier);
		$this->storage[$socket][$identifier] = $process;
		$process->start();
		return $process;
	}

	public function get(string $identifier, string $socket): ?LumineSubprocess {
		return $this->storage[$socket][$identifier] ?? null;
	}

	public function getAll(): array {
		return $this->storage;
	}

	/**
	 * @param string $socket
	 * @return LumineSubprocess[]
	 */
	public function getFromSocket(string $socket): array {
		return $this->storage[$socket] ?? [];
	}

	public function remove(string $identifier, string $socket): void {
		if (isset($this->storage[$socket][$identifier])) {
			$this->storage[$socket][$identifier]->stop();
		}
		unset($this->storage[$socket][$identifier]);
	}

	public function reset(string $socket): void {
		if (!isset($this->storage[$socket])) {
			return;
		}
		$keys = array_keys($this->storage[$socket]);
		foreach ($keys as $key) {
			$this->storage[$socket][$key]->stop();
			unset($this->storage[$socket][$key]);
		}
		unset($this->storage[$socket]);
	}

	public function kill(): void {
		foreach ($this->storage as $queue) {
			foreach ($queue as $proc) {
				/** @var LumineSubprocess $proc */
				$proc->stop();
			}
		}
	}

}