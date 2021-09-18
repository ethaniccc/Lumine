<?php

namespace ethaniccc\Lumine\data\protocol\v428;

use ethaniccc\Lumine\data\protocol\LegacyItemSlot;
use pocketmine\item\Item;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\types\inventory\NetworkInventoryAction;

class UseItemInteractionData {

	public int $legacyRequestId;
	/** @var LegacyItemSlot[] */
	public array $legacyItemSlots = [];
	public bool $hasNetworkIds;
	/** @var NetworkInventoryAction[] */
	public array $actions;
	public int $actionType;
	public Vector3 $blockPos;
	public int $blockFace;
	public int $hotbarSlot;
	public Item $heldItem;
	public Vector3 $playerPos;
	public Vector3 $clickPos;
	public int $blockRuntimeId;

}