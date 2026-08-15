<?php

#[Attribute(Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
class REQUEST
{
    public const GET = "GET";
    public const POST = "POST";
    public const PUT = "PUT";
    public const DELETE = "DELETE";
    public const PATCH = "PATCH";
    public const OPTIONS = "OPTIONS";
    public const HEAD = "HEAD";

    public $request_type;
    public $url_pattern;
    public $white_list;
    public $with_refresh_token;

    public static $uargs;
    public static $req_body;
    public static $req_body_rare;
    public static $qstring;
    public function __construct( $request_type, $url_pattern, $white_list = false, $with_refresh_token = false )
    {
        $this->request_type = $request_type;
        $this->url_pattern = $url_pattern;
        $this->white_list = $white_list;
        $this->with_refresh_token = $with_refresh_token;
    }

    public static function SearchAndAddRequests()
    {
        $functions = get_defined_functions()["user"];
        foreach ($functions as $function) {
            $reflection = new ReflectionFunction($function);
            $attributes = $reflection->getAttributes(REQUEST::class);
            if (count($attributes) > 0) {
                $instance = $attributes[0]->newInstance();
                // print_r($instance);
                PHAST($instance->request_type, $instance->url_pattern,
                    $function,
                    $instance->white_list,
                    $instance->with_refresh_token);
            }
        }
    }

    public static function SetArgs( $uargs, $req_body, $req_body_rare, $qstring )
    {
        self::$uargs = $uargs;
        self::$req_body = $req_body;
        self::$req_body_rare = $req_body_rare;
        self::$qstring = $qstring;
    }

    public static function GetUargs()
    {
        return self::$uargs;
    }

    public static function GetReqBody()
    {
        return self::$req_body;
    }

    public static function GetData()
    {
        return self::$req_body_rare;
    }

    public static function GetQstring()
    {
        return self::$qstring;
    }

    /**
     * 업로드된 파일을 편리하게 조회
     *
     * @param string|null $fieldName 특정 필드명만 조회 (null이면 전체)
     * @param bool $successOnly true면 UPLOAD_ERR_OK인 파일만 반환 (기본값: true)
     * @return array<object> 각 항목: field, name, tmp_path, size, type, error, ext
     */
    public static function GetFiles(?string $fieldName = null, bool $successOnly = true): array
    {
        $result = [];
        if (empty($_FILES)) {
            return $result;
        }

        foreach ($_FILES as $field => $file) {
            if ($fieldName !== null && $field !== $fieldName) {
                continue;
            }

            $normalized = self::_normalizeFiles($field, $file);
            foreach ($normalized as $item) {
                if ($successOnly && (int)($item->error ?? 0) !== UPLOAD_ERR_OK) {
                    continue;
                }
                $result[] = $item;
            }
        }

        return $result;
    }

    /**
     * 단일 필드의 첫 번째 업로드 파일 반환 (없으면 null)
     */
    public static function GetFile(string $fieldName): ?object
    {
        $files = self::GetFiles($fieldName, true);
        return $files[0] ?? null;
    }

    /**
     * $_FILES 구조를 단일/복수 모두 동일한 객체 배열로 정규화
     */
    private static function _normalizeFiles(string $field, array $file): array
    {
        $items = [];

        $isMultiple = is_array($file['name']);
        $names = $isMultiple ? $file['name'] : [$file['name']];
        $tmpNames = $isMultiple ? $file['tmp_name'] : [$file['tmp_name']];
        $errors = $isMultiple ? $file['error'] : [$file['error']];
        $sizes = $isMultiple ? $file['size'] : [$file['size']];
        $types = $isMultiple ? ($file['type'] ?? []) : [($file['type'] ?? '')];

        $count = count($names);
        for ($i = 0; $i < $count; $i++) {
            $name = $names[$i] ?? '';
            $tmpName = $tmpNames[$i] ?? '';
            $error = (int)($errors[$i] ?? UPLOAD_ERR_NO_FILE);
            $size = (int)($sizes[$i] ?? 0);
            $type = $types[$i] ?? '';

            $ext = $name ? strtolower(pathinfo($name, PATHINFO_EXTENSION)) : '';

            $items[] = (object)[
                'field' => $field,
                'name' => $name,
                'tmp_path' => $tmpName ?: null,
                'size' => $size,
                'type' => $type ?: null,
                'error' => $error,
                'ext' => $ext ?: null,
            ];
        }

        return $items;
    }
}

#[Attribute(Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
class _GET_ extends REQUEST
{
    public function __construct( $url_pattern )
    {
        parent::__construct(REQUEST::GET, $url_pattern, false, false);
    }
}

#[Attribute(Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
class _POST_ extends REQUEST
{
    public function __construct( $url_pattern )
    {
        parent::__construct(REQUEST::POST, $url_pattern, false, false);
    }
}

#[Attribute(Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
class _PUT_ extends REQUEST
{
    public function __construct( $url_pattern )
    {
        parent::__construct(REQUEST::PUT, $url_pattern, false, false);
    }
}

#[Attribute(Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
class _PATCH_ extends REQUEST
{
    public function __construct( $url_pattern )
    {
        parent::__construct(REQUEST::PATCH, $url_pattern, false, false);
    }
}

#[Attribute(Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
class _DELETE_ extends REQUEST
{
    public function __construct( $url_pattern )
    {
        parent::__construct(REQUEST::DELETE, $url_pattern, false, false);
    }
}

#[Attribute(Attribute::TARGET_FUNCTION)]
class FILTER
{
    public const IN = "IN";
    public const OUT = "OUT";

    public $type;
    public $order;

    public static $args;

    public function __construct( $type, $order = -1 )
    {
        $this->type = $type;
        $this->order = $order;
    }
    public static function SetArgs( $args )
    {
        self::$args = $args;
    }

    public static function GetArgs()
    {
        return self::$args;
    }
}

// #[Attribute]
// class WHITE_LIST
// {
//     public function __construct()
//     {
//     }
// }

function ScanFunctionAndApplyAttributes()
{
    $functions = get_defined_functions()["user"];

    $request_attribute_functions = function ($request_type, $function, $attributes) {
        if (count($attributes) > 0) {
            foreach ($attributes as $attribute) {
                $instance = $attribute->newInstance();
                PHAST($instance->request_type, $instance->url_pattern,
                    $function,
                    $instance->white_list,
                    $instance->with_refresh_token);
            }
        }
    };

    foreach ($functions as $function) {
        $reflection = new ReflectionFunction($function);
        $request_attribute_functions(null, $function, $reflection->getAttributes(REQUEST::class));
        $request_attribute_functions(REQUEST::GET, $function, $reflection->getAttributes(_GET_::class));
        $request_attribute_functions(REQUEST::POST, $function, $reflection->getAttributes(_POST_::class));
        $request_attribute_functions(REQUEST::PUT, $function, $reflection->getAttributes(_PUT_::class));
        $request_attribute_functions(REQUEST::PATCH, $function, $reflection->getAttributes(_PATCH_::class));
        $request_attribute_functions(REQUEST::DELETE, $function, $reflection->getAttributes(_DELETE_::class));

        $attributes = $reflection->getAttributes(FILTER::class);
        if (count($attributes) > 0) {
            $instance = $attributes[0]->newInstance();
            if ($instance?->type == FILTER::IN) {
                PHASTAPI_CORE::AddInFilter($function, $instance->order);
            } else if ($instance?->type == FILTER::OUT) {
                PHASTAPI_CORE::AddOutFilter($function, $instance->order);
            }
        }
    }
}