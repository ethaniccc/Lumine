<?php

namespace LumineServer\socket\packets;

class UpdateUserPacket extends Packet {

	public const ACTION_ADD = 0;
	public const ACTION_REMOVE = 1;

	public int $action = -1;
	public string $identifier = "";

	public function pid(): int {
		return self::UPDATE_USER;
	}

	public function encode(): void {
		parent::encode();
		$this->buffer->putInt($this->action);
		$this->buffer->putString($this->identifier);
	}

	public function decode(): void {
		$this->action = $this->buffer->getInt();
		$this->identifier = $this->buffer->getString();
	}

}