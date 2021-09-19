<?php

namespace LumineServer\threads;

use LogicException;
use LumineServer\webhook\Webhook;
use Thread;
use Threaded;
use function curl_close;
use function curl_exec;
use function curl_init;
use function curl_setopt;
use function is_file;
use function json_encode;
use function sleep;
use function spl_autoload_register;
use function str_replace;

final class WebhookThread extends Thread {

    private Threaded $queue;
    private bool $running = true;

    public function __construct() {
        $this->queue = new Threaded();
    }

    public function queue(Webhook $webhook): void {
        $this->queue[] = $webhook;
    }

    public function run() {
        spl_autoload_register(function ($class) {
            $class = str_replace ("\\", DIRECTORY_SEPARATOR, $class);
            if (!is_file ("src/$class.php")) {
                throw new LogicException ("Class $class not found");
            } else {
                require_once "src/$class.php";
            }
        });
        while ($this->running) {
            if ($this->queue->count() === 0) {
                sleep(1);
            } else {
                while (($webhook = $this->queue->shift()) !== null) {
                    /** @var Webhook $webhook */
                    $ch = curl_init($webhook->getURL());
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($webhook->getMessage()));
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
                    curl_exec($ch);
                    curl_close($ch);
                    sleep(1);
                }
            }
        }
    }

    public function quit(): void {
        $this->running = false;
    }

}