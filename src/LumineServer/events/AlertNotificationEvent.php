<?php

namespace LumineServer\events;

use pocketmine\network\mcpe\protocol\BatchPacket;
use pocketmine\network\mcpe\protocol\DataPacket;

final class AlertNotificationEvent extends SocketEvent {

	public const NAME = self::ALERT_NOTIFICATION;

	public BatchPacket $alertPacket;

	public function __construct(array $data) {
		$this->alertPacket = $data["alertPacket"];
	}

}