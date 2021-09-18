<?php

declare(strict_types=1);

namespace ethaniccc\Lumine;

use ethaniccc\Lumine\commands\LumineCommand;
use ethaniccc\Lumine\data\DataCache;
use ethaniccc\Lumine\data\protocol\v428\PlayerAuthInputPacket;
use ethaniccc\Lumine\events\InitDataEvent;
use ethaniccc\Lumine\tasks\TickingTask;
use ethaniccc\Lumine\thread\LumineSocketThread;
use pocketmine\network\mcpe\convert\GlobalItemTypeDictionary;
use pocketmine\network\mcpe\convert\ItemTranslator;
use pocketmine\network\mcpe\convert\RuntimeBlockMapping;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\plugin\PluginBase;
use ReflectionException;
use ReflectionProperty;
use function serialize;

class Lumine extends PluginBase {

	private static Lumine $instance;

	public Settings $settings;
	public LumineSocketThread $socketThread;
	public PMListener $listener;
	public TickingTask $task;
	public DataCache $cache;

	/** @var int[] */
	public array $alertCooldowns = [];
	/** @var float[] */
	public array $lastAlertTimes = [];

	public static function getInstance(): ?self {
		return self::$instance;
	}

	/**
	 * @throws ReflectionException
	 */
	public function onEnable() : void {
		self::$instance = $this;
		$rtl = new ReflectionProperty(RuntimeBlockMapping::getInstance(), 'runtimeToLegacyMap');
		$rtl->setAccessible(true);
		$ltr = new ReflectionProperty(RuntimeBlockMapping::getInstance(), 'legacyToRuntimeMap');
		$ltr->setAccessible(true);

		PacketPool::getInstance()->registerPacket(new PlayerAuthInputPacket());
		$this->settings = new Settings($this->getConfig()->getAll());
		$this->socketThread = new LumineSocketThread($this->settings, $this->getServer()->getLogger());
		$this->socketThread->start();
		$this->socketThread->send(new InitDataEvent([
			"extraData" => [
				"bedrockKnownStates" => serialize(RuntimeBlockMapping::getInstance()->getBedrockKnownStates()),
				"runtimeToLegacyMap" => serialize($rtl->getValue(RuntimeBlockMapping::getInstance())),
				"legacyToRuntimeMap" => serialize($ltr->getValue(RuntimeBlockMapping::getInstance())),
				"itemTranslator" => serialize(ItemTranslator::getInstance()),
				"itemDictionary" => serialize(GlobalItemTypeDictionary::getInstance()->getDictionary()),
			]
		])); // init some data the server is going to need
		$this->listener = new PMListener();
		$this->getServer()->getPluginManager()->registerEvents($this->listener, $this);
		$this->task = new TickingTask();
		$this->getScheduler()->scheduleRepeatingTask($this->task, 1);
		$this->cache = new DataCache();
		$this->getServer()->getCommandMap()->register($this->getName(), new LumineCommand());
	}

}