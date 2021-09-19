<?php

namespace LumineServer\events;

use pocketmine\network\mcpe\protocol\ServerboundPacket;

final class PlayerSendPacketEvent extends SocketEvent {

	public const NAME = self::PLAYER_SEND_PACKET;

	public string $identifier;
	public ServerboundPacket $packet;
	public float $timestamp;

	public function __construct(array $data) {
		$this->identifier = $data["identifier"];
		$this->packet = $data["packet"];
		$this->timestamp = $data["timestamp"];
	}

}