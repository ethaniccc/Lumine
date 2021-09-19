<?php

namespace LumineServer\socket;

final class SocketData {

    public function __construct(
        /** @var resource */
        public $socket,
        public string $address,
        public float $lastACK
    ) {}

    public int $toRead = 4;
    public bool $isAwaitingBuffer = false;
    public string $recvBuffer = "";
    public string $sndBuffer = "";

    public float $lastRetryTime = 0.0;

}