<?php
function PReturn( $data, $false_message = "" )
{
    global $G_LOGGING_RESPONSE, $G_PHASTAPI_RESPONSE_FORMATTER;

    // 앱이 자체 응답 규격을 쓰는 경우 여기서 출력하고 종료한다.
    if (isset($G_PHASTAPI_RESPONSE_FORMATTER) && is_callable($G_PHASTAPI_RESPONSE_FORMATTER)) {
        $G_PHASTAPI_RESPONSE_FORMATTER($data, $false_message);
    }

    $r = array();
    if ($data === null) {
        $r = array(
            "success" => false,
            "data" => null,
            "error" => PHASTAPI_ERROR::get_error_message(-1)
        );
    } else if ($data === false) {
        // if (is_string($false_message)) {
        //     $r = array(
        //         "success" => false,
        //         "error" => [
        //             "message" => $false_message,
        //             "code" => 9998
        //         ]
        //     );
        // } else 
        if (is_object($false_message)) {
            $r = array(
                "success" => false,
                "error" => [
                    "message" => $false_message->message,
                    "code" => $false_message->code
                ]
            );
        } else if (is_array($false_message)) {
            $r = array(
                "success" => false,
                "error" => [
                    "message" => $false_message["message"],
                    "code" => $false_message["code"]
                ]
            );
        } else if (is_numeric($false_message)) {
            $r = array(
                "success" => false,
                "error" => PHASTAPI_ERROR::get_error_message($false_message)
            );
        } else {
            $r = array(
                "success" => false,
                "error" => PHASTAPI_ERROR::get_error_message($false_message)
            );
        }
    } else if (is_array($data) && @($data[0] === false)) {
        $r = array(
            "success" => false,
            "error" => PHASTAPI_ERROR::get_error_message($data[1])
        );
    } else if (is_array($data) && @($data[0] === true)) {
        $r = array(
            "success" => true,
            "message" => $data[1],
            "error" => PHASTAPI_ERROR::get_error_message(0)
        );
    } else {
        $r = array(
            "success" => true,
            "error" => PHASTAPI_ERROR::get_error_message(0),
            "data" => $data
        );
    }

    if (isset($r["error"]) && isset($r["error"]["code"])) {
        $r["error"]["code"] = intval($r["error"]["code"]);
        $error_code = $r["error"]["code"];

        if ($error_code >= 400 && $error_code <= 600) {
            header("HTTP/1.0 " . $error_code . " " . $r["error"]["message"]);
        } else if ($error_code != 0) {
            header("HTTP/1.0 400 " . $r["error"]["message"]);
        }
    }
    header("Content-Type: application/json");
    $ret = json_encode($r, JSON_UNESCAPED_UNICODE);

    if ($G_LOGGING_RESPONSE)
        LOG::info("[RET]", $ret);

    echo $ret;
    exit(0);
}

function request_headers_insensitive()
{
    if (function_exists("getallheaders")) {
        $headers = getallheaders();
    } else if (function_exists("apache_request_headers")) {
        $headers = apache_request_headers();
    } else {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, "HTTP_")) {
                $name = str_replace(" ", "-", ucwords(strtolower(str_replace("_", " ", substr($key, 5)))));
                $headers[$name] = $value;
            } else if (in_array($key, ["CONTENT_TYPE", "CONTENT_LENGTH"], true)) {
                $name = str_replace("_", "-", ucwords(strtolower($key), "_"));
                $headers[$name] = $value;
            }
        }
    }
    if (!is_array($headers)) {
        $headers = [];
    }

    // Some FastCGI/proxy configurations expose Authorization only through a
    // dedicated server variable rather than getallheaders().
    if (!isset($headers["Authorization"]) && !isset($headers["authorization"])) {
        $authorization = $_SERVER["HTTP_AUTHORIZATION"]
            ?? $_SERVER["REDIRECT_HTTP_AUTHORIZATION"]
            ?? null;
        if ($authorization !== null && $authorization !== "") {
            $headers["Authorization"] = $authorization;
        }
    }

    $out = array();
    foreach ($headers as $key => $value) {
        $out[strtolower($key)] = $value;
    }

    return $out;
}

