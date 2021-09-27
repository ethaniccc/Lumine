<?php

namespace ethaniccc\Lumine\packets;

class RequestPunishmentPacket extends Packet {

	public const TYPE_KICK = 0;
	public const TYPE_BAN = 1;

	public string $identifier;
	public int $type;
	public string $message;
	public ?int $expiration = null;

	public function pid(): int {
		return self::REQUEST_PUNISHMENT;
	}

	public function encode(): void {
		parent::encode();
		$this->buffer->putString($this->identifier);
		$this->buffer->putInt($this->type);
		$this->buffer->putString($this->message);
		if ($this->expiration !== null) {
			$this->buffer->putUnsignedVarInt($this->expiration);
		}
	}

	public function decode(): void {
		$this->identifier = $this->buffer->getString();
		$this->type = $this->buffer->getInt();
		$this->message = $this->buffer->getString();
		if ($this->type === self::TYPE_BAN) {
			$this->expiration = $this->buffer->getUnsignedVarInt();
		}
	}

}