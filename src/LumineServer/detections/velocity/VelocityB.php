<?php

namespace LumineServer\detections\velocity;

use ethaniccc\Lumine\data\protocol\v428\PlayerAuthInputPacket;
use LumineServer\data\UserData;
use LumineServer\detections\DetectionModule;
use pocketmine\network\mcpe\protocol\DataPacket;

final class VelocityB extends DetectionModule {

	public function __construct(UserData $data) {
		parent::__construct($data, "Velocity", "B", "Checks if the user is taking an abnormal amount of horizontal knockback");
	}

	public function run(DataPacket $packet, float $timestamp): void {
		$data = $this->data;
		if ($packet instanceof PlayerAuthInputPacket && $data->ticksSinceMotion === 1 && hypot($data->serverSentMotion->x, $data->serverSentMotion->z) > 0.01) {
			$xPct = ($data->motion->x / $data->previousServerPredictedMotion->x) * 100;
			$zPct = ($data->motion->z / $data->previousServerPredictedMotion->z) * 100;
			$min = $this->settings->get("min_pct", 99.99);
			$max = $this->settings->get("max_pct", 150);
			if (($xPct < $min && $zPct < $min) || ($xPct > $max && $zPct > $max)) {
				$this->flag([
					"xPct" => round($xPct, 5),
					"zPct" => round($zPct, 5)
				]);
			} else {
				$this->violations = max($this->violations - 0.05, 0);
			}
		}
	}

}