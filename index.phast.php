<?php

$auth_whiteurl_list = array();
function addWhiteList( $req_type, $url_pattern, $with_refresh_token = false )
{
    global $auth_whiteurl_list;
    array_push($auth_whiteurl_list, array($req_type, $url_pattern, $with_refresh_token));
}

function addWhiteListByFunction( $function )
{
    global $auth_whiteurl_list;
    foreach (PHASTAPI_CORE::$apis as $api) {
        if ($api["func"] == $function) {
            array_push($auth_whiteurl_list, array($api["req_type"], $api["pattern"], false));
        }
    }
}

function isWhiteList( $req_type, $url_pattern )
{
    global $auth_whiteurl_list;
    foreach ($auth_whiteurl_list as $index => $whiteurl) {
        if ($whiteurl[0] == $req_type && $whiteurl[1] == $url_pattern) {
            return true;
        }
    }
    return false;
}

PHASTAPI::AddPreRouterFunction(
    // check auth
    function ($req_type, $url_pattern) {
        global $auth_whiteurl_list;
        $is_white = false;
        $with_refresh_token = false;

        foreach ($auth_whiteurl_list as $index => $whiteurl) {
            if ($whiteurl[0] == $req_type && $whiteurl[1] == $url_pattern) {
                $is_white = true;
                $with_refresh_token = $whiteurl[2];
                break;
            }
        }

        if ($is_white && !$with_refresh_token)
            return true;

        $access_token = PHASTAPI_AUTH::GetAccessToken();
        if (PHASTAPI_AUTH::CheckValidAccessToken($access_token))
            return true;

        if ($with_refresh_token && PHASTAPI_AUTH::CheckValidRefreshToken($access_token))
            return true;

        PReturn(false, "UNAUTHORIZED");
    }
);