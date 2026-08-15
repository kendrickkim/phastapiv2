<?php
class PHASTAPI_AUTH
{
    const ERR_FAILED = 0;
    const ERR_SUCCESS = 1;
    const ERR_KEY_EXPIRED = 100;
    const ERR_KEY_INVALID = 101;
    static $access_token_key;
    static $access_token_expire;
    static $refresh_token_key;
    static $refresh_token_expire;
    static $secret_data;
    static $secret_key;

    public static function SetTokenInfo( $access_token_key, $access_token_expire, $refresh_token_key, $refresh_token_expire, $secret_data, $secret_key )
    {
        self::$access_token_key = $access_token_key;
        self::$access_token_expire = $access_token_expire;
        self::$refresh_token_key = $refresh_token_key;
        self::$refresh_token_expire = $refresh_token_expire;
        self::$secret_data = $secret_data;
        self::$secret_key = $secret_key;
    }

    public static function Get16BytesFromMilliSecond()
    {
        $msec = round(microtime(true) * 1000);
        $msec_str = strval($msec);

        $msec_str = str_pad($msec_str, 16, "0", STR_PAD_LEFT);
        $msec_str = substr($msec_str, strlen($msec_str) - 16, 16);
        return $msec_str;
    }

    public static function CheckEmptyToken( $token )
    {
        if (trim($token) == "") {
            LOG::info("access token : " . $token . " is empty, return unauthorized");
            header("HTTP/1.1 401 Access Token Expired");
            PReturn(false, "UNAUTHORIZED");
        }
    }

    public static function CheckValidAccessToken( $access_token )
    {
        self::CheckEmptyToken($access_token);

        $parsed = self::ParseToken($access_token);
        $payload = $parsed->payload;
        $secret = $parsed->secret;
        $iv = $payload->gen_tms;

        // expire time check
        $gen_ts = floor(intval($iv) / 1000);
        $token_expire = self::$access_token_expire;
        $expire_ts = $gen_ts + $token_expire;

        if ($expire_ts <= time()) {
            header("HTTP/1.1 401 Access Token Expired");
            PReturn(false, "UNAUTHORIZED");
        }

        // secret check
        $secret = self::DecryptWithKey($secret, self::$access_token_key, $iv);
        if (is_string($secret) && hash_equals((string)self::$secret_data, $secret))
            return true;
        else
            return false;
    }

    public static function CheckValidRefreshToken( $refresh_token )
    {
        self::CheckEmptyToken($refresh_token);

        $parsed = self::ParseToken($refresh_token);
        $payload = $parsed->payload;
        $secret = $parsed->secret;
        $iv = $payload->gen_tms;

        // expire time check
        $gen_ts = floor(intval($iv) / 1000);
        $expire_ts = $gen_ts + self::$refresh_token_expire;
        if ($expire_ts < time()) {
            header("HTTP/1.1 401 Access Token Expired");
            PReturn(false, "UNAUTHORIZED");
        }

        // secret check
        $secret = self::DecryptWithKey($secret, self::$refresh_token_key, $iv);
        if (is_string($secret) && hash_equals((string)self::$secret_data, $secret))
            return true;
        else
            return false;
    }

    public static function GetAccessToken()
    {
        $headers = apache_request_headers_insensitive();
        if (isset($headers["authorization"])) {
            $authorization = trim((string)$headers["authorization"]);
            if (preg_match('/^(MBPAuth|Bearer)\s+(\S+)$/i', $authorization, $matches)) {
                return $matches[2];
            }
            return "";
        } else {
            return "";
        }
    }

    public static function EncryptWithKey( $str, $key, $iv )
    {
        $key = hash('sha256', $key, true);
        $ciphertext = openssl_encrypt($str, "AES-256-CBC", $key, OPENSSL_RAW_DATA, $iv);
        return base64_encode($ciphertext);
    }

    public static function DecryptWithKey( $str, $key, $iv )
    {
        $key = hash('sha256', $key, true);
        $ciphertext = base64_decode($str);
        return openssl_decrypt($ciphertext, "AES-256-CBC", $key, OPENSSL_RAW_DATA, $iv);
    }

    public static function GenerateTokens( $user_info )
    {
        $r = new stdClass;
        $iv = self::Get16BytesFromMilliSecond();
        $user_info_json = json_encode($user_info);
        $user_info = json_decode($user_info_json, false);
        $user_info->gen_tms = $iv;

        $encryptedPayload = self::EncryptPayload($user_info);
        $secret_data = self::$secret_data;

        $common_header = "{\"alg\":\"HS256\",\"typ\":\"PHASTAPI\"}";
        $common_header = base64_encode($common_header);

        $accesstoken_header = $common_header;
        $accesstoken_secret = self::EncryptWithKey($secret_data, self::$access_token_key, $iv);

        $r->access_token = self::GenerateToken($accesstoken_header, $encryptedPayload, $accesstoken_secret);

        $refresh_token_header = $common_header;
        $refresh_token_secret = self::EncryptWithKey($secret_data, self::$refresh_token_key, $iv);

        $r->refresh_token = self::GenerateToken($refresh_token_header, $encryptedPayload, $refresh_token_secret);

        return $r;
    }

    public static function GenerateToken( $header, $payload, $secret )
    {
        $r = $header . "." . $payload . "." . $secret;
        return $r;
    }

    public static function EncryptPayload( $payload )
    {
        $token = $payload;
        $token = json_encode($token);
        $token = base64_encode($token);
        return $token;
    }

    public static function ParseToken( $token )
    {
        $tokens = explode(".", $token);
        if (count($tokens) !== 3) {
            PReturn(false, "UNAUTHORIZED");
        }

        $token_header = null;
        $token_payload = null;
        try {
            $header_json = base64_decode($tokens[0], true);
            $payload_json = base64_decode($tokens[1], true);
            if ($header_json === false || $payload_json === false) {
                PReturn(false, "UNAUTHORIZED");
            }
            $token_header = json_decode($header_json);
            $token_payload = json_decode($payload_json);
            if (!is_object($token_header) || !is_object($token_payload)
                || ($token_header->alg ?? null) !== "HS256"
                || ($token_header->typ ?? null) !== "PHASTAPI"
                || !isset($token_payload->gen_tms)
                || !preg_match('/^\d{16}$/', (string)$token_payload->gen_tms)
                || trim((string)$tokens[2]) === "") {
                PReturn(false, "UNAUTHORIZED");
            }
        } catch ( Throwable $e ) {
            PReturn(false, "UNAUTHORIZED");
        }

        $r = new stdClass;
        $r->header = $token_header;
        $r->payload = $token_payload;
        $r->secret = $tokens[2];

        return $r;
    }

    public static function GetUserInfo()
    {
        $access_token = self::GetAccessToken();

        if ($access_token == "") {
            return null;
        }

        $parsed = self::ParseToken($access_token);
        $user_info = $parsed->payload;

        return $user_info;
    }

    public static function CompareKey( $key )
    {
        if (strcmp($key, self::$secret_key) !== 0) {
            PReturn(false, "UNAUTHORIZED");
        }
    }
}