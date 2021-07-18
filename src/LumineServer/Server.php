<?php

namespace LumineServer;

use LumineServer\data\DataStorage;
use LumineServer\socket\SocketHandler;
use LumineServer\threads\CommandThread;
use LumineServer\threads\LoggerThread;
use pocketmine\block\BlockFactory;
use pocketmine\item\ItemFactory;
use pocketmine\network\mcpe\convert\RuntimeBlockMapping;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\snooze\SleeperHandler;
use pocketmine\snooze\SleeperNotifier;

final class Server {

	private static Server $instance;

	public SocketHandler $socketHandler;
	public SleeperHandler $tickSleeper;
	public CommandThread $console;
	public LoggerThread $logger;
	public DataStorage $dataStorage;
	public Settings $settings;
	public bool $running = true;
	public int $currentTick = 0;
	public float $currentTPS = self::TPS;

	public const TPS = 40;

	public static function getInstance(): self {
		return self::$instance;
	}

	public function __construct() {
		self::$instance = $this;
		@mkdir("logs");
		@mkdir("resources");
		$this->logger = new LoggerThread("logs/server.log");
		$this->dataStorage = new DataStorage();
		$this->tickSleeper = new SleeperHandler();
		$this->settings = new Settings(yaml_parse(file_get_contents("./resources/config.yml")));
		$this->socketHandler = new SocketHandler($this->settings->get("server_port", 3001));
	}

	public function run(): void {
		$this->logger->start(PTHREADS_INHERIT_NONE);

		$consoleNotifier = new SleeperNotifier();
		$this->console = new CommandThread($consoleNotifier);
		$this->tickSleeper->addNotifier($consoleNotifier, function (): void {
			while (($line = $this->console->getLine()) !== null) {
				switch ($line) {
					case "stop":
						$this->shutdown();
						break;
					case "status":
						$this->logger->log("Server status:");
						$this->logger->log("TPS=" . round($this->currentTPS, 2));
						$this->logger->log("Memory usage=" . round(memory_get_usage() / 1e+6, 4) . "MB");
						break;
					case "clear":
						echo str_repeat(PHP_EOL, 50);
						break;
				}
			}
		});
		$this->console->start(PTHREADS_INHERIT_NONE);
		$this->socketHandler->start();
		PacketPool::init();
		ItemFactory::init();
		BlockFactory::init();
		ini_set("memory_limit", $this->settings->get("memory_limit", "1024M"));
		$this->logger->log("Lumine server has started");

		while ($this->running) {
			++$this->currentTick;
			$start = microtime(true);
			$this->socketHandler->tick();
			$delta = microtime(true) - $start;
			$this->currentTPS = min(self::TPS, 1 / max(0.0001, $delta));
			if ($delta <= 1 / self::TPS) {
				$this->tickSleeper->sleepUntil(microtime(true) + (1 / self::TPS) - $delta);
			} else {
				$this->logger->log("Server running slower than normal (delta=$delta) - not sleeping to catch up");
			}
		}
		echo "Shutting down the Lumine server...\n";
		$this->socketHandler->shutdown();
		$this->logger->quit();
		$this->console->quit();
		exit("Terminated.");
	}

	public function shutdown(): void {
		$this->running = false;
	}

}