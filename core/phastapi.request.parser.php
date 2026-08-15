<?php
$inputdata = "";
$inputdata_json = null;

$headers = apache_request_headers_insensitive();
$content_type = strtolower((string)($headers["content-type"] ?? ""));
$multiform_file_upload = str_starts_with($content_type, 'multipart/form-data');

if ($G_LOGGING_REQUEST) {
    $requestAPI = $_SERVER["REQUEST_METHOD"] . " " . $_SERVER["REQUEST_URI"];
    if ($multiform_file_upload) {
        LOG::info("[CAL]", $requestAPI, $_POST, $_FILES);
    } else {
        LOG::info("[CAL]", $requestAPI, "(body omitted in log)");
    }
}

if ($multiform_file_upload) {
    // 파일은 $_FILES에 있으므로 json_body 파싱이 파일 본문을 메모리에 올리지는 않는다.
    if (isset($_POST["json_body"]) && trim((string)$_POST["json_body"]) !== "") {
        $inputdata = $_POST["json_body"];
        $inputdata_json = json_decode($inputdata);
        if (json_last_error() != JSON_ERROR_NONE || is_object($inputdata_json) == false) {
            PReturn(false, "UNPROCESSABLE_ENTITY");
        }
    } else {
        $inputdata = json_encode($_POST);
        $inputdata_json = json_decode($inputdata);
    }
} else {
    // JSON 요청만: php://input에서 body 읽어 파싱
    $inputdata = file_get_contents('php://input');
    if (trim($inputdata) !== "") {
        $inputdata_json = json_decode($inputdata);

        if (json_last_error() != JSON_ERROR_NONE || is_object($inputdata_json) == false) {

            if (str_starts_with($content_type, 'application/x-www-form-urlencoded')) {
                parse_str($inputdata, $parsed);
                $inputdata_json = (object) $parsed;
            } else {
                PReturn(false, "UNPROCESSABLE_ENTITY");
            }
        }
    }
}

try {
    if ($G_CROSS_ORIGIN_ENABLE) {
        $cross_origin_allow_headers =
            [
                "Accept",
                "Authorization",
                "Content-Type",
                "Content-Length",
                "X-CSRF-Token",
                "X-Page-Param",
            ];

        $cross_origin_expose_headers =
            [
                "X-Page-Param",
            ];
        if ($G_CROSS_ORIGIN_ALLOW_HEADERS) {
            $cross_origin_allow_headers = array_merge($cross_origin_allow_headers, $G_CROSS_ORIGIN_ALLOW_HEADERS);
            $cross_origin_allow_headers = array_unique($cross_origin_allow_headers);
        }

        if ($G_CROSS_ORIGIN_EXPOSE_HEADERS) {
            $cross_origin_expose_headers = array_merge($cross_origin_expose_headers, $G_CROSS_ORIGIN_EXPOSE_HEADERS);
            $cross_origin_expose_headers = array_unique($cross_origin_expose_headers);
        }

        $allow_origin = $G_CROSS_ORIGIN_ALLOW_ORIGIN ?? "*";
        $allow_credentials = (bool)($G_CROSS_ORIGIN_ALLOW_CREDENTIALS ?? false);
        if ($allow_credentials && $allow_origin === "*") {
            // 자격 증명과 wildcard origin은 브라우저 CORS 규격상 함께 쓸 수 없다.
            $allow_credentials = false;
        }
        header("Access-Control-Allow-Origin: " . $allow_origin);
        header("Access-Control-Allow-Credentials: " . ($allow_credentials ? "true" : "false"));
        header("Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS");
        header("Access-Control-Allow-Headers: " . implode(", ", $cross_origin_allow_headers));
        header("Access-Control-Expose-Headers: " . implode(", ", $cross_origin_expose_headers));

        if ($_SERVER["REQUEST_METHOD"] == "OPTIONS") {
            header("Access-Control-Max-Age: 86400");
            header("Content-Length: 0");
            header("Content-Type: text/plain");
            header("HTTP/1.1 200 OK");
            exit(0);
        }
    }

    PHASTAPI::FindMatchedFunction($_SERVER["REQUEST_METHOD"], $_SERVER["REQUEST_URI"], $inputdata_json, $inputdata);
} catch ( Throwable $e ) {
    Log::error($e->getMessage());
    header('HTTP/1.0 500 Internal Server Error', true, 500);
    PReturn(false, $e->getMessage());
}