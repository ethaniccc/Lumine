<?php

namespace LumineServer\socket;

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
			if ($this->handleInboundPackets($client) === null) {
				@socket_close($client->socket);
				$removeList[] = $client->address;
			} else {
				foreach (Server::getInstance()->dataStorage->getFromSocket($client->address) as $data) {
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
				$this->send(new HeartbeatPacket(), $client->address);
			}
			unset($retries);
		}

		foreach ($removeList as $address) {
			Server::getInstance()->dataStorage->reset($address);
			Server::getInstance()->logger->log("Connection for $address terminated");
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
		unset($packet, $write, $buffer);
	}

	private function handleInboundPackets(SocketData $client): ?bool {
		$retries = 0;
		$clear = false;
		while (true) {
			if ($clear) {
				$client->toRead = 4;
				$client->isAwaitingBuffer = false;
				$client->recvBuffer = "";
				$clear = false;
			}
			$current = @socket_read($client->socket, $client->toRead);
			if ($current === "") {
				$error = socket_last_error($client->socket);
				if ($error !== 0) {
					Server::getInstance()->logger->log("Socket client {$client->address} had an error (empty buffer): " . socket_strerror($error));
				}
				return null;
			} elseif ($current === false) {
				if ($retries++ > 5) {
					return false;
				}
			} else {
				$client->lastACK = microtime(true);
				if (!$client->isAwaitingBuffer) {
					$client->recvBuffer .= $current;
					if (strlen($client->recvBuffer) !== 4) {
						$client->toRead -= strlen($current);
					} else {
						$unpacked = @unpack("l", $client->recvBuffer)[1];
						if ($unpacked !== false && $unpacked !== null) {
							$client->toRead = $unpacked;
							$client->isAwaitingBuffer = true;
							$client->recvBuffer = "";
							if ($client->toRead >= 100000) {
								Server::getInstance()->logger->log("Client {$client->address} is expecting a read of {$client->toRead} bytes (above 100kb)");
							}
						} else {
							Server::getInstance()->logger->log("Unable to unpack read length from {$client->socket}");
							return null;
						}
					}
				} else {
					$length = strlen($current);
					$client->recvBuffer .= $current;
					if ($length !== $client->toRead) {
						$client->toRead -= strlen($current);
						//Server::getInstance()->logger->log("Need to retry read (remaining={$client->toRead})", false);
					} else {
						$buffer = zlib_decode($client->recvBuffer);
						if ($buffer === false) {
							Server::getInstance()->logger->log("Unable to decode buffer from {$client->address}");
							return false;
						}
						$packet = PacketPool::getPacket($buffer);
						if ($packet === null) {
							$len = strlen($buffer);
							Server::getInstance()->logger->log("Unable to create data from received buffer from {$client->address} (len=$len)");
							return false;
						} else {
							$retries = 0;
							$packet->decode();
							if ($packet instanceof ServerSendDataPacket) {
								$data = Server::getInstance()->dataStorage->get($packet->identifier, $client->address);
								if ($data === null) {
									Server::getInstance()->logger->log("{$packet->identifier} had a packet event, but no data was found");
									continue;
								}
								if ($packet->eventType === ServerSendDataPacket::SERVER_SEND_PACKET) {
									$pk = new BatchPacket($packet->packetBuffer);
									$pk->decode();
									// TODO: The 'if' statement below is a workaround over something that should not be happening to make Lumine work in production - find the root cause of this.
									if (!is_string($pk->payload)) {
										$pk->rewind();
										if ($pk->getByte() !== 0xfe) {
											throw new \UnexpectedValueException("PID in batch packet sent was incorrect");
										}
										$dat = $pk->getRemaining();
										$pk->payload = zlib_decode($dat); // decode data - screw the 2MB limit
										if (!is_string($pk->payload)) {
											Server::getInstance()->logger->log("Server sent a packet to {$data->authData->username}, but could not be processed");
											$data->kick("The server received a malformed packet and could not process it [code=PPERR]\nContact staff if this issue persists");
											$clear = true;
											continue;
										} else {
											Server::getInstance()->logger->log("Successfully averted batch decode misery");
										}
									}
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
									$clear = true;
									continue;
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
											$dataList = Server::getInstance()->dataStorage->find($targetPlayer);
											if (count($dataList) === 0) {
												$pk = new CommandResponsePacket();
												$pk->target = $packet->sender;
												$pk->response = TextFormat::RED . "$targetPlayer was not found on the socket server";
											} else {
												$message = "";
												$times = 1;
												foreach ($dataList as $otherData) {
													$message .= TextFormat::UNDERLINE . TextFormat::GOLD . "Server no." . TextFormat::YELLOW . $times . TextFormat::RESET . PHP_EOL;
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
												$pk = new CommandResponsePacket();
												$pk->target = $packet->sender;
												$pk->response = $message;
											}
										}
										$this->send($pk, $client->address);
										break;
									case "debug":
										$targetW = array_shift($packet->args);
										$pk = new CommandResponsePacket();
										$pk->target = $packet->sender;
										if ($targetW === null) {
											$pk->response = TextFormat::RED . "You need to specify a target player.";
										} else {
											$target = Server::getInstance()->dataStorage->find($targetW)[0] ?? null;
											if ($target === null) {
												$pk->response = TextFormat::RED . "$targetW was not found in any server connected to Lumine.";
											} else {
												$action = array_shift($packet->args);
												if ($action === null) {
													$pk->response = TextFormat::RED . "You must specify if you want to subscribe or unsubscribe to a channel.";
												} else {
													$action = strtolower($action);
													$wantedChannel = array_shift($packet->args);
													if ($wantedChannel === null) {
														$pk->response = TextFormat::RED . "You need to specify a debug channel.";
													} else {
														$channel = $target->debugHandler->getChannel($wantedChannel);
														if ($channel === null) {
															$pk->response = TextFormat::RED . "$wantedChannel is not a valid debug channel.";
														} else {
															$sub = Server::getInstance()->dataStorage->get($packet->sender, $client->address);
															if ($packet->sender === "CONSOLE") {
																$pk->response = TextFormat::RED . "You cannot run this command from the console.";
															} elseif ($sub === null) {
																$pk->response = TextFormat::DARK_RED . "CRITICAL ERROR - You were not found.";
															} elseif ($action === "subscribe" || $action === "sub") {
																$channel->subscribe($sub);
																$pk->response = TextFormat::GREEN . "You have been subscribed to the debug channel!";
															} elseif ($action === "unsubscribe" || $action === "unsub") {
																$channel->unsubscribe($sub);
																$pk->response = TextFormat::GREEN . "You have been unsubscribed from the debug channel!";
															} else {
																$pk->response = TextFormat::RED . "Invalid action.";
															}
														}
													}
												}
											}
										}
										$this->send($pk, $client->address);
										break;
								}
							}
							unset($packet);
							$clear = true;
						}
					}
				}
			}
		}
	}

}