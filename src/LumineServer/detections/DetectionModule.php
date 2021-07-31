<?php

namespace LumineServer\detections;

use LumineServer\data\UserData;
use LumineServer\Server;
use LumineServer\Settings;
use pocketmine\network\mcpe\protocol\DataPacket;

abstract class DetectionModule {

	public static Settings $globalSettings;

	public static function init(): void {
		self::$globalSettings = Server::getInstance()->settings->get("detections", new Settings([
		]));
	}

	public ?UserData $data;

	public string $category;
	public string $subCategory;
	public string $description;
	public bool $experimental;
	public bool $enabled;
	public float $buffer = 0;
	public Settings $settings;

	public function __construct(UserData $data, $category, string $subCategory, string $description, bool $experimental = false) {
		$this->data = $data;

		$this->category = $category;
		$this->subCategory = $subCategory;
		$this->description = $description;
		$this->experimental = $experimental;
		$categorySettings = self::$globalSettings->get($category);
		if (!$categorySettings instanceof Settings) {
			$this->enabled = true;
			$this->settings = new Settings([]);
			Server::getInstance()->logger->log("Settings were not found for the $category detections", false);
		} else {
			$this->settings = $categorySettings->get($subCategory, new Settings([
			]));
			$this->enabled = $this->settings->get("enabled", true);
		}
	}

	public abstract function run(DataPacket $packet): void;

	public function buff(float $amount = 1): float {
		$this->buffer += $amount;
		$this->buffer = max(0, $this->buffer);
		return $this->buffer;
	}

	public function destroy(): void {
		$this->data = null;
	}

}