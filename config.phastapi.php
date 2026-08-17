<?php

$G_ISCLI = php_sapi_name() == "cli";
// echo("G_ISCLI : " . $G_ISCLI . "\n");

$G_TIMEZONE = "Asia/Seoul";
$G_API_ROOT = __DIR__ . "/..";          // api root 패스
$G_PHASTAPI_BASEURL = phastapi_detect_base_url(); // PHASTAPI_BASE_URL 또는 index.php 경로
$G_SUPPORT_PLANTUML = true;             // plantuml 지원 여부
$G_PLANTUML_URL = "https://www.plantuml.com/plantuml/svg";    // plantuml 서버 주소

// 아래 내용들은 override에서 변경 가능
$G_PHASTAPI_LOGPATH = __DIR__ . "/log";     // 로그 파일 저장 패스

$G_PHASTAPI_DOMAIN_DIR = "domain";               // 도메인 파일 패스

$G_LOGGING_REQUEST = false;              // 요청 로깅 여부
$G_LOGGING_RESPONSE = false;             // 응답 로깅 여부

$G_SHOW_API_ENTRY = true;                // API docs // entry 표시 여부
$G_PHASTAPI_LEGACY_AUTH_ENABLE = PHP_MAJOR_VERSION < 8;

// 앱 전용 응답 규격이 필요할 때 override에서 callable을 지정한다.
$G_PHASTAPI_RESPONSE_FORMATTER = null;

$G_CROSS_ORIGIN_ENABLE = false;          // Cross Origin 허용 여부
$G_CROSS_ORIGIN_ALLOW_ORIGIN = "*";      // 특정 origin 권장 (예: https://example.com)
$G_CROSS_ORIGIN_ALLOW_CREDENTIALS = false;
$G_CROSS_ORIGIN_ALLOW_HEADERS = [
    // "Accept",
    // "Authorization",
    // "Content-Type",
    // "Content-Length",
    // "X-Page-Param",
];

$G_CROSS_ORIGIN_EXPOSE_HEADERS = [
    // "X-Page-Param",
];


// token 관련 전역 변수
$GLOBALS["PHASTAPI_GLOBALS"] = array();   // 전역 변수
$GLOBALS["PHASTAPI_GLOBALS"]["ACCESS_TOKEN_KEY"] = getenv("PHASTAPI_ACCESS_TOKEN_KEY") ?: "IAMSUPERMAN";
$GLOBALS["PHASTAPI_GLOBALS"]["ACCESS_TOKEN_EXPIRE"] = 60 * 60 * 24 * 30; // 30 days
$GLOBALS["PHASTAPI_GLOBALS"]["REFRESH_TOKEN_KEY"] = getenv("PHASTAPI_REFRESH_TOKEN_KEY") ?: "IAMSUPERMANREFRESH";
$GLOBALS["PHASTAPI_GLOBALS"]["REFRESH_TOKEN_EXPIRE"] = 60 * 60 * 24 * 30 * 6; // 180 days
$GLOBALS["PHASTAPI_GLOBALS"]["SECRET_DATA"] = getenv("PHASTAPI_SECRET_DATA") ?: "IAM!SUPER!MAIN!!!";
$GLOBALS["PHASTAPI_GLOBALS"]["SECRET_KEY"] = getenv("PHASTAPI_SECRET_KEY") ?: "aV9hbV9zdXBlcm1hbl95b3Vfa25vdw=="; //base64 encode
$GLOBALS["PHASTAPI_GLOBALS"]["SERIAL_SECRET_KEY"] = "aV9hbV9zdXBlcm1hbl95b3Vfa25vdw=="; //base64 encode
$GLOBALS["PHASTAPI_GLOBALS"]["ORDER_TOKE_EXPIRE"] = (60 * 40); // 40 minutes

// override config
// For each developer, who can have different settings,
// add --assume-unchanged this file


$G_PHASTAPI_CUSTOM_DIR = getenv("PHASTAPI_CUSTOM_DIR") ?: "../wikiman-php"; // 사용자 정의 앱 경로
/************* 사용자 정의 설정 파일 로드 : 수정할 필요 없음 *************/
$custom_root = str_starts_with($G_PHASTAPI_CUSTOM_DIR, "/")
    ? rtrim($G_PHASTAPI_CUSTOM_DIR, "/")
    : __DIR__ . "/" . trim($G_PHASTAPI_CUSTOM_DIR, "/");
$custom_override_config_file_path = $custom_root . "/config.phastapi.override.php";
$custom_lib_path = $custom_root . "/libs";
include_all_files_in_dir($custom_lib_path. "/common");

// filter dir
$custom_filter_path = $custom_root . "/filter";
include_all_files_in_dir($custom_filter_path);

if (file_exists($custom_override_config_file_path)) {
    $G_API_ROOT = $G_PHASTAPI_CUSTOM_DIR;
    include_once($custom_override_config_file_path);
    $G_PHASTAPI_BASEURL = phastapi_normalize_base_url($G_PHASTAPI_BASEURL);

    // 사용자 정의 에러 코드 추가
    if (isset($G_CUSTOM_ERROR_CODE)) {
        PHASTAPI_ERROR::add_error_code($G_CUSTOM_ERROR_CODE);
    }
} else {
    http_response_code(500);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode(["error" => "config.phastapi.override.php is not found"], JSON_UNESCAPED_SLASHES);
    exit(1);
}
