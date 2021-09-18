<?php

namespace ethaniccc\Lumine\events;

use pocketmine\network\mcpe\protocol\ClientboundPacket;
use function base64_decode;
use function is_array;
use function unserialize;

final class AlertNotificationEvent extends SocketEvent {

	public const NAME = self::ALERT_NOTIFICATION;

	public string $alertType;
	/** @var $alertPackets ClientboundPacket[] */
	public array $alertPackets;

	public function __construct(array $data) {
		$this->alertType = $data["alertType"];
		$this->alertPackets = is_array($data["alertPacket"]) ? $data["alertPacket"] : unserialize(base64_decode($data["alertPacket"]));
	}

}