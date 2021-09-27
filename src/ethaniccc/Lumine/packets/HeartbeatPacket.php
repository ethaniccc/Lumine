<?php

namespace ethaniccc\Lumine\packets;

class HeartbeatPacket extends Packet {

	public function pid(): int {
		return self::HEARTBEAT;
	}

	public function decode(): void {
	}

}