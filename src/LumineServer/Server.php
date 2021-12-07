<?php

namespace LumineServer;

use ethaniccc\Lumine\data\protocol\v428\PlayerAuthInputPacket;
use LumineServer\data\DataStorage;
use LumineServer\data\UserData;
use LumineServer\detections\DetectionModule;
use LumineServer\socket\SocketHandler;
use LumineServer\threads\CommandThread;
use LumineServer\threads\LoggerThread;
use LumineServer\threads\WebhookThread;
use LumineServer\utils\MCMathHelper;
use pocketmine\block\BlockFactory;
use pocketmine\entity\Attribute;
use pocketmine\item\ItemFactory;
use pocketmine\nbt\NetworkLittleEndianNBTStream;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\convert\ItemTranslator;
use pocketmine\network\mcpe\convert\ItemTypeDictionary;
use pocketmine\network\mcpe\convert\R12ToCurrentBlockMapEntry;
use pocketmine\network\mcpe\convert\RuntimeBlockMapping;
use pocketmine\network\mcpe\NetworkBinaryStream;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\network\mcpe\protocol\types\ItemTypeEntry;
use pocketmine\snooze\SleeperHandler;
use pocketmine\snooze\SleeperNotifier;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\utils\TextFormat;
use ReflectionClass;
use RuntimeException;
use UnexpectedValueException;

final class Server {

	public const TPS = 20;
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

	public function __construct() {
		self::$instance = $this;
		@mkdir("logs");
		@mkdir("resources");
		$this->logger = new LoggerThread("logs/server.log");
		$this->webhookThread = new WebhookThread();
		$this->dataStorage = new DataStorage();
		$this->tickSleeper = new SleeperHandler();
		$this->settings = new Settings(yaml_parse(file_get_contents("./resources/config/config.yml")));
		$this->socketHandler = new SocketHandler($this->settings->get("server_port", 3001));
	}

	public static function getInstance(): self {
		return self::$instance;
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
						$this->settings = new Settings(yaml_parse(file_get_contents("./resources/config/config.yml")));
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

		Attribute::init();
		PacketPool::init();
		PacketPool::registerPacket(new PlayerAuthInputPacket());
		\LumineServer\socket\packets\PacketPool::init();
		ItemFactory::init();
		BlockFactory::init();
		//RuntimeBlockMapping::init();
		$this->setupRuntimeBlockMapping();
		//ItemTypeDictionary::getInstance();
		$this->setupItemTypeDictionary();
		//ItemTranslator::getInstance();
		$this->setupItemTranslator();
		//BlockFactory::registerBlock(new WoodenFenceOverride(), true);
		DetectionModule::init();
		MCMathHelper::init();
		$this->logger->log("Initialized data");
		ini_set("memory_limit", $this->settings->get("memory_limit", "1024M"));
		$time = round(microtime(true) - $start, 4);
		$this->logger->log("Lumine server has started in $time seconds");

		while ($this->running) {
			try {
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
			} catch (\Error|\Exception $e) {
				$this->logger->log("The socket server had an error while ticking - a shutdown will be preformed");
				$this->logger->log($e->getMessage() . PHP_EOL . $e->getTraceAsString());
				break;
			}
		}
		echo "Shutting down the Lumine server...\n";
		$this->socketHandler->shutdown();
		$this->logger->quit();
		$this->console->quit();
		$this->webhookThread->quit();
	}

	public function shutdown(): void {
		$this->running = false;
	}

