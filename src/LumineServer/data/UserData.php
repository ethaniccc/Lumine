<?php

namespace LumineServer\data;

use DateTime;
use LumineServer\data\attack\AttackData;
use LumineServer\data\auth\AuthData;
use LumineServer\data\click\ClickData;
use LumineServer\data\effect\EffectData;
use LumineServer\data\handler\DebugHandler;
use LumineServer\data\handler\GhostBlockHandler;
use LumineServer\data\handler\MovementPredictionHandler;
use LumineServer\data\handler\NetworkStackLatencyManager;
use LumineServer\data\handler\PacketHandler;
use LumineServer\data\location\LocationMap;
use LumineServer\data\movement\MovementConstants;
use LumineServer\data\world\VirtualWorld;
use LumineServer\detections\aimassist\AimAssistA;
use LumineServer\detections\auth\AuthA;
use LumineServer\detections\autoclicker\AutoclickerA;
use LumineServer\detections\autoclicker\AutoclickerB;
use LumineServer\detections\autoclicker\AutoclickerC;
use LumineServer\detections\autoclicker\AutoclickerD;
use LumineServer\detections\badpackets\BadPacketsA;
use LumineServer\detections\DetectionModule;
use LumineServer\detections\invalidmovement\InvalidMovementA;
use LumineServer\detections\invalidmovement\InvalidMovementB;
use LumineServer\detections\invalidmovement\InvalidMovementC;
use LumineServer\detections\killaura\KillauraA;
use LumineServer\detections\killaura\KillauraB;
use LumineServer\detections\range\RangeA;
use LumineServer\detections\timer\TimerA;
use LumineServer\detections\velocity\VelocityA;
use LumineServer\detections\velocity\VelocityB;
use LumineServer\socket\packets\RequestPunishmentPacket;
use LumineServer\utils\AABB;
use pocketmine\level\Location;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\BatchPacket;
use pocketmine\network\mcpe\protocol\DataPacket;
use pocketmine\network\mcpe\protocol\MovePlayerPacket;
use pocketmine\network\mcpe\protocol\TextPacket;
use pocketmine\network\mcpe\protocol\types\DeviceOS;
use function LumineServer\subprocess\sendPacketToSocket;

final class UserData {

	public int $currentTick = 0;
	public int $entityRuntimeId = -1;
	public int $latency = -1;
	public bool $loggedIn = false;
	public bool $isClosed = false;

	public Location $currentPos;
	public Location $lastPos;

	public Vector3 $motion;
	public Vector3 $lastMotion;
	public Vector3 $clientPrediction;
	public Vector3 $serverPredictedMotion;
	public Vector3 $previousServerPredictedMotion;
	public Vector3 $serverSentMotion;
	public Vector3 $lastOnGroundLocation;
	public ?AttackData $attackData = null;

	public int $ticksSinceMotion = 0;
	public int $ticksOnGround = 0;
	public int $ticksOffGround = 0;
	public int $ticksSinceInLiquid = 0;
	public int $ticksSinceInCobweb = 0;
	public int $ticksSinceInClimbable = 0;
	public int $ticksSinceSpawn = 0;

	public bool $onGround = true;
	public bool $lastOnGround = true;
	public bool $expectedOnGround = true;
	public bool $isCollidedVertically = true;
	public bool $isCollidedHorizontally = true;
	public bool $isInLoadedChunk = false;
	public bool $isInVoid = false;
	public bool $isSprinting = false;
	public bool $isSneaking = false;
	public bool $isJumping = false;
	public bool $isTeleporting = true;
	public bool $isImmobile = false;
	public bool $isFlying = false;
	public bool $isSurvival = true;
	public bool $isAlive = false;

	public float $moveForward = 0.0;
	public float $moveStrafe = 0.0;
	public float $movementSpeed = 0.1;
	public float $jumpVelocity = MovementConstants::DEFAULT_JUMP_MOTION;
	public float $gravity = MovementConstants::NORMAL_GRAVITY;

	/** @var EffectData[] */
	public array $effects = [];

	public AABB $boundingBox;
	public float $hitboxWidth = 0.3;
	public float $hitboxHeight = 1.8;
	public float $ySize = 0.0;

	public ?ClickData $clickData;
	public ?AuthData $authData = null;

	public LocationMap $locationMap;

	public int $playerOS = DeviceOS::UNKNOWN;
	public int $inputMode = -1;

	public int $lastACKTick = -1;
	public int $waitingACKCount = 0;

	public ?VirtualWorld $world;

	public int $sendPackets = 0;
	public BatchPacket $sendQueue;

	/** @var DetectionModule[] */
	public array $detections = [];

