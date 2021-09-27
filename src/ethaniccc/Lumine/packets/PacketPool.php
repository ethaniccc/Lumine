<?php

namespace ethaniccc\Lumine\packets;

use pocketmine\network\mcpe\NetworkBinaryStream;

final class PacketPool {

	/** @var Packet[] */
	private static array $packets = [];

	public static function init(): void {
		self::$packets = [
			new UpdateUserPacket(),
			new ServerSendDataPacket(),
			new HeartbeatPacket(),
			new AlertNotificationPacket(),
			new RequestPunishmentPacket(),
			new CommandRequestPacket(),
			new CommandResponsePacket(),
			new LagCompensationPacket(),
		];
	}

	public static function getPacket(string $buffer): ?Packet {
		$stream = new NetworkBinaryStream($buffer);
		$packet = self::$packets[$stream->getInt()] ?? null;
		if ($packet !== null) {
			$packet = $packet->clone();
			$packet->setBuffer($stream);
		}
		return $packet;
 	}

}