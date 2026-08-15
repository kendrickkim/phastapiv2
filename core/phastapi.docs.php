<?php
/**
 * PHAST API documentation generator.
 * Routes come from PHP attributes; descriptions and schemas come from triple-star doc comments.
 */

include_once(__DIR__ . "/lib.core.php");

header("Content-Type: text/html; charset=utf-8");

$docs_base_url = rtrim((string)($G_PHASTAPI_BASEURL ?? "/api"), "/");
$docs_asset_base = $docs_base_url === "" ? "" : $docs_base_url;
$docs_plantuml_enabled = !empty($G_SUPPORT_PLANTUML);
$docs_plantuml_url = rtrim((string)($G_PLANTUML_URL ?? "https://www.plantuml.com/plantuml/svg"), "/");
$domain_dir = $G_PHASTAPI_DOMAIN_DIR;
if (!str_starts_with((string)$domain_dir, "/")) {
    $domain_dir = realpath(__DIR__ . "/../" . trim((string)$domain_dir, "/")) ?: (__DIR__ . "/../" . trim((string)$domain_dir, "/"));
}

const DOC_PHASE_NONE = 0;
const DOC_PHASE_OPEN = 1;
const DOC_PHASE_URL_PARAMS = 3;
const DOC_PHASE_JSON_PARAMS = 4;
const DOC_PHASE_RETURN = 5;
const DOC_PHASE_QUERY_STRING = 6;
const DOC_PHASE_PLANTUML = 7;
const DOC_PHASE_REQ_BODY = 8;
const DOC_PHASE_MARKDOWN = 9;

$DOC_ROUTE_ATTRIBUTES = [
    "_GET_" => "GET",
    "_POST_" => "POST",
    "_PUT_" => "PUT",
    "_PATCH_" => "PATCH",
    "_DELETE_" => "DELETE",
    "REQUEST" => null,
];

$DOC_KNOWN_TYPES = [
    "number", "string", "json", "boolean", "bool", "uuid", "array",
    "mixed", "complex", "timestamp", "object", "file", "integer", "int", "float",
];

function docs_h( $value ): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
}

function docs_strip_comment_prefix( string $line ): string
{
    $line = trim($line);
    while (str_starts_with($line, "*")) {
        $line = trim(substr($line, 1));
    }
    return $line;
}

function docs_after_first_colon( string $line ): string
{
    $pos = strpos($line, ":");
    if ($pos === false) {
        return "";
    }
    return trim(substr($line, $pos + 1));
}

function docs_slug( string $value ): string
{
    $slug = preg_replace('/[^a-zA-Z0-9_\-\/{}]+/', "-", $value) ?? $value;
    return trim($slug, "-");
}

function docs_domain_from_url( string $url ): string
{
    $parts = array_values(array_filter(explode("/", trim($url, "/")), "strlen"));
    return $parts[0] ?? "root";
}

function docs_extract_url_params( string $url ): array
{
    preg_match_all('/\{([a-zA-Z0-9_]+)\}/', $url, $matches);
    $params = [];
    foreach ($matches[1] as $name) {
        $params[] = [
            "name" => $name,
            "type" => "string",
            "desc" => "",
            "optional" => false,
        ];
    }
    return $params;
}

function docs_encode_plantuml( string $text ): string
{
    $compressed = @gzdeflate($text, 9);
    if ($compressed === false) {
        return "";
    }

    $encode6bit = static function ( int $b ): string {
        if ($b < 10) {
            return chr(48 + $b);
        }
        $b -= 10;
        if ($b < 26) {
            return chr(65 + $b);
        }
        $b -= 26;
        if ($b < 26) {
            return chr(97 + $b);
        }
        $b -= 26;
        if ($b === 0) {
            return "-";
        }
        if ($b === 1) {
            return "_";
        }
        return "?";
    };

    $append3bytes = static function ( int $b1, int $b2, int $b3 ) use ($encode6bit): string {
        $c1 = $b1 >> 2;
        $c2 = (($b1 & 0x3) << 4) | ($b2 >> 4);
        $c3 = (($b2 & 0xF) << 2) | ($b3 >> 6);
        $c4 = $b3 & 0x3F;
        return $encode6bit($c1 & 0x3F)
            . $encode6bit($c2 & 0x3F)
            . $encode6bit($c3 & 0x3F)
            . $encode6bit($c4 & 0x3F);
    };

    $str = "";
    $len = strlen($compressed);
    for ($i = 0; $i < $len; $i += 3) {
        if ($i + 2 === $len) {
            $str .= $append3bytes(ord($compressed[$i]), ord($compressed[$i + 1]), 0);
        } else if ($i + 1 === $len) {
            $str .= $append3bytes(ord($compressed[$i]), 0, 0);
        } else {
            $str .= $append3bytes(ord($compressed[$i]), ord($compressed[$i + 1]), ord($compressed[$i + 2]));
        }
    }
    return $str;
}

