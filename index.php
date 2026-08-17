<?php
/*
    PHAST API Framework V2
    Author : Kendrick Kim(kjkim@mobipintech.com)
    Last Update : 2024-03-08
*/

// error_reporting(E_WARNING | E_ERROR);
// error_reporting(E_ALL & ~E_NOTICE);

define("PHASTAPI_VERSION", "2.3");


include_once(__DIR__ . "/core/phastapi.error.php");

if (PHP_MAJOR_VERSION >= 8) {
    include_once(__DIR__ . "/core/phastapi.php8.php");
}

include_once(__DIR__ . "/core/phastapi.core.php");   // router core
include_once(__DIR__ . "/libs/phastapi.utils.php");



if (isset($_GET["test"]) && $_GET["test"] == "true") {
    include_once(__DIR__ . "/config.test.phastapi.php");      // configuration
} else {
    include_once(__DIR__ . "/config.phastapi.php");      // configuration
}
include_once(__DIR__ . "/core/phastapi.logger.php");
include_once(__DIR__ . "/core/phastapi.auth.php");   // authorization
// print_r($GLOBALS["PHASTAPI_GLOBALS"]);

PHASTAPI_AUTH::SetTokenInfo($GLOBALS["PHASTAPI_GLOBALS"]["ACCESS_TOKEN_KEY"],
    $GLOBALS["PHASTAPI_GLOBALS"]["ACCESS_TOKEN_EXPIRE"],
    $GLOBALS["PHASTAPI_GLOBALS"]["REFRESH_TOKEN_KEY"],
    $GLOBALS["PHASTAPI_GLOBALS"]["REFRESH_TOKEN_EXPIRE"],
    $GLOBALS["PHASTAPI_GLOBALS"]["SECRET_DATA"],
    $GLOBALS["PHASTAPI_GLOBALS"]["SECRET_KEY"]);

include_once(__DIR__ . "/core/phastapi.legacydb.php");
include_once(__DIR__ . "/core/phastapi.background.process.php");

// 문서 노출 여부와 인증 동작을 분리한다.
if (!empty($G_PHASTAPI_LEGACY_AUTH_ENABLE)) {
    include_once(__DIR__ . "/index.phast.php");
}

// URL parsing is based on REQUEST_URI so routing behaves the same behind
// Apache mod_rewrite and Nginx/PHP-FPM.
$url = phastapi_request_path();
$base_url = phastapi_normalize_base_url($G_PHASTAPI_BASEURL);
if ($base_url !== "" &&
    $url !== $base_url &&
    !str_starts_with($url, $base_url . "/")) {
    http_response_code(404);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode(["error" => "PHASTAPI_BASE_URL_MISMATCH"], JSON_UNESCAPED_SLASHES);
    exit(0);
}

$relative_url = $base_url === "" ? $url : substr($url, strlen($base_url));
$segments = array_values(array_filter(explode("/", trim($relative_url, "/")), "strlen"));
$entry = $segments[0] ?? "";

if (isset($G_SHOW_API_ENTRY) && $G_SHOW_API_ENTRY) {
    // api docs ------------------------
    if ($entry == "docs") {
        include_once(__DIR__ . "/core/phastapi.docs.php");
        exit(0);
    }
    // ----------------------------------

    if ($entry == "entries") {
        include_once(__DIR__ . "/core/phastapi.entry.php");
        show_entry();
        exit(0);
    }
}

$domain_path = $G_PHASTAPI_DOMAIN_DIR;
if (is_dir($G_PHASTAPI_DOMAIN_DIR . "/common")) {
    include_all_files_in_dir($G_PHASTAPI_DOMAIN_DIR . "/common");
}

if ($entry != "") {
    auto_include($entry); // auto include domain files
    // this line is last of framework
    include_once(__DIR__ . "/core/phastapi.request.parser.php");
} else {
    echo ("PHAST API Framework 2<br/>Mobipin Technology<br/>version : " . PHASTAPI_VERSION);
}

