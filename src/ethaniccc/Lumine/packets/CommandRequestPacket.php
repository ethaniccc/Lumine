<?php

namespace ethaniccc\Lumine\packets;

class CommandRequestPacket extends Packet {

	public string $sender;
	public string $command;
	public array $args = [];

	public function pid(): int {
		return self::COMMAND_REQUEST;
	}

	public function encode(): void {
		parent::encode();
		$this->buffer->putString($this->sender);
		$this->buffer->putString($this->command);
		foreach ($this->args as $arg) {
			$this->buffer->putString($arg);
		}
	}

	public function decode(): void {
		$this->sender = $this->buffer->getString();
		$this->command = $this->buffer->getString();
		while (!$this->buffer->feof()) {
			$this->args[] = $this->buffer->getString();
		}
	}

}