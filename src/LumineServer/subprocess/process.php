<?php

namespace LumineServer\subprocess;

use ethaniccc\Lumine\data\protocol\v428\PlayerAuthInputPacket;
use Exception;
use LumineServer\data\UserData;
use LumineServer\detections\DetectionModule;
use LumineServer\Settings;
use LumineServer\socket\packets\CommandRequestPacket;
use LumineServer\socket\packets\CommandResponsePacket;
use LumineServer\socket\packets\LagCompensationPacket;
use LumineServer\socket\packets\Packet;
use LumineServer\socket\packets\ServerSendDataPacket;
use LumineServer\utils\MCMathHelper;
use pocketmine\block\BlockFactory;
use pocketmine\entity\Attribute;
use pocketmine\item\ItemFactory;
use pocketmine\network\mcpe\convert\ItemTranslator;
use pocketmine\network\mcpe\convert\ItemTypeDictionary;
use pocketmine\network\mcpe\protocol\BatchPacket;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\network\mcpe\protocol\types\ItemTypeEntry;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\utils\TextFormat;
use Socket;
use UnexpectedValueException;

require_once "./vendor/autoload.php";
spl_autoload_register (function ($class) {
	$class = str_replace ("\\", DIRECTORY_SEPARATOR, $class);
	if (is_file ("src/$class.php")) {
		require_once "src/$class.php";
	}
});

ini_set("memory_limit", "100M");

const TPS = 128;
$socketAddress = $argv[2];
$userIdentifier = $argv[3];

define("USER_IDENTIFIER", $argv[3]);

$GLOBALS["LUMINE_SOCKET"] = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
$GLOBALS["LUMINE_SETTINGS"] = new Settings(yaml_parse(file_get_contents("./resources/config/config.yml")));
if (!socket_connect(getSocket(), "0.0.0.0", $argv[1])) {
	throw new Exception("Unable to connect to parent socket");
} else {
	log("The subprocess connected");
}

Attribute::init();
PacketPool::init();
PacketPool::registerPacket(new PlayerAuthInputPacket());
\LumineServer\socket\packets\PacketPool::init();
ItemFactory::init(); // ~7MB
BlockFactory::init();
setupItemTypeDictionary();
setupItemTranslator();
DetectionModule::init();
MCMathHelper::init(); // ~2MB

$data = new UserData($userIdentifier, $socketAddress);
socket_set_nonblock(getSocket());
$executions = [];
while(true) {
	$start = microtime(true);
	while (true) {
		[$tries, $toRead, $buffer, $awaitingBuffer] = [0, 4, "", false];
		$packet = readPacketFromSocket($tries, $toRead, $buffer, $awaitingBuffer);
		if ($packet === null) break;
		if ($packet instanceof ServerSendDataPacket) {
			if ($packet->eventType === ServerSendDataPacket::PLAYER_SEND_PACKET) {
				$pk = \pocketmine\network\mcpe\protocol\PacketPool::getPacket($packet->packetBuffer);
				$pk->decode();
				$data->handler->inbound($pk, $packet->timestamp);
			} elseif ($packet->eventType === ServerSendDataPacket::SERVER_SEND_PACKET) {
				$pk = new BatchPacket($packet->packetBuffer);
				$pk->decode();
				$data->handler->outbound($pk, $packet->timestamp);
			} else {
				log("Unknown event type in ServerSendDataPacket");
			}
		} elseif ($packet instanceof LagCompensationPacket) {
			if ($packet->isBatch) {
				$pk = new BatchPacket($packet->packetBuffer);
			} else {
				$pk = \pocketmine\network\mcpe\protocol\PacketPool::getPacket($packet->packetBuffer);
			}
			$pk->decode();
			$data->handler->compensate($pk, $packet->timestamp);
		} elseif ($packet instanceof CommandRequestPacket) {
			switch ($packet->command) {
				case "logs":
					// we already know we're the target since we received this packet in the first place in the subprocess
					$message = "";
					$logs = 0;
					foreach ($data->detections as $detection) {
						if ($detection->violations >= 2) {
							$message .= TextFormat::AQUA . "(" . TextFormat::LIGHT_PURPLE . var_export(round($detection->violations, 2), true) . TextFormat::AQUA . ") ";
							$message .= TextFormat::GRAY . $detection->category . " (" . TextFormat::YELLOW . $detection->subCategory . TextFormat::GRAY . ") ";
							$message .= TextFormat::DARK_GRAY . "- " . TextFormat::GOLD . $detection->description . PHP_EOL;
							$logs++;
						}
					}
					if ($logs === 0) {
						$message .= TextFormat::GREEN . "No logs found for {$data->authData->username}" . PHP_EOL;
					}
					$pk = new CommandResponsePacket();
					$pk->target = $packet->sender;
					$pk->response = $message;
					sendPacketToSocket($pk);
					break;
				case "debug":
					$pk = new CommandResponsePacket();
					$pk->target = $packet->sender;
					if ($packet->sender === "CONSOLE") {
						$pk->response = TextFormat::RED . "You cannot run this command from CONSOLE";
					} elseif ($packet->sender !== USER_IDENTIFIER) {
						$pk->response = TextFormat::RED . "Debugging other users is not possible at this time";
					} else {
						$action = array_shift($packet->args);
						if ($action === null) {
							$pk->response = TextFormat::RED . "You must specify if you want to subscribe or unsubscribe to/from a channel";
						} else {
							$action = strtolower($action);
							$wantedChannel = array_shift($packet->args);
							if ($wantedChannel === null) {
								$pk->response = TextFormat::RED . "You musty specify a debug channel";
							} else {
								$channel = $data->debugHandler->getChannel($wantedChannel);
								if ($channel === null) {
									$pk->response = TextFormat::RED . "The specified debug channel does not exist";
								} elseif ($action === "subscribe") {
									$pk->response = TextFormat::GREEN . "You have been subscribed to the debug channel";
									$channel->subscribe($data);
								} elseif ($action === "unsubscribe") {
									$pk->response = TextFormat::GREEN . "You have been unsubscribed from the debug channel";
									$channel->unsubscribe($data);
								} else {
									$pk->response = TextFormat::RED . "Invalid action given";
								}
							}
						}
					}
					sendPacketToSocket($pk);
					break;
			}
		}
	}
	if ($data->sendPackets > 0) {
		$data->sendQueue->encode();
		$pk = new ServerSendDataPacket();
		$pk->eventType = ServerSendDataPacket::SERVER_SEND_PACKET;
		$pk->identifier = $data->identifier;
		$pk->packetBuffer = $data->sendQueue->getBuffer();
		$pk->timestamp = microtime(true);
		sendPacketToSocket($pk);
		$data->sendPackets = 0;
		unset($data->sendQueue);
		$data->sendQueue = new BatchPacket();
		$data->sendQueue->setCompressionLevel(7);
	}
	$executions[] = round((microtime(true) - $start) * 1000, 2);
	if (count($executions) > 100) {
		array_shift($executions);
	}
	addTick();
	if (getTick() % 600 === 0) {
		$avgExec = round(array_sum($executions) / count($executions), 2);
		log("memory_usage=" . getMemory() . "MB" . " avg_exec_time=" . $avgExec . "ms");
	}
	usleep(1000000 / TPS);
}

