<?php

namespace ethaniccc\Lumine\thread;

use ethaniccc\Lumine\events\ConnectionErrorEvent;
use ethaniccc\Lumine\events\HeartbeatEvent;
use ethaniccc\Lumine\events\SendErrorEvent;
use ethaniccc\Lumine\events\SocketEvent;
use ethaniccc\Lumine\events\UnknownEvent;
use ethaniccc\Lumine\packets\Packet;
use ethaniccc\Lumine\packets\PacketPool;
use ethaniccc\Lumine\Settings;
use pocketmine\network\mcpe\NetworkBinaryStream;
use pocketmine\Thread;
use pocketmine\utils\BinaryDataException;
use pocketmine\utils\BinaryStream;

final class LumineSocketThread extends Thread {

	public const TPS = 1 / 40;

	public \AttachableThreadedLogger $logger;
	public \Threaded $sendQueue;
	public \Threaded $receiveQueue;
	public Settings $settings;

	public bool $isAwaitingBuffer = false;
	public $fullBuffer = "";
	public int $toRead = 4;

	public function __construct(Settings $settings, \AttachableThreadedLogger $logger) {
		$this->setClassLoader();
		$this->logger = $logger;
		$this->sendQueue = new \Threaded();
		$this->receiveQueue = new \Threaded();
		$this->settings = $settings;
	}

	public function run(): void {
		$this->registerClassLoader();
		PacketPool::init();
		$lastReceiveTime = microtime(true);
		$tries = 0;
		/** @var Settings $serverSettings */
		$serverSettings = $this->settings->get("socket_server", new Settings([
			"address" => "127.0.0.1",
			"port" => 3001
		]));
		$sendSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
		if (!@socket_connect($sendSocket, $serverSettings->get("address", "127.0.0.1"), $serverSettings->get("port", 3001))) {
			$this->logger->error("Unable to establish initial connection to socket server - make sure it's running");
			$this->isKilled = true;
			goto end;
		}
		while (!$this->isKilled) {
			$start = microtime(true);
			$sS = microtime(true);
			retry_read:
			socket_set_nonblock($sendSocket);
			$current = @socket_read($sendSocket, $this->toRead);
			if ($current === "") {
				$error = socket_last_error($sendSocket);
				$this->logger->error("[Socket Error $error] " . socket_strerror($error));
				$this->isKilled = true;
				goto end;
			} elseif ($current !== false) {
				$lastReceiveTime = microtime(true);
				$length = strlen($current);
				if ($this->isAwaitingBuffer) {
					$this->fullBuffer .= $current;
					if ($length !== $this->toRead) {
						$this->toRead -= $length;
					} else {
						$decoded = zlib_decode($this->fullBuffer);
						if ($decoded === false) {
							$this->isKilled = true;
							goto end;
						}
						$packet = PacketPool::getPacket($decoded);
						if ($packet === null) {
							$this->logger->error("Unknown packet received from the socket server");
							$this->isKilled = true;
							goto end;
						}
						$packet->decode();
						$this->queueReceive($packet);
						$this->fullBuffer = "";
						$this->toRead = 4;
						$this->isAwaitingBuffer = false;
						goto retry_read;
					}
				} else {
					$len = strlen($current);
					$this->fullBuffer .= $current;
					if ($len !== $this->toRead) {
						$this->toRead -= $len;
						goto retry_read;
					} else {
						$unpacked = @unpack("l", $this->fullBuffer)[1];
						if ($unpacked !== false && $unpacked !== null) {
							$this->toRead = $unpacked;
							$this->isAwaitingBuffer = true;
						} else {
							$this->logger->error("Unable to unpack read length");
							$this->isKilled = true;
							goto end;
						}
						$this->fullBuffer = "";
					}
				}
			}
			// now we get everything from the send queue and send stuff to the socket server
			socket_set_block($sendSocket);
			while (($packet = $this->sendQueue->shift()) !== null) {
				/** @var Packet $packet */
				$packet->encode();
				$write = zlib_encode($packet->buffer->getBuffer(), ZLIB_ENCODING_RAW, 7);
				$buffer = pack("l", strlen($write)) . $write;
				retry_send:
				$len = strlen($buffer);
				$start = microtime(true);
				$res = @socket_write($sendSocket, $buffer, $len);
				if ($res === false) {
					$this->logger->error("Failed to write $len bytes after " . (microtime(true) - $start) . " seconds");
					$error = socket_last_error($sendSocket);
					$this->logger->info("[Socket Error $error] " . socket_strerror($error));
					$this->isKilled = true;
					goto end;
				} elseif ($res !== $len) {
					// this means that the whole buffer wasn't sent, and we need to send the remaining data
					$buffer = substr($buffer, $res);
					goto retry_send;
				}
			}
			$sendDelta = microtime(true) - $sS;
			if (microtime(true) - $lastReceiveTime >= 20 + $sendDelta) {
				if (++$tries >= 10) {
					$error = socket_last_error($sendSocket);
					$this->logger->info("[Socket Error $error] " . socket_strerror($error));
					$this->logger->error("Timed out - no heartbeats after 20 seconds");
					$this->isKilled = true;
					goto end;
				}
			} else {
				$tries = 0;
			}
			$delta = microtime(true) - $start;
			if ($delta <= self::TPS) {
				@time_sleep_until(microtime(true) + self::TPS - $delta);
			} else {
				$this->logger->debug("Thread running to slow - catching up (delta=$delta)");
			}
		}
		end:
		socket_close($sendSocket);
	}

	public function send(Packet $packet): void {
		if ($this->isKilled) {
			return;
		}
		$this->sendQueue[] = $packet;
	}

	public function queueReceive(Packet $packet): void {
		$this->receiveQueue[] = $packet;
	}

	public function receive(): \Generator {
		while (($packet = $this->receiveQueue->shift()) !== null) {
			yield $packet;
		}
	}


	public function __serialize(): array {
		return [
			"sendQueue",
			"receiveQueue",
			"settings"
		];
	}

}