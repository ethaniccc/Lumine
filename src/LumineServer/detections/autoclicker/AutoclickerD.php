<?php

namespace LumineServer\detections\autoclicker;

use LumineServer\data\UserData;
use LumineServer\detections\DetectionModule;
use LumineServer\utils\MathUtils;
use pocketmine\network\mcpe\protocol\DataPacket;

class AutoclickerD extends DetectionModule {

	private array $samples = [];

	public function __construct(UserData $data) {
		parent::__construct($data, "Autoclicker", "D", "Checks for an irregular clicking pattern", true);
	}

	public function run(DataPacket $packet, float $timestamp): void {
		$data = $this->data;
		if ($data->clickData->isClicking) {
			$this->samples[] = $data->clickData->delay;
			if (count($this->samples) === 20) {
				$kurtosis = MathUtils::getKurtosis($this->samples);
				$skewness = MathUtils::getSkewness($this->samples);
				$outliers = MathUtils::getOutliers($this->samples);
				$deviation = MathUtils::getStandardDeviation($this->samples);
				$this->debug("kurt=$kurtosis skew=$skewness o=$outliers dev=$deviation cps={$data->clickData->cps}");
				if ($kurtosis <= 0.05 && $skewness < 0 && $outliers === 0 && $deviation <= 25 && $data->clickData->cps >= 9) {
					if ($this->buff(1, 2) > 1) {
						$this->flag([
							"k" => round($kurtosis, 2),
							"s" => round($skewness, 2),
							"cps" => $data->clickData->cps
						]);
					}
				} else {
					$this->buff(-0.5);
				}
				$this->samples = [];
			}
		}
	}

}