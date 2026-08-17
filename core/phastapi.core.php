<?php
/*
    PHAST API Framework
    Author : Kendrick Kim(kjkim@mobipintech.com)
    Last Update : 2023-09-29
*/

include_once(__DIR__ . "/lib.core.php");
const POST = "post";
const GET = "get";
const PUT = "put";
const PATCH = "patch";
const DELETE = "delete";

class PHASTAPI_CORE
{
    public static $apis = array();
    public static $pre_router_functions = array();
    public static $in_filters = array();
    public static $out_filters = array();

    static function add_entry( $req_type, $pattern, $func )
    {
        global $G_PHASTAPI_BASEURL;

        array_push(self::$apis, array(
            "req_type" => $req_type,
            "patterns" => self::URLPatternToArray($G_PHASTAPI_BASEURL . $pattern),
            "pattern" => $pattern,
            "func" => $func
        ));

    }
    static function put( $pattern, $func )
    {
        self::add_entry("PUT", $pattern, $func);
    }
    static function patch( $pattern, $func )
    {
        self::add_entry("PATCH", $pattern, $func);
    }
    static function get( $pattern, $func )
    {
        self::add_entry("GET", $pattern, $func);
    }
    static function post( $pattern, $func )
    {
        self::add_entry("POST", $pattern, $func);
    }
    static function delete( $pattern, $func )
    {
        self::add_entry("DELETE", $pattern, $func);
    }

    static function URLPatternToArray( $url = "" )
    {
        global $G_API_ROOT;

        $patterns = array();

        $is_open_brace = false;
        $is_close_brace = true;

        $pattern_str = "";
        for ($i = 0; $i < strlen($url); $i++) {
            $c = $url[$i];
            if ($c == '{' && $is_close_brace) {
                $is_open_brace = true;
                $is_close_brace = false;
                array_push($patterns, $pattern_str);
                $pattern_str = "{";
                continue;
            }

            if ($c == '}' && $is_open_brace) {
                $is_open_brace = false;
                $is_close_brace = true;
                array_push($patterns, $pattern_str . "}");
                $pattern_str = "";
                continue;
            }
            $pattern_str .= $c;

        }

        if ($pattern_str != "")
            array_push($patterns, $pattern_str);

        return $patterns;
    }

    static function URLParse( $patterns, $url )
    {
        $url_target = $url;
        $args = new stdClass();

        for ($i = 0; $i < count($patterns); $i++) {
            $pattern = $patterns[$i];
            $is_variable = false;
            $variable_str = "";

            if ($pattern[0] == '{' && $pattern[strlen($pattern) - 1] == '}') {
                $is_variable = true;
            }

            if (!$is_variable) {
                if (str_starts_with($url_target, $pattern)) {
                    $url_target = substr($url_target, strlen($pattern));
                } else {
                    return false;
                }
            } else if ($is_variable) {
                if ($i < count($patterns) - 1) {
                    $next_pattern = $patterns[$i + 1];
                    $l_next_pattern = strstr($url_target, $next_pattern);
                    $variable_str = substr($url_target, 0, strlen($url_target) - strlen($l_next_pattern));
                    $variable_str = explode("/", $variable_str)[0];
                    $url_target = $l_next_pattern;
                } else {
                    $variable_str = explode("/", $url_target)[0];
                    $url_target = substr($url_target, strlen($variable_str));
                }

                $member_name = $pattern;
                $member_name = substr($member_name, 1, strlen($member_name) - 2);
                $args->$member_name = $variable_str;
            }
        }

        if ($url_target != "")
            return false;

        return $args;
    }

    static function AddPreRouterFunction( $func )
    {
        array_push(self::$pre_router_functions, $func);
    }

