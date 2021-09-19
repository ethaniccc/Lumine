<?php

namespace LumineServer\events;

use pocketmine\network\mcpe\protocol\ClientboundPacket;

final class ServerSendPacketEvent extends SocketEvent {

	public const NAME = self::SERVER_SEND_PACKET;

	public string $identifier;
    /** @var ClientboundPacket[] */
	public array $packets;
	public float $timestamp;

	public function __construct(array $data) {
		$this->identifier = $data["identifier"];
		$this->packets = $data["packet"];
		$this->timestamp = $data["timestamp"];
	}

}