// Backward-compatible alias for existing PHAST applications.
function apache_request_headers_insensitive()
{
    return request_headers_insensitive();
}

function phastapi_normalize_base_url( $base_url )
{
    $base_url = trim((string)$base_url);
    if ($base_url === "" || $base_url === "/") {
        return "";
    }

    $path = parse_url($base_url, PHP_URL_PATH);
    if (!is_string($path)) {
        $path = $base_url;
    }

    $path = "/" . trim($path, "/");
    return $path === "/" ? "" : $path;
}

function phastapi_detect_base_url()
{
    $configured = getenv("PHASTAPI_BASE_URL");
    if ($configured !== false) {
        return phastapi_normalize_base_url($configured);
    }

    if (PHP_SAPI === "cli") {
        return "/api";
    }

    // Works with Apache mod_php, Apache/Nginx FastCGI, and the PHP built-in
    // server when index.php is the front controller.
    $script_name = $_SERVER["SCRIPT_NAME"] ?? $_SERVER["PHP_SELF"] ?? "";
    if ($script_name === "") {
        return "/api";
    }

    $directory = str_replace("\\", "/", dirname($script_name));
    return phastapi_normalize_base_url($directory);
}

function phastapi_request_path()
{
    $request_uri = $_SERVER["REQUEST_URI"] ?? "/";
    $path = parse_url($request_uri, PHP_URL_PATH);
    if (!is_string($path) || $path === "") {
        return "/";
    }
    return "/" . ltrim(rawurldecode($path), "/");
}

if (!function_exists('str_starts_with')) {
    function str_starts_with( $haystack, $needle )
    {
        return strpos($haystack, $needle) === 0;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with( $haystack, $needle )
    {
        return (@substr_compare($haystack, $needle, -strlen($needle)) == 0);
    }
}

function include_all_files_in_dir( $dir )
{
    $files = @scandir($dir);
    if ($files === false) {
        return;
    }
    foreach ($files as $file) {
        if (str_starts_with($file, "__test"))
            continue;
        if ($file == "." || $file == "..")
            continue;
        if (is_dir($dir . "/" . $file)) {
            include_all_files_in_dir($dir . "/" . $file);
        } else {
            // echo($dir."/".$file."\n");
            if (str_ends_with($file, ".php"))
                include_once($dir . "/" . $file);
        }
    }
}

function include_domain( $domains )
{
    global $G_PHASTAPI_DOMAIN_DIR;
    if (is_array($domains)) {
        foreach ($domains as $domain)
            include_all_files_in_dir($G_PHASTAPI_DOMAIN_DIR . "/" . $domain);
    } else if (is_string($domains)) {
        include_all_files_in_dir($G_PHASTAPI_DOMAIN_DIR . "/" . $domains);
    }
}

function auto_include( $target_domain )
{
    global $G_PHASTAPI_DOMAIN_DIR;

    if ($target_domain == "")
        return;

    if (is_dir($G_PHASTAPI_DOMAIN_DIR . "/" . $target_domain)) {
        include_all_files_in_dir($G_PHASTAPI_DOMAIN_DIR . "/" . $target_domain);
    } else if (str_ends_with($target_domain, "s") && is_dir($G_PHASTAPI_DOMAIN_DIR . "/" . substr($target_domain, 0, strlen($target_domain) - 1))) {
        // 도메인 찾기에 실패했을 경우 복수형으로 찾아본다.
        include_all_files_in_dir($G_PHASTAPI_DOMAIN_DIR . "/" . substr($target_domain, 0, strlen($target_domain) - 1));
    }

    if (PHP_MAJOR_VERSION >= 8) {
        ScanFunctionAndApplyAttributes();
    }
}

function removeDoubleSlash( $str )
{
    return preg_replace('#/+#', '/', $str);
}

