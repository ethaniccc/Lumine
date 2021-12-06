<?php

namespace LumineServer\detections\auth;

use LumineServer\data\UserData;
use LumineServer\detections\DetectionModule;
use pocketmine\network\mcpe\protocol\DataPacket;
use pocketmine\network\mcpe\protocol\LoginPacket;
use pocketmine\network\mcpe\protocol\types\DeviceOS;
use pocketmine\utils\TextFormat;
use function LumineServer\subprocess\log;

final class AuthA extends DetectionModule {

	public function __construct(UserData $data) {
		parent::__construct($data, "Auth", "A", "Checks if the user is faking their device OS");
	}

	public function run(DataPacket $packet, float $timestamp): void {
		$data = $this->data;
		if ($packet instanceof LoginPacket) {
			if ($data->authData->titleID === "UNKNOWN") {
				return;
			}
			// match the title ID and return the expected device os
			$expectedOS = match ($data->authData->titleID) {
				"896928775" => DeviceOS::WINDOWS_10,
				"2047319603" => DeviceOS::NINTENDO,
				"1739947436" => DeviceOS::ANDROID,
				"2044456598" => DeviceOS::PLAYSTATION,
				"1828326430" => DeviceOS::XBOX,
				"1810924247" => DeviceOS::IOS,
				default => null
			};
			if ($expectedOS === null) {
				log(TextFormat::RED . "({$data->authData->username}) Unknown title ID: " . $data->authData->titleID . " Player OS: " . $data->playerOS);
				return;
			}
			log("{$data->authData->username} @ AuthA: expected=$expectedOS got={$data->playerOS} titleID={$data->authData->titleID}");
			if ($data->playerOS !== $expectedOS) {
				$this->flag([
					"expectedOS" => $expectedOS,
					"givenOS" => $data->playerOS
				]);
			}
		}
	}

}