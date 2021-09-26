<?php

namespace LumineServer\events;

class ChunkSendEvent extends SocketEvent {

    public string $identifier;
    public int $chunkX;
    public int $chunkZ;

    public function __construct(array $data){
        $this->identifier = $data['identifier'];
        $this->chunkX = $data['chunkX'];
        $this->chunkZ = $data['chunkZ'];
    }

}