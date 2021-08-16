<?php

namespace LumineServer\socket;

use Socket;

final class SocketData {

    public function __construct(
        public Socket $socket,
        public string $address,
        public float $lastACK
    ) {}

    public int $toRead = 4;
    public bool $isAwaitingBuffer = false;
    /** @var string */
    public $buffer = "";

    public float $lastRetryTime = 0.0;

}