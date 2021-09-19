<?php

namespace LumineServer\events;

final class UnknownEvent extends SocketEvent {

	public const NAME = "UNKNOWN";
	public string $name;

	public function __construct(array $data) {
		$this->name = $data["name"];
	}

}