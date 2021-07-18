<?php

namespace ethaniccc\Lumine\events;

final class RemoveUserDataEvent extends SocketEvent {

	public const NAME = self::REMOVE_USER_DATA;

	public string $identifier;

	public function __construct(array $data) {
		$this->identifier = $data["identifier"];
	}

}