/**
 * @throws Exception
 */
function sendPacketToSocket(Packet $packet): void {
	$packet->encode();
	$write = zlib_encode($packet->buffer->getBuffer(), ZLIB_ENCODING_RAW, 7);
	$buffer = pack("l", strlen($write)) . $write;
	$len = strlen($buffer);
	retry_send:
	$res = @socket_write(getSocket(), $buffer);
	if ($res === false) {
		throw new Exception("Failed to send data to socket on subprocess");
	} elseif ($res !== $len) {
		$buffer = substr($buffer, $res);
		goto retry_send;
	}
}

/**
 * @throws Exception
 */
function readPacketFromSocket(int &$tries, int &$toRead, string &$buffer, bool &$awaitingBuffer): ?Packet {
	$read = @socket_read(getSocket(), $toRead);
	if ($read === "") {
		throw new Exception("Empty buffer while reading socket");
	} elseif ($read === false) {
		return ++$tries > 5 ? null : readPacketFromSocket($tries, $toRead, $buffer, $awaitingBuffer);
	} else {
		$tries = 0;
		$length = strlen($read);
		$buffer .= $read;
		$toRead -= $length;
		if (!$awaitingBuffer) {
			if ($toRead === 0) {
				$unpacked = @unpack("l", $buffer)[1];
				if ($unpacked !== false && $unpacked !== null) {
					$toRead = $unpacked;
					$awaitingBuffer = true;
				} else {
					log("Unable to unpack length from socket");
					return null;
				}
				$buffer = "";
			}
			return readPacketFromSocket($tries, $toRead, $buffer, $awaitingBuffer);
		} else {
			if ($toRead === 0) {
				$packet = unserialize($buffer);
				unset($buffer, $toRead, $awaitingBuffer, $tries);
				return $packet;
			}
		}
	}
	return null;
}

function setupItemTypeDictionary(): void {
	$data = file_get_contents('resources/required_item_list.json');
	if ($data === false) throw new AssumptionFailedError("Missing required resource file");
	$table = json_decode($data, true);
	if (!is_array($table)) {
		throw new AssumptionFailedError("Invalid item list format");
	}

	$params = [];
	foreach ($table as $name => $entry) {
		if (!is_array($entry) || !is_string($name) || !isset($entry["component_based"], $entry["runtime_id"]) || !is_bool($entry["component_based"]) || !is_int($entry["runtime_id"])) {
			throw new AssumptionFailedError("Invalid item list format");
		}
		$params[] = new ItemTypeEntry($name, $entry["runtime_id"], $entry["component_based"]);
	}
	ItemTypeDictionary::setInstance(new ItemTypeDictionary($params));
}

