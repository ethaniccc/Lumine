<?php

class A {

	public string $bytes = "";

	public function __construct() {
		$this->bytes = str_repeat("\x00", 1024 * 1000);
	}

}

$a = new A();
$d = 0;
doStuffWithA($a, $d);
$d = 0;
doStuffWithA($a, $d);

function doStuffWithA(A &$object, int &$tries = 0): int {
	if ($tries++ >= 10000000) {
		return 0;
	}
	$s = microtime(true);
	var_dump(microtime(true) - $s);
	echo round(memory_get_usage() / 1e+6, 4) . "MB ($tries)\n";
	return doStuffWithA($object, $tries);
}