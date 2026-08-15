<?php

$db = null;

function mysql_query( $sql )
{
    $db = dbconn();
    $result = mysqli_query($db, $sql);
    if (!$result) {
        LOG::error("mysql query error : \n", mysqli_error($db), "\n", $sql);
    }

    return $result;
}

function mysqli_result( $res, $row = 0 )
{
    return mysql_result($res, $row = 0);
}

function mysql_result( $res, $row = 0 )
{
    $data = mysqli_fetch_row($res);
    return $data[$row];
}

function dbconn()
{
    global $G_PHASTAPI_DBINFO;
    static $db = null;

    if ($db == null) {
        $db = @mysqli_connect(
            $G_PHASTAPI_DBINFO["host"],
            $G_PHASTAPI_DBINFO["user"],
            $G_PHASTAPI_DBINFO["password"],
            $G_PHASTAPI_DBINFO["database"]
        );
        @mysqli_query($db, "set names utf8");
    }

    if (!$db)
        die("DB Connection is failed");

    return $db;
}

function buildwhere( $fieldname, $value, $tf = true )
{
    if ($value == null)
        return "";

    // $where = " and ";
    $where = "";
    if (!$tf)
        $where .= " not ";

    // :, +:, -:, +, -
    if (gettype($value) == "array") {
        $where .= "$fieldname in (";
        foreach ($value as $index => $element) {
            if (gettype($element) == "string")
                $where = $where . ("'" . $element . "'");
            else
                $where = $where . ($element);

            if ($index < count($value) - 1)
                $where .= ",";
        }
        $where .= ")";
    } else {
        $pos = strpos($value, ":");
        if ($pos === false) {
            $op = substr($value, 0, 1);
            $val = substr($value, 1);
            if ($op === "-")
                $where .= "$fieldname < " . (is_numeric($val) ? "$val" : "'$val'");
            else if ($op === "+")
                $where .= "$fieldname > " . (is_numeric($val) ? "$val" : "'$val'");
            else {
                $pos = strpos($value, ",");
                $where .= "$fieldname in (";
                if ($pos === false) {
                    $where .= (is_numeric($value) ? "$value" : "'$value'");
                } else {
                    $vals = explode(",", $value);
                    foreach ($vals as $i => $val) {
                        if ($i > 0)
                            $where .= ",";
                        $where .= (is_numeric($val) ? "$val" : "'$val'");
                    }
                }
                $where .= ")";
            }
        } else {
            $op = substr($value, 0, $pos + 1);
            $val = substr($value, $pos + 1);
            if ($op === "-:")
                $where .= "$fieldname <= " . (is_numeric($val) ? "$val" : "'$val'");
            else if ($op === "+:")
                $where .= "$fieldname >= " . (is_numeric($val) ? "$val" : "'$val'");
            else {
                $pos = strpos($value, ",");
                $where .= "$fieldname in (";
                if ($pos === false) {
                    $where .= (is_numeric($val) ? "$val" : "'$val'");
                } else {
                    $vals = explode(",", $val);
                    foreach ($vals as $i => $val) {
                        if ($i > 0)
                            $where .= ",";
                        $where .= (is_numeric($val) ? "$val" : "'$val'");
                    }
                }
                $where .= ")";
            }
        }
    }

    return $where;
}

function sql_start_transaction()
{
    return sql_query("START TRANSACTION");
}

function sql_commit()
{
    return sql_query("COMMIT");
}

function sql_rollback()
{
    return sql_query("ROLLBACK");
}

function sql_query( $sql )
{
    return mysql_query($sql);
}

function sql_fetch( $res )
{
    return mysqli_fetch_assoc($res);
}

function sql_fetch_all( $res )
{
    return mysqli_fetch_all($res, MYSQLI_ASSOC);
}

function sql_result( $res, $col = 0 )
{
    if(is_string($res))
    {
        $res = sql_query($res);
    }
    return mysqli_fetch_row($res)[$col];
}

function sql_insert_id()
{
    return mysqli_insert_id(dbconn());
}

function sql_real_escape_string( $str )
{
    return mysqli_real_escape_string(dbconn(), $str);
}
/**
 * @param $update_set_string
 * @param $key
 * @param $value
 * @return string
 * @description add_update_set_string
 */
function add_update_set_string( $update_set_string, $key, $value )
{
    if ($value != null) {
        if (is_string($value))
            $value = "'$value'";
        if ($update_set_string == "")
            $update_set_string = "$key = $value";
        else
            $update_set_string .= ", $key = $value";
        $update_set_string .= ", $key = $value";
    }

    return $update_set_string;
}

function sql_errno()
{
    return mysqli_errno(dbconn());
}

function sql_error()
{
    return mysqli_error(dbconn());
}