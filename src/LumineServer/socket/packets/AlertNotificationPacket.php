<?php

namespace LumineServer\socket\packets;

class AlertNotificationPacket extends Packet {

	public const VIOLATION = 0;
	public const PUNISHMENT = 1;

	public int $type;
	public string $message;

	public function pid(): int {
		return self::ALERT_NOTIFICATION;
	}

	public function encode(): void {
		parent::encode();
		$this->buffer->putInt($this->type);
		$this->buffer->putString($this->message);
	}

	public function decode(): void {
		$this->type = $this->buffer->getInt();
		$this->message = $this->buffer->getString();
	}

}