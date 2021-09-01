<?php

namespace LumineServer\events;

final class CommandResponseEvent extends SocketEvent {

	public const NAME = self::COMMAND_RESPONSE;

	public string $target;
	public string $response;

	public function __construct(array $data) {
		$this->target = $data["target"];
		$this->response = $data["response"];
	}

}