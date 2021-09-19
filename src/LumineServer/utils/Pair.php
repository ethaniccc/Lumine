<?php

namespace LumineServer\utils;

final class Pair {

    public float $x;
    public float $y;

    public function __construct(float $x, float $y) {
        $this->x = $x;
        $this->y = $y;
    }

    public function getX() : float {
        return $this->x;
    }

    public function getY() : float {
        return $this->y;
    }

}