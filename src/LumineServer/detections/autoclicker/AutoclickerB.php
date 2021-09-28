<?php

namespace LumineServer\detections\autoclicker;

use LumineServer\data\UserData;
use LumineServer\detections\DetectionModule;
use LumineServer\utils\MathUtils;
use pocketmine\network\mcpe\protocol\DataPacket;

class AutoclickerB extends DetectionModule {

	private array $samples = [];

	public function __construct(UserData $data) {
		parent::__construct($data, "Autoclicker", "B", "Checks if the user is clicking above 16 cps with no double clicks");
	}

	public function run(DataPacket $packet, float $timestamp): void {
		$data = $this->data;
		if ($data->clickData->isClicking) {
			$this->samples[] = $data->clickData->delay;
			if (count($this->samples) === 20) {
				if (!in_array(0, $this->samples, true) && $data->clickData->cps >= 16) {
					$this->flag([
						"cps" => $data->clickData->cps
					]);
				}
				/* $kurtosis = MathUtils::getKurtosis($this->samples);
				$skewness = MathUtils::getSkewness($this->samples);
				$average = MathUtils::getAverage($this->samples);
				$outliers = MathUtils::getOutliers($this->samples);
				$deviation = MathUtils::getStandardDeviation($this->samples);
				$data->message("({$data->currentTick}) k=$kurtosis s=$skewness avg=$average o=$outliers dev=$deviation"); */
				$this->samples = [];
			}
		}
	}

}