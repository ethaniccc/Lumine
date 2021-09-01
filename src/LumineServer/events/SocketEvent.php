<?php

namespace LumineServer\events;

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
		return match ($data["name"] ?? "ERR_NO_NAME") {
			self::SOCKET_CONNECT_ERROR => new ConnectionErrorEvent($data),
			self::HEARTBEAT => new HeartbeatEvent(),
			self::SOCKET_SEND_ERROR => new SendErrorEvent(),
			self::ADD_USER_DATA => new AddUserDataEvent($data),
			self::REMOVE_USER_DATA => new RemoveUserDataEvent($data),
			self::RESET_ALL_USER_DATA => new ResetDataEvent(),
			self::PLAYER_SEND_PACKET => new PlayerSendPacketEvent($data),
			self::SERVER_SEND_PACKET => new ServerSendPacketEvent($data),
			self::LAG_COMPENSATION => new LagCompensationEvent($data),
			self::INIT_DATA => new InitDataEvent($data),
			self::ALERT_NOTIFICATION => new AlertNotificationEvent($data),
			self::COMMAND_REQUEST => new CommandRequestEvent($data),
			self::COMMAND_RESPONSE => new CommandResponseEvent($data),
			default => new UnknownEvent($data),
		};
	}

}