    static function FindMatchedFunction( $req_type, $req_url, $req_body, $req_body_rare )
    {
        global $G_PHASTAPI_BASEURL;
        $query_strings = new stdClass();

        if (strpos($req_url, "?") !== false) {
            list($req_url, $query_string) = explode("?", $req_url, 2);
            parse_str($query_string, $parsed_query);
            $query_strings = (object) $parsed_query;
        } else if (!empty($_SERVER["QUERY_STRING"])) {
            // Front controllers often pass a path without "?...", so query
            // parameters come from QUERY_STRING / $_GET instead.
            parse_str((string)$_SERVER["QUERY_STRING"], $parsed_query);
            $query_strings = (object) $parsed_query;
        } else if (!empty($_GET) && is_array($_GET)) {
            $query_strings = (object) $_GET;
        }
        // sort by patterns count and pattern
        usort(self::$apis, function ($a, $b) {
            $a_patterns = $a["patterns"];
            $b_patterns = $b["patterns"];

            if (count($a_patterns) > count($b_patterns))
                return -1;
            else if (count($a_patterns) < count($b_patterns))
                return 1;
            else {
                return strcmp($a["pattern"], $b["pattern"]);
            }
        });

        $found_api = false;


        for ($i = count(self::$apis) - 1; $i >= 0; $i--) {

            $api = self::$apis[$i];
            $req_url_pattern = substr($req_url, strlen($G_PHASTAPI_BASEURL));

            if (count(explode("/", $api["pattern"])) != count(explode("/", $req_url_pattern)))  // check url element count
                continue;

            $patterns = $api["patterns"];
            $pre_pass_all = true;
            $parse_ret = self::URLParse($patterns, $req_url);

            if ($req_type == $api["req_type"] && $parse_ret != false) {
                $func = $api["func"];
                $found_api = true;

                $user_info = null;
                $is_white_list = false;
                if (PHP_MAJOR_VERSION < 8) {
                    $is_white_list = isWhiteList($req_type, $api["pattern"]);
                    if ($is_white_list) {
                        $user_info = PHASTAPI_AUTH::GetUserInfo();
                    }
                }

                $list_param = null;
                if ($req_type == "GET")
                    $list_param = self::GetPageParams();

                // IN 필터는 pre-router 등록 여부와 관계없이 항상 실행한다.
                if (count(self::$in_filters) > 0) {
                    array_multisort(array_column(self::$in_filters, 'order'), SORT_ASC, self::$in_filters);
                }
                foreach (self::$in_filters as $in_filter) {
                    $args = new stdClass();
                    $args->req_type = $req_type;
                    $args->url_pattern = $api["pattern"];
                    $args->req_body = $req_body;
                    $args->req_body_rare = $req_body_rare;
                    $args->query_strings = $query_strings;
                    $args->user_info = $user_info;
                    $args->list_param = $list_param;
                    $args->func = $func;
                    if (PHP_MAJOR_VERSION >= 8) {
                        FILTER::SetArgs($args);
                    } else {
                        $args->is_white_list = $is_white_list;
                    }
                    $filter_passed = $in_filter["filter"]($args);
                    if ($filter_passed !== true) {
                        PReturn($filter_passed);
                        return;
                    }
                }

                foreach (self::$pre_router_functions as $pre_func) {
                    $pre_pass_all = $pre_func($req_type, $api["pattern"], $req_body, $req_body_rare, $query_strings, $user_info, $list_param);
                    if ($pre_pass_all == false) {
                        PReturn(false, "pre_router_functions failed");
                        return;
                    }
                }

                if (PHP_MAJOR_VERSION >= 8) {
                    REQUEST::SetArgs($parse_ret, $req_body, $req_body_rare, $query_strings);
                }
                $ret = $func($parse_ret, $req_body, $req_body_rare, $query_strings);

                if (count(self::$out_filters) > 0) {
                    array_multisort(array_column(self::$out_filters, 'order'), SORT_ASC, self::$out_filters);
                }
                foreach (self::$out_filters as $out_filter) {
                    $ret = $out_filter["filter"]($ret);
                }

                PReturn($ret);
            }
        }

        if (!$found_api) {
            // header('HTTP/1.0 404 Not Found REST API matched with your request.', true, 404);
            PReturn(false, "NOT_MATCHED_API");
        }
    }
    public static function GetPageParams()
    {
        $headers = request_headers_insensitive();
        $r = new PHASTAPI_LIST_PARAM();
        if (!isset($headers["x-page-param"])) {
            return $r;
        }

        $list_param_str = $headers["x-page-param"];
        $r = new PHASTAPI_LIST_PARAM();
        if (trim($list_param_str) != "") {
            $r->Parse($list_param_str);
        }

        return $r;
    }

