<?php

namespace LumineServer\data\movement;

final class MovementConstants {

    public const NORMAL_GRAVITY = 0.08;
    public const SLOW_FALLING_GRAVITY = 0.01;
    public const GRAVITY_MULTIPLICATION = 0.98000001907349;

    public const FRICTION = 0.98;
    public const DEFAULT_BLOCK_FRICTION = 0.6;

    public const JUMP_MOVE_NORMAL = 0.02;
    public const JUMP_MOVE_SPRINT = 0.026;

    public const GROUND_MODULO = 0.015625;

    public const DEFAULT_JUMP_MOTION = 0.42;

    public const STEP_CLIP_MULTIPLIER = 0.4;
    public const STEP_HEIGHT = 0.6;

    public const UNKNOWN_1 = 0.017453292;

    public const MOVEMENT_THRESHOLD = 0.03;
    public const MOVEMENT_THRESHOLD_SQUARED = 0.03 ** 2;

    public const FULL_KEYBOARD_ROTATION_MULTIPLIER = 2.22222222;

}