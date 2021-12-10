<?php

namespace LumineServer\detections\autoclicker;

use LumineServer\data\UserData;
use LumineServer\detections\DetectionModule;
use pocketmine\network\mcpe\protocol\DataPacket;

final class AutoclickerA extends DetectionModule {

	public function __construct(UserData $data) {
		parent::__construct($data, "Autoclicker", "A", "Checks if the user's cps is over a threshold");
	}

	public function run(DataPacket $packet, float $timestamp): void {
		$data = $this->data;
		if ($data->clickData->isClicking) {
			$cps = $data->clickData->cps;
			$this->debug("cps=$cps");
			if ($cps >= $this->settings->get("max_cps", 23)) {
				$this->flag([
					"cps" => $cps
				]);
				if ($cps > 50) {
					$data->kick("You've been kicked to prevent any crashes to the server [code=CC1]\nContact staff if this issue persists");
				}
			} else {
				$this->violations = max($this->violations - 0.0075, 0);
			}
		}
	}

}