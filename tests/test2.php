<?php
$array = [1, 2, 3, 4, 5];
for ($i = 1; $i <= 3; $i++) {
	array_unshift($array, array_pop($array));
}
echo implode(",", $array) . PHP_EOL;