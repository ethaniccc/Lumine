<?php

namespace LumineServer\socket;

use LumineServer\events\AddUserDataEvent;
use LumineServer\events\HeartbeatEvent;
use LumineServer\events\InitDataEvent;
use LumineServer\events\LagCompensationEvent;
use LumineServer\events\PlayerSendPacketEvent;
use LumineServer\events\RemoveUserDataEvent;
use LumineServer\events\ResetDataEvent;
use LumineServer\events\ServerSendPacketEvent;
use LumineServer\events\SocketEvent;
use LumineServer\events\UnknownEvent;
use LumineServer\Server;
use pocketmine\network\mcpe\convert\RuntimeBlockMapping;
use pocketmine\network\mcpe\protocol\BatchPacket;
use ReflectionClass;
use Socket;

final class SocketHandler {

	public int $port;
	public Socket $socket;
	/** @var SocketData[] */
	public array $clients = [];
	public array $retryQueue = [];

	public function __construct(int $port) {
		$this->port = $port;
		$this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
	}

	public function start(): void {
		if (!socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, 1)) {
			Server::getInstance()->logger->log(socket_strerror(socket_last_error($this->socket)));
		}
		if (!socket_bind($this->socket, Server::getInstance()->settings->get("bind_address", "127.0.0.1"), $this->port)) {
			Server::getInstance()->logger->log("Socket failed to bind to address");
			Server::getInstance()->shutdown();
		}
		socket_listen($this->socket, SOMAXCONN);
		socket_set_nonblock($this->socket);
	}

	public function tick(): void {
		while (($newClient = socket_accept($this->socket)) !== false) {
			socket_getpeername($newClient, $address, $port);
			socket_set_nonblock($newClient);
			$this->clients["$address:$port"] = new SocketData($newClient, "$address:$port", microtime(true));
			Server::getInstance()->logger->log("Added session for [$address:$port]");
		}
		/** @var string[] $removeList */
		$removeList = [];
		foreach ($this->clients as $client) {
			$s = microtime(true);
			$toRemove = false;
			retry_read:
			$current = @socket_read($client->socket, $client->toRead);
			if ($current === "") {
				$error = socket_last_error($client->socket);
				if ($error !== 0) {
					Server::getInstance()->logger->log("Socket client {$client->address} had an error (empty buffer): " . socket_strerror($error));
				}
				$toRemove = true;
				Server::getInstance()->logger->log("Socket from {$client->address} sent an empty buffer");
				goto end;
			} elseif ($current === false) {
				goto end;
			} else {
				$client->lastACK = microtime(true);
				$retryQueue = $this->retryQueue[$client->address] ?? null;
				if ($retryQueue !== null && microtime(true) - $client->lastRetryTime >= 1) {
					$client->lastRetryTime = microtime(true);
					foreach ($retryQueue as $key => $buff) {
						$length = strlen($buff);
						$res = @socket_write($client->socket, $buff, $length);
						if ($res === false) {
							Server::getInstance()->logger->log("Still unable to send buffer ($length) due to [" . socket_strerror(socket_last_error()) . "] - adding buffer to retry queue");
							break;
						} else {
							if ($res !== $length) {
								Server::getInstance()->logger->log("Socket {$client->address} still unable to have full buffer written (expected=$length got=$res) - adding to retry queue");
								$this->retryQueue[$client->address][] = substr($buff, $res);
							} else {
								Server::getInstance()->logger->log("Socket {$client->address} has had full stream buffer written");
							}
						}
						unset($this->retryQueue[$key]);
					}
				}
				$length = strlen($current);
				if ($client->isAwaitingBuffer) {
					$client->buffer .= $current;
					if ($length !== $client->toRead) {
						$client->toRead -= strlen($current);
					} else {
						$zlibD = zlib_decode($client->buffer);
						$decoded = igbinary_unserialize($zlibD);
						if ($decoded === false) {
							Server::getInstance()->logger->log("Unable to binary un-serialize data");
						} else {
							$event = SocketEvent::get($decoded);
							if ($event instanceof AddUserDataEvent) {
								Server::getInstance()->dataStorage->add($event->identifier, $client->address);
								Server::getInstance()->logger->log("Added user data for {$event->identifier}");
							} elseif ($event instanceof RemoveUserDataEvent) {
								Server::getInstance()->dataStorage->remove($event->identifier, $client->address);
								Server::getInstance()->logger->log("Removed user data for {$event->identifier}");
							} elseif ($event instanceof ResetDataEvent) {
								Server::getInstance()->dataStorage->reset($client->address);
								Server::getInstance()->logger->log("Server requested for data removal - request accepted");
							} elseif ($event instanceof PlayerSendPacketEvent) {
								$data = Server::getInstance()->dataStorage->get($event->identifier, $client->address);
								if ($data === null) {
									Server::getInstance()->logger->log("Received a player send packet event for {$event->identifier}, but no data was found.");
									goto finish_read;
								}
								$data->handler->inbound($event->packet, $event->timestamp);
							} elseif ($event instanceof ServerSendPacketEvent) {
								$data = Server::getInstance()->dataStorage->get($event->identifier, $client->address);
								if ($data === null) {
									Server::getInstance()->logger->log("Received a server send packet event for {$event->identifier}, but no data was found.");
									goto finish_read;
								}
								$data->handler->outbound($event->packet, $event->timestamp);
							} elseif ($event instanceof LagCompensationEvent) {
								$data = Server::getInstance()->dataStorage->get($event->identifier, $client->address);
								if ($data === null) {
									Server::getInstance()->logger->log("Received a server send packet event for {$event->identifier}, but no data was found.");
									goto finish_read;
								}
								$data->handler->compensate($event);
							} elseif ($event instanceof InitDataEvent) {
								if ($event->extraData["bedrockKnownStates"]) {
									$reflection = new ReflectionClass(RuntimeBlockMapping::class);
									$property = $reflection->getStaticPropertyValue("bedrockKnownStates");
									if ($property === null) {
										$reflection->setStaticPropertyValue("bedrockKnownStates", unserialize($event->extraData["bedrockKnownStates"]));
										$reflection->setStaticPropertyValue("runtimeToLegacyMap", unserialize($event->extraData["runtimeToLegacyMap"]));
										$reflection->setStaticPropertyValue("legacyToRuntimeMap", unserialize($event->extraData["legacyToRuntimeMap"]));
										Server::getInstance()->logger->log("RuntimeBlockMapping was initialized");
									}
								}
							} elseif ($event instanceof UnknownEvent) {
								Server::getInstance()->logger->log("Got unknown event ({$event->name})");
							}
						}
						finish_read:
						$client->toRead = 4;
						$client->isAwaitingBuffer = false;
						$client->buffer = "";
					}
					goto retry_read;
				} else {
					$unpacked = @unpack("l", $current)[1];
					if ($unpacked !== false && $unpacked !== null) {
						$client->toRead = $unpacked;
						$client->isAwaitingBuffer = true;
					}
					goto retry_read;
				}
			}
			end:
			$oDelta = microtime(true) - $s;
			if (microtime(true) - $client->lastACK >= 60 + $oDelta) {
				Server::getInstance()->logger->log("Client {$client->address} timed out, removing connection...");
				$toRemove = true;
			}

			foreach (Server::getInstance()->dataStorage->getFromSocket($client->address) as $data) {
				if ($data->sendPackets > 0) {
					$data->sendQueue->encode();
					$this->send(new ServerSendPacketEvent([
						"identifier" => $data->identifier,
						"packet" => $data->sendQueue,
						"timestamp" => microtime(true)
					]), $client->address);
					$data->sendPackets = 0;
					unset($data->sendQueue);
					$data->sendQueue = new BatchPacket();
					$data->sendQueue->setCompressionLevel(7);
				}
			}

			if (Server::getInstance()->currentTick % 20 === 0 && !$toRemove) {
				$this->send(new HeartbeatEvent(), $client->address);
			}

			if ($toRemove) {
				socket_close($client->socket);
				$removeList[] = $client->address;
				Server::getInstance()->logger->log("Connection for {$client->address} terminated");
			}
		}

		foreach ($removeList as $address) {
			Server::getInstance()->dataStorage->reset($address);
			unset($this->clients[$address]);
		}
	}

	public function shutdown(): void {
		socket_close($this->socket);
		foreach ($this->clients as $client) {
			socket_close($client->socket);
			Server::getInstance()->logger->log("Connection for {$client->address} terminated");
		}
	}

	/**
	 * @param SocketEvent $event
	 */
	public function send(SocketEvent $event, string $socketAddr): void {
		$client = $this->clients[$socketAddr] ?? null;
		if ($client === null) {
			Server::getInstance()->logger->log("Unable to send packet to {$socketAddr} because it is not connected connected=[" . implode(", ", array_keys($this->clients)) . "]");
			return;
		}
		$data = (array) $event;
		$data["name"] = $event::NAME;
		$write = zlib_encode(igbinary_serialize($data), ZLIB_ENCODING_RAW, 7);
		$buffer = pack("l", strlen($write)) . $write;
		$length = strlen($buffer);
		$res = @socket_write($client->socket, $buffer, $length);
		if ($res === false) {
			Server::getInstance()->logger->log("Unable to send buffer ($length) due to [" . socket_strerror(socket_last_error()) . "] - adding buffer to retry queue");
			$this->retryQueue[$client->address][] = $buffer;
		} else {
			if ($res !== $length) {
				Server::getInstance()->logger->log("Socket {$client->address} unable to have full buffer written (expected=$length got=$res) - adding to retry queue");
				$this->retryQueue[$client->address][] = substr($buffer, $res);
			}
		}
	}



}