function docs_find_type( string $str, array $known_types ): array
{
    if (preg_match('/\[([a-zA-Z0-9_|\/\.\+\-]+)\]/', $str, $matches)) {
        $type = $matches[1];
        $str = trim(str_replace($matches[0], "", $str));
        return [$type, $str];
    }

    foreach ($known_types as $t) {
        $find_type = "[" . $t . "]";
        if (str_contains($str, $find_type)) {
            return [$t, trim(str_replace($find_type, "", $str))];
        }
    }
    return ["", $str];
}

function docs_parse_param_line( string $line, array &$target, array $known_types ): void
{
    $line = docs_strip_comment_prefix($line);
    if ($line === "" || str_starts_with($line, "@")) {
        return;
    }

    if (str_starts_with($line, "__")) {
        if (count($target) === 0) {
            return;
        }
        $line = trim(substr($line, 2));
        $line = str_replace("\\t", "    ", $line);
        $target[count($target) - 1]["desc"] .= "\n" . $line;
        return;
    }

    if (!str_contains($line, ":")) {
        // Comma-separated return fields without types: a, b, c
        $names = array_filter(array_map("trim", explode(",", $line)), "strlen");
        if (count($names) > 1) {
            foreach ($names as $name) {
                $target[] = [
                    "name" => $name,
                    "desc" => "",
                    "type" => "",
                    "optional" => false,
                ];
            }
        }
        return;
    }

    $name_part = trim(explode(":", $line, 2)[0]);
    $optional = str_contains($line, "(optional)");
    if (str_starts_with($name_part, "-")) {
        $optional = true;
        $name_part = trim(substr($name_part, 1));
    }

    $names = array_filter(array_map("trim", explode(",", $name_part)), "strlen");
    if (count($names) === 0) {
        return;
    }

    [$type, $desc] = docs_find_type(docs_after_first_colon($line), $known_types);
    $desc = trim(str_replace("(optional)", "", $desc));
    if (str_starts_with($desc, ":")) {
        $desc = trim(substr($desc, 1));
    }

    foreach ($names as $name) {
        $target[] = [
            "name" => $name,
            "desc" => $desc,
            "type" => $type,
            "optional" => $optional,
        ];
    }
}

function docs_empty_api(): array
{
    return [
        "title" => "",
        "desc" => [],
        "path" => "",
        "method" => "",
        "url" => "",
        "auth" => [],
        "attrs" => [],
        "url_params" => [],
        "query_strings" => [],
        "json_params" => [],
        "req_body" => [],
        "req_body_meta" => "",
        "return" => [],
        "return_meta" => "",
        "source" => "comment",
    ];
}

function docs_parse_comment_block( string $comment, array $known_types, bool $plantuml_enabled ): array
{
    $api = docs_empty_api();
    $phase = DOC_PHASE_NONE;
    $plantuml = "";
    $markdown = "";
    $old_phase = DOC_PHASE_NONE;

    $comment = preg_replace('/^\s*\/\*\*\*?/', "", $comment) ?? $comment;
    $comment = preg_replace('/\*\/\s*$/', "", $comment) ?? $comment;

    $lines = preg_split("/\r\n|\n|\r/", $comment) ?: [];
    foreach ($lines as $raw_line) {
        $line = rtrim($raw_line);
        $stripped = docs_strip_comment_prefix($line);
        $upper = strtoupper($stripped);

        if ($plantuml_enabled && str_starts_with(strtolower($stripped), "<plantuml>")) {
            $old_phase = $phase;
            $phase = DOC_PHASE_PLANTUML;
            $plantuml = "";
            continue;
        }
        if ($plantuml_enabled && str_starts_with(strtolower($stripped), "</plantuml>") && $phase === DOC_PHASE_PLANTUML) {
            $phase = $old_phase;
            if (trim($plantuml) !== "") {
                $api["desc"][] = "@plantuml : " . $plantuml;
            }
            continue;
        }
        if (str_starts_with(strtolower($stripped), "<markdown>")) {
            $old_phase = $phase;
            $phase = DOC_PHASE_MARKDOWN;
            $markdown = "";
            continue;
        }
        if (str_starts_with(strtolower($stripped), "</markdown>") && $phase === DOC_PHASE_MARKDOWN) {
            $phase = $old_phase;
            if (trim($markdown) !== "") {
                $api["desc"][] = "@markdown : " . $markdown;
            }
            continue;
        }

        if ($phase === DOC_PHASE_MARKDOWN) {
            $markdown .= $stripped . "\n";
            continue;
        }
        if ($phase === DOC_PHASE_PLANTUML) {
            $plantuml .= $stripped . "\n";
            continue;
        }

        if (str_starts_with($upper, "@ METHOD")) {
            $api["method"] = strtoupper(docs_after_first_colon($stripped));
            $phase = DOC_PHASE_NONE;
            continue;
        }
        if (str_starts_with($upper, "@ URL ARGS") || str_starts_with($upper, "@ URL PARAMETERS")) {
            $phase = DOC_PHASE_URL_PARAMS;
            $api["url_params"] = [];
            continue;
        }
        if (str_starts_with($upper, "@ URL")) {
            $api["url"] = docs_after_first_colon($stripped);
            $phase = DOC_PHASE_NONE;
            continue;
        }
        if (str_starts_with($upper, "@ JSON PARAMETERS")) {
            $phase = DOC_PHASE_JSON_PARAMS;
            $api["json_params"] = [];
            continue;
        }
        if (str_starts_with($upper, "@ REQUEST BODY")) {
            $phase = DOC_PHASE_REQ_BODY;
            $api["req_body"] = [];
            $meta = docs_after_first_colon($stripped);
            if ($meta !== "") {
                $api["req_body_meta"] = $meta;
            }
            continue;
        }
        if (str_starts_with($upper, "@ QUERY STRINGS") || str_starts_with($upper, "@ QUERY PARAMETERS")) {
            $phase = DOC_PHASE_QUERY_STRING;
            $api["query_strings"] = [];
            continue;
        }
        if (str_starts_with($upper, "@ RETURN")) {
            $phase = DOC_PHASE_RETURN;
            $api["return"] = [];
            $meta = docs_after_first_colon($stripped);
            if ($meta !== "") {
                $api["return_meta"] = $meta;
            }
            continue;
        }

        if ($phase === DOC_PHASE_NONE || $phase === DOC_PHASE_OPEN) {
            if ($api["title"] === "" && $stripped !== "") {
                $api["title"] = $stripped;
                $phase = DOC_PHASE_OPEN;
            } else if ($stripped !== "") {
                $api["desc"][] = $stripped;
                $phase = DOC_PHASE_OPEN;
            }
            continue;
        }

        if ($phase === DOC_PHASE_URL_PARAMS) {
            docs_parse_param_line($line, $api["url_params"], $known_types);
        } else if ($phase === DOC_PHASE_JSON_PARAMS) {
            docs_parse_param_line($line, $api["json_params"], $known_types);
        } else if ($phase === DOC_PHASE_REQ_BODY) {
            docs_parse_param_line($line, $api["req_body"], $known_types);
        } else if ($phase === DOC_PHASE_RETURN) {
            docs_parse_param_line($line, $api["return"], $known_types);
        } else if ($phase === DOC_PHASE_QUERY_STRING) {
            docs_parse_param_line($line, $api["query_strings"], $known_types);
        }
    }

    return $api;
}

