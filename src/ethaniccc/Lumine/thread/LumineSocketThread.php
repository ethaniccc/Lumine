<?php

namespace ethaniccc\Lumine\thread;

use ethaniccc\Lumine\events\ConnectionErrorEvent;
use ethaniccc\Lumine\events\HeartbeatEvent;
use ethaniccc\Lumine\events\SendErrorEvent;
use ethaniccc\Lumine\events\SocketEvent;
use ethaniccc\Lumine\events\UnknownEvent;
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
		$lastReceiveTime = microtime(true);
		/** @var Settings $serverSettings */
		$serverSettings = $this->settings->get("socket_server", new Settings([
			"address" => "127.0.0.1",
			"port" => 3001
		]));
		$sendSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
		if (!@socket_connect($sendSocket, $serverSettings->get("address", "127.0.0.1"), $serverSettings->get("port", 3001))) {
			$this->queueReceive(new ConnectionErrorEvent(["message" => "Unable to establish first connection to the server. Check that the server is online."]));
			return;
		}
		while (!$this->isKilled) {
			$start = microtime(true);
			socket_set_nonblock($sendSocket);
			$buffer = "";
			retry_read:
			$current = @socket_read($sendSocket, $this->toRead);
			if ($current === "") {
				$this->queueReceive(new ConnectionErrorEvent([
					"message" => "Unable to read buffer from the external socket server (check if the server is still running)"
				]));
				return;
			} elseif ($current !== false) {
				$lastReceiveTime = microtime(true);
				$length = strlen($current);
				if ($this->isAwaitingBuffer) {
					$buffer .= $current;
					if ($length !== $this->toRead) {
						$this->toRead -= $length;
					} else {
						$decoded = json_decode($buffer, true);
						if ($decoded === false) {
							$this->queueReceive(new ConnectionErrorEvent([
								"message" => "Unable to decode JSON data"
							]));
							goto end;
						}
						$event = SocketEvent::get($decoded);
						$this->queueReceive($event);
						$buffer = "";
						$this->toRead = 4;
						$this->isAwaitingBuffer = false;
						goto retry_read;
					}
				} else {
					$unpacked = unpack("l", $current)[1];
					$this->toRead = $unpacked;
					$this->isAwaitingBuffer = true;
					goto retry_read;
				}
			}
			// now we get everything from the send queue and send stuff to the socket server
			socket_set_block($sendSocket);
			/** @var SocketEvent $event */
			while (($event = $this->sendQueue->shift()) !== null) {
				$data = (array) $event;
				$data["name"] = $event::NAME;
				foreach ($data as $key => $value) {
					if (is_object($value)) {
						$data[$key] = base64_encode(serialize($value));
					}
				}
				$write = json_encode($data);
				$res = @socket_write($sendSocket, pack("l", strlen($write)) . $write);
				if ($res === false) {
					$this->queueReceive(new SendErrorEvent());
				}
			}
			if (microtime(true) - $lastReceiveTime >= 10) {
				$this->queueReceive(new ConnectionErrorEvent([
					"message" => "Timed out, received no heartbeats from the server after 10 seconds"
				]));
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
		$this->sendQueue[] = $event;
	}

	public function queueReceive(SocketEvent $event): void {
		$this->receiveQueue[] = $event;
	}

	public function receive(): \Generator {
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