<?php

namespace LumineServer {

    use function date_default_timezone_set;
    use function ignore_user_abort;
    use function is_file;
    use function spl_autoload_register;
    use function str_replace;

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
        $server->run();
    }

    ignore_user_abort(true);
    server();

}