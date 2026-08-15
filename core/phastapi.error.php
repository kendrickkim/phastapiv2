<?php

$pre_defined_error_code = "
    NO_RESPONSE : -1 : No response
    NO_ERROR : 0 : No error
    OK : 200 : OK
    BAD_REQUEST : 400 : Bad Request
    UNAUTHORIZED : 401 : Unauthorized
    FORBIDDEN : 403 : Forbidden
    NOT_FOUND : 404 : Not Found
    METHOD_NOT_ALLOWED : 405 : Method Not Allowed
    NOT_ACCEPTABLE : 406 : Not Acceptable
    PROXY_AUTHENTICATION_REQUIRED : 407 : Proxy Authentication Required
    PAYLOAD_TOO_LARGE : 413 : Payload Too Large
    MISSING_PARAMETERS : 415 : Missing Parameters
    UNSUPPORTED_MEDIA_TYPE : 415 : Unsupported Media Type
    UNPROCESSABLE_ENTITY : 422 : Unprocessable Entity
    TOO_MANY_REQUESTS : 429 : Too Many Requests
    INTERNAL_SERVER_ERROR : 500 : Internal Server Error
    SERVICE_UNAVAILABLE : 503 : Service Unavailable
    GATEWAY_TIMEOUT : 504 : Gateway Timeout
    HTTP_VERSION_NOT_SUPPORTED : 505 : HTTP Version Not Supported
    NOT_MATCHED_API : 1001 : There is no REST API matched with your request.
    NOT_DEFINED_ERROR : 9998 : Not defined error
    UNKNOWN_ERROR : 9999 : Unknown Error
";

class PHASTAPI_ERROR
{
    public static $ERROR_CODE = [];
    public static $ERROR_CODE_MAP = [];
    public static function error( $message )
    {
        return [false, $message];
    }

    public static function add_error_code( $error_strings )
    {
        try {
            $error_strings = explode("\n", $error_strings);
            foreach ($error_strings as $error_string) {
                if (trim($error_string) == "") {
                    continue;
                }
                $error_string = explode(" : ", $error_string);
                if (trim($error_string[0]) == "" || trim($error_string[1]) == "" || trim($error_string[2]) == "") {
                    continue;
                }

                PHASTAPI_ERROR::$ERROR_CODE[trim($error_string[1])] = [
                    "code" => trim($error_string[1]),
                    "message" => trim($error_string[2])
                ];
                PHASTAPI_ERROR::$ERROR_CODE_MAP[trim($error_string[0])] = [
                    "code" => trim($error_string[1]),
                    "message" => trim($error_string[2])
                ];
            }
        } catch ( Exception $e ) {
            LOG::error($e->getMessage());
        }
    }

    public static function get_error_message( $error_code )
    {
        if (is_numeric($error_code)) {
            $error_code = strval($error_code);

            if (isset(PHASTAPI_ERROR::$ERROR_CODE[$error_code])) {
                return [
                    "code" => $error_code,
                    "message" => PHASTAPI_ERROR::$ERROR_CODE[$error_code]["message"]
                ];
            } else {
                return [
                    "code" => $error_code,
                    "message" => "Unknown error"
                ];
            }
        } else if (is_string($error_code)) {
            if (trim($error_code) == "") {
                return [
                    "code" => 9999,
                    "message" => "Unknown error"
                ];
            }

            if (isset(PHASTAPI_ERROR::$ERROR_CODE_MAP[$error_code])) {
                return [
                    "code" => PHASTAPI_ERROR::$ERROR_CODE_MAP[$error_code]["code"],
                    "message" => PHASTAPI_ERROR::$ERROR_CODE_MAP[$error_code]["message"]
                ];
            } else {
                return [
                    "code" => 9998,
                    "message" => $error_code
                ];
            }
        } else {
            return [
                "code" => 9999,
                "message" => "Unknown error"
            ];
        }
    }
}

PHASTAPI_ERROR::add_error_code($pre_defined_error_code);