	public ?PacketHandler $handler;
	public ?NetworkStackLatencyManager $latencyManager;
	public ?GhostBlockHandler $ghostBlockHandler;
	public ?MovementPredictionHandler $movementPredictionHandler;
	public ?DebugHandler $debugHandler;

	public function __construct(
		public string $identifier,
		public string $socketAddress
	) {
		$this->clickData = new ClickData();
		$this->world = new VirtualWorld();

		$this->currentPos = Location::fromObject(new Vector3(0, 0, 0));
		$this->lastPos = Location::fromObject(new Vector3(0, 0, 0));

		$this->motion = new Vector3(0, 0, 0);
		$this->lastMotion = new Vector3(0, 0, 0);
		$this->serverSentMotion = new Vector3(0, 0, 0);
		$this->clientPrediction = new Vector3(0, -0.078, 0);
		$this->serverPredictedMotion = new Vector3(0, 0, 0);
		$this->previousServerPredictedMotion = new Vector3(0, 0, 0);
		$this->lastOnGroundLocation = new Vector3(0, 0, 0);

		$this->locationMap = new LocationMap();

		$this->detections = [
			new InvalidMovementA($this),
			new InvalidMovementB($this),
			new InvalidMovementC($this),

			new VelocityA($this),
			new VelocityB($this),

			new RangeA($this),

			new KillauraA($this),
			new KillauraB($this),

			new AutoclickerA($this),
			new AutoclickerB($this),
			new AutoclickerC($this),
			new AutoclickerD($this),

			new AimAssistA($this),

			new AuthA($this),

			new TimerA($this),

			new BadPacketsA($this),
		];

		$this->handler = new PacketHandler($this);
		$this->latencyManager = new NetworkStackLatencyManager($this);
		$this->ghostBlockHandler = new GhostBlockHandler($this);
		$this->movementPredictionHandler = new MovementPredictionHandler($this);
		$this->debugHandler = new DebugHandler($this);

		$this->sendQueue = new BatchPacket();
		$this->sendQueue->setCompressionLevel(7);
	}

	public function queue(DataPacket $packet): void {
		if ($packet->canBeBatched()) {
			if (!$this->loggedIn && !$packet->canBeSentBeforeLogin()) {
				return;
			}
			$this->sendPackets++;
			$this->sendQueue->addPacket($packet);
		}
	}

	public function message(string $message): void {
		$packet = new TextPacket();
		$packet->type = TextPacket::TYPE_CHAT;
		$packet->sourceName = "";
		$packet->message = $message;
		$this->queue($packet);
	}

	public function teleport(Vector3 $pos): void {
		$packet = new MovePlayerPacket();
		$packet->entityRuntimeId = $this->entityRuntimeId;
		$packet->position = $pos;
		$packet->position->y += 1.62;
		$packet->yaw = $this->currentPos->yaw;
		$packet->pitch = $this->currentPos->pitch;
		$packet->headYaw = $packet->yaw;
		$packet->mode = MovePlayerPacket::MODE_TELEPORT;
		$this->latencyManager->sandwich(function (float $timestamp): void {
			$this->isTeleporting = true;
		}, $packet);
	}

	public function kick(string $message = "Lumine - No Reason Provided"): void {
		$packet = new RequestPunishmentPacket();
		$packet->identifier = $this->identifier;
		$packet->type = RequestPunishmentPacket::TYPE_KICK;
		$packet->message = $message;
		sendPacketToSocket($packet);
		$this->isClosed = true;
	}

	public function ban(string $reason = "Lumine - No Reason Provided", ?DateTime $expiration = null): void {
		$packet = new RequestPunishmentPacket();
		$packet->identifier = $this->identifier;
		$packet->type = RequestPunishmentPacket::TYPE_BAN;
		$packet->message = $reason;
		if ($expiration !== null) {
			$packet->expiration = $expiration->getTimestamp();
		}
		sendPacketToSocket($packet);
		$this->isClosed = true;
	}

	public function destroy(): void {
		$this->handler->destroy();
		$this->handler = null;
		$this->latencyManager->destroy();
		$this->latencyManager = null;
		$this->ghostBlockHandler->destroy();
		$this->ghostBlockHandler = null;
		$this->movementPredictionHandler->destroy();
		$this->movementPredictionHandler = null;
		$this->debugHandler->destroy();
		$this->debugHandler = null;

		$this->world->destroy();
		$this->world = null;
		$this->clickData = null;
		foreach (array_keys($this->detections) as $key) {
			$this->detections[$key]->destroy();
			unset($this->detections[$key]);
		}
	}

}