<?php

namespace ethaniccc\Lumine\events;

final class ServerSendPacketEvent extends SocketEvent {

	public const NAME = self::SERVER_SEND_PACKET;

	public string $identifier;
	public BatchPacket $packet; // todo
	public float $timestamp;

	public function __construct(array $data) {
		$this->identifier = $data["identifier"];
		$this->packet = $data["packet"];
		$this->timestamp = $data["timestamp"];
	}

}