function docs_parse_domain_block( string $comment, bool $plantuml_enabled ): array
{
    $desc = [];
    $phase = DOC_PHASE_NONE;
    $plantuml = "";
    $markdown = "";
    $old_phase = DOC_PHASE_NONE;

    $comment = preg_replace('/^\s*\/\*/', "", $comment) ?? $comment;
    $comment = preg_replace('/\*\/\s*$/', "", $comment) ?? $comment;

    $lines = preg_split("/\r\n|\n|\r/", $comment) ?: [];
    foreach ($lines as $raw_line) {
        $stripped = docs_strip_comment_prefix($raw_line);
        if (preg_match('/^Domain\s*:/i', $stripped)) {
            continue;
        }

        if ($plantuml_enabled && str_starts_with(strtolower($stripped), "<plantuml>")) {
            $old_phase = $phase;
            $phase = DOC_PHASE_PLANTUML;
            $plantuml = "";
            continue;
        }
        if ($plantuml_enabled && str_starts_with(strtolower($stripped), "</plantuml>") && $phase === DOC_PHASE_PLANTUML) {
            $phase = $old_phase;
            if (trim($plantuml) !== "") {
                $desc[] = "@plantuml : " . $plantuml;
            }
            continue;
        }
        if (str_starts_with(strtolower($stripped), "<markdown>")) {
            $old_phase = $phase;
            $phase = DOC_PHASE_MARKDOWN;
            $markdown = "";
            continue;
        }
        if (str_starts_with(strtolower($stripped), "</markdown>") && $phase === DOC_PHASE_MARKDOWN) {
            $phase = $old_phase;
            if (trim($markdown) !== "") {
                $desc[] = "@markdown : " . $markdown;
            }
            continue;
        }

        if ($phase === DOC_PHASE_MARKDOWN) {
            $markdown .= $stripped . "\n";
            continue;
        }
        if ($phase === DOC_PHASE_PLANTUML) {
            $plantuml .= $stripped . "\n";
            continue;
        }
        if ($stripped !== "") {
            $desc[] = $stripped;
        }
    }

    return ["desc" => $desc];
}

function docs_merge_params( array $auto, array $manual ): array
{
    $by_name = [];
    foreach ($auto as $param) {
        $by_name[$param["name"]] = $param;
    }
    foreach ($manual as $param) {
        $name = $param["name"];
        if (isset($by_name[$name])) {
            if ($param["type"] !== "") {
                $by_name[$name]["type"] = $param["type"];
            }
            if ($param["desc"] !== "") {
                $by_name[$name]["desc"] = $param["desc"];
            }
            $by_name[$name]["optional"] = !empty($param["optional"]);
        } else {
            $by_name[$name] = $param;
        }
    }
    return array_values($by_name);
}

function docs_parse_attribute_args( string $args ): array
{
    $args = trim($args);
    if ($args === "") {
        return [];
    }
    if (preg_match('/^["\'](.*)["\']\s*$/s', $args, $m)) {
        return [$m[1]];
    }
    return [$args];
}

