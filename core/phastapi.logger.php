<?php
const LOG_MAX_LENGTH = 1000; // -1 : no limit

function get_real_remote_ip()
{
    // Get real visitor IP behind CloudFlare network
    if (php_sapi_name() == "cli") {
        return "[BACKGROUND]";
    }

    if (isset($_SERVER["HTTP_CF_CONNECTING_IP"])) {
        $_SERVER['REMOTE_ADDR'] = $_SERVER["HTTP_CF_CONNECTING_IP"];
        $_SERVER['HTTP_CLIENT_IP'] = $_SERVER["HTTP_CF_CONNECTING_IP"];
    }
    $client = @$_SERVER['HTTP_CLIENT_IP'];
    $forward = @$_SERVER['HTTP_X_FORWARDED_FOR'];
    $remote = $_SERVER['REMOTE_ADDR'];

    if (filter_var($client, FILTER_VALIDATE_IP)) {
        $ip = $client;
    } elseif (filter_var($forward, FILTER_VALIDATE_IP)) {
        $ip = $forward;
    } else {
        $ip = $remote;
    }

    return $ip;
}

function get_local_datetime()
{
    global $G_TIMEZONE;
    $date = new DateTime();
    $date->setTimezone(new DateTimeZone($G_TIMEZONE));
    return $date;
}

class LOG
{
    public static function write_log( $line, $type = "info" )
    {
        global $G_PHASTAPI_LOGPATH;
        $LOG_PATH = $G_PHASTAPI_LOGPATH;
        if (substr($LOG_PATH, strlen($LOG_PATH) - 1, 1) != "/")
            $LOG_PATH .= "/";

        $logfile = get_local_datetime()->format("y-m-d");
        if (strcmp($type, "info"))
            $logfile = $type . "-" . $logfile;

        $logfile = $LOG_PATH . $logfile . ".log";

        if (!file_exists($LOG_PATH)) {
            // echo("directory is not exist");
            mkdir($LOG_PATH, 0777, true);
        }

        $fp = fopen($logfile, "a");
        fwrite($fp, $line);
        fclose($fp);

    }

    public static function makelog( ...$vararg )
    {
        $line = "";
        $line .= (get_local_datetime()->format("y-m-d H:i:s") . " ");
        $line .= (get_real_remote_ip() . " ");

        $ex = new Exception();
        $trace = $ex->getTrace();

        // foreach ( $trace as $key => $value ) {
        //     echo $key . " : " . print_r($value, true) . "<br/>";
        // }

        if (count($trace) > 1) {
            foreach ($trace as $t) {
                if (isset($t["file"]) && strstr($t["file"], __FILE__) == false) {
                    $varvalue = $vararg[0];
                    if (is_array($varvalue) || is_object($varvalue)) {
                        $varvalue = json_encode($varvalue);
                    }
                    $line .= ((!@strcmp($varvalue, "[CALL]") || !@strcmp($varvalue, "[RETURN]")) ? "" : ($t["file"] . "(" . $t["line"] . ") "));
                    break;
                }
            }
        }

        // print_r($vararg);

        foreach ($vararg as $arg) {
            $na = "";
            if (is_array($arg)) {
                if (count($arg) == 1)
                    $na = ($arg[0] . "\t");
                else {
                    for ($i = 0; $i < count($arg); $i++) {
                        $na .= print_r($arg[$i], true);
                        if ($i < count($arg) - 1)
                            $na .= ", ";
                    }
                }
            } else if (is_object($arg)) {
                $na = print_r($arg, true);
            } else
                $na = $arg;

            $line .= ($na . "\t");
        }
        $line .= "\n";

        if (LOG_MAX_LENGTH > 0 && strlen($line) > LOG_MAX_LENGTH)
            $line = substr($line, 0, LOG_MAX_LENGTH) . "...\n";

        return $line;

        // LOG::write_log($line);
    }

    public static function _( ...$args )
    {
        LOG::info(...$args);
    }

    public static function info( ...$vararg )
    {
        LOG::write("info", ...$vararg);
    }

    public static function debug( ...$vararg )
    {
        LOG::write("debug", ...$vararg);
    }

    public static function write( $type, ...$vararg )
    {
        $line = LOG::makelog(...$vararg);
        LOG::write_log($line, $type);
    }

    public static function error( ...$vararg )
    {
        $line = "";
        $line .= (get_local_datetime()->format("y-m-d H:i:s") . " ");

        $ex = new Exception();
        $trace = $ex->getTrace();

        $trace_str = print_r($trace, true);


        if (count($trace) > 1) {
            $debug_info = $trace[1];
            $line .= ($trace[0]["file"] . "(" . $trace[0]["line"] . ")[" . $debug_info["function"] . "] ");
        }

        foreach ($vararg as $arg) {
            if (is_object($arg) || is_array($arg))
                $arg = print_r($arg, true);
            $line .= ($arg . "\t");
        }
        $line .= "\n";
        LOG::write_log($line, "error");
        LOG::write_log($trace_str, "error");
    }

    public static function warn( ...$vargs )
    {
        LOG::write("warn", ...$vargs);
    }
}