<?php

namespace LumineServer\socket;

use LumineServer\data\UserData;
use LumineServer\Server;
use LumineServer\socket\packets\CommandRequestPacket;
use LumineServer\socket\packets\CommandResponsePacket;
use LumineServer\socket\packets\HeartbeatPacket;
use LumineServer\socket\packets\LagCompensationPacket;
use LumineServer\socket\packets\Packet;
use LumineServer\socket\packets\PacketPool;
use LumineServer\socket\packets\ServerSendDataPacket;
use LumineServer\socket\packets\UpdateUserPacket;
use pocketmine\network\mcpe\protocol\BatchPacket;
use pocketmine\network\mcpe\protocol\UnknownPacket;
use pocketmine\utils\TextFormat;
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
	 * @param Packet $packet
	 * @param string $socketAddr
	 */
	public function send(Packet $packet, string $socketAddr): void {
		$client = $this->clients[$socketAddr] ?? null;
		if ($client === null) {
			Server::getInstance()->logger->log("Unable to send packet to $socketAddr because it is not connected connected=[" . implode(", ", array_keys($this->clients)) . "]");
			return;
		}
		$packet->encode();
		$write = zlib_encode($packet->buffer->getBuffer(), ZLIB_ENCODING_RAW, 7);
		$buffer = pack("l", strlen($write)) . $write;
		$len = strlen($buffer);
		retry_send:
		$res = @socket_write($client->socket, $buffer);
		if ($res === false) {
			Server::getInstance()->logger->log("Unable to send data to {$client->address} - terminating connection");
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
					$buffer = zlib_decode($client->recvBuffer);
					if ($buffer === false) {
						Server::getInstance()->logger->log("Unable to decode buffer from {$client->address}");
						$toRemove = true;
						goto end;
					}
					$packet = PacketPool::getPacket($buffer);
					if ($packet === null) {
						$len = strlen($buffer);
						Server::getInstance()->logger->log("Unable to create data from received buffer from {$client->address} (len=$len)");
						$toRemove = true;
						goto end;
					} else {
						$packet->decode();
						if ($packet instanceof ServerSendDataPacket) {
							$data = Server::getInstance()->dataStorage->get($packet->identifier, $client->address);
							if ($data === null) {
								Server::getInstance()->logger->log("{$packet->identifier} had a packet event, but no data was found");
								goto finish_read;
							}
							if ($packet->eventType === ServerSendDataPacket::SERVER_SEND_PACKET) {
								$pk = new BatchPacket($packet->packetBuffer);
								$pk->decode();
								$data->handler->outbound($pk, $packet->timestamp);
							} elseif ($packet->eventType === ServerSendDataPacket::PLAYER_SEND_PACKET) {
								$pk = \pocketmine\network\mcpe\protocol\PacketPool::getPacket($packet->packetBuffer);
								$pk->decode();
								$data->handler->inbound($pk, $packet->timestamp);
							} else {
								Server::getInstance()->logger->log("Invalid event type ({$packet->eventType}) received in ServerSendDataPacket");
							}
						} elseif ($packet instanceof UpdateUserPacket) {
							if ($packet->action === UpdateUserPacket::ACTION_ADD) {
								Server::getInstance()->dataStorage->add($packet->identifier, $client->address);
								Server::getInstance()->logger->log("Added user data {$packet->identifier}");
							} elseif ($packet->action === UpdateUserPacket::ACTION_REMOVE) {
								Server::getInstance()->dataStorage->remove($packet->identifier, $client->address);								Server::getInstance()->logger->log("Added user data {$packet->identifier}");
								Server::getInstance()->logger->log("Removed user data {$packet->identifier}");
							} else {
								Server::getInstance()->logger->log("Invalid action ({$packet->action}) received in UpdateUserPacket");
							}
						} elseif ($packet instanceof LagCompensationPacket) {
							$data = Server::getInstance()->dataStorage->get($packet->identifier, $client->address);
							if ($data === null) {
								Server::getInstance()->logger->log("{$packet->identifier} had a lag compensation event, but no data was found");
								goto finish_read;
							}
							if ($packet->isBatch) {
								$pk = new BatchPacket($packet->packetBuffer);
							} else {
								$pk = \pocketmine\network\mcpe\protocol\PacketPool::getPacket($packet->packetBuffer);
							}
							$pk->decode();
							$data->handler->compensate($pk, $packet->timestamp);
						} elseif ($packet instanceof CommandRequestPacket) {
							switch ($packet->command) {
								case "logs":
									$targetPlayer = array_shift($packet->args);
									if ($targetPlayer === null) {
										$pk = new CommandResponsePacket();
										$pk->target = $packet->sender;
										$pk->response = TextFormat::RED . "You need to specify a player to get the logs of.";
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
											$pk = new CommandResponsePacket();
											$pk->target = $packet->sender;
											$pk->response = TextFormat::RED . "$targetPlayer was not found on the socket server";
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
											$pk = new CommandResponsePacket();
											$pk->target = $packet->sender;
											$pk->response = $message;
										}
									}
									$this->send($pk, $client->address);
									break;
							}
						}
					}
					finish_read:
					$client->toRead = 4;
					$client->isAwaitingBuffer = false;
					$client->recvBuffer = "";
				}
				unset($packet);
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
			/** @var UserData $data */
			if ($data->sendPackets > 0) {
				$data->sendQueue->encode();
				$pk = new ServerSendDataPacket();
				$pk->eventType = ServerSendDataPacket::SERVER_SEND_PACKET;
				$pk->identifier = $data->identifier;
				$pk->packetBuffer = $data->sendQueue->getBuffer();
				$pk->timestamp = microtime(true);
				$this->send($pk, $client->address);
				$data->sendPackets = 0;
				unset($data->sendQueue);
				$data->sendQueue = new BatchPacket();
				$data->sendQueue->setCompressionLevel(7);
			}
		}

		if (Server::getInstance()->currentTick % Server::TPS === 0 && !$toRemove) {
			$this->send(new HeartbeatPacket(), $client->address);
		}

		if ($toRemove) {
			Server::getInstance()->logger->log("Connection for {$client->address} terminated");
			return false;
		}

		return true;
	}

}