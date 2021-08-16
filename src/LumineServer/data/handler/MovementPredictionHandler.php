<?php

namespace LumineServer\data\handler;

use LumineServer\data\movement\MovementConstants;
use LumineServer\data\UserData;
use LumineServer\utils\AABB;
use LumineServer\utils\LevelUtils;
use LumineServer\utils\MCMathHelper;
use pocketmine\block\Ladder;
use pocketmine\block\Stair;
use pocketmine\math\Vector3;
use pocketmine\utils\TextFormat;

final class MovementPredictionHandler {

	public ?UserData $data;

	private int $teleportOffsetFuckery = 0;

	public function __construct(UserData $data) {
		$this->data = $data;
	}

	public function execute(): void {
		$data = $this->data;
		if (!$data->isInLoadedChunk || $data->isInVoid) {
			$data->onGround = true;
			$data->expectedOnGround = true;
			$data->isCollidedVertically = false;
			$data->isCollidedVertically = false;
			$data->previousServerPredictedMotion = clone $data->motion;
			$data->serverPredictedMotion = clone $data->motion;
			$data->serverPredictedMotion->y -= $data->gravity;
			$data->serverPredictedMotion->y *= MovementConstants::GRAVITY_MULTIPLICATION;
			$data->serverPredictedMotion->x *= 0.6 * 0.91;
			$data->serverPredictedMotion->z *= 0.6 * 0.91;
		} else {
			$this->moveEntityWithHeading();
		}
	}

	private function moveEntityWithHeading(): void {
		$data = $this->data;
		$motion = &$data->serverPredictedMotion;
		$forward = $data->moveForward;
		$strafe = $data->moveStrafe;

		if ($data->ticksSinceMotion === 0) {
			// $data->message("({$data->currentTick}) motion taken");
			$data->serverPredictedMotion = clone $data->serverSentMotion;
			if ($data->isJumping) {
				$motion->y = $data->jumpVelocity;
			}
			if ($data->isTeleporting) {
				$data->motion = clone $data->serverSentMotion;
				if ($data->isJumping) {
					$data->motion->y = $data->jumpVelocity;
				}
			}
		}

		if ($data->isJumping) {
			$this->jump();
		}

		$var1 = 0.91;
		if ($data->onGround) {
			$floor = $data->lastPos->floor()->subtract(0, 1);
			$var1 *= $data->world->getBlock($floor)->getFrictionFactor();
		}
		// refer to https://media.discordapp.net/attachments/727159224320131133/868630080316928050/unknown.png
		$var2 = ((0.91 * 0.6) / $var1) ** 3;
		if ($data->onGround) {
			$var3 = $data->movementSpeed * $var2;
		} else {
			$var3 = $data->isSprinting ? 0.026 : 0.02;
		}
		$this->moveFlying($forward, $strafe, $var3, $motion);

		$blockPos = $data->lastPos->floor();
		$block = $data->world->getBlock($blockPos);
		if ($block->canClimb()) {
			$f6 = 0.2;
			$motion->x = MCMathHelper::clamp_float($motion->x, -$f6, $f6);
			$motion->z = MCMathHelper::clamp_float($motion->z, -$f6, $f6);
			if ($motion->y < -0.2) {
				$motion->y = -0.2;
			}
			if ($data->isSneaking && $motion->y < 0) {
				$motion->y = 0;
			}
		}

		$this->moveEntity($motion->x, $motion->y, $motion->z, $data->isCollidedVertically, $data->isCollidedHorizontally, $cX, $cZ, $data->onGround, $position);
		$blockPos = $data->lastPos->floor();
		$block = $data->world->getBlock($blockPos);
		if ($block->canClimb() && $data->isCollidedHorizontally) {
			$motion->y = 0.2;
		}
		$data->previousServerPredictedMotion = clone $motion;


		/**
		 * TODO: Find a method that completes full compensation for stairs.
		 * These 4 lines are shitty hacks to compensate for an improper and incomplete stair prediction.
		 * In Minecraft bedrock, it seems that the player clips into the stairs, making the minecraft java
		 * movement code obsolete for this case.
		 */
		$list = LevelUtils::checkBlocksInAABB($data->boundingBox->expandedCopy(0.2, 0.2, 0.2), $data->world, LevelUtils::SEARCH_ALL);
		foreach ($list as $block) {
			if ($block instanceof Stair) {
				$has = true;
				break;
			}
		}
		if ($data->ySize > 1E-5 || (isset($has) && $motion->y >= 0 && $motion->y < 0.6 && $data->motion->y > -1E-6 && $data->motion->y < 1)) {
			$data->onGround = true;
			$data->previousServerPredictedMotion = clone $data->motion;
			$data->serverPredictedMotion = clone $data->motion;
		}

		if ($data->isTeleporting) {
			// $data->message(TextFormat::YELLOW . "({$data->currentTick}) teleporting");
			$this->teleportOffsetFuckery = 2;
		}

		/**
		 * HACK: This is a hack to compensate for some weird behavior where Minecraft will think
		 * it's still collided vertically for an extra tick after teleporting.
		 */
		if ($this->teleportOffsetFuckery > 0 && !$data->isJumping) {
			$motion->y = $data->serverSentMotion->y;
			if ($data->isJumping) {
				$motion->y = $data->jumpVelocity;
			}
			--$this->teleportOffsetFuckery;
		}

		/* if ($data->motion->length() > 0.0001) {
			$diffVec = $data->motion->subtract($motion)->round(10)->abs();
			$color = TextFormat::GREEN;
			if ($diffVec->y > 0.01) {
				$color = TextFormat::RED;
			}
			$data->message($color . "({$data->currentTick}) $diffVec predicted=$motion movement={$data->motion} cH=" . var_export($data->isCollidedHorizontally, true) . " cV=" . var_export($data->isCollidedVertically, true) . " onGround=" . var_export($data->onGround, true));
		} */

		if ($cX) {
			$motion->x = 0;
		}
		if ($data->isCollidedVertically) {
			$motion->y = 0;
		}
		if ($cZ) {
			$motion->z = 0;
		}

		$motion->y -= $data->gravity;
		$motion->y *= MovementConstants::GRAVITY_MULTIPLICATION;
		$motion->x *= $var1;
		$motion->z *= $var1;
	}

