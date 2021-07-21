<?php

namespace LumineServer\threads;

use pocketmine\utils\AssumptionFailedError;
use Threaded;
use function fclose;
use function fwrite;
use function is_resource;

class LoggerThread extends \Thread {

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
			throw new AssumptionFailedError("Open File $this->log failed");
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
		if ($write) {
			$this->buffer[] = $data;
			$this->notify();
		} else {
			echo "[LumineServer] $data\n";
		}
	}

	private function writeStream($stream): void {
		while ($this->buffer->count() > 0) {
			/** @var string $line */
			$line = $this->buffer->pop();
			echo "[LumineServer] $line\n";
			fwrite($stream, $line . "\n");
		}
	}

}