function docs_collect_php_files( string $dir ): array
{
    if (!is_dir($dir) || str_ends_with($dir, "__")) {
        return [];
    }

    $entries = @scandir($dir);
    if ($entries === false) {
        return [];
    }

    $files = [];
    foreach ($entries as $entry) {
        if ($entry === "." || $entry === "..") {
            continue;
        }
        $path = $dir . "/" . $entry;
        if (is_dir($path)) {
            $files = array_merge($files, docs_collect_php_files($path));
            continue;
        }
        if (str_ends_with(strtolower($entry), ".php")) {
            $files[] = $path;
        }
    }
    return $files;
}

function docs_scan_file(
    string $file,
    string $domain_dir,
    array $route_attributes,
    array $known_types,
    bool $plantuml_enabled
): array {
    $source = @file_get_contents($file);
    if ($source === false || $source === "") {
        return ["apis" => [], "domains" => []];
    }

    $tokens = token_get_all($source);
    $apis = [];
    $domains = [];
    $pending_doc = null;
    $pending_attrs = [];
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if (!is_array($token)) {
            continue;
        }

        [$id, $text] = [$token[0], $token[1]];

        if ($id === T_DOC_COMMENT || $id === T_COMMENT) {
            $trim = trim($text);
            if (str_starts_with($trim, "/***")) {
                $pending_doc = $trim;
                continue;
            }
            if (preg_match('/^\/\*\s*Domain\s*:/i', $trim)) {
                $name = trim(docs_after_first_colon(preg_replace('/^\/\*|\*\/$/', "", $trim) ?? $trim));
                $name = explode("\n", $name)[0] ?? $name;
                $name = trim($name);
                if ($name !== "") {
                    $domains[strtolower($name)] = docs_parse_domain_block($trim, $plantuml_enabled);
                }
                continue;
            }
        }

        if ($id === T_ATTRIBUTE) {
            // T_ATTRIBUTE text is "#["; collect until the matching "]".
            // Support contiguous groups: #[...]#[...]
            while ($i < $count) {
                $cur = $tokens[$i];
                if (!is_array($cur) || $cur[0] !== T_ATTRIBUTE) {
                    break;
                }
                $i++;
                $attr_body = "";
                $depth = 1; // opening "[" already consumed by T_ATTRIBUTE
                for (; $i < $count; $i++) {
                    $t = $tokens[$i];
                    $chunk = is_array($t) ? $t[1] : $t;
                    if ($chunk === "[") {
                        $depth++;
                        $attr_body .= $chunk;
                        continue;
                    }
                    if ($chunk === "]") {
                        $depth--;
                        if ($depth === 0) {
                            break;
                        }
                        $attr_body .= $chunk;
                        continue;
                    }
                    $attr_body .= $chunk;
                }

                foreach (preg_split('/\s*,\s*(?![^()]*\))/', $attr_body) ?: [] as $attr_expr) {
                    $attr_expr = trim($attr_expr);
                    if ($attr_expr === "") {
                        continue;
                    }
                    if (preg_match('/^([\\\\A-Za-z_][\\\\A-Za-z0-9_]*)\s*(?:\((.*)\))?$/s', $attr_expr, $m)) {
                        $attr_name = ltrim($m[1], "\\");
                        $attr_short = preg_replace('/^.*\\\\/', "", $attr_name) ?? $attr_name;
                        $args = docs_parse_attribute_args($m[2] ?? "");
                        $pending_attrs[] = [
                            "name" => $attr_short,
                            "args" => $args,
                        ];
                    }
                }

                // Skip whitespace to allow adjacent #[...] groups
                $j = $i + 1;
                while ($j < $count) {
                    $t = $tokens[$j];
                    if (is_array($t) && $t[0] === T_WHITESPACE) {
                        $j++;
                        continue;
                    }
                    break;
                }
                if ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_ATTRIBUTE) {
                    $i = $j;
                    continue;
                }
                $i = $j - 1;
                break;
            }
            continue;
        }

        if ($id === T_FUNCTION) {
            $api = docs_empty_api();
            $api["path"] = str_replace($domain_dir, "", $file);
            $has_route = false;

            foreach ($pending_attrs as $attr) {
                $name = $attr["name"];
                if (array_key_exists($name, $route_attributes)) {
                    $method = $route_attributes[$name];
                    $url = $attr["args"][0] ?? "";
                    if ($method === null && isset($attr["args"][0], $attr["args"][1])) {
                        // REQUEST(method, url) style if ever used with string args
                        $method = strtoupper((string)$attr["args"][0]);
                        $url = (string)$attr["args"][1];
                    }
                    if ($method && $url !== "") {
                        $api["method"] = strtoupper((string)$method);
                        $api["url"] = (string)$url;
                        $api["source"] = "attribute";
                        $has_route = true;
                    }
                } else {
                    $api["auth"][] = $name;
                    $api["attrs"][] = $name;
                }
            }

            if ($pending_doc !== null) {
                $from_comment = docs_parse_comment_block($pending_doc, $known_types, $plantuml_enabled);
                if ($api["title"] === "") {
                    $api["title"] = $from_comment["title"];
                }
                $api["desc"] = $from_comment["desc"];
                $api["url_params"] = $from_comment["url_params"];
                $api["query_strings"] = $from_comment["query_strings"];
                $api["json_params"] = $from_comment["json_params"];
                $api["req_body"] = $from_comment["req_body"];
                $api["req_body_meta"] = $from_comment["req_body_meta"];
                $api["return"] = $from_comment["return"];
                $api["return_meta"] = $from_comment["return_meta"];
                if (!$has_route && $from_comment["url"] !== "" && $from_comment["method"] !== "") {
                    $api["url"] = $from_comment["url"];
                    $api["method"] = $from_comment["method"];
                    $api["source"] = "comment";
                    $has_route = true;
                }
            }

            if ($has_route && $api["url"] !== "") {
                $auto_params = docs_extract_url_params($api["url"]);
                $api["url_params"] = docs_merge_params($auto_params, $api["url_params"]);
                if ($api["title"] === "") {
                    $api["title"] = $api["method"] . " " . $api["url"];
                }
                $api["auth"] = array_values(array_unique($api["auth"]));
                $api["attrs"] = array_values(array_unique($api["attrs"]));
                $apis[] = $api;
            }

            $pending_doc = null;
            $pending_attrs = [];
        }
    }

    // Fallback: comment-only blocks without nearby attributes (legacy)
    if (count($apis) === 0 && preg_match_all('/\/\*\*\*.*?\*\//s', $source, $matches)) {
        foreach ($matches[0] as $block) {
            $api = docs_parse_comment_block($block, $known_types, $plantuml_enabled);
            if ($api["url"] === "" || $api["method"] === "") {
                continue;
            }
            $api["path"] = str_replace($domain_dir, "", $file);
            $api["url_params"] = docs_merge_params(docs_extract_url_params($api["url"]), $api["url_params"]);
            $api["source"] = "comment";
            $apis[] = $api;
        }
    }

    return ["apis" => $apis, "domains" => $domains];
}

