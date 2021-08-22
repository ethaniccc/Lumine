<?php

namespace LumineServer\data\click;

use LumineServer\utils\Pair;

final class ClickData {

	/** @var int[] */
	public array $clicks = [];

	public int $cps = 0;
	public int $delay = 0;
	public bool $isClicking = false;

	public function add(int $currentTick): void {
		$this->isClicking = true;
		$this->delay = (count($this->clicks) > 0 ? $currentTick - max($this->clicks) : 0);
		$this->clicks[] = $currentTick;
		$this->clicks = array_filter($this->clicks, function (int $clickTick) use ($currentTick): bool {
			return $currentTick - $clickTick <= 20;
		});
		$this->cps = count($this->clicks);
	}

}