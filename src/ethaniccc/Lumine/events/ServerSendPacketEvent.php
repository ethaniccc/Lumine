<?php

namespace ethaniccc\Lumine\events;

use pocketmine\network\mcpe\protocol\ClientboundPacket;

final class ServerSendPacketEvent extends SocketEvent {

	public const NAME = self::SERVER_SEND_PACKET;

	public string $identifier;
	/** @var $packets ClientboundPacket[] */
	public array $packets;
	public float $timestamp;

	public function __construct(array $data) {
		$this->identifier = $data["identifier"];
		$this->packets = $data["packet"];
		$this->timestamp = $data["timestamp"];
	}

}