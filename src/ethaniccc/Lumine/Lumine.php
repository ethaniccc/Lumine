<?php

declare(strict_types=1);

namespace ethaniccc\Lumine;

use ethaniccc\Lumine\commands\LumineCommand;
use ethaniccc\Lumine\data\DataCache;
use ethaniccc\Lumine\data\protocol\v428\PlayerAuthInputPacket;
use ethaniccc\Lumine\tasks\TickingTask;
use ethaniccc\Lumine\thread\LumineSocketThread;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\plugin\PluginBase;

class Lumine extends PluginBase {

	private static Lumine $instance;

	public Settings $settings;
	public LumineSocketThread $socketThread;
	public PMListener $listener;
	public TickingTask $task;
	public DataCache $cache;
	public bool $hasDisconnected = false;

	/** @var int[] */
	public array $alertCooldowns = [];
	/** @var float[] */
	public array $lastAlertTimes = [];

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
		\ethaniccc\Lumine\packets\PacketPool::init();
		PacketPool::registerPacket(new PlayerAuthInputPacket());
		$this->settings = new Settings($this->getConfig()->getAll());
		$this->socketThread = new LumineSocketThread($this->settings, $this->getServer()->getLogger());
		$this->socketThread->start(PTHREADS_INHERIT_NONE);
		$this->listener = new PMListener();
		$this->getServer()->getPluginManager()->registerEvents($this->listener, $this);
		$this->task = new TickingTask();
		$this->getScheduler()->scheduleRepeatingTask($this->task, 1);
		$this->cache = new DataCache();
		$this->getServer()->getCommandMap()->register($this->getName(), new LumineCommand());
	}

	public function onDisable() {
		if ($this->hasDisconnected) {
			file_put_contents($this->getDataFolder() . "reconnect", microtime(true) + 1);
		}
	}

}