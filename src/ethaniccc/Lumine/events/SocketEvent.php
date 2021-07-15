<?php

namespace ethaniccc\Lumine\events;

abstract class SocketEvent {

	public const NAME = "default";
	public const SOCKET_CONNECT_ERROR = "thread:connect_error";
	public const HEARTBEAT = "socket:heartbeat";
	public const SOCKET_SEND_ERROR = "socket:send_error";

	public static function get(array $data): SocketEvent {
		switch ($data["name"] ?? "ERR_NO_NAME") {
			case self::SOCKET_CONNECT_ERROR:
				return new ConnectionErrorEvent($data);
			case self::HEARTBEAT:
				return new HeartbeatEvent();
			case self::SOCKET_SEND_ERROR:
				return new SendErrorEvent();
			default:
				return new UnknownEvent($data["name"]);
		}
	}

}