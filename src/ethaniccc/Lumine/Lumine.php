<?php

declare(strict_types=1);

namespace ethaniccc\Lumine;

use ethaniccc\Lumine\data\DataCache;
use ethaniccc\Lumine\data\protocol\v428\PlayerAuthInputPacket;
use ethaniccc\Lumine\events\InitDataEvent;
use ethaniccc\Lumine\events\ResetDataEvent;
use ethaniccc\Lumine\tasks\TickingTask;
use ethaniccc\Lumine\thread\LumineSocketThread;
use pocketmine\network\mcpe\convert\RuntimeBlockMapping;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\plugin\PluginBase;

class Lumine extends PluginBase {

	private static Lumine $instance;

	public Settings $settings;
	public LumineSocketThread $socketThread;
	public PMListener $listener;
	public TickingTask $task;
	public DataCache $cache;

	public static function getInstance(): ?self {
		return self::$instance;
	}

	public function onEnable() {
		try {
			$_ = isset(self::$instance);
		} catch (\Error $e) {
			$this->getLogger()->notice("Lumine is already enabled - please make sure you are not reloading the plugin.");
			$this->getServer()->getPluginManager()->disablePlugin($this);
		}
		self::$instance = $this;
		$reflection = new \ReflectionClass(RuntimeBlockMapping::class);
		PacketPool::registerPacket(new PlayerAuthInputPacket());
		$this->settings = new Settings($this->getConfig()->getAll());
		$this->socketThread = new LumineSocketThread($this->settings, $this->getServer()->getLogger());
		$this->socketThread->start(PTHREADS_INHERIT_NONE);
		$this->socketThread->send(new ResetDataEvent()); // reset all data when the server is started up
		$this->socketThread->send(new InitDataEvent([
			"extraData" => [
				"bedrockKnownStates" => serialize(RuntimeBlockMapping::getBedrockKnownStates()),
				"runtimeToLegacyMap" => serialize($reflection->getStaticPropertyValue("runtimeToLegacyMap")),
				"legacyToRuntimeMap" => serialize($reflection->getStaticPropertyValue("legacyToRuntimeMap")),
			]
		])); // init some data the server is going to need
		$this->listener = new PMListener();
		$this->getServer()->getPluginManager()->registerEvents($this->listener, $this);
		$this->task = new TickingTask();
		$this->getScheduler()->scheduleRepeatingTask($this->task, 1);
		$this->cache = new DataCache();
	}

}