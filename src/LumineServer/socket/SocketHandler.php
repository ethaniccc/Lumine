<?php

namespace LumineServer\socket;

use LumineServer\data\UserData;
use LumineServer\events\AddUserDataEvent;
use LumineServer\events\CommandRequestEvent;
use LumineServer\events\CommandResponseEvent;
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
use pocketmine\utils\BinaryStream;
use pocketmine\utils\TextFormat;
use ReflectionClass;
use Socket;

final class SocketHandler {

	public int $port;
	public Socket $socket;
	/** @var SocketData[] */
	public array $clients = [];

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
			if (!$this->process($client)) {
				@socket_close($client->socket);
				$removeList[] = $client->address;
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
	 * @param string $socketAddr
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
		$len = strlen($buffer);
		retry_send:
		$res = @socket_write($client->socket, $buffer);
		if ($res === false) {
			@socket_close($client->socket);
			unset($this->clients[$client->address]);
		} elseif ($res !== $len) {
			$buffer = substr($buffer, $res);
			goto retry_send;
		}
	}

	private function process(SocketData $client): bool {
		$s = microtime(true);
		$toRemove = false;
		$times = 0;
		retry_read:
		$current = @socket_read($client->socket, $client->toRead);
		if ($current === "") {
			$error = socket_last_error($client->socket);
			if ($error !== 0) {
				Server::getInstance()->logger->log("Socket client {$client->address} had an error (empty buffer): " . socket_strerror($error));
			}
			$toRemove = true;
			goto end;
		} elseif ($current === false) {
			$times++;
			goto end;
		} else {
			$times = 0;
			$client->lastACK = microtime(true);
			$length = strlen($current);
			if ($client->isAwaitingBuffer) {
				$client->recvBuffer .= $current;
				if ($length !== $client->toRead) {
					$client->toRead -= strlen($current);
					//Server::getInstance()->logger->log("Need to retry read (remaining={$client->toRead})", false);
					goto retry_read;
				} else {
					$zlibD = zlib_decode($client->recvBuffer);
					if ($zlibD === false) {
						Server::getInstance()->logger->log("Unable to decode buffer from {$client->address}");
						$toRemove = true;
						goto end;
					}
					$decoded = igbinary_unserialize($zlibD);
					if ($decoded === false) {
						Server::getInstance()->logger->log("Unable to binary un-serialize data from {$client->address}");
						$toRemove = true;
						goto end;
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
						} elseif ($event instanceof CommandRequestEvent) {
							switch ($event->commandType) {
								case "logs":
									$targetPlayer = array_shift($event->args);
									if ($targetPlayer === null) {
										$this->send(new CommandResponseEvent([
											"target" => $event->sender,
											"response" => TextFormat::RED . "You need to specify a player to obtain logs"
										]), $client->address);
									} else {
										/** @var string|null $found */
										$found = null;
										$name = strtolower($targetPlayer);
										$delta = PHP_INT_MAX;
										foreach (Server::getInstance()->dataStorage->getAll() as $queue) {
											foreach ($queue as $otherData) {
												/** @var UserData $otherData */
												$username = $otherData->authData->username;
												if(stripos($username, $name) === 0){
													$curDelta = strlen($username) - strlen($name);
													if($curDelta < $delta){
														$found = $username;
														$delta = $curDelta;
													}
													if($curDelta === 0){
														break;
													}
												}
											}
										}
										if ($found === null) {
											$this->send(new CommandResponseEvent([
												"target" => $event->sender,
												"response" => TextFormat::RED . "The specified player was not found"
											]), $client->address);
										} else {
											$message = "";
											$times = 1;
											foreach (Server::getInstance()->dataStorage->getAll() as $queue) {
												foreach ($queue as $otherData) {
													if ($otherData->authData->username === $found) {
														$message .= TextFormat::GOLD . "Server " . TextFormat::GRAY . "(" . TextFormat::YELLOW . $times . TextFormat::GRAY . "):" . PHP_EOL;
														$logs = 0;
														foreach ($otherData->detections as $detection) {
															if ($detection->violations >= 2) {
																$message .= TextFormat::AQUA . "(" . TextFormat::LIGHT_PURPLE . var_export(round($detection->violations, 2), true) . TextFormat::AQUA . ") ";
																$message .= TextFormat::GRAY . $detection->category . " (" . TextFormat::YELLOW . $detection->subCategory . TextFormat::GRAY . ") ";
																$message .= TextFormat::DARK_GRAY . "- " . TextFormat::GOLD . $detection->description . PHP_EOL;
																$logs++;
															}
														}
														if ($logs === 0) {
															$message .= TextFormat::GREEN . "No logs found for {$otherData->authData->username}" . PHP_EOL;
														}
														$times++;
													}
												}
											}
											$this->send(new CommandResponseEvent([
												"target" => $event->sender,
												"response" => $message
											]), $client->address);
										}
									}
									break;
							}
						} elseif ($event instanceof UnknownEvent) {
							Server::getInstance()->logger->log("Got unknown event ({$event->name})");
						}
					}
					finish_read:
					$client->toRead = 4;
					$client->isAwaitingBuffer = false;
					$client->recvBuffer = "";
				}
				goto retry_read;
			} else {
				$client->recvBuffer .= $current;
				if (strlen($client->recvBuffer) !== 4) {
					$client->toRead -= strlen($current);
					//Server::getInstance()->logger->log("Failed to get full 4 bytes (current=" . strlen($client->toRead) . ")");
					goto retry_read;
				} else {
					$unpacked = @unpack("l", $client->recvBuffer)[1];
					if ($unpacked !== false && $unpacked !== null) {
						$client->toRead = $unpacked;
						$client->isAwaitingBuffer = true;
					} else {
						$toRemove = true;
						Server::getInstance()->logger->log("Unable to unpack read length from {$client->socket}");
						goto end;
					}
					$client->recvBuffer = "";
				}
				//Server::getInstance()->logger->log("expecting to read $unpacked bytes", false);
			}
		}
		end:
		if ($times !== 5 && !$toRemove) {
			goto retry_read;
		}
		unset($current);
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

		if (Server::getInstance()->currentTick % Server::TPS === 0 && !$toRemove) {
			$this->send(new HeartbeatEvent(), $client->address);
		}

		if ($toRemove) {
			Server::getInstance()->logger->log("Connection for {$client->address} terminated");
			return false;
		}

		return true;
	}

}