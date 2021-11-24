<?php

namespace LumineServer\data\debug;

use LumineServer\data\UserData;
use LumineServer\Server;

final class DebugChannel {

	/** @var UserData[] */
	public array $subscribers = [];

	public function __construct(
		public string $channelName
	) {}

	public function getName(): string {
		return $this->channelName;
	}

	public function subscribe(UserData $data): void {
		$this->subscribers[$data->identifier] = $data;
	}

	public function unsubscribe(UserData $data): void {
		unset($this->subscribers[$data->identifier]);
	}

	public function broadcast(string $message): void {
		foreach ($this->subscribers as $subscriber) {
			$subscriber->message($message);
		}
	}

	public function isActive(): bool {
		return count($this->subscribers) > 0;
	}

	public function destroy(): void {
		foreach (array_keys($this->subscribers) as $key) {
			unset($this->subscribers[$key]);
		}
	}

}