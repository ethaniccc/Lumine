<?php

namespace LumineServer;

use function is_array;

final class Settings {

    /** @var Settings[] */
    private array $data;

    public function __construct(array $data) {
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $data[$k] = new Settings($v);
            }
        }
        $this->data = $data;
    }

    /**
     * @param string $key
     * @param mixed|null $default
     * @return string|int|Settings|null
     */
    public function get(string $key, string|int|null $default = null) : string|int|null|Settings {
        return $this->data[$key] ?? $default;
    }

    public function all(): array {
        return $this->data;
    }

}