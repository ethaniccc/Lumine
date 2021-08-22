<?php

namespace LumineServer\detections\autoclicker;

use LumineServer\data\UserData;
use LumineServer\detections\DetectionModule;
use pocketmine\network\mcpe\protocol\DataPacket;

final class AutoclickerA extends DetectionModule {

	public function __construct(UserData $data) {
		parent::__construct($data, "Autoclicker", "A", "Checks if the user's cps is over a threshold");
	}

	public function run(DataPacket $packet): void {
		$data = $this->data;
		if ($data->clickData->isClicking) {
			$cps = $data->clickData->cps;
			if ($cps >= $this->settings->get("max_cps", 23)) {
				$this->flag([
					"cps" => $cps
				]);
			} else {
				$this->violations = max($this->violations - 0.0075, 0);
			}
		}
	}

}