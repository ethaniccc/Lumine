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
	public float $violations = 0;
	public float $buffer = 0;
	public Settings $settings;

	protected float $lastViolationTime = 0;

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

	protected function flag(array $debug = [], float $vl = 1): void {
		$this->violations += $vl;
		$debugString = "";
		if (count($debug) === 0) {
			$debugString = "NO DATA";
		} else {
			$n = count($debug);
			$i = 1;
			foreach ($debug as $name => $value) {
				$debugString .= "$name=$value";
				if ($i !== $n) {
					$debugString .= " ";
				}
				$i++;
			}
		}
		Server::getInstance()->logger->log("[{$this->data->authData->username} @ {$this->data->socketAddress}] - Flagged {$this->category} ({$this->subCategory}) (x" . var_export((float) round($this->violations, 2), true) . ") [$debugString]");
	}

	protected function buff(float $amount = 1): float {
		$this->buffer += $amount;
		$this->buffer = max(0, $this->buffer);
		$this->buffer = min($this->buffer, 5);
		return $this->buffer;
	}

	public function destroy(): void {
		$this->data = null;
	}

}