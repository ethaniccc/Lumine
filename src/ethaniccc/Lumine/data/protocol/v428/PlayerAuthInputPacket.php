<?php

namespace ethaniccc\Lumine\data\protocol\v428;

use ethaniccc\Lumine\data\protocol\InputConstants;
use ethaniccc\Lumine\data\protocol\LegacyItemSlot;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStackWrapper;
use pocketmine\network\mcpe\protocol\types\inventory\NetworkInventoryAction;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\ItemStackRequest;
use pocketmine\network\mcpe\protocol\serializer\PacketSerializer;

class PlayerAuthInputPacket extends \pocketmine\network\mcpe\protocol\PlayerAuthInputPacket {

	public int $tick = 0;
	public ?UseItemInteractionData $itemInteractionData = null;
	public ?ItemStackRequest $stackRequest = null;
	/** @var PlayerBlockAction[]|null */
	public ?array $blockActions = null;

	protected function decodePayload(PacketSerializer $in): void {
		parent::decodePayload($in);
		if (InputConstants::hasFlag($this, InputConstants::PERFORM_ITEM_INTERACTION)) {
			$this->itemInteractionData = new UseItemInteractionData();
			$this->itemInteractionData->legacyRequestId = $in->getVarInt();
			if ($this->itemInteractionData->legacyRequestId !== 0) {
				$k = $in->getUnsignedVarInt();
				for ($i = 0; $i < $k; ++$i) {
					$sl = new LegacyItemSlot();
					$sl->containerId = $in->getByte();
					$sl->slots = $in->getString();
					$this->itemInteractionData->legacyItemSlots[] = $sl;
				}
			}
			$l = $in->getUnsignedVarInt();
			for ($i = 0; $i < $l; ++$i) {
				$this->itemInteractionData->actions[] = (new NetworkInventoryAction())->read($in);
			}
			$this->itemInteractionData->actionType = $in->getUnsignedVarInt();
			$x = $y = $z = 0;
			$in->getBlockPosition($x, $y, $z);
			$this->itemInteractionData->blockPos = new Vector3($x, $y, $z);
			$this->itemInteractionData->blockFace = $in->getVarInt();
			$this->itemInteractionData->hotbarSlot = $in->getVarInt();
			$this->itemInteractionData->heldItem = ItemStackWrapper::read($in)->getItemStack();
			$this->itemInteractionData->playerPos = $in->getVector3();
			$this->itemInteractionData->clickPos = $in->getVector3();
			$this->itemInteractionData->blockRuntimeId = $in->getUnsignedVarInt();
		}
		if (InputConstants::hasFlag($this, InputConstants::PERFORM_ITEM_STACK_REQUEST)) {
			$this->stackRequest = ItemStackRequest::read($in);
		}
		if (InputConstants::hasFlag($this, InputConstants::PERFORM_BLOCK_ACTIONS)) {
			$max = $in->getVarInt();
			for ($i = 0; $i < $max; ++$i) {
				$action = new PlayerBlockAction();
				$action->actionType = $in->getVarInt();
				switch ($action->actionType) {
					case PlayerBlockAction::ABORT_BREAK:
					case PlayerBlockAction::START_BREAK:
					case PlayerBlockAction::CRACK_BREAK:
					case PlayerBlockAction::PREDICT_DESTROY:
					case PlayerBlockAction::CONTINUE:
						$action->blockPos = new Vector3($in->getVarInt(), $in->getVarInt(), $in->getVarInt());
						$action->face = $in->getVarInt();
						break;
				}
				$this->blockActions[] = $action;
			}
		}
	}

	public function getTick() : int {
		return $this->tick;
	}

}