    public static function SetPageParams( $total_count )
    {
        header("x-page-param: " . json_encode(array(
            "total_count" => $total_count
        )));
    }

    public static function SetPageParamsCustom( $values )
    {
        header("x-page-param: " . json_encode($values));
    }

    public static function AddInFilter( $filter, $order = -1 )
    {
        $f = array(
            "filter" => $filter,
            "order" => $order
        );
        array_push(self::$in_filters, $f);
    }

    public static function AddOutFilter( $filter, $order = -1 )
    {
        $f = array(
            "filter" => $filter,
            "order" => $order
        );
        array_push(self::$out_filters, $f);
    }
}



class PHASTAPI_LIST_PARAM
{
    public $page = 1; // index is started from 1
    public $page_size = 10;
    public $order_by_array = array();
    public function Parse( $param_json )
    {
        $params = json_decode($param_json);

        if (isset($params->page)) {
            $this->page = $params->page;
        }
        if (isset($params->page_size))
            $this->page_size = $params->page_size;
        if (isset($params->order)) {
            $order = $params->order;
            $order_array = explode(",", $order);
            foreach ($order_array as $order_item) {
                $order_item = trim($order_item);
                $order_by = "ASC";
                if (substr($order_item, -1) == "+") {
                    $order_by = "ASC";
                    $order_item = substr($order_item, 0, strlen($order_item) - 1);
                } else if (substr($order_item, -1) == "-") {
                    $order_by = "DESC";
                    $order_item = substr($order_item, 0, strlen($order_item) - 1);
                }

                $db_keywords = ["index", "order", "group", "by", "limit", "asc", "desc", "name", "text", "select"];
                if (in_array($order_item, $db_keywords) == true) {
                    $order_item = "`" . $order_item . "`";
                }
                array_push($this->order_by_array, array(
                    "column" => $order_item,
                    "order" => $order_by
                ));
            }
        }
    }

    public function BuildLimitQuery()
    {
        return " LIMIT " . ($this->page - 1) * $this->page_size . ", " . $this->page_size;
    }

    public function BuildOrderQuery()
    {
        $order_query = "";
        foreach ($this->order_by_array as $order_item) {
            $order_query .= $order_item["column"] . " " . $order_item["order"] . ", ";
        }
        if ($order_query != "")
            $order_query = " ORDER BY " . substr($order_query, 0, strlen($order_query) - 2);
        return $order_query;
    }

    public function BuildOrderLimitQuery()
    {
        return $this->BuildOrderQuery() . $this->BuildLimitQuery();
    }
}

function PHAST( $method, $pattern, $func, $add_white_list = false, $with_refresh_token = false )
{
    $method = strtolower($method);
    PHASTAPI_CORE::$method($pattern, $func);

    if ($add_white_list && function_exists("addWhiteList")) {
        $method = strtoupper($method);
        addWhiteList($method, $pattern, $with_refresh_token);
    }

}

function _POST_( $pattern, $func, $add_white_list = false, $with_refresh_token = false )
{
    PHAST("post", $pattern, $func, $add_white_list, $with_refresh_token);
}
function _PUT_( $pattern, $func, $add_white_list = false, $with_refresh_token = false )
{
    PHAST("put", $pattern, $func, $add_white_list, $with_refresh_token);
}
function _PATCH_( $pattern, $func, $add_white_list = false, $with_refresh_token = false )
{
    PHAST("patch", $pattern, $func, $add_white_list, $with_refresh_token);
}
function _GET_( $pattern, $func, $add_white_list = false, $with_refresh_token = false )
{
    PHAST("get", $pattern, $func, $add_white_list, $with_refresh_token);
}

function _DELETE_( $pattern, $func, $add_white_list = false, $with_refresh_token = false )
{
    PHAST("delete", $pattern, $func, $add_white_list, $with_refresh_token);
}

function _ADD_IN_FILTER_( $filter, $order = -1 )
{
    PHASTAPI_CORE::AddInFilter($filter, $order);
}

function _ADD_OUT_FILTER_( $filter, $order = -1 )
{
    PHASTAPI_CORE::AddOutFilter($filter, $order);
}
