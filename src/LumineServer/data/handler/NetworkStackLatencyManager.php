<?php

namespace LumineServer\data\handler;

use Closure;
use LumineServer\data\UserData;
use pocketmine\network\mcpe\protocol\DataPacket;
use pocketmine\network\mcpe\protocol\NetworkStackLatencyPacket;
use pocketmine\network\mcpe\protocol\types\DeviceOS;
use function array_keys;
use function mt_rand;

final class NetworkStackLatencyManager {

    /** @var Closure[] */
    public array $list = [];
    public ?UserData $data;

    public function __construct(UserData $data) {
        $this->data = $data;
    }

    public function add(int $timestamp, Closure $run): void {
        if ($this->data->playerOS === DeviceOS::PLAYSTATION) {
            $timestamp /= 1000;
        }
        $this->list[$timestamp] = $run;
        $this->data->waitingACKCount++;
    }

    public function sandwich(Closure $run, DataPacket $other): void {
        $this->data->queue($other);
        $this->send($run);
    }

    public function send(Closure $run): void {
        $packet = new NetworkStackLatencyPacket();
        $packet->timestamp = mt_rand(1, 100000000) * 1000;
        $packet->needResponse = true;
        $this->add($packet->timestamp, $run);
        $this->data->queue($packet);
    }

    public function execute(int $timestamp, float $receive): void {
        if (isset($this->list[$timestamp])) {
            ($this->list[$timestamp])($receive);
            $this->data->lastACKTick = $this->data->currentTick;
            $this->data->waitingACKCount--;
        }
        unset($this->list[$timestamp]);
    }

    public function destroy(): void {
        $this->data = null;
        foreach (array_keys($this->list) as $key) {
            unset($this->list[$key]);
        }
        unset($this->list);
    }

}