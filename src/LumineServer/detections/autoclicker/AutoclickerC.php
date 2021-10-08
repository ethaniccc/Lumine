<?php

namespace LumineServer\detections\autoclicker;

use LumineServer\data\UserData;
use LumineServer\detections\DetectionModule;
use LumineServer\utils\MathUtils;
use pocketmine\network\mcpe\protocol\DataPacket;

class AutoclickerC extends DetectionModule {

	private array $samples = [];

	public function __construct(UserData $data) {
		parent::__construct($data, "Autoclicker", "C", "Checks if a user has a constant and low standard deviation in their click data", true);
	}

	public function run(DataPacket $packet, float $timestamp): void {
		$data = $this->data;
		if ($data->clickData->isClicking) {
			$this->samples[] = $data->clickData->delay;
			if (count($this->samples) === 20) {
				$deviation = MathUtils::getStandardDeviation($this->samples);
				$skewness = MathUtils::getSkewness($this->samples);
				if ($deviation <= 20 && ($skewness > 1 || $skewness === 0.0) && $data->clickData->cps >= 9) {
					if ($this->buff() >= ($skewness === 0.0 ? 1 : 5)) {
						$this->flag([
							"cps" => $data->clickData->cps,
							"dv" => round($deviation, 3),
							"sk" => round($skewness, 3)
						]);
					}
				} else {
					$this->buffer = 0;
				}
				$this->samples = [];
			}
		}
	}

}