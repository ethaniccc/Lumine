<?php

namespace ethaniccc\Lumine\events;

final class InitDataEvent extends SocketEvent {

	public const NAME = self::INIT_DATA;

	public array $extraData;

	public function __construct(array $data) {
		$this->extraData = $data["extraData"];
	}

}