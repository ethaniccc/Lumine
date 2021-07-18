<?php

namespace LumineServer\socket;

use LumineServer\events\AddUserDataEvent;
use LumineServer\events\HeartbeatEvent;
use LumineServer\events\InitDataEvent;
use LumineServer\events\LagCompensationEvent;
use LumineServer\events\PlayerSendPacketEvent;
use LumineServer\events\RemoveUserDataEvent;
use LumineServer\events\ResetDataEvent;
use LumineServer\events\SendErrorEvent;
use LumineServer\events\ServerSendPacketEvent;
use LumineServer\events\SocketEvent;
use LumineServer\events\UnknownEvent;
use LumineServer\Server;
use pocketmine\network\mcpe\convert\RuntimeBlockMapping;
use pocketmine\network\mcpe\protocol\BatchPacket;

final class SocketHandler {

	public int $port;
	public float $lastSend = 0;
	/** @var resource */
	public $socket;
	/** @var resource */
	public $targetClient;

	public function __construct(int $port) {
		$this->port = $port;
		$this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
	}

	public function start(): void {
		socket_bind($this->socket, "127.0.0.1", $this->port);
		socket_listen($this->socket);
		socket_set_nonblock($this->socket);
	}

	public function tick(): void {
		while (($newClient = socket_accept($this->socket)) !== false) {
			if ($this->targetClient !== null) {
				socket_close($newClient);
				continue;
			}
			socket_getpeername($newClient, $address, $port);
			socket_set_nonblock($newClient);
			$this->targetClient = $newClient;
			$this->lastSend = microtime(true);
			Server::getInstance()->logger->log("Added session for [$address:$port]");
		}

		$toRemove = false;
		if ($this->targetClient === null) {
			goto end;
		}
		$buffer = "";
		while (true) {
			$current = @socket_read($this->targetClient, 1024);
			if ($current === false) {
				if ($buffer !== "") {
					goto did_something_go_wrong;
					/* Server::getInstance()->logger->log("Unexpected non empty buffer when read was false - shutting down server...");
					Server::getInstance()->shutdown();
					return; */
				}
				goto end;
			}
			$buffer .= $current;
			if (strlen($buffer) % 1024 !== 0 || $current === "") {
				did_something_go_wrong:
				$this->lastSend = microtime(true);
				break;
			}
		}
		if ($buffer === "") {
			Server::getInstance()->logger->log("Closed connection (client disconnect)");
			$toRemove = true;
			goto end;
		}
		foreach (explode("\0", $buffer) as $buffer) {
			if (strlen($buffer) === 0) {
				continue;
			}
			$decoded = json_decode($buffer, true);
			if ($decoded === null) {
				Server::getInstance()->logger->log("Unable to decode JSON data");
				$this->send(new SendErrorEvent());
				continue;
			}
			$event = SocketEvent::get($decoded);
			if ($event instanceof HeartbeatEvent) {
				$this->send(new HeartbeatEvent());
			} elseif ($event instanceof AddUserDataEvent) {
				Server::getInstance()->dataStorage->add($event->identifier);
				Server::getInstance()->logger->log("Added user data for {$event->identifier}");
			} elseif ($event instanceof RemoveUserDataEvent) {
				Server::getInstance()->dataStorage->remove($event->identifier);
				Server::getInstance()->logger->log("Removed user data for {$event->identifier}");
			} elseif ($event instanceof ResetDataEvent) {
				Server::getInstance()->dataStorage->reset();
				Server::getInstance()->logger->log("Server requested for data removal - request accepted");
			} elseif ($event instanceof PlayerSendPacketEvent) {
				$data = Server::getInstance()->dataStorage->get($event->identifier);
				if ($data === null) {
					Server::getInstance()->logger->log("Received a player send packet event for {$event->identifier}, but no data was found.");
					continue;
				}
				$data->handler->inbound($event->packet, $event->timestamp);
			} elseif ($event instanceof ServerSendPacketEvent) {
				$data = Server::getInstance()->dataStorage->get($event->identifier);
				if ($data === null) {
					Server::getInstance()->logger->log("Received a server send packet event for {$event->identifier}, but no data was found.");
					continue;
				}
				$data->handler->outbound($event->packet, $event->timestamp);
			} elseif ($event instanceof LagCompensationEvent) {
				$data = Server::getInstance()->dataStorage->get($event->identifier);
				if ($data === null) {
					Server::getInstance()->logger->log("Received a server send packet event for {$event->identifier}, but no data was found.");
					continue;
				}
				$data->handler->compensate($event);
			} elseif ($event instanceof InitDataEvent) {
				if ($event->extraData["bedrockKnownStates"]) {
					$reflection = new \ReflectionClass(RuntimeBlockMapping::class);
					$reflection->setStaticPropertyValue("bedrockKnownStates", unserialize($event->extraData["bedrockKnownStates"]));
					$reflection->setStaticPropertyValue("runtimeToLegacyMap", unserialize($event->extraData["runtimeToLegacyMap"]));
					$reflection->setStaticPropertyValue("legacyToRuntimeMap", unserialize($event->extraData["legacyToRuntimeMap"]));
					Server::getInstance()->logger->log("RuntimeBlockMapping was initialized");
				}
			} elseif ($event instanceof UnknownEvent) {
				Server::getInstance()->logger->log("Got unknown event ({$event->name})");
			}
		}
		end:

		foreach (Server::getInstance()->dataStorage->getAll() as $data) {
			if ($data->sendPackets > 0) {
				$data->sendQueue->encode();
				$this->send(new ServerSendPacketEvent([
					"identifier" => $data->identifier,
					"packet" => $data->sendQueue,
					"timestamp" => microtime(true)
				]));
				$data->sendPackets = 0;
				unset($data->sendQueue);
				$data->sendQueue = new BatchPacket();
				$data->sendQueue->setCompressionLevel(7);
			}
		}

		if ($this->targetClient !== null && microtime(true) - $this->lastSend >= 10) {
			Server::getInstance()->logger->log("Closed connection (timeout)");
			$toRemove = true;
		}

		if ($toRemove) {
			socket_close($this->targetClient);
			$this->targetClient = null;
			$this->lastSend = 0;
			Server::getInstance()->logger->log("Connection terminated");
		}
	}

	public function shutdown(): void {
		socket_close($this->socket);
		if ($this->targetClient !== null) {
			socket_close($this->targetClient);
		}
	}

	/**
	 * @param SocketEvent $event
	 */
	public function send(SocketEvent $event): void {
		if ($this->targetClient === null) {
			return;
		}
		$data = (array) $event;
		$data["name"] = $event::NAME;
		foreach ($data as $key => $value) {
			if (is_object($value)) {
				$data[$key] = base64_encode(serialize($value));
			}
		}
		if (@socket_write($this->targetClient, json_encode($data) . "\0") === false) {
			Server::getInstance()->logger->log("Unable to send an event to the target client - closing connection");
			socket_close($this->targetClient);
			$this->targetClient = null;
			$this->lastSend = 0;
		}
	}



}