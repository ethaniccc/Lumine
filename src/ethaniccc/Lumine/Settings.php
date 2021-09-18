<?php

namespace ethaniccc\Lumine;

use function is_array;

final class Settings {

	private array $data;

	public function __construct(array $data) {
		foreach ($data as $k => $v) {
			if (is_array($v)) {
				$data[$k] = new Settings($v);
			}
		}
		$this->data = $data;
	}

	public function get(string $key, $default = null) {
		return $this->data[$key] ?? $default;
	}

	public function all(): array {
		return $this->data;
	}

}