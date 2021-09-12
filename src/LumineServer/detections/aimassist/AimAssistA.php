<?php

namespace LumineServer\detections\aimassist;

use ethaniccc\Lumine\data\protocol\v428\PlayerAuthInputPacket;
use LumineServer\data\UserData;
use LumineServer\detections\DetectionModule;
use LumineServer\utils\MathUtils;
use pocketmine\network\mcpe\protocol\DataPacket;
use pocketmine\network\mcpe\protocol\InventoryTransactionPacket;
use pocketmine\network\mcpe\protocol\types\inventory\UseItemOnEntityTransactionData;

/**
 * This is an aim check that checks for positive correlation with the player's rotations and expected aimbot rotations.
 * The correlation is checked using the correlation coefficient between two arrays, one containing the actual rotation of the
 * player and one containing the rotation of a lock-in aimbot. The correlation coefficient is a statistical measure to check the correlation
 * between two measures (graphs). You can learn more about it here: https://www.investopedia.com/terms/c/correlationcoefficient.asp
 * In short, what this check does is check if there's a significant positive correlation between the actual rotations
 * and the expected rotations of an aimbot. The correlation coefficient is always between -1 and 1 - however we
 * are looking for positive correlation, not negative correlation.
 */
final class AimAssistA extends DetectionModule {

	private int $target = -1;
	private bool $waiting = false;
	private array $actualRotationSamples = [];
	private array $aimbotRotationSamples = [];

	public function __construct(UserData $data) {
		parent::__construct($data, "AimAssist", "A", "Checks the correlation coefficient between expected aimbot rotation values and actual rotation values");
	}

	public function run(DataPacket $packet, float $timestamp): void {
		$data = $this->data;
		if ($packet instanceof InventoryTransactionPacket && $packet->trData instanceof UseItemOnEntityTransactionData && $packet->trData->getActionType() === UseItemOnEntityTransactionData::ACTION_ATTACK) {
			if ($this->target !== $packet->trData->getEntityRuntimeId()) {
				$this->target = $packet->trData->getEntityRuntimeId();
				$this->actualRotationSamples = [];
				$this->aimbotRotationSamples = [];
				return;
			}
			$this->waiting = true;
		} elseif ($packet instanceof PlayerAuthInputPacket && $this->waiting) {
			$locationData = $data->locationMap->get($this->target);
			$yawDiff = fmod($data->currentPos->yaw - $data->lastPos->yaw, 180);
			if ($yawDiff >= 180) {
				$yawDiff = 180 - fmod($yawDiff, 180);
			}
			if ($locationData !== null) {
				$xDist = $locationData->lastPos->x - $data->attackPos->x;
				$zDist = $locationData->lastPos->z - $data->attackPos->z;
				$aimbotYaw = fmod(atan2($zDist, $xDist) / M_PI * 180 - 90, 180);
				$aimbotDiff = $aimbotYaw - $data->lastPos->yaw;
				if ($aimbotDiff >= 180) {
					$aimbotDiff = 180 - fmod($aimbotDiff, 180);
				}
				if (abs($yawDiff) >= 0.0075 || abs($aimbotDiff) >= 0.0075) {
					$this->actualRotationSamples[] = $data->currentPos->yaw;
					$this->aimbotRotationSamples[] = $aimbotYaw;
				}
				if (count($this->actualRotationSamples) === 40 && count($this->aimbotRotationSamples) === 40) {
					// we multiply the correlation coefficient with 100 to make it into a percentage
					// this makes configuration easier for the user using Lumine.
					$cC = MathUtils::getCorrelationCoefficient($this->actualRotationSamples, $this->aimbotRotationSamples) * 100;
					if ($cC > $this->settings->get("correlation", 99.0)) { // TODO: Test and experiment with this threshold
						$this->flag([
							"correlation" => var_export(round($cC, 2), true) . "%"
						]);
					}
					$this->actualRotationSamples = [];
					$this->aimbotRotationSamples = [];
				}
			}
			$this->waiting = false;
		}
	}

}