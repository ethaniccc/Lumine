<?php

namespace LumineServer\events;

final class AddUserDataEvent extends SocketEvent {

	public const NAME = self::ADD_USER_DATA;

	public string $identifier;

	public function __construct(array $data) {
		$this->identifier = $data["identifier"];
	}

}