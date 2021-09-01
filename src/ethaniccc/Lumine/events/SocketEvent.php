<?php

namespace ethaniccc\Lumine\events;

abstract class SocketEvent {

	public const NAME = "default";

	public const SOCKET_CONNECT_ERROR = "thread:connect_error";
	public const HEARTBEAT = "socket:heartbeat";
	public const SOCKET_SEND_ERROR = "socket:send_error";
	public const ADD_USER_DATA = "socket:add_user";
	public const REMOVE_USER_DATA = "socket:remove_user";
	public const RESET_ALL_USER_DATA = "socket:reset_data";
	public const PLAYER_SEND_PACKET = "player:send_packet";
	public const SERVER_SEND_PACKET = "server:send_packet";
	public const LAG_COMPENSATION = "player:lag_compensation";
	public const INIT_DATA = "socket:init_data";
	public const ALERT_NOTIFICATION = "server:alert_notification";
	public const COMMAND_REQUEST = "command:request";
	public const COMMAND_RESPONSE = "command:response";

	public static function get(array $data): SocketEvent {
		switch ($data["name"] ?? "ERR_NO_NAME") {
			case self::SOCKET_CONNECT_ERROR:
				return new ConnectionErrorEvent($data);
			case self::HEARTBEAT:
				return new HeartbeatEvent();
			case self::SOCKET_SEND_ERROR:
				return new SendErrorEvent();
			case self::ADD_USER_DATA:
				return new AddUserDataEvent($data);
			case self::REMOVE_USER_DATA:
				return new RemoveUserDataEvent($data);
			case self::RESET_ALL_USER_DATA:
				return new ResetDataEvent();
			case self::PLAYER_SEND_PACKET:
				return new PlayerSendPacketEvent($data);
			case self::SERVER_SEND_PACKET:
				return new ServerSendPacketEvent($data);
			case self::LAG_COMPENSATION:
				return new LagCompensationEvent($data);
			case self::INIT_DATA:
				return new InitDataEvent($data);
			case self::ALERT_NOTIFICATION:
				return new AlertNotificationEvent($data);
			case self::COMMAND_REQUEST:
				return new CommandRequestEvent($data);
			case self::COMMAND_RESPONSE:
				return new CommandResponseEvent($data);
			default:
				return new UnknownEvent($data);
		}
	}

}