<?php

namespace ethaniccc\Lumine\events;

final class UnknownEvent extends SocketEvent {

	public const NAME = "UNKNOWN";
	public mixed $name;

	public function __construct(array $data) {
		$this->name = $data["name"];
	}

}