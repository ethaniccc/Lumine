<?php

namespace LumineServer\detections\velocity;

use ethaniccc\Lumine\data\protocol\v428\PlayerAuthInputPacket;
use LumineServer\data\UserData;
use LumineServer\detections\DetectionModule;
use pocketmine\network\mcpe\protocol\DataPacket;

final class VelocityA extends DetectionModule {

	private bool $shouldRun = false;

	public function __construct(UserData $data) {
		parent::__construct($data, "Velocity", "A", "Checks if the player is taking an abnormal amount of vertical velocity");
	}

	public function run(DataPacket $packet, float $timestamp): void {
		$data = $this->data;
		if ($packet instanceof PlayerAuthInputPacket) {
			if ($data->ticksSinceMotion === 1) {
				$this->shouldRun = true;
			}
			if ($data->previousServerPredictedMotion->y < 0.005 || $data->ticksSinceInClimbable < 10 || $data->ticksSinceInCobweb < 10 || $data->ticksSinceInLiquid < 10 || $data->isTeleporting) {
				$this->shouldRun = false;
			}
			if ($this->shouldRun) {
				$expected = $data->previousServerPredictedMotion->y;
				$movement = $data->motion->y;
				$percentage = ($movement / $expected) * 100;
				$this->debug("pct=$percentage%");
				if ($percentage < $this->settings->get("min_pct", 99.99) || $percentage > $this->settings->get("max_pct", 110)) {
					if ($this->buff() >= 12) {
						$this->flag([
							"pct" => round($percentage, 3) . "%"
						]);
					}
				} else {
					$this->buff(-0.05);
					$this->violations = max($this->violations - 0.025, 0);
				}
			}
		}
	}

}