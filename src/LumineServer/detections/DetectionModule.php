<?php

namespace LumineServer\detections;

use DateTime;
use LumineServer\data\UserData;
use LumineServer\events\AlertNotificationEvent;
use LumineServer\Server;
use LumineServer\Settings;
use LumineServer\webhook\Message;
use LumineServer\webhook\Embed;
use LumineServer\webhook\Webhook;
use pocketmine\network\mcpe\protocol\DataPacket;
use pocketmine\network\mcpe\protocol\TextPacket;
use function count;
use function is_null;
use function max;
use function min;
use function round;
use function str_replace;
use function var_export;

abstract class DetectionModule {

    public static Settings $globalSettings;

    public static function init(): void {
        self::$globalSettings = Server::getInstance()->settings->get("detections", new Settings([
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
            Server::getInstance()->logger->log("Settings were not found for the $this->category detections", false);
        } else {
            unset($this->settings);
            $this->settings = $categorySettings->get($this->subCategory, new Settings([
            ]));
            $this->enabled = $this->settings->get("enabled", true);
        }
    }

    protected function flag(array $debug = [], float $vl = 1): void {
        $this->violations += $vl;
        if ($this->violations >= $this->settings->get("max_vl", 20)) {
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
            Server::getInstance()->getLuminePrefix(),
            $this->data->authData->username,
            "$this->category ($this->subCategory)",
            var_export(round($this->violations, 2), true),
            $debugString
        ], Server::getInstance()->settings->get("alert_message", "{prefix} §e{name} §7failed §e{detection} §7(§cx{violations}§7) §7{debug}"));
        $this->alert($violationMessage, "violation");
        $this->sendDiscordAlert($this->data->authData->username, $debugString);
        Server::getInstance()->logger->log("[{$this->data->authData->username} ({$this->data->currentTick}) @ {$this->data->socketAddress}] - Flagged $this->category ($this->subCategory) (x" . var_export(round($this->violations, 2), true) . ") [$debugString]");
    }

    protected function buff(float $amount = 1): float {
        $this->buffer += $amount;
        $this->buffer = max(0, $this->buffer);
        $this->buffer = min($this->buffer, 8);
        return $this->buffer;
    }

    protected function kick(): void {
        $kickMessage = str_replace([
            "{prefix}",
            "{codename}"
        ], [
            Server::getInstance()->getLuminePrefix(),
            $this->settings->get("codename", "???")
        ], Server::getInstance()->settings->get("kick_message"));
        $this->data->kick($kickMessage);
        $kickBroadcast = str_replace([
            "{prefix}",
            "{player}",
            "{detection}",
            "{codename}"
        ], [
            Server::getInstance()->getLuminePrefix(),
            $this->data->authData->username,
            "$this->category ($this->subCategory)",
            $this->settings->get("codename", "???")
        ], Server::getInstance()->settings->get("kick_broadcast"));
        $this->alert($kickBroadcast, "punishment");
    }

    protected function ban(): void {
        $expiration = (new DateTime("now"))->modify(Server::getInstance()->settings->get("ban_expiration", "7 days"));
        $banMessage = str_replace([
            "{prefix}",
            "{codename}",
            "{expiration}"
        ], [
            Server::getInstance()->getLuminePrefix(),
            $this->settings->get("codename", "???"),
            ($expiration === false ? "never" : $expiration->format("m/d/y H:i A T"))
        ], Server::getInstance()->settings->get("ban_message"));
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
            Server::getInstance()->getLuminePrefix(),
            $this->data->authData->username,
            "$this->category ($this->subCategory)",
            $this->settings->get("codename", "???")
        ], Server::getInstance()->settings->get("ban_broadcast"));
        $this->alert($banBroadcast, "punishment");
    }

    public function destroy(): void {
        $this->data = null;
        unset($this->settings);
    }

    protected function alert(string $broadcast, string $type): void {
        $packet = new TextPacket();
        $packet->sourceName = "";
        $packet->type = TextPacket::TYPE_CHAT;
        $packet->message = $broadcast;
        $event = new AlertNotificationEvent([
            "alertType" => $type,
            "alertPacket" => [$packet]
        ]);
        Server::getInstance()->socketHandler->send($event, $this->data->socketAddress);
    }

    protected function sendDiscordAlert(string $player, string $debug): void {
        $webhookSettings = Server::getInstance()->settings->get("webhook");
        $webhookLink = $webhookSettings->get("link");

        if (is_null($webhookLink) || $webhookLink === "none" || $webhookSettings->get("alerts") === false) {
            return;
        }

        $msg = new Message();
        $msg->setContent("");
        $embed = new Embed();
        $embed->setTitle("Lumine");
        $embed->setColor(0xFFC300);
        $embed->setFooter((new DateTime('now'))->format("m/d/y @ h:m:s A"));
        $embed->setDescription("
		Player: **`$player`**
		Violations: **`$this->violations`**
		Codename: **`{$this->settings->get("codename")}`**
		Detection: **`$this->category ($this->subCategory)`**
		Debug data: **`$debug`**
		");

        $msg->addEmbed($embed);

        $webhook = new Webhook($webhookLink, $msg);
        Server::getInstance()->webhookThread->queue($webhook);
    }

}