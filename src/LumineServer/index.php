<?php

namespace LumineServer {

	function server(): void {
		require_once "./vendor/autoload.php";
		spl_autoload_register (function ($class) {
			$class = str_replace ("\\", DIRECTORY_SEPARATOR, $class);
			if (is_file ("src/$class.php")) {
				require_once "src/$class.php";
			}
		});
		date_default_timezone_set('America/New_York');
		$server = new Server();
		try {
			$server->run();
		} catch (\Exception $e) {
			echo "Error: {$e->getMessage()}\n";
			echo $e->getTraceAsString() . PHP_EOL;
			$server->kill();
			echo "Killing application\n";
			exit();
		}
	}

	\LumineServer\server();

}