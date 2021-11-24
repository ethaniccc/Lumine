<?php

namespace LumineServer\data\handler;

use LumineServer\data\debug\DebugChannel;
use LumineServer\data\UserData;

final class DebugHandler {

	/** @var DebugChannel[] */
	public array $channels = [];
	/** @var DebugChannel[] */
	public array $subscribed = [];

	public function __construct(
		public ?UserData $data,
	) {
		foreach ($this->data->detections as $detection) {
			$this->addChannel(new DebugChannel(strtolower($detection->category . $detection->subCategory)));
		}
	}

	public function getChannel(string $channel): ?DebugChannel {
		return $this->channels[strtolower($channel)] ?? null;
	}

	public function addChannel(DebugChannel $channel): void {
		$this->channels[strtolower($channel->getName())] = $channel;
	}

	public function destroy(): void {
		foreach (array_keys($this->subscribed) as $key) {
			$this->subscribed[$key]->unsubscribe($this->data);
			unset($this->subscribed[$key]);
		}
		foreach (array_keys($this->channels) as $key) {
			$this->channels[$key]->destroy();
			unset($this->channels[$key]);
		}
		$this->data = null;
	}

}