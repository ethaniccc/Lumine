<?php

namespace ethaniccc\Lumine\events;

use pocketmine\network\mcpe\protocol\BatchPacket;

final class AlertNotificationEvent extends SocketEvent {

	public const NAME = self::ALERT_NOTIFICATION;

	public string $alertType;
	public BatchPacket $alertPacket;

	public function __construct(array $data) {
		$this->alertType = $data["alertType"];
		$this->alertPacket = $data["alertPacket"] instanceof BatchPacket ? $data["alertPacket"] : unserialize(base64_decode($data["alertPacket"]));
	}

}