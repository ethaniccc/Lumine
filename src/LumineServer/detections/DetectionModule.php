<?php

namespace LumineServer\detections;

use LumineServer\data\UserData;
use LumineServer\Settings;
use LumineServer\socket\packets\AlertNotificationPacket;
use pocketmine\network\mcpe\protocol\DataPacket;
use pocketmine\utils\TextFormat;
use function LumineServer\subprocess\getPrefix;
use function LumineServer\subprocess\getSettings;
use function LumineServer\subprocess\sendPacketToSocket;

abstract class DetectionModule {

	public static Settings $globalSettings;

	public static function init(): void {
		self::$globalSettings = getSettings()->get("detections", new Settings([
		]));
	}

	public ?UserData $data;

	public string $category;
	public string $subCategory;
	public string $description;
	public bool $experimental;
	public bool $enabled;
	public float $violations = 0;
	public float $buffer = 0;
	public Settings $settings;

	public function __construct(UserData $data, $category, string $subCategory, string $description, bool $experimental = false) {
		$this->data = $data;
		$this->category = $category;
		$this->subCategory = $subCategory;
		$this->description = $description;
		$this->experimental = $experimental;
		$this->reloadSettings();
	}

	public abstract function run(DataPacket $packet, float $timestamp): void;

	public function reloadSettings(): void {
		$categorySettings = self::$globalSettings->get($this->category);
		if (!$categorySettings instanceof Settings) {
			$this->enabled = true;
			$this->settings = new Settings([]);
		} else {
			unset($this->settings);
			$this->settings = $categorySettings->get($this->subCategory, new Settings([
			]));
			$this->enabled = $this->settings->get("enabled", true);
		}
	}

	protected function debug(string $message): void {
		$this->data->debugHandler->getChannel($this->category . $this->subCategory)->broadcast(getPrefix() . TextFormat::GRAY . "(" . TextFormat::BOLD . TextFormat::RED . "DEBUG" . TextFormat::RESET . TextFormat::GRAY . ") " . TextFormat::RESET . $message);
	}

	protected function flag(array $debug = [], float $vl = 1): void {
		$this->violations += $vl;
		if (!$this->experimental && $this->violations >= $this->settings->get("max_vl", 20)) {
			switch ($this->settings->get("punishment_type", "none")) {
				case "kick":
					$this->kick();
					break;
				case "ban":
					$this->ban();
					break;
			}
		}
		$debugString = "";
		if (count($debug) === 0) {
			$debugString = "NO DATA";
		} else {
			$n = count($debug);
			$i = 1;
			foreach ($debug as $name => $value) {
				$debugString .= "$name=$value";
				if ($i !== $n) {
					$debugString .= " ";
				}
				$i++;
			}
		}
		$violationMessage = str_replace([
			"{prefix}",
			"{name}",
			"{detection}",
			"{violations}",
			"{debug}"
		], [
			getPrefix(),
			$this->data->authData->username,
			"{$this->category} ({$this->subCategory})",
			var_export(round($this->violations, 2), true),
			$debugString
		], getSettings()->get("alert_message", "{prefix} §e{name} §7failed §e{detection} §7(§cx{violations}§7) §7{debug}"));
		$this->alert($violationMessage, AlertNotificationPacket::VIOLATION);
		$this->sendDiscordAlert($this->data->authData->username, $debugString);
		echo("[{$this->data->authData->username} ({$this->data->currentTick}) @ {$this->data->socketAddress}] - Flagged {$this->category} ({$this->subCategory}) (x" . var_export((float) round($this->violations, 2), true) . ") [$debugString]" . PHP_EOL);
	}

	protected function buff(float $amount = 1, float $max = 15): float {
		$this->buffer += $amount;
		$this->buffer = max(0, $this->buffer);
		$this->buffer = min($this->buffer, $max);
		return $this->buffer;
	}

	protected function kick(): void {
		$kickMessage = str_replace([
			"{prefix}",
			"{codename}"
		], [
			getPrefix(),
			$this->settings->get("codename", "???")
		], getSettings()->get("kick_message"));
		$this->data->kick($kickMessage);
		$kickBroadcast = str_replace([
			"{prefix}",
			"{player}",
			"{detection}",
			"{codename}"
		], [
			getPrefix(),
			$this->data->authData->username,
			"{$this->category} ({$this->subCategory})",
			$this->settings->get("codename", "???")
		], getSettings()->get("kick_broadcast"));
		$this->alert($kickBroadcast, AlertNotificationPacket::VIOLATION);
	}

	protected function ban(): void {
		$expiration = (new \DateTime("now"))->modify(getSettings()->get("ban_expiration", "7 days"));
		$banMessage = str_replace([
			"{prefix}",
			"{codename}",
			"{expiration}"
		], [
			getPrefix(),
			$this->settings->get("codename", "???"),
			($expiration === false ? "never" : $expiration->format("m/d/y H:i A T"))
		], getSettings()->get("ban_message"));
		if ($expiration === false) {
			$expiration = null;
		}
		$this->data->ban($banMessage, $expiration);
		$banBroadcast = str_replace([
			"{prefix}",
			"{player}",
			"{detection}",
			"{codename}"
		], [
			getPrefix(),
			$this->data->authData->username,
			"{$this->category} ({$this->subCategory})" . ($this->experimental ? TextFormat::RED . " (*Exp)" : ""),
			$this->settings->get("codename", "???")
		], getSettings()->get("ban_broadcast"));
		$this->alert($banBroadcast, AlertNotificationPacket::PUNISHMENT);
	}

	public function destroy(): void {
		$this->data = null;
		unset($this->settings);
	}

	protected function alert(string $broadcast, int $type): void {
		$packet = new AlertNotificationPacket();
		$packet->type = $type;
		$packet->message = $broadcast;
		sendPacketToSocket($packet);
	}

	protected function sendDiscordAlert(string $player, string $debug): void {
		// TODO: Bring this back after finishing with subprocesses
		/* $webhookSettings = Server::getInstance()->settings->get("webhook");
		$webhookLink = $webhookSettings->get("link");

		if ($webhookLink === null || $webhookLink === "none" || $webhookSettings->get("alerts") === false) {
			return;
		}

		$msg = new Message();
		$msg->setContent("");
		$embed = new Embed();
		$embed->setTitle("Lumine");
		$embed->setColor(0xFFC300);
		$embed->setFooter((new \DateTime('now'))->format("m/d/y @ h:m:s A"));
		$embed->setDescription("
		Player: **`$player`**
		Violations: **`{$this->violations}`**
		Codename: **`{$this->settings->get("codename")}`**
		Detection: **`{$this->category} ({$this->subCategory})`**
		Debug data: **`$debug`**
		");

		$msg->addEmbed($embed);

		$webhook = new Webhook($webhookLink, $msg);
		Server::getInstance()->webhookThread->queue($webhook); */
	}

}