function setupItemTranslator(): void {
	$data = file_get_contents('resources/r16_to_current_item_map.json');
	if ($data === false) throw new AssumptionFailedError("Missing required resource file");
	$json = json_decode($data, true);
	if (!is_array($json) or !isset($json["simple"], $json["complex"]) || !is_array($json["simple"]) || !is_array($json["complex"])) {
		throw new AssumptionFailedError("Invalid item table format");
	}

	$legacyStringToIntMapRaw = file_get_contents('resources/item_id_map.json');
	if ($legacyStringToIntMapRaw === false) {
		throw new AssumptionFailedError("Missing required resource file");
	}
	$legacyStringToIntMap = json_decode($legacyStringToIntMapRaw, true);
	if (!is_array($legacyStringToIntMap)) {
		throw new AssumptionFailedError("Invalid mapping table format");
	}

	/** @phpstan-var array<string, int> $simpleMappings */
	$simpleMappings = [];
	foreach ($json["simple"] as $oldId => $newId) {
		if (!is_string($oldId) || !is_string($newId)) {
			throw new AssumptionFailedError("Invalid item table format");
		}
		if (!isset($legacyStringToIntMap[$oldId])) {
			//new item without a fixed legacy ID - we can't handle this right now
			continue;
		}
		$simpleMappings[$newId] = $legacyStringToIntMap[$oldId];
	}
	foreach ($legacyStringToIntMap as $stringId => $intId) {
		if (isset($simpleMappings[$stringId])) {
			throw new UnexpectedValueException("Old ID $stringId collides with new ID");
		}
		$simpleMappings[$stringId] = $intId;
	}

	/** @phpstan-var array<string, array{int, int}> $complexMappings */
	$complexMappings = [];
	foreach ($json["complex"] as $oldId => $map) {
		if (!is_string($oldId) || !is_array($map)) {
			throw new AssumptionFailedError("Invalid item table format");
		}
		foreach ($map as $meta => $newId) {
			if (!is_numeric($meta) || !is_string($newId)) {
				throw new AssumptionFailedError("Invalid item table format");
			}
			$complexMappings[$newId] = [$legacyStringToIntMap[$oldId], (int)$meta];
		}
	}

	ItemTranslator::setInstance(new ItemTranslator(ItemTypeDictionary::getInstance(), $simpleMappings, $complexMappings));
}

function getSettings(): ?Settings {
	return $GLOBALS["LUMINE_SETTINGS"] ?? null;
}

function getPrefix(): string {
	return getSettings()->get("prefix", "§l§8[§dL§6u§em§bi§5n§de§8]") . TextFormat::RESET;
}

function getSocket(): ?Socket {
	return $GLOBALS["LUMINE_SOCKET"] ?? null;
}

function getMemory(): float {
	return round(memory_get_usage() / 1e+6, 4);
}

/* function getBedrockKnownStates(): array {
	if (!isset($GLOBALS["bedrockKnownStates"])) {
		$block = shmop_open(1, "w", 0644, 102400);
		if ($block === false) {
			throw new Exception("Unable to open shared memory block for bedrock known states");
		}
		$packed = shmop_read($block, 0, 4);
		$GLOBALS["bedrockKnownStates"] = shmop_read($block, 4, unpack("l", $packed)[1]);
	}
	return unserialize(zlib_decode($GLOBALS["bedrockKnownStates"]));
} */

function getRuntimeToLegacyMap(): array {
	if (!isset($GLOBALS["runtimeToLegacyMap"])) {
		$block = shmop_open(2, "w", 0644, 102400);
		if ($block === false) {
			throw new Exception("Unable to open shared memory block for runtime to legacy map");
		}
		$packed = shmop_read($block, 0, 4);
		$GLOBALS["runtimeToLegacyMap"] = shmop_read($block, 4, unpack("l", $packed)[1]);
	}
	return unserialize(zlib_decode($GLOBALS["runtimeToLegacyMap"]));
}

function getLegacyToRuntimeMap(): array {
	if (!isset($GLOBALS["legacyToRuntimeMap"])) {
		$block = shmop_open(3, "w", 0644, 102400);
		if ($block === false) {
			throw new Exception("Unable to open shared memory block for legacy to runtime map");
		}
		$packed = shmop_read($block, 0, 4);
		$GLOBALS["legacyToRuntimeMap"] = shmop_read($block, 4, unpack("l", $packed)[1]);
	}
	return unserialize(zlib_decode($GLOBALS["legacyToRuntimeMap"]));
}

function getTick(): int {
	if (!isset($GLOBALS["tick"])) {
		$GLOBALS["tick"] = 0;
	}
	return $GLOBALS["tick"];
}

function addTick(): void {
	if (!isset($GLOBALS["tick"])) {
		@$GLOBALS["tick"] = 0;
	}
	$GLOBALS["tick"]++;
}

function log(string $message): void {
	echo "[SubProcess @ " . USER_IDENTIFIER . "] $message" . PHP_EOL;
}