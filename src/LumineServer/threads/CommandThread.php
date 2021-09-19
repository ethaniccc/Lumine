<?php

namespace LumineServer\threads;

use pocketmine\snooze\SleeperNotifier;
use pocketmine\thread\ThreadException;
use Thread;
use Threaded;
use function fclose;
use function fgets;
use function fopen;
use function fstat;
use function is_resource;
use function microtime;
use function preg_replace;
use function stream_isatty;
use function stream_select;
use function trim;
use function usleep;

class CommandThread extends Thread {

    public const TYPE_READLINE = 0;
    public const TYPE_STREAM = 1;
    public const TYPE_PIPED = 2;

    /** @var resource */
    private static $stdin;

    protected Threaded $buffer;
    private bool $shutdown = false;
    private int $type = self::TYPE_STREAM;
    private SleeperNotifier $notifier;

    public function __construct(SleeperNotifier $notifier) {
        $this->buffer = new Threaded;
        $this->notifier = $notifier;
    }

    /**
     * @return void
     */
    public function shutdown() {
        $this->shutdown = true;
    }

    public function quit() {
        $wait = microtime(true) + 0.5;
        while (microtime(true) < $wait) {
            if ($this->isRunning()) {
                usleep(100000);
            } else {
                return;
            }
        }

        $message = "Thread blocked for unknown reason";
        if ($this->type === self::TYPE_PIPED) {
            $message = "STDIN is being piped from another location and the pipe is blocked, cannot stop safely";
        }

        throw new ThreadException($message);
    }

    /**
     * Reads a line from console, if available. Returns null if not available
     *
     * @return string|null
     */
    public function getLine(): ?string {
        if ($this->buffer->count() !== 0) {
            return (string)$this->buffer->shift();
        }

        return null;
    }

    /**
     * @return void
     */
    public function run() {
        $this->initStdin();

        while (!$this->shutdown && $this->readLine()) {
        }

        fclose(self::$stdin);
    }

    private function initStdin(): void {
        if (is_resource(self::$stdin)) {
            fclose(self::$stdin);
        }

        self::$stdin = fopen("php://stdin", "r");
        if ($this->isPipe(self::$stdin)) {
            $this->type = self::TYPE_PIPED;
        } else {
            $this->type = self::TYPE_STREAM;
        }
    }

    /**
     * Checks if the specified stream is a FIFO pipe.
     *
     * @param resource $stream
     */
    private function isPipe($stream): bool {
        return is_resource($stream) and (!stream_isatty($stream) or ((fstat($stream)["mode"] & 0170000) === 0010000));
    }

    /**
     * Reads a line from the console and adds it to the buffer. This method may block the thread.
     *
     * @return bool if the main execution should continue reading lines
     */
    private function readLine(): bool {
        if (!is_resource(self::$stdin)) {
            $this->initStdin();
        }

        $r = [self::$stdin];
        $w = $e = null;
        if (($count = stream_select($r, $w, $e, 0, 200000)) === 0) { //nothing changed in 200000 microseconds
            return true;
        } elseif ($count === false) { //stream error
            $this->initStdin();
        }

        if (($raw = fgets(self::$stdin)) === false) { //broken pipe or EOF
            $this->initStdin();
            $this->synchronized(function (): void {
                $this->wait(200000);
            }); //prevent CPU waste if it's end of pipe
            return true; //loop back round
        }

        $line = trim($raw);

        if ($line !== "") {
            $this->buffer[] = preg_replace("#\\x1b\\x5b([^\\x1b]*\\x7e|[\\x40-\\x50])#", "", $line);
            if ($line === "stop") {
                $this->shutdown = true;
            }
            $this->notifier?->wakeupSleeper();
            return !$this->shutdown;
        }

        return true;
    }

    public function getThreadName(): string {
        return "Console";
    }

}