<?php

namespace LumineServer\events;

use pocketmine\network\mcpe\protocol\DataPacket;

final class LagCompensationEvent extends SocketEvent {

	public const NAME = self::LAG_COMPENSATION;

	public string $identifier;
	public int $timestamp;
    /** @var DataPacket[] */
	public array $packets;

	public function __construct(array $data) {
		$this->identifier = $data["identifier"];
		$this->timestamp = $data["timestamp"];
		$this->packets = $data["packet"];
	}

}