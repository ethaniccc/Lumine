<?php

namespace ethaniccc\Lumine\commands;

use ethaniccc\Lumine\Lumine;
use ethaniccc\Lumine\packets\CommandRequestPacket;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\command\PluginIdentifiableCommand;
use pocketmine\Player;
use pocketmine\plugin\Plugin;
use pocketmine\utils\TextFormat;

final class LumineCommand extends Command implements PluginIdentifiableCommand {

	public function __construct() {
		parent::__construct("anticheat", "The command for the Lumine anti-cheat", "/ac <subcommand>", ["ac"]);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args) {
		if (count($args) === 0) {
			$sender->sendMessage(TextFormat::LIGHT_PURPLE . "Lumine anti-cheat " . TextFormat::YELLOW . "created by " . TextFormat::DARK_PURPLE . "ethaniccc");
		} else {
			$subCommand = array_shift($args);
			switch ($subCommand) {
				case "logs":
					if (!$sender->hasPermission("ac.command.$subCommand")) {
						$this->deny($sender);
					} else {
						$packet = new CommandRequestPacket();
						$packet->sender = $sender instanceof Player ? $this->getPlugin()->cache->get($sender) : "CONSOLE";
						$packet->command = $subCommand;
						$packet->args = $args;
						var_dump($packet->args);
						$this->request($packet, $sender);
					}
					break;
				case "cooldown":
					if (!$sender->hasPermission("ac.command.cooldown") || !$sender instanceof Player) {
						$this->deny($sender);
					} else {
						$cooldown = (int) ($args[0] ?? 3);
						Lumine::getInstance()->alertCooldowns[$sender->getName()] = $cooldown;
						$sender->sendMessage(TextFormat::GREEN . "Your alert cooldown has been set to $cooldown seconds");
					}
					break;
			}
		}
	}

	public function getPlugin(): Lumine {
		return Lumine::getInstance();
	}

	private function request(CommandRequestPacket $packet, CommandSender $sender): void {
		$this->getPlugin()->socketThread->send($packet);
		$sender->sendMessage(TextFormat::GRAY . "Requesting data from the socket server...");
	}

	private function deny(CommandSender $sender): void {
		$sender->sendMessage(TextFormat::RED . "You don't have permission to use this command.");
	}

}