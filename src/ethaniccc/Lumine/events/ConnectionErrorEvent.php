<?php

namespace ethaniccc\Lumine\events;

class ConnectionErrorEvent extends SocketEvent {

	public const NAME = SocketEvent::SOCKET_CONNECT_ERROR;

	/** @var string */
	public $message;

	public function __construct(array $data) {
		$this->message = $data["message"];
	}

}