<?php

namespace LumineServer\subprocess;

use LumineServer\Server;
use LumineServer\socket\packets\Packet;
use LumineServer\socket\SocketData;
use pocketmine\utils\AssumptionFailedError;

final class LumineSubprocess {

	/** @var resource */
	private $process;
	private \Socket $serverSocket;
	private ?SocketData $clientSocket = null;
	private array $queue = [];
	private bool $isRunning = false;

	public function __construct(
		private string $owningSocketAddr,
		private string $userIdentifier
	) {}

	/**
	 * @throws \Exception|AssumptionFailedError
	 */
	public function start(): void {
		$sock = socket_create_listen(0);
		socket_getsockname($sock, $addr, $port);
		socket_close($sock);
		$this->serverSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
		if (!socket_bind($this->serverSocket, "0.0.0.0", $port)) {
			throw new \Exception("Unable to bind port in subprocess socket");
		}
		socket_listen($this->serverSocket, SOMAXCONN);
		socket_set_nonblock($this->serverSocket);
		$this->process = proc_open(
			[PHP_BINARY, "-r", 'require "src/LumineServer/subprocess/process.php";', $port, $this->owningSocketAddr, $this->userIdentifier],
			[2 => fopen("php://stderr", "w")],
			$pipes
		);
		if ($this->process === false) {
			throw new AssumptionFailedError("What the fuck is going on here - the subprocess failed to open!");
		}
		$this->isRunning = true;
	}

	public function queue(Packet $packet): void {
		$this->queue[] = $packet;
	}

	/**
	 * @throws \Exception
	 */
	public function check(): void {
		if ($this->clientSocket === null) {
			if (($newClient = socket_accept($this->serverSocket)) !== false) {
				socket_getpeername($newClient, $address, $port);
				socket_set_nonblock($newClient);
				$this->clientSocket = new SocketData($newClient, "$address:$port", microtime(true));
				echo "Subprocess Client connected!\n";
			}
		} else {
			if ($this->isRunning && !$this->process()) {
				$this->isRunning = false;
				var_dump(socket_strerror(socket_last_error($this->clientSocket->socket)));
				throw new \Exception("Failed to process");
			}
		}
	}

	public function stop(): void {
		proc_terminate($this->process);
		if ($this->clientSocket->socket !== null) {
			@socket_close($this->clientSocket->socket);
		}
		if ($this->serverSocket !== null) {
			@socket_close($this->serverSocket);
		}
	}

	private function process(): bool {
		$toRemove = false;
		$times = 0;
		retry_read:
		$current = @socket_read($this->clientSocket->socket, $this->clientSocket->toRead);
		if ($current === "") {
			$toRemove = true;
			echo "EMPTY BUFF\n";
			goto end;
		} elseif ($current === false) {
			$times++;
			goto end;
		} else {
			$times = 0;
			$this->clientSocket->lastACK = microtime(true);
			$length = strlen($current);
			if ($this->clientSocket->isAwaitingBuffer) {
				$this->clientSocket->recvBuffer .= $current;
				if ($length !== $this->clientSocket->toRead) {
					$this->clientSocket->toRead -= $length;
					goto retry_read;
				} else {
					Server::getInstance()->socketHandler->sendRaw(pack("l", strlen($this->clientSocket->recvBuffer)) . $this->clientSocket->recvBuffer, $this->owningSocketAddr);
					finish_read:
					$this->clientSocket->toRead = 4;
					$this->clientSocket->isAwaitingBuffer = false;
					$this->clientSocket->recvBuffer = "";
				}
				unset($packet);
				goto retry_read;
			} else {
				$this->clientSocket->recvBuffer .= $current;
				if (strlen($this->clientSocket->recvBuffer) !== 4) {
					$this->clientSocket->toRead -= strlen($current);
					goto retry_read;
				} else {
					$unpacked = @unpack("l", $this->clientSocket->recvBuffer)[1];
					if ($unpacked !== false && $unpacked !== null) {
						$this->clientSocket->toRead = $unpacked;
						$this->clientSocket->isAwaitingBuffer = true;
					} else {
						echo "FAIL UNPACK\n";
						$toRemove = true;
						goto end;
					}
					$this->clientSocket->recvBuffer = "";
				}
			}
		}
		end:
		if ($times !== 5 && !$toRemove) {
			goto retry_read;
		}
		unset($current);

		if ($toRemove) {
			return false;
		}

		foreach ($this->queue as $packet) {
			if (!$this->sendPacket($packet)) {
				echo "FAIL SEND\n";
				return false;
			}
		}
		$this->queue = [];

		return true;
	}

	private function sendPacket(Packet $packet): bool {
		$buffer = serialize($packet);
		$realBuff = pack("l", strlen($buffer)) . $buffer;
		$len = strlen($realBuff);
		retry_send:
		$res = socket_write($this->clientSocket->socket, $realBuff);
		if ($res === false) {
			return false;
		} elseif ($res !== $len) {
			echo "RES=$res EXPECTED=$len - RETRYING\n";
			$realBuff = substr($realBuff, $res);
			$len -= $res;
			goto retry_send;
		}
		return true;
	}

}