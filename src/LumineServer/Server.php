<?php

namespace LumineServer;

use LumineServer\blocks\WoodenFenceOverride;
use LumineServer\data\DataStorage;
use LumineServer\data\UserData;
use LumineServer\detections\DetectionModule;
use LumineServer\socket\SocketHandler;
use LumineServer\threads\CommandThread;
use LumineServer\threads\LoggerThread;
use LumineServer\threads\WebhookThread;
use LumineServer\utils\MCMathHelper;
use pocketmine\block\BlockFactory;
use pocketmine\item\ItemFactory;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\snooze\SleeperHandler;
use pocketmine\snooze\SleeperNotifier;
use pocketmine\utils\TextFormat;

final class Server {

	private static Server $instance;

	public SocketHandler $socketHandler;
	public SleeperHandler $tickSleeper;
	public CommandThread $console;
	public LoggerThread $logger;
	public WebhookThread $webhookThread;
	public DataStorage $dataStorage;
	public Settings $settings;
	public bool $running = true;
	public bool $performanceLogging = false;
	public int $currentTick = 0;
	public float $currentTPS = self::TPS;

	public const TPS = 20;

	public static function getInstance(): self {
		return self::$instance;
	}

	public function __construct() {
		self::$instance = $this;
		@mkdir("logs");
		@mkdir("resources");
		$this->logger = new LoggerThread("logs/server.log");
		$this->webhookThread = new WebhookThread();
		$this->dataStorage = new DataStorage();
		$this->tickSleeper = new SleeperHandler();
		$this->settings = new Settings(yaml_parse(file_get_contents("./resources/config.yml")));
		$this->socketHandler = new SocketHandler($this->settings->get("server_port", 3001));
	}

	public function run(): void {
		$start = microtime(true);
		$this->logger->start(PTHREADS_INHERIT_NONE);
		$this->logger->log("Started up logger thread");

		$consoleNotifier = new SleeperNotifier();
		$this->console = new CommandThread($consoleNotifier);
		$this->tickSleeper->addNotifier($consoleNotifier, function (): void {
			while (($line = $this->console->getLine()) !== null) {
				$args = explode(" ", $line);
				$command = array_shift($args);
				switch ($command) {
					case "stop":
						$this->shutdown();
						break;
					case "status":
						$this->logger->log("Server status:");
						$this->logger->log("TPS=" . round($this->currentTPS, 2));
						$this->logger->log("Memory usage=" . round(memory_get_usage() / 1e+6, 4) . "MB");
						break;
					case "reloadconfig":
						unset($this->settings);
						$this->settings = new Settings(yaml_parse(file_get_contents("./resources/config.yml")));
						DetectionModule::init();
						foreach ($this->dataStorage->getAll() as $queue) {
							foreach ($queue as $data) {
								/** @var UserData $data */
								foreach ($data->detections as $detection) {
									$detection->reloadSettings();
								}
							}
						}
						$this->logger->log("Config settings were reloaded");
						break;
					case "logperf":
						$sub = $args[0] ?? null;
						switch ($sub) {
							case null:
								$this->logger->log("No arguments supplied for the performance logging command", false);
								break;
							case "on":
							case "enable":
								$this->performanceLogging = true;
								$this->logger->log("Performance logging has been enabled");
								break;
							case "off":
							case "disable":
								$this->performanceLogging = false;
								$this->logger->log("Performance logging has been disabled");
								break;
						}
						break;
					case "clear":
						echo str_repeat(PHP_EOL, 50);
						break;
				}
			}
		});
		$this->console->start(PTHREADS_INHERIT_NONE);
		$this->logger->log("Command input thread started");

		$this->webhookThread->start(PTHREADS_INHERIT_NONE);
		$this->logger->log("Webhook thread was started");

		$this->socketHandler->start();
		$this->logger->log("Socket handler started");

		PacketPool::init();
		ItemFactory::init();
		BlockFactory::init();
		//BlockFactory::registerBlock(new WoodenFenceOverride(), true);
		DetectionModule::init();
		MCMathHelper::init();
		$this->logger->log("Initialized needed data");
		ini_set("memory_limit", $this->settings->get("memory_limit", "1024M"));
		$time = round(microtime(true) - $start, 4);
		$this->logger->log("Lumine server has started in $time seconds");

		while ($this->running) {
			++$this->currentTick;
			$start = microtime(true);
			$this->socketHandler->tick();
			$delta = microtime(true) - $start;
			$this->currentTPS = min(self::TPS, 1 / max(0.0001, $delta));
			if ($this->performanceLogging) {
				$cpu = function_exists("sys_getloadavg") ? sys_getloadavg()[0] : "N/A";
				$memory = round(memory_get_usage() / 1e+6, 4) . "MB";
				$load = round($delta / (1 / $this->currentTPS), 5) * 100;
				$this->logger->log("CPU=$cpu% Memory=$memory Load=$load%", false);
			}
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
		$this->webhookThread->quit();
		exit("Terminated.");
	}

	public function getLuminePrefix(): string {
		return $this->settings->get("prefix", "§l§8[§dL§6u§em§bi§5n§de§8]") . TextFormat::RESET;
	}

	public function shutdown(): void {
		$this->running = false;
	}

}