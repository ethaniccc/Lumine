while getopts "l" OPTION 2> /dev/null; do
	case ${OPTION} in
		l)
			DO_LOOP="yes"
			;;
		\?)
			break
			;;
	esac
done

./bin/php7/bin/php src/LumineServer/index.php

LOOPS=0

set +e

if [ "$DO_LOOP" == "yes" ]; then
	while true; do
		echo "To escape the loop, press CTRL+C now. Otherwise, wait 1 second for the server to restart."
		echo ""
		sleep 1
		((LOOPS++))
		./bin/php7/bin/php src/LumineServer/index.php
	done
fi