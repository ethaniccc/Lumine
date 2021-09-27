<?php

namespace ethaniccc\Lumine\packets;

class LagCompensationPacket extends Packet {

	public string $identifier;
	public int $timestamp;
	public string $packetBuffer;
	public bool $isBatch = false;

	public function pid(): int {
		return self::LAG_COMPENSATION;
	}

	public function encode(): void {
		parent::encode();
		$this->buffer->putString($this->identifier);
		$this->buffer->putLLong($this->timestamp);
		$this->buffer->putString($this->packetBuffer);
		$this->buffer->putBool($this->isBatch);
	}

	public function decode(): void {
		$this->identifier = $this->buffer->getString();
		$this->timestamp = $this->buffer->getLLong();
		$this->packetBuffer = $this->buffer->getString();
		$this->isBatch = $this->buffer->getBool();
	}

}