	private function moveEntity(float $dx, float $dy, float $dz, &$cV, &$cH, &$cX, &$cZ, &$onGround, &$position): void {
		$movX = $dx;
		$movY = $dy;
		$movZ = $dz;

		// TODO: Prediction with collision on cobweb

		$this->data->ySize *= 0.4;

		$oldBB = AABB::fromPosition($this->data->lastPos, $this->data->hitboxWidth, $this->data->hitboxHeight);
		$oldBB->expand(-0.0025, 0, -0.0025);
		$oldBBClone = clone $oldBB;

		$world = $this->data->world;

		if ($this->data->onGround && $this->data->isSneaking) {
			for ($mov = 0.05; $dx != 0.0 && count(LevelUtils::checkBlocksInAABB($oldBB->offset($dx, -1, 0), $world, LevelUtils::SEARCH_SOLID)) === 0; $movX = $dx) {
				if ($dx < $mov and $dx >= -$mov) {
					$dx = 0;
				} elseif ($dx > 0) {
					$dx -= $mov;
				} else {
					$dx += $mov;
				}
			}
			for (; $dz != 0.0 and count(LevelUtils::checkBlocksInAABB($oldBB->offset(0, -1, $dz), $world, LevelUtils::SEARCH_SOLID)) === 0; $movZ = $dz) {
				if ($dz < $mov and $dz >= -$mov) {
					$dz = 0;
				} elseif ($dz > 0) {
					$dz -= $mov;
				} else {
					$dz += $mov;
				}
			}
		}

		$list = LevelUtils::getCollisionBBList($oldBB->addCoord($dx, $dy, $dz), $world);

		foreach ($list as $bb) {
			$dy = $bb->calculateYOffset($oldBB, $dy);
		}

		$oldBB->offset(0, $dy, 0);

		$fallingFlag = $onGround || ($dy != $movY && $movY < 0);

		foreach ($list as $bb) {
			$dx = $bb->calculateXOffset($oldBB, $dx);
		}

		$oldBB->offset($dx, 0, 0);

		foreach ($list as $bb) {
			$dz = $bb->calculateZOffset($oldBB, $dz);
		}

		$oldBB->offset(0, 0, $dz);

		if ($fallingFlag && ($movX != $dx || $movZ != $dz)) {
			$cx = $dx;
			$cy = $dy;
			$cz = $dz;
			$dx = $movX;
			$dy = MovementConstants::STEP_HEIGHT;
			$dz = $movZ;

			$oldBBClone2 = clone $oldBB;
			$oldBB->setBB($oldBBClone);

			$list = LevelUtils::getCollisionBBList($oldBB->addCoord($dx, $dy, $dz), $world);

			foreach ($list as $bb) {
				$dy = $bb->calculateYOffset($oldBB, $dy);
			}

			$oldBB->offset(0, $dy, 0);

			foreach ($list as $bb) {
				$dx = $bb->calculateXOffset($oldBB, $dx);
			}

			$oldBB->offset($dx, 0, 0);

			foreach ($list as $bb) {
				$dz = $bb->calculateZOffset($oldBB, $dz);
			}

			$oldBB->offset(0, 0, $dz);

			$reverseDY = -$dy;
			foreach ($list as $bb) {
				$reverseDY = $bb->calculateYOffset($oldBB, $reverseDY);
			}
			$dy += $reverseDY;
			$oldBB->offset(0, $reverseDY, 0);

			if (($cx ** 2 + $cz ** 2) >= ($dx ** 2 + $dz ** 2)) {
				$dx = $cx;
				$dy = $cy;
				$dz = $cz;
				$oldBB->setBB($oldBBClone2);
			} else {
				$this->data->ySize += $dy;
			}
		}

		$position = new Vector3(0, 0, 0);
		$position->x = ($oldBB->minX + $oldBB->maxX) / 2;
		$position->y = $oldBB->minY - $this->data->ySize;
		$position->z = ($oldBB->minZ + $oldBB->maxZ) / 2;

		$cV = $movY != $dy;
		$cH = ($movX != $dx || $movZ != $dz);
		$cX = $movX != $dx;
		$cZ = $movZ != $dz;
		$onGround = $cV && $movY < 0;

		$this->data->serverPredictedMotion->x = $dx;
		$this->data->serverPredictedMotion->y = $dy;
		$this->data->serverPredictedMotion->z = $dz;
	}

	private function moveFlying(float $forward, float $strafe, float $friction, Vector3 &$motion): void {
		$var1 = ($forward ** 2) + ($strafe ** 2);
		if ($var1 >= 1E-4) {
			$var1 = sqrt($var1);
			if ($var1 < 1) {
				$var1 = 1;
			}
			$var1 = $friction / $var1;
			$forward *= $var1;
			$strafe *= $var1;
			$var2 = MCMathHelper::sin($this->data->currentPos->yaw * M_PI / 180);
			$var3 = MCMathHelper::cos($this->data->currentPos->yaw * M_PI / 180);
			$motion->x += $strafe * $var3 - $forward * $var2;
			$motion->z += $forward * $var3 + $strafe * $var2;
		}
	}

	private function jump(): void {
		$this->data->serverPredictedMotion->y = $this->data->jumpVelocity;
		if ($this->data->isSprinting) {
			$f = $this->data->currentPos->yaw * 0.017453292;
			$this->data->serverPredictedMotion->x -= MCMathHelper::sin($f) * 0.2;
			$this->data->serverPredictedMotion->z += MCMathHelper::cos($f) * 0.2;
		}
	}

	public function destroy(): void {
		$this->data = null;
	}

}