<?php

namespace LumineServer\socket\packets;

class ServerSendDataPacket extends Packet {

	public const PLAYER_SEND_PACKET = 0;
	public const SERVER_SEND_PACKET = 1;

	public int $eventType;
	public string $identifier;
	public string $packetBuffer;
	public float $timestamp;

	public function pid(): int {
		return self::SERVER_SEND_DATA;
	}

	public function encode(): void {
		parent::encode();
		$this->buffer->putInt($this->eventType);
		$this->buffer->putString($this->identifier);
		$this->buffer->putString($this->packetBuffer);
		$this->buffer->putDouble($this->timestamp);
	}

	public function decode(): void {
		$this->eventType = $this->buffer->getInt();
		$this->identifier = $this->buffer->getString();
		$this->packetBuffer = $this->buffer->getString();
		$this->timestamp = $this->buffer->getDouble();
	}

}