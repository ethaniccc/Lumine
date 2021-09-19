<?php

namespace LumineServer\events;

use pocketmine\network\mcpe\protocol\TextPacket;

final class AlertNotificationEvent extends SocketEvent {

	public const NAME = self::ALERT_NOTIFICATION;

	public string $alertType;
    /** @var TextPacket[] */
	public array $alertPackets;

	public function __construct(array $data) {
		$this->alertType = $data["alertType"];
		$this->alertPackets = $data["alertPacket"];
	}

}