	private function setupRuntimeBlockMapping(): void {
		$reflection = new ReflectionClass(RuntimeBlockMapping::class);
		$canonicalBlockStatesFile = file_get_contents("resources/canonical_block_states.nbt");
		if ($canonicalBlockStatesFile === false) {
			throw new AssumptionFailedError("Missing required resource file");
		}
		$stream = new NetworkBinaryStream($canonicalBlockStatesFile);
		$list = [];
		while (!$stream->feof()) {
			$list[] = $stream->getNbtCompoundRoot();
		}

		$legacyIdMap = json_decode(file_get_contents("resources/block_id_map.json"), true);
		/** @var R12ToCurrentBlockMapEntry[] $legacyStateMap */
		$legacyStateMap = [];
		$legacyStateMapReader = new NetworkBinaryStream(file_get_contents("resources/r12_to_current_block_map.bin"));
		$nbtReader = new NetworkLittleEndianNBTStream();
		while (!$legacyStateMapReader->feof()) {
			$id = $legacyStateMapReader->getString();
			$meta = $legacyStateMapReader->getLShort();

			$offset = $legacyStateMapReader->getOffset();
			$state = $nbtReader->read($legacyStateMapReader->getBuffer(), false, $offset);
			$legacyStateMapReader->setOffset($offset);
			if (!($state instanceof CompoundTag)) {
				throw new RuntimeException("Blockstate should be a TAG_Compound");
			}
			$legacyStateMap[] = new R12ToCurrentBlockMapEntry($id, $meta, $state);
		}

		/**
		 * @var int[][] $idToStatesMap string id -> int[] list of candidate state indices
		 */
		$idToStatesMap = [];
		foreach ($list as $k => $state) {
			$idToStatesMap[$state->getString("name")][] = $k;
		}
		$legacyToRuntimeMap = [];
		$runtimeToLegacyMap = [];
		foreach ($legacyStateMap as $pair) {
			$id = $legacyIdMap[$pair->getId()] ?? null;
			if ($id === null) {
				throw new RuntimeException("No legacy ID matches " . $pair->getId());
			}
			$data = $pair->getMeta();
			if ($data > 15) {
				//we can't handle metadata with more than 4 bits
				continue;
			}
			$mappedState = $pair->getBlockState();

			//TODO HACK: idiotic NBT compare behaviour on 3.x compares keys which are stored by values
			$mappedState->setName("");
			$mappedName = $mappedState->getString("name");
			if (!isset($idToStatesMap[$mappedName])) {
				throw new RuntimeException("Mapped new state does not appear in network table");
			}
			foreach ($idToStatesMap[$mappedName] as $k) {
				$networkState = $list[$k];
				if ($mappedState->equals($networkState)) {
					$legacyToRuntimeMap[($id << 4) | $data] = $k;
					$runtimeToLegacyMap[$k] = ($id << 4) | $data;
					continue 2;
				}
			}
			throw new RuntimeException("Mapped new state does not appear in network table");
		}
		$reflection->setStaticPropertyValue("bedrockKnownStates", $list);
		$reflection->setStaticPropertyValue("runtimeToLegacyMap", $runtimeToLegacyMap);
		$reflection->setStaticPropertyValue("legacyToRuntimeMap", $legacyToRuntimeMap);
	}

	private function setupItemTypeDictionary(): void {
		$data = file_get_contents('resources/required_item_list.json');
		if ($data === false) throw new AssumptionFailedError("Missing required resource file");
		$table = json_decode($data, true);
		if (!is_array($table)) {
			throw new AssumptionFailedError("Invalid item list format");
		}

		$params = [];
		foreach ($table as $name => $entry) {
			if (!is_array($entry) || !is_string($name) || !isset($entry["component_based"], $entry["runtime_id"]) || !is_bool($entry["component_based"]) || !is_int($entry["runtime_id"])) {
				throw new AssumptionFailedError("Invalid item list format");
			}
			$params[] = new ItemTypeEntry($name, $entry["runtime_id"], $entry["component_based"]);
		}
		ItemTypeDictionary::setInstance(new ItemTypeDictionary($params));
	}

	private function setupItemTranslator(): void {
		$data = file_get_contents('resources/r16_to_current_item_map.json');
		if ($data === false) throw new AssumptionFailedError("Missing required resource file");
		$json = json_decode($data, true);
		if (!is_array($json) or !isset($json["simple"], $json["complex"]) || !is_array($json["simple"]) || !is_array($json["complex"])) {
			throw new AssumptionFailedError("Invalid item table format");
		}

		$legacyStringToIntMapRaw = file_get_contents('resources/item_id_map.json');
		if ($legacyStringToIntMapRaw === false) {
			throw new AssumptionFailedError("Missing required resource file");
		}
		$legacyStringToIntMap = json_decode($legacyStringToIntMapRaw, true);
		if (!is_array($legacyStringToIntMap)) {
			throw new AssumptionFailedError("Invalid mapping table format");
		}

		/** @phpstan-var array<string, int> $simpleMappings */
		$simpleMappings = [];
		foreach ($json["simple"] as $oldId => $newId) {
			if (!is_string($oldId) || !is_string($newId)) {
				throw new AssumptionFailedError("Invalid item table format");
			}
			if (!isset($legacyStringToIntMap[$oldId])) {
				//new item without a fixed legacy ID - we can't handle this right now
				continue;
			}
			$simpleMappings[$newId] = $legacyStringToIntMap[$oldId];
		}
		foreach ($legacyStringToIntMap as $stringId => $intId) {
			if (isset($simpleMappings[$stringId])) {
				throw new UnexpectedValueException("Old ID $stringId collides with new ID");
			}
			$simpleMappings[$stringId] = $intId;
		}

		/** @phpstan-var array<string, array{int, int}> $complexMappings */
		$complexMappings = [];
		foreach ($json["complex"] as $oldId => $map) {
			if (!is_string($oldId) || !is_array($map)) {
				throw new AssumptionFailedError("Invalid item table format");
			}
			foreach ($map as $meta => $newId) {
				if (!is_numeric($meta) || !is_string($newId)) {
					throw new AssumptionFailedError("Invalid item table format");
				}
				$complexMappings[$newId] = [$legacyStringToIntMap[$oldId], (int)$meta];
			}
		}

		ItemTranslator::setInstance(new ItemTranslator(ItemTypeDictionary::getInstance(), $simpleMappings, $complexMappings));
	}

	public function getLuminePrefix(): string {
		return $this->settings->get("prefix", "§l§8[§dL§6u§em§bi§5n§de§8]") . TextFormat::RESET;
	}

}