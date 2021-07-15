<?php

namespace ethaniccc\Lumine\thread;

use ethaniccc\Lumine\events\ConnectionErrorEvent;
use ethaniccc\Lumine\events\SendErrorEvent;
use ethaniccc\Lumine\events\SocketEvent;
use ethaniccc\Lumine\Settings;
use pocketmine\Thread;

final class LumineSocketThread extends Thread {

	public const SEPARATOR = "\0";
	public const TPS = 1 / 50;

	public \AttachableThreadedLogger $logger;
	public \Threaded $sendQueue;
	public \Threaded $receiveQueue;
	public Settings $settings;

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
		$sendSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
		/** @var Settings $serverSettings */
		$serverSettings = $this->settings->get("socket_server", new Settings([
			"address" => "127.0.0.1",
			"port" => 3001
		]));
		if (!@socket_connect($sendSocket, $serverSettings->get("address", "0.0.0.0"), $serverSettings->get("port", 3001))) {
			$this->queueReceive(new ConnectionErrorEvent(["message" => "Unable to establish first connection to the server. Check that the server is online."]));
			return;
		}
		while (!$this->isKilled) {
			$start = microtime(true);
			// first, we read from the receive socket, then go on to sending stuff from the sendQueue
			socket_set_nonblock($sendSocket);
			$buffer = "";
			$hasBuffer = true;
			while (true) {
				$current = @socket_read($sendSocket, 1024);
				if ($current === false) { // the buffer was empty, the PMMP server has not received anything from the socket server
					if ($buffer !== "") {
						exit("Unexpected non-empty current buffer");
					}
					$hasBuffer = false;
					break;
				}
				$buffer .= $current;
				if (strlen($buffer) % 1024 !== 0 || $current === "") {
					break;
				}
			}
			if ($hasBuffer) {
				if ($buffer === "") {
					$this->queueReceive(new ConnectionErrorEvent([
						"message" => "Unable to read buffer from the external socket server (check if the server is still running)"
					]));
					return;
				}
			}
			// now we get everything from the send queue and send stuff to the socket server
			socket_set_block($sendSocket);
			while (($event = $this->sendQueue->shift()) !== null) {
				$data = (array) $event;
				$data["name"] = $event::NAME;
				if (@socket_write($sendSocket, json_encode($data) . self::SEPARATOR) === false) {
					$this->queueReceive(new SendErrorEvent());
				}
			}
			$delta = microtime(true) - $start;
			if ($delta <= self::TPS) {
				@time_sleep_until(microtime(true) + self::TPS - $delta);
			} else {
				$this->logger->debug("Thread running to slow - catching up (delta=$delta)");
			}
		}
		socket_close($sendSocket);
	}

	public function send(SocketEvent $event): void {
		$this->sendQueue[] = $event;
		$this->notify();
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