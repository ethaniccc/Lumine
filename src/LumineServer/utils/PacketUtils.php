<?php

namespace LumineServer\utils;

use JsonMapper;
use JsonMapper_Exception;
use LumineServer\data\auth\AuthData;
use LumineServer\Server;
use pocketmine\network\mcpe\JwtException;
use pocketmine\network\mcpe\JwtUtils;
use pocketmine\network\mcpe\protocol\types\login\ClientData;
use pocketmine\network\mcpe\protocol\types\login\JwtChain;
use pocketmine\network\PacketHandlingException;
use pocketmine\utils\TextFormat;

class PacketUtils {

    public static function fetchAuthData(JwtChain $chain, ClientData $clientData): AuthData {
        /** @var AuthData|null $extraData */
        $extraData = null;
        foreach ($chain->chain as $jwt) {
            //validate every chain element
            try {
                [, $claims,] = JwtUtils::parse($jwt);
            } catch (JwtException $e) {
                throw PacketHandlingException::wrap($e);
            }
            if (isset($claims['extraData'])) {
                if ($extraData !== null) {
                    throw new PacketHandlingException('Found \'extraData\' more than once in chainData');
                }

                if (!is_array($claims['extraData'])) {
                    throw new PacketHandlingException('\'extraData\' key should be an array');
                }
                $auth = $claims['extraData'];
                $extraData = new AuthData($auth["XUID"], $auth["identity"], TextFormat::clean($auth["displayName"]), $auth["titleId"]);
            }
        }
        if (is_null($extraData)) {
            Server::getInstance()->logger->log("\"extraData\" not found in chain data for player: $clientData->ThirdPartyName");
            return new AuthData("UNKNOWN", "UNKNOWN", TextFormat::clean($clientData->ThirdPartyName), "UNKNOWN");
        }
        return $extraData;
    }

    public static function parseClientData(string $clientDataJwt): ClientData {
        try {
            [, $clientDataClaims,] = JwtUtils::parse($clientDataJwt);
        } catch (JwtException $e) {
            throw PacketHandlingException::wrap($e);
        }

        $mapper = new JsonMapper;
        $mapper->bEnforceMapType = false; //TODO: we don't really need this as an array, but right now we don't have enough models
        $mapper->bExceptionOnMissingData = true;
        $mapper->bExceptionOnUndefinedProperty = true;
        try {
            $clientData = $mapper->map($clientDataClaims, new ClientData);
        } catch (JsonMapper_Exception $e) {
            throw PacketHandlingException::wrap($e);
        }
        return $clientData;
    }

}