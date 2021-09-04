<?php

namespace LumineServer\detections\timer;

use ethaniccc\Lumine\data\protocol\v428\PlayerAuthInputPacket;
use LumineServer\data\UserData;
use LumineServer\detections\DetectionModule;
use LumineServer\Server;
use pocketmine\network\mcpe\protocol\DataPacket;

final class TimerA extends DetectionModule {

	private float $balance = 0.0;
	private float $lastTimestamp = -1;

	public function __construct(UserData $data) {
		parent::__construct($data, "Timer", "A", "Checks if the user is sending movement packets too quickly");
	}

	public function run(DataPacket $packet, float $timestamp): void {
		$data = $this->data;
		if ($packet instanceof PlayerAuthInputPacket) {
			if (!$data->isAlive || !$data->loggedIn) {
				$this->lastTimestamp = -1;
			}
			if ($this->lastTimestamp == -1) {
				$this->lastTimestamp = $timestamp;
				return;
			}
			$diff = $timestamp - $this->lastTimestamp;
			$this->balance += 0.05;
			$this->balance -= $diff;
			Server::getInstance()->logger->log("balance={$this->balance}", false);
			if ($this->balance >= 0.25) {
				$this->flag([
					"balance" => round($this->balance, 3)
				]);
			}
		}
	}

}