function docs_render_url_html( string $url ): string
{
    $escaped = docs_h($url);
    return preg_replace('/\{([^}]+)\}/', '<i class="variable">{$1}</i>', $escaped) ?? $escaped;
}

function docs_render_desc_lines( array $lines, bool $plantuml_enabled, string $plantuml_url ): string
{
    $html = "";
    foreach ($lines as $line) {
        if (str_starts_with($line, "@plantuml :")) {
            if (!$plantuml_enabled) {
                continue;
            }
            $content = trim(substr($line, strlen("@plantuml :")));
            $encoded = docs_encode_plantuml($content);
            if ($encoded === "") {
                continue;
            }
            $src = docs_h($plantuml_url . "/" . $encoded);
            $html .= '<img class="plantuml" src="' . $src . '" alt="PlantUML diagram"/><br/>';
            continue;
        }
        if (str_starts_with($line, "@markdown :")) {
            $markdown = trim(substr($line, strlen("@markdown :")));
            $parsedown_path = __DIR__ . "/../libs/Parsedown/Parsedown.php";
            if (is_file($parsedown_path)) {
                include_once($parsedown_path);
                $parser = new Parsedown();
                if (method_exists($parser, "setSafeMode")) {
                    $parser->setSafeMode(true);
                }
                $html .= '<div class="markdown">' . $parser->text($markdown) . "</div>";
            } else {
                $html .= "<pre>" . docs_h($markdown) . "</pre>";
            }
            continue;
        }
        $html .= docs_h($line) . "<br/>";
    }
    return $html;
}

function docs_render_param_table( array $params, bool $with_optional ): string
{
    if (count($params) === 0) {
        return "";
    }
    $html = "<table><thead><tr>";
    $html .= "<th scope=\"col\">Name</th><th scope=\"col\">Type</th>";
    if ($with_optional) {
        $html .= "<th scope=\"col\" class=\"opt\">Optional</th>";
    }
    $html .= "<th scope=\"col\">Description</th></tr></thead><tbody>";
    foreach ($params as $param) {
        $html .= "<tr>";
        $html .= "<td>" . docs_h($param["name"]) . "</td>";
        $html .= "<td>" . docs_h($param["type"]) . "</td>";
        if ($with_optional) {
            $html .= "<td class=\"opt\">" . (!empty($param["optional"]) ? "O" : "") . "</td>";
        }
        $html .= "<td>" . nl2br(docs_h($param["desc"])) . "</td>";
        $html .= "</tr>";
    }
    $html .= "</tbody></table>";
    return $html;
}

function docs_build_curl( string $method, string $url, array $api, string $base_url ): string
{
    $full = $base_url . $url;
    $full = preg_replace('/\{([^}]+)\}/', ':$1', $full) ?? $full;
    $parts = ["curl -X " . strtoupper($method) . " '" . $full . "'"];
    if (count($api["auth"]) > 0) {
        $parts[] = "  -H 'Authorization: Bearer <token>'";
    }
    if (count($api["req_body"]) > 0 || count($api["json_params"]) > 0) {
        $meta = strtolower((string)$api["req_body_meta"]);
        if (str_contains($meta, "multipart")) {
            foreach ($api["req_body"] as $field) {
                $parts[] = "  -F '" . $field["name"] . "=@" . $field["name"] . "'";
            }
        } else {
            $body = [];
            foreach (array_merge($api["json_params"], $api["req_body"]) as $field) {
                $body[$field["name"]] = $field["type"] !== "" ? "<" . $field["type"] . ">" : "<value>";
            }
            $parts[] = "  -H 'Content-Type: application/json'";
            $parts[] = "  -d '" . json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "'";
        }
    }
    return implode(" \\\n", $parts);
}

