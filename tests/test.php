<?php

$shm_id = shmop_open(2, "n", 0644, 1024 * 1000 * 0.5);
if (!$shm_id) {
	echo "Couldn't create shared memory segment\n";
	$shm_id = shmop_open(2, "w", 0644, 1024 * 1000 * 0.5);
}

// Get shared memory block's size
$shm_size = shmop_size($shm_id);
echo "SHM Block Size: ".$shm_size.
	" has been created.\n";

// Lets write a test string into shared memory
$data = zlib_encode(str_repeat("0", 1000 * 1024 * 50), ZLIB_ENCODING_RAW, 9);
$data = pack("l", strlen($data)) . $data;
$shm_bytes_written = shmop_write($shm_id, $data, 0);
if ($shm_bytes_written != strlen($data)) {
	echo "Couldn't write the entire length of data\n";
}

sleep(10);

//Now lets delete the block and close the shared memory segment
if (!shmop_delete($shm_id)) {
	echo "Couldn't mark shared memory block for deletion.";
}

@shmop_close($shm_id);
echo "Shared memory block was closed\n";