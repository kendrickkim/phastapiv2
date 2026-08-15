<?php
$G_ISCLI = php_sapi_name() == "cli";

if ($G_ISCLI) {
    include_once(__DIR__ . "/lib.core.php");
    include_once(__DIR__ . "/phastapi.error.php");
    include_once(__DIR__ . "/../config.phastapi.php");
    include_once(__DIR__ . "/phastapi.logger.php");
    include_once(__DIR__ . "/phastapi.core.php");
    class PHASTAPI extends PHASTAPI_CORE
    {
    }

    include_once(__DIR__ . "/../index.phast.php");
    include_once(__DIR__ . "/phastapi.legacydb.php");

    $argv = print_r($_SERVER["argv"], true);

    $cliArgs = $_SERVER["argv"];

    $callType = $cliArgs[1];
    $functionFile = $cliArgs[2];
    $callName = $cliArgs[3];
    $funcArgs = array_slice($cliArgs, 4);

    $ret = false;

    @include_once($functionFile);
    if ($callType == "string") {
        $ret = call_user_func($callName, ...$funcArgs);
    } else if ($callType == "closure") {
        $code = base64_decode($callName);
        $closure = eval("return " . $code . ";");
        $ret = call_user_func($closure, ...$funcArgs);
    } else if ($callType == "tmpfile") {
        $includeFile = "/tmp/" . $callName . ".php";
        echo($includeFile);
        include_once($includeFile);
        $ret = call_user_func($callName, ...$funcArgs);
        unlink($includeFile);
    }

    if ($ret !== true) {
        LOG::error("background_process", "background_process error", $argv);
        echo ("background_process error\n");
        exit(0);
    }

} else {
    class PHASTAPI extends PHASTAPI_CORE
    {
        static function RunTask( $command, ...$vararg )
        {
            $callType = "string"; // string of clousre
            $callName = "";
            $functionFile = "_";

            $ex = new Exception();
            $trace = $ex->getTrace();
            $functionFile = $trace[0]["file"];

            if (gettype($command) == "string") {
                $callType = "string";
                $callName = $command;
                $sargs = array();

                foreach ($vararg as $arg) {
                    array_push($sargs, $arg);
                }
                // proc_open($shellcommand, array(), $pipes);
            } else if ($command instanceof Closure) {

                @include_once(getcwd() . "/libs/Laravel/ReflectionClosure.php");
                $rc = new ReflectionClosure($command);
                $code = $rc->getCode();
                $callType = "closure";

                if (strlen($code) > 4 * 1024) {
                    $uuid = PHASTAPI_UTILS::GenUUID();
                    $callName = "F" . str_replace("-", "", $uuid);
                    $filename = $callName . ".php";
                    $filename = "/tmp/" . $filename;
                    $code = str_replace("function", "function " . $callName, $code);
                    $fp = fopen($filename, "w");
                    fwrite($fp, "<?php\n" . $code . "\n?>");
                    fclose($fp);
                    $callType = "tmpfile";
                } else {
                    $callName = base64_encode($code);
                }

                $sargs = array();
                foreach ($vararg as $arg) {
                    array_push($sargs, $arg);
                }
            }

            $shellCommand = "php " . __FILE__ . " " . $callType . " " . $functionFile . " " . $callName . " " . implode(" ", $sargs);
            $shellCommand = str_replace("\\", "/", $shellCommand);

            proc_open($shellCommand, array(), $pipes);

            return true;
        }
    }
}

