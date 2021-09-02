<?php

namespace ethaniccc\Lumine\events;

use DateTime;

final class BanUserEvent extends SocketEvent {

	public const NAME = self::BAN_USER;

	public string $username;
	public string $reason;
	public ?DateTime $expiration;

	public function __construct(array $data) {
		$this->username = $data["username"];
		$this->reason = $data["reason"];
		$this->expiration = $data["expiration"];
	}

}