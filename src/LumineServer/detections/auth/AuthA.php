<?php

namespace LumineServer\detections\auth;

use LumineServer\data\UserData;
use LumineServer\detections\DetectionModule;
use LumineServer\Server;
use pocketmine\network\mcpe\protocol\DataPacket;
use pocketmine\network\mcpe\protocol\LoginPacket;
use pocketmine\network\mcpe\protocol\types\DeviceOS;
use pocketmine\utils\TextFormat;

final class AuthA extends DetectionModule {

	public function __construct(UserData $data) {
		parent::__construct($data, "Auth", "A", "Checks if the user is faking their device OS");
	}

	public function run(DataPacket $packet): void {
		$data = $this->data;
		if ($packet instanceof LoginPacket) {
			if ($data->authData->titleID === "UNKNOWN") {
				return;
			}
			switch ($data->authData->titleID) {
				case "896928775":
					$expectedOS = DeviceOS::WINDOWS_10;
					break;
				case "2047319603":
					$expectedOS = DeviceOS::NINTENDO;
					break;
				case "1739947436":
					$expectedOS = DeviceOS::ANDROID;
					break;
				case "2044456598":
					$expectedOS = DeviceOS::PLAYSTATION;
					break;
				case "1828326430":
					$expectedOS = DeviceOS::XBOX;
					break;
				case "1810924247":
					$expectedOS = DeviceOS::IOS;
					break;
				default:
					Server::getInstance()->logger->log("Unknown TitleID from " . TextFormat::clean($packet->username) . " (titleID=$titleID os=$givenOS)");
					return;
			}
			if ($data->playerOS !== $expectedOS) {
				$this->flag([
					"expectedOS" => $expectedOS,
					"givenOS" => $data->playerOS
				]);
			}
		}
	}

}