<?php

namespace LumineServer\data;

use LumineServer\data\handler\GhostBlockHandler;
use LumineServer\data\handler\NetworkStackLatencyManager;
use LumineServer\data\handler\PacketHandler;
use LumineServer\data\world\VirtualWorld;
use LumineServer\utils\AABB;
use pocketmine\block\Block;
use pocketmine\level\Location;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\BatchPacket;
use pocketmine\network\mcpe\protocol\DataPacket;
use pocketmine\network\mcpe\protocol\MovePlayerPacket;
use pocketmine\network\mcpe\protocol\TextPacket;

final class UserData {

	public string $identifier;

	public int $currentTick = 0;
	public int $entityRuntimeId = -1;
	public bool $loggedIn = false;

	public Location $currentPos;
	public Location $lastPos;

	public Vector3 $motion;
	public Vector3 $lastMotion;
	public Vector3 $clientPrediction;
	public Vector3 $serverSentMotion;

	public int $ticksSinceMotion = 0;
	public int $ticksOnGround = 0;
	public int $ticksOffGround = 0;
	public int $ticksSinceInLiquid = 0;
	public int $ticksSinceInCobweb = 0;
	public int $ticksSinceInClimbable = 0;

	public bool $onGround = true;
	public bool $expectedOnGround = true;
	public bool $isCollidedVertically = true;
	public bool $hasCollisionAbove = false;
	public bool $isCollidedHorizontally = true;
	public bool $isInLoadedChunk = false;
	public bool $isInVoid = false;

	public float $moveForward = 0.0;
	public float $moveStrafe = 0.0;

	/** @var Block[] */
	public array $blocksBelow = [];
	/** @var Block[] */
	public array $lastBlocksBelow = [];

	public AABB $boundingBox;
	public float $hitboxWidth = 0.3;
	public float $hitboxHeight = 1.8;

	public ?VirtualWorld $world;

	public int $sendPackets = 0;
	public BatchPacket $sendQueue;

	public ?PacketHandler $handler;
	public ?NetworkStackLatencyManager $latencyManager;
	public ?GhostBlockHandler $ghostBlockHandler;

	public function __construct(string $identifier) {
		$this->identifier = $identifier;
		$this->handler = new PacketHandler($this);
		$this->latencyManager = new NetworkStackLatencyManager($this);
		$this->ghostBlockHandler = new GhostBlockHandler($this);
		$this->world = new VirtualWorld();

		$this->currentPos = Location::fromObject(new Vector3(0, 0, 0));
		$this->lastPos = Location::fromObject(new Vector3(0, 0, 0));

		$this->motion = new Vector3(0, 0, 0);
		$this->lastMotion = new Vector3(0, 0, 0);
		$this->serverSentMotion = new Vector3(0, 0, 0);
		$this->clientPrediction = new Vector3(0, -0.078, 0);

		$this->sendQueue = new BatchPacket();
		$this->sendQueue->setCompressionLevel(7);
	}

	public function queue(DataPacket $packet): void {
		if ($packet->canBeBatched()) {
			if (!$this->loggedIn && !$packet->canBeSentBeforeLogin()) {
				return;
			}
			$this->sendPackets += 1;
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
		$this->queue($packet);
	}

	public function destroy(): void {
		$this->handler->destroy();
		$this->handler = null;
		$this->latencyManager->destroy();
		$this->latencyManager = null;
		$this->ghostBlockHandler->destroy();
		$this->ghostBlockHandler = null;
		$this->world->destroy();
		$this->world = null;
	}

}