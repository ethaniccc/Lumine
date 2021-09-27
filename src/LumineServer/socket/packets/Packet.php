<?php

namespace LumineServer\socket\packets;

use pocketmine\network\mcpe\NetworkBinaryStream;

abstract class Packet {

	public const ID = -1;
	public const UPDATE_USER = 0;
	public const SERVER_SEND_DATA = 1;
	public const HEARTBEAT = 2;
	public const ALERT_NOTIFICATION = 3;
	public const REQUEST_PUNISHMENT = 4;
	public const COMMAND_REQUEST = 5;
	public const COMMAND_RESPONSE = 6;
	public const LAG_COMPENSATION = 7;

	public NetworkBinaryStream $buffer;

	public function __construct(string $buffer = "") {
		$this->buffer = new NetworkBinaryStream($buffer);
	}

	public abstract function decode(): void;

	public function clone(): self {
		return clone $this;
	}

	public function pid(): int {
		return self::ID;
	}

	public function encode(): void {
		$this->buffer->reset();
		$this->buffer->putInt($this->pid());
	}

	public function setBuffer(NetworkBinaryStream $buffer): void {
		unset($this->buffer);
		$this->buffer = $buffer;
	}

}