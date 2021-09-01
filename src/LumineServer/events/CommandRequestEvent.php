<?php

namespace LumineServer\events;

final class CommandRequestEvent extends SocketEvent {

	public const NAME = self::COMMAND_REQUEST;

	public string $sender;
	public string $commandType;
	public array $args = [];

	public function __construct(array $data) {
		$this->sender = $data["sender"];
		$this->commandType = $data["commandType"];
		$this->args = $data["args"];
	}

}