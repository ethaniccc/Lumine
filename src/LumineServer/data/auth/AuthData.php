<?php

namespace LumineServer\data\auth;

final class AuthData {

    public function __construct(
        public string $xuid,
        public string $identity,
        public string $username,
        public string $titleID
    ) {}

}