// -------- collect --------
$api_docs = [];
$api_domain_docs = [];
foreach (docs_collect_php_files($domain_dir) as $domain_file) {
    $scanned = docs_scan_file(
        $domain_file,
        $domain_dir,
        $DOC_ROUTE_ATTRIBUTES,
        $DOC_KNOWN_TYPES,
        $docs_plantuml_enabled
    );
    foreach ($scanned["apis"] as $api) {
        $api_docs[] = $api;
    }
    foreach ($scanned["domains"] as $name => $info) {
        $api_domain_docs[$name] = $info;
    }
}

usort($api_docs, static function ( $a, $b ) {
    $url_cmp = strcmp((string)($a["url"] ?? ""), (string)($b["url"] ?? ""));
    if ($url_cmp !== 0) {
        return $url_cmp;
    }
    return strcmp((string)($a["method"] ?? ""), (string)($b["method"] ?? ""));
});

$grouped = [];
foreach ($api_docs as $api) {
    if (($api["url"] ?? "") === "") {
        continue;
    }
    $domain = docs_domain_from_url($api["url"]);
    if (!isset($grouped[$domain])) {
        $grouped[$domain] = [];
    }
    $grouped[$domain][] = $api;
}

$total_count = count($api_docs);
$methods_present = [];
foreach ($api_docs as $api) {
    $methods_present[strtoupper((string)$api["method"])] = true;
}
ksort($methods_present);

?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <title>PHAST API Documentation</title>
    <script>
        (() => {
            let theme = "dark";
            try {
                theme = localStorage.getItem("phastapi-docs-theme")
                    || (window.matchMedia("(prefers-color-scheme: light)").matches ? "light" : "dark");
            } catch (e) {
                theme = window.matchMedia("(prefers-color-scheme: light)").matches ? "light" : "dark";
            }
            document.documentElement.dataset.theme = theme;
        })();
    </script>
    <link rel="stylesheet" href="<?php echo docs_h($docs_asset_base); ?>/css/default.docs.css"/>
</head>
<body>
<header class="docs-header">
    <div class="docs-brand">
        <img src="<?php echo docs_h($docs_asset_base); ?>/images/logo_white.svg" alt="PHAST API"/>
        <span>v<?php echo docs_h(defined("PHASTAPI_VERSION") ? PHASTAPI_VERSION : "2"); ?> documentation</span>
        <button type="button" class="theme-toggle" id="theme-toggle" aria-label="Switch to light theme" aria-pressed="false">
            <span class="theme-toggle-icon" aria-hidden="true">☀</span>
            <span class="theme-toggle-label">Light</span>
        </button>
    </div>
    <div class="docs-toolbar">
        <label class="docs-search">
            <span class="visually-hidden">Search APIs</span>
            <input type="search" id="docs-search" placeholder="Search method, URL, title, auth…" autocomplete="off"/>
        </label>
        <div class="docs-method-filters" role="group" aria-label="HTTP method filter">
            <button type="button" class="method-filter is-active" data-method="ALL">ALL</button>
            <?php foreach (array_keys($methods_present) as $method) { ?>
                <button type="button" class="method-filter <?php echo docs_h($method); ?>" data-method="<?php echo docs_h($method); ?>"><?php echo docs_h($method); ?></button>
            <?php } ?>
        </div>
        <div class="docs-count" id="docs-count"><?php echo (int)$total_count; ?> endpoints</div>
    </div>
</header>

