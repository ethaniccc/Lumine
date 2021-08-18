<?php

namespace ethaniccc\Lumine\events;

use pocketmine\network\mcpe\protocol\BatchPacket;
use pocketmine\network\mcpe\protocol\DataPacket;

final class ServerSendPacketEvent extends SocketEvent {

	public const NAME = self::SERVER_SEND_PACKET;

	public string $identifier;
	public BatchPacket $packet;
	public float $timestamp;

	public function __construct(array $data) {
		$this->identifier = $data["identifier"];
		$this->packet = $data["packet"];
		$this->timestamp = $data["timestamp"];
	}

}