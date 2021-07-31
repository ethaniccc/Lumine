<?php

namespace LumineServer\utils;

final class Pair {

	/** @var mixed */
	public $x;
	/** @var mixed */
	public $y;

	public function __construct($x, $y) {
		$this->x = $x;
		$this->y = $y;
	}

	public function getX() {
		return $this->x;
	}

	public function getY() {
		return $this->y;
	}

}