<div class="docs-layout">
    <nav class="docs-nav" aria-label="API domains" id="docs-nav">
        <?php foreach ($grouped as $domain_name => $apis) {
            $domain_id = "domain-" . docs_slug($domain_name);
            ?>
            <section class="nav-domain" data-domain="<?php echo docs_h($domain_name); ?>">
                <div class="nav-domain-header">
                    <a href="#<?php echo docs_h($domain_id); ?>"><?php echo docs_h($domain_name); ?></a>
                    <button type="button" class="but-expand" aria-expanded="true" aria-controls="nav-apis-<?php echo docs_h(docs_slug($domain_name)); ?>" data-domain="<?php echo docs_h($domain_name); ?>">−</button>
                </div>
                <div class="nav-apis" id="nav-apis-<?php echo docs_h(docs_slug($domain_name)); ?>">
                    <?php foreach ($apis as $api) {
                        $anchor = docs_slug(strtoupper($api["method"]) . "-" . $api["url"]);
                        $search_blob = strtolower(implode(" ", [
                            $api["method"], $api["url"], $api["title"],
                            implode(" ", $api["auth"]), implode(" ", $api["desc"]),
                        ]));
                        ?>
                        <a class="nav-api"
                           href="#<?php echo docs_h($anchor); ?>"
                           data-method="<?php echo docs_h(strtoupper($api["method"])); ?>"
                           data-search="<?php echo docs_h($search_blob); ?>">
                            <span class="method-badge <?php echo docs_h(strtoupper($api["method"])); ?>"><?php echo docs_h(strtoupper($api["method"])); ?></span>
                            <span class="nav-url"><?php echo docs_render_url_html($api["url"]); ?></span>
                        </a>
                    <?php } ?>
                </div>
            </section>
        <?php } ?>
    </nav>

    <main class="docs-main" id="docs-main">
        <?php foreach ($grouped as $domain_name => $apis) {
            $domain_id = "domain-" . docs_slug($domain_name);
            $domain_key = strtolower($domain_name);
            ?>
            <section class="domain-block" id="<?php echo docs_h($domain_id); ?>">
                <h2 class="domain-title"><?php echo docs_h($domain_name); ?></h2>
                <?php if (isset($api_domain_docs[$domain_key]["desc"]) && count($api_domain_docs[$domain_key]["desc"]) > 0) { ?>
                    <div class="domain-desc">
                        <?php echo docs_render_desc_lines($api_domain_docs[$domain_key]["desc"], $docs_plantuml_enabled, $docs_plantuml_url); ?>
                    </div>
                <?php } ?>

                <?php foreach ($apis as $api) {
                    $method = strtoupper((string)$api["method"]);
                    $anchor = docs_slug($method . "-" . $api["url"]);
                    $search_blob = strtolower(implode(" ", [
                        $method, $api["url"], $api["title"],
                        implode(" ", $api["auth"]), implode(" ", $api["desc"]),
                    ]));
                    $curl = docs_build_curl($method, $api["url"], $api, $docs_base_url === "" ? "" : $docs_base_url);
                    ?>
                    <article class="api-card"
                             id="<?php echo docs_h($anchor); ?>"
                             data-method="<?php echo docs_h($method); ?>"
                             data-search="<?php echo docs_h($search_blob); ?>">
                        <header class="api-card-header">
                            <div class="api-card-top">
                                <h3 class="api-title"><?php echo docs_h($api["title"]); ?></h3>
                                <span class="method-badge <?php echo docs_h($method); ?>"><?php echo docs_h($method); ?></span>
                                <?php foreach ($api["auth"] as $auth) { ?>
                                    <span class="auth-badge"><?php echo docs_h($auth); ?></span>
                                <?php } ?>
                            </div>
                            <div class="api-card-bottom">
                                <code class="api-url"><?php echo docs_render_url_html($api["url"]); ?></code>
                                <span class="api-file"><?php echo docs_h($api["path"]); ?></span>
                            </div>
                            <div class="api-actions">
                                <button type="button" class="copy-btn" data-copy="<?php echo docs_h($api["url"]); ?>">Copy URL</button>
                                <button type="button" class="copy-btn" data-copy="<?php echo docs_h($curl); ?>">Copy curl</button>
                            </div>
                        </header>

                        <?php if (count($api["desc"]) > 0) { ?>
                            <div class="api-description">
                                <?php echo docs_render_desc_lines($api["desc"], $docs_plantuml_enabled, $docs_plantuml_url); ?>
                            </div>
                        <?php } ?>

                        <div class="api-sections">
                            <?php if (count($api["url_params"]) > 0) { ?>
                                <section class="param-section">
                                    <div class="param-title">URL<br/>Parameters</div>
                                    <div class="param-body"><?php echo docs_render_param_table($api["url_params"], true); ?></div>
                                </section>
                            <?php } ?>

                            <?php if (count($api["query_strings"]) > 0) { ?>
                                <section class="param-section">
                                    <div class="param-title">Query<br/>String</div>
                                    <div class="param-body"><?php echo docs_render_param_table($api["query_strings"], true); ?></div>
                                </section>
                            <?php } ?>

                            <?php if (count($api["json_params"]) > 0) { ?>
                                <section class="param-section">
                                    <div class="param-title">JSON<br/>Parameters</div>
                                    <div class="param-body"><?php echo docs_render_param_table($api["json_params"], true); ?></div>
                                </section>
                            <?php } ?>

                            <?php if (count($api["req_body"]) > 0 || $api["req_body_meta"] !== "") { ?>
                                <section class="param-section">
                                    <div class="param-title">Request<br/>Body</div>
                                    <div class="param-body">
                                        <?php if ($api["req_body_meta"] !== "") { ?>
                                            <div class="meta-line"><?php echo docs_h($api["req_body_meta"]); ?></div>
                                        <?php } ?>
                                        <?php echo docs_render_param_table($api["req_body"], true); ?>
                                    </div>
                                </section>
                            <?php } ?>

                            <?php if (count($api["return"]) > 0 || $api["return_meta"] !== "") { ?>
                                <section class="param-section">
                                    <div class="param-title">Returns</div>
                                    <div class="param-body">
                                        <?php if ($api["return_meta"] !== "") { ?>
                                            <div class="meta-line"><?php echo docs_h($api["return_meta"]); ?></div>
                                        <?php } ?>
                                        <?php echo docs_render_param_table($api["return"], false); ?>
                                    </div>
                                </section>
                            <?php } ?>
                        </div>

                        <details class="curl-block">
                            <summary>curl example</summary>
                            <pre><code><?php echo docs_h($curl); ?></code></pre>
                        </details>
                    </article>
                <?php } ?>
            </section>
        <?php } ?>

        <?php if ($total_count === 0) { ?>
            <p class="docs-empty">No documented API endpoints were found.</p>
        <?php } ?>
    </main>
