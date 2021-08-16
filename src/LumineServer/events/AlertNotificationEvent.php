<?php

namespace LumineServer\events;

use pocketmine\network\mcpe\protocol\BatchPacket;
use pocketmine\network\mcpe\protocol\DataPacket;

/**
 * @deprecated - After some planning, Lumine will be an alert-less anticheat. It will be 100% silent.
 * TODO: Remove this event.
 */
final class AlertNotificationEvent extends SocketEvent {

	public const NAME = self::ALERT_NOTIFICATION;

	public BatchPacket $alertPacket;

	public function __construct(array $data) {
		$this->alertPacket = $data["alertPacket"] instanceof DataPacket ? $data["alertPacket"] : unserialize(base64_decode($data["alertPacket"]));
	}

}