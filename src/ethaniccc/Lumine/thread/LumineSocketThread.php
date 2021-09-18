<?php

namespace ethaniccc\Lumine\thread;

use AttachableThreadedLogger;
use ethaniccc\Lumine\events\ConnectionErrorEvent;
use ethaniccc\Lumine\events\SendErrorEvent;
use ethaniccc\Lumine\events\SocketEvent;
use ethaniccc\Lumine\Settings;
use Generator;
use pocketmine\thread\Thread;
use Threaded;
use function igbinary_serialize;
use function igbinary_unserialize;
use function microtime;
use function pack;
use function socket_close;
use function socket_connect;
use function socket_create;
use function socket_last_error;
use function socket_read;
use function socket_set_block;
use function socket_set_nonblock;
use function socket_strerror;
use function socket_write;
use function strlen;
use function substr;
use function time_sleep_until;
use function unpack;
use function zlib_decode;
use function zlib_encode;

final class LumineSocketThread extends Thread {

	public const TPS = 1 / 40;

	public AttachableThreadedLogger $logger;
	public Threaded $sendQueue;
	public Threaded $receiveQueue;
	public Settings $settings;

	public bool $isAwaitingBuffer = false;
	public string $fullBuffer = "";
	public int $toRead = 4;

	public function __construct(Settings $settings, AttachableThreadedLogger $logger) {
		$this->setClassLoaders();
		$this->logger = $logger;
		$this->sendQueue = new Threaded();
		$this->receiveQueue = new Threaded();
		$this->settings = $settings;
	}

	public function onRun(): void {
		$this->registerClassLoaders();
		$lastReceiveTime = microtime(true);
		$tries = 0;
		/** @var Settings $serverSettings */
		$serverSettings = $this->settings->get("socket_server", new Settings([
			"address" => "127.0.0.1",
			"port" => 3001
		]));
		$sendSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
		if (!socket_connect($sendSocket, $serverSettings->get("address", "127.0.0.1"), $serverSettings->get("port", 3001))) {
			$this->queueReceive(new ConnectionErrorEvent(["message" => "Unable to establish first connection to the server. Check that the server is online."]));
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
				$this->logger->info("[$error] " . socket_strerror($error));
				$this->queueReceive(new ConnectionErrorEvent([
					"message" => "Unable to read buffer from the external socket server (check if the server is still running)"
				]));
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
						$zlibD = zlib_decode($this->fullBuffer);
						if ($zlibD === false) {
							$this->queueReceive(new ConnectionErrorEvent([
								"message" => "Unable to decode data"
							]));
							$this->isKilled = true;
							goto end;
						}
						$decoded = igbinary_unserialize($zlibD);
						if ($decoded === false || $decoded === null) {
							$this->queueReceive(new ConnectionErrorEvent([
								"message" => "Unable to binary un-serialize data"
							]));
							$this->isKilled = true;
							goto end;
						}
						$event = SocketEvent::get($decoded);
						$this->queueReceive($event);
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
							$this->logger->info("Unable to unpack read length");
							goto end;
						}
						$this->fullBuffer = "";
					}
				}
			}
			// now we get everything from the send queue and send stuff to the socket server
			socket_set_block($sendSocket);
			while (($event = $this->sendQueue->shift()) !== null) {
				$event = igbinary_unserialize($event);
				$data = (array) $event;
				$data["name"] = $event::NAME;
				$write = zlib_encode(igbinary_serialize($data), ZLIB_ENCODING_RAW, 7);
				$buffer = pack("l", strlen($write)) . $write;
				retry_send:
				$len = strlen($buffer);
				$start = microtime(true);
				$res = @socket_write($sendSocket, $buffer, $len);
				if ($res === false) {
					$this->logger->info("Failed to write $len bytes after " . (microtime(true) - $start) . " seconds");
					$error = socket_last_error($sendSocket);
					$this->logger->info("[$error] " . socket_strerror($error));
					$this->queueReceive(new SendErrorEvent());
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
					$this->logger->info("[$error] " . socket_strerror($error));
					$this->queueReceive(new ConnectionErrorEvent([
						"message" => "Timed out, received no heartbeats from the server after 20 seconds"
					]));
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

	public function send(SocketEvent $event): void {
		if ($this->isKilled) {
			return;
		}
		$this->sendQueue[] = igbinary_serialize($event);
	}

	public function queueReceive(SocketEvent $event): void {
		$this->receiveQueue[] = $event;
	}

	public function receive(): Generator {
		while (($event = $this->receiveQueue->shift()) !== null) {
			yield $event;
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