</div>

<script>
(() => {
    const searchInput = document.getElementById("docs-search");
    const countEl = document.getElementById("docs-count");
    const themeToggle = document.getElementById("theme-toggle");
    const cards = Array.from(document.querySelectorAll(".api-card"));
    const navLinks = Array.from(document.querySelectorAll(".nav-api"));
    let activeMethod = "ALL";

    function setTheme(theme, persist = false) {
        const isLight = theme === "light";
        document.documentElement.dataset.theme = isLight ? "light" : "dark";
        if (themeToggle) {
            themeToggle.setAttribute("aria-pressed", isLight ? "true" : "false");
            themeToggle.setAttribute("aria-label", isLight ? "Switch to dark theme" : "Switch to light theme");
            const icon = themeToggle.querySelector(".theme-toggle-icon");
            const label = themeToggle.querySelector(".theme-toggle-label");
            if (icon) icon.textContent = isLight ? "☾" : "☀";
            if (label) label.textContent = isLight ? "Dark" : "Light";
        }
        if (persist) {
            try {
                localStorage.setItem("phastapi-docs-theme", isLight ? "light" : "dark");
            } catch (e) {
                // The selected theme still applies when storage is unavailable.
            }
        }
    }

    setTheme(document.documentElement.dataset.theme || "dark");
    themeToggle?.addEventListener("click", () => {
        setTheme(document.documentElement.dataset.theme === "light" ? "dark" : "light", true);
    });

    function applyFilters() {
        const q = (searchInput?.value || "").trim().toLowerCase();
        let visible = 0;
        cards.forEach((card) => {
            const method = card.getAttribute("data-method") || "";
            const hay = card.getAttribute("data-search") || "";
            const methodOk = activeMethod === "ALL" || method === activeMethod;
            const searchOk = !q || hay.includes(q);
            const show = methodOk && searchOk;
            card.hidden = !show;
            if (show) visible += 1;
        });
        navLinks.forEach((link) => {
            const method = link.getAttribute("data-method") || "";
            const hay = link.getAttribute("data-search") || "";
            const methodOk = activeMethod === "ALL" || method === activeMethod;
            const searchOk = !q || hay.includes(q);
            link.hidden = !(methodOk && searchOk);
        });
        document.querySelectorAll(".nav-domain").forEach((section) => {
            const any = Array.from(section.querySelectorAll(".nav-api")).some((el) => !el.hidden);
            section.hidden = !any;
        });
        document.querySelectorAll(".domain-block").forEach((block) => {
            const any = Array.from(block.querySelectorAll(".api-card")).some((el) => !el.hidden);
            block.hidden = !any;
        });
        if (countEl) {
            countEl.textContent = visible + " endpoints";
        }
    }

    searchInput?.addEventListener("input", applyFilters);

    document.querySelectorAll(".method-filter").forEach((btn) => {
        btn.addEventListener("click", () => {
            document.querySelectorAll(".method-filter").forEach((b) => b.classList.remove("is-active"));
            btn.classList.add("is-active");
            activeMethod = btn.getAttribute("data-method") || "ALL";
            applyFilters();
        });
    });

    document.querySelectorAll(".but-expand").forEach((btn) => {
        btn.addEventListener("click", () => {
            const domain = btn.getAttribute("data-domain");
            const panel = document.getElementById("nav-apis-" + (domain || "").replace(/[^a-zA-Z0-9_\-]/g, "-"));
            // Prefer aria-controls target
            const controlled = btn.getAttribute("aria-controls");
            const target = controlled ? document.getElementById(controlled) : panel;
            if (!target) return;
            const expanded = btn.getAttribute("aria-expanded") !== "false";
            btn.setAttribute("aria-expanded", expanded ? "false" : "true");
            btn.textContent = expanded ? "+" : "−";
            target.hidden = expanded;
        });
    });

    document.querySelectorAll(".copy-btn").forEach((btn) => {
        btn.addEventListener("click", async () => {
            const text = btn.getAttribute("data-copy") || "";
            try {
                await navigator.clipboard.writeText(text);
                const prev = btn.textContent;
                btn.textContent = "Copied";
                setTimeout(() => { btn.textContent = prev; }, 1200);
            } catch (e) {
                const ta = document.createElement("textarea");
                ta.value = text;
                document.body.appendChild(ta);
                ta.select();
                document.execCommand("copy");
                document.body.removeChild(ta);
                const prev = btn.textContent;
                btn.textContent = "Copied";
                setTimeout(() => { btn.textContent = prev; }, 1200);
            }
        });
    });
})();
</script>
</body>
</html>
