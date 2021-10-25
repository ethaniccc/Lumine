<?php

namespace LumineServer\socket;

use Socket;

final class SocketData {

    public function __construct(
    	/** @var resource */
        public $socket,
        public string $address,
        public float $lastACK
    ) {}

    public int $toRead = 4;
    public bool $isAwaitingBuffer = false;
    /** @var string */
    public $recvBuffer = "";

    public float $lastRetryTime = 0.0;

}