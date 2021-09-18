<?php

namespace ethaniccc\Lumine\events;

use function base64_decode;
use function unserialize;

final class AlertNotificationEvent extends SocketEvent {

	public const NAME = self::ALERT_NOTIFICATION;

	public string $alertType;
	public BatchPacket $alertPacket; // todo

	public function __construct(array $data) {
		$this->alertType = $data["alertType"];
		$this->alertPacket = $data["alertPacket"] instanceof BatchPacket ? $data["alertPacket"] : unserialize(base64_decode($data["alertPacket"]));
	}

}