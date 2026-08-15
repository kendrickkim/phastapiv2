<?php
function include_all( $dir )
{

    $files = scandir($dir);
    foreach ($files as $file) {
        if (str_starts_with($file, "test") || str_starts_with($file, "lib"))
            continue;
        if ($file == "." || $file == "..")
            continue;
        if (is_dir($dir . "/" . $file)) {
            include_all($dir . "/" . $file);
        } else {
            // echo($dir."/".$file."\n");
            if (str_ends_with($file, ".php"))
                // echo($dir . "/" . $file . "\n");
                include_once($dir . "/" . $file);
        }
    }
}

function show_entry()
{
    global $G_PHASTAPI_DOMAIN_DIR;
    include_all($G_PHASTAPI_DOMAIN_DIR);
    if (PHP_MAJOR_VERSION >= 8) {
        ScanFunctionAndApplyAttributes();
    }
    echo ("
        <style>
            .api_entry {
                display: flex;
                flex-direction: row;
                font-size: 15px;
                color: #333;
                background-color: #f0f0f0;
                padding: 5px;
                margin: 5px;
                border: 1px solid #ccc;
                border-radius: 5px;
            }
            .api_entry_index {
                font-weight: bold;
                color: #000;
                width : 50px;
            }
            .api_entry_req_type {
                font-weight: bold;
                color: #000;
                width : 60px;
            }
            .api_entry_pattern {
                
            }
            .api_entry_pattern_param {
                background-color: #777;
                color : white;
                border: 1px solid #ccc;
                border-radius: 5px;
            }
        </style>
    ");

    $apis = PHASTAPI_CORE::$apis;

    $index = 1;

    foreach ($apis as $api) {
        echo ("<div class='api_entry'>");
        echo ("<div class='api_entry_index'>" . $index++ . "</div>");
        echo ("<div class='api_entry_req_type'>" . $api["req_type"] . "</div>");
        $pattern = str_replace("{", "<span class='api_entry_pattern_param'>{", $api["pattern"]);
        $pattern = str_replace("}", "}</span>", $pattern);
        echo ("<div class='api_entry_pattern'>" . $pattern . "</div>");
        echo ("</div>");
    }
}