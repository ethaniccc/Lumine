<?php

namespace LumineServer\threads;

use pocketmine\utils\AssumptionFailedError;
use Thread;
use Threaded;
use function fclose;
use function fopen;
use function fwrite;
use function is_resource;

class LoggerThread extends Thread {

    private string $log;
    private Threaded $buffer;
    private bool $running = true;

    public function __construct(string $log) {
        $this->log = $log;
        $this->buffer = new Threaded();
    }

    public function run(): void {
        $stream = fopen($this->log, 'ab');
        if (!is_resource($stream)) {
            throw new AssumptionFailedError("Failed to open a stream for $this->log");
        }
        while ($this->running) {
            $this->writeStream($stream);
            $this->synchronized(function () {
                if ($this->running) {
                    $this->wait();
                }
            });
        }
        $this->writeStream($stream);
        fclose($stream);
    }

    public function quit(): void {
        $this->running = false;
        $this->notify();
    }

    public function log(string $data, bool $write = true): void {
        echo "[LumineServer] $data\n";
        if ($write) {
            $this->buffer[] = $data;
            $this->notify();
        }
    }

    private function writeStream($stream): void {
        while ($this->buffer->count() > 0) {
            /** @var string $line */
            $line = $this->buffer->pop();
            fwrite($stream, $line . "\n");
        }
    }

}