<?php

namespace ethaniccc\Lumine\commands;

use ethaniccc\Lumine\events\CommandRequestEvent;
use ethaniccc\Lumine\Lumine;
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
					if (!$sender->hasPermission("ac.command.logs")) {
						$this->deny($sender);
					} else {
						$this->request(new CommandRequestEvent([
							"sender" => $sender instanceof Player ? $this->getPlugin()->cache->get($sender) : "CONSOLE",
							"commandType" => "logs",
							"args" => $args
						]), $sender);
					}
					break;
			}
		}
	}

	public function getPlugin(): Lumine {
		return Lumine::getInstance();
	}

	private function request(CommandRequestEvent $event, CommandSender $sender): void {
		$this->getPlugin()->socketThread->send($event);
		$sender->sendMessage(TextFormat::GRAY . "Requesting data from the socket server...");
	}

	private function deny(CommandSender $sender): void {
		$sender->sendMessage(TextFormat::RED . "You don't have permission to use this command.");
	}

}