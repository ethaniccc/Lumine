<?php

namespace LumineServer\detections\auth;

use LumineServer\data\UserData;
use LumineServer\detections\DetectionModule;
use LumineServer\Server;
use pocketmine\network\mcpe\protocol\DataPacket;
use pocketmine\network\mcpe\protocol\LoginPacket;
use pocketmine\network\mcpe\protocol\types\DeviceOS;
use pocketmine\utils\TextFormat;
use function is_null;

final class AuthA extends DetectionModule {

    public function __construct(UserData $data) {
        parent::__construct($data, "Auth", "A", "Checks if the user is faking their device OS");
    }

    public function run(DataPacket $packet, float $timestamp): void {
        $data = $this->data;
        if ($packet instanceof LoginPacket) {
            if ($data->authData->titleID === "UNKNOWN") return;
            $expectedOS = match ($data->authData->titleID) {
                "896928775" => DeviceOS::WINDOWS_10,
                "2047319603" => DeviceOS::NINTENDO,
                "1739947436" => DeviceOS::ANDROID,
                "2044456598" => DeviceOS::PLAYSTATION,
                "1828326430" => DeviceOS::XBOX,
                "1810924247" => DeviceOS::IOS,
                "1944307183" => DeviceOS::AMAZON,
                default => null
            };
            if(is_null($expectedOS)){
                Server::getInstance()->logger->log("Unknown TitleID from " . TextFormat::clean($data->authData->username) . " (titleID={$data->authData->titleID} os=$data->playerOS)");
                return;
            }
            Server::getInstance()->logger->log("{$data->authData->username} @ AuthA: expected=$expectedOS got=$data->playerOS titleID={$data->authData->titleID}");
            if ($data->playerOS !== $expectedOS) {
                $this->flag([
                    "expectedOS" => $expectedOS,
                    "givenOS" => $data->playerOS
                ]);
            }
        }
    }

}