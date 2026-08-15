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

// url parsing
$url = $_SERVER["REQUEST_URI"];
if (($qs_pos = strpos($url, "?")) !== false) {
    $url = substr($url, 0, $qs_pos);
}
$urls = explode("/", $url);
$num_slashes = count(explode("/", $G_PHASTAPI_BASEURL)) - 1;

if (isset($G_SHOW_API_ENTRY) && $G_SHOW_API_ENTRY) {
    // api docs ------------------------
    if ($urls[$num_slashes + 1] == "docs") {
        include_once(__DIR__ . "/core/phastapi.docs.php");
        exit(0);
    }
    // ----------------------------------

    if ($urls[$num_slashes + 1] == "entries") {
        include_once(__DIR__ . "/core/phastapi.entry.php");
        show_entry();
        exit(0);
    }
}

$domain_path = $G_PHASTAPI_DOMAIN_DIR;
if (is_dir($G_PHASTAPI_DOMAIN_DIR . "/common")) {
    include_all_files_in_dir($G_PHASTAPI_DOMAIN_DIR . "/common");
}

if (count($urls) >= (2 + $num_slashes) && $urls[1 + $num_slashes] != "") {
    auto_include($urls[1 + $num_slashes]); // auto include domain files
    // this line is last of framework
    include_once(__DIR__ . "/core/phastapi.request.parser.php");
} else {
    echo ("PHAST API Framework 2<br/>Mobipin Technology<br/>version : " . PHASTAPI_VERSION);
}

