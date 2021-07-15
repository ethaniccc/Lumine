<?php

declare(strict_types=1);

namespace ethaniccc\Lumine;

use ethaniccc\Lumine\tasks\TickingTask;
use ethaniccc\Lumine\thread\LumineSocketThread;
use pocketmine\plugin\PluginBase;

class Lumine extends PluginBase {

	private static Lumine $instance;

	public Settings $settings;
	public LumineSocketThread $socketThread;
	public PMListener $listener;
	public TickingTask $task;

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
		$this->settings = new Settings($this->getConfig()->getAll());
		$this->socketThread = new LumineSocketThread($this->settings, $this->getServer()->getLogger());
		$this->socketThread->start(PTHREADS_INHERIT_NONE);
		$this->listener = new PMListener();
		$this->getServer()->getPluginManager()->registerEvents($this->listener, $this);
		$this->task = new TickingTask();
		$this->getScheduler()->scheduleRepeatingTask($this->task, 1);
	}

	public function onDisable() {
		$this->socketThread->quit();
	}

}