<center><img src="./images/logo_rect.svg" width="70%"/></center><br/>

# PHAST API v2.3

### World easiest REST API framework for PHP

PHP로 작은 REST API를 빠르게 만들기 위한 경량 프레임워크입니다.
PHP 8 Attribute 기반 라우팅, URL 파라미터, JSON·FormData 파싱, 필터,
토큰 인증, 페이지 정보 헤더, 자동 API 문서를 제공합니다.

애플리케이션은 별도 디렉터리에 두고 `config.phastapi.override.php`로
연결하는 구성을 권장합니다.

## 요구 사항

- PHP 8.1 이상 권장 (PHP 7 레거시 라우팅도 일부 지원)
- Apache `mod_rewrite` 또는 Nginx + PHP-FPM
- JSON 확장
- 애플리케이션이 사용하는 DB·파일 관련 PHP 확장

## 디렉터리 구성

```text
api/                          # PHAST API 프레임워크
├── index.php                 # 웹 진입점
├── config.phastapi.php       # 기본 설정과 사용자 앱 경로
├── config.test.phastapi.php  # 선택적 테스트 설정 (환경변수로만 전환)
├── index.phast.php           # 레거시 PHAST 토큰 인증
├── core/
└── libs/

my-app/                       # 사용자 애플리케이션
├── config.phastapi.override.php
├── attribute/
├── filter/
├── libs/
│   └── common/
└── domain/
    ├── auth/
    │   └── auth.php
    └── posts/
        └── posts.php
```

`config.phastapi.php`에서 사용자 앱 경로를 지정합니다.

```php
$G_PHASTAPI_CUSTOM_DIR = "../my-app";
```

환경변수로 배포 환경마다 바꿀 수도 있습니다.

```bash
PHASTAPI_CUSTOM_DIR=../my-app
PHASTAPI_USE_TEST_CONFIG=true   # config.test.phastapi.php 사용 (공개 URL로는 전환 불가)
```

상대 경로는 `api/` 디렉터리 기준이며 절대 경로도 지원합니다.

프레임워크는 다음 순서로 앱을 로드합니다.

1. `libs/common/*.php` 재귀 로드
2. `filter/*.php` 재귀 로드
3. `config.phastapi.override.php`
4. 요청 URL의 첫 세그먼트에 해당하는 `domain/<도메인>/*.php`
5. PHP 8 Attribute 스캔과 라우트 실행

예를 들어 `/api/posts/10` 요청은 `domain/posts/`를 로드합니다.
복수형 도메인이 없으면 끝의 `s`를 제거한 디렉터리도 확인합니다.

## 웹 서버 설정

PHAST API는 Apache와 Nginx에서 같은 방식으로 동작합니다. `/api`처럼 하위
경로에 배치할 때는 모든 API 요청을 `index.php`로 보내세요.

기준 경로는 다음 순서로 결정됩니다.

1. 환경변수 `PHASTAPI_BASE_URL`
2. `SCRIPT_NAME`에 나타난 `index.php`의 경로
3. CLI처럼 웹 경로를 알 수 없는 경우 기본값 `/api`

애플리케이션의 `config.phastapi.override.php`에서 `$G_PHASTAPI_BASEURL`을
지정하면 이 값을 덮어쓸 수 있습니다. 값은 `/api`처럼 앞에 `/`를 붙이고 끝의
`/`는 생략합니다. 문서 루트에서 바로 실행하면 빈 문자열을 사용합니다.

```bash
PHASTAPI_BASE_URL=/api
```

### Apache

`api/.htaccess`:

```apacheconf
Options -Indexes -MultiViews

<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteBase /api/
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^ index.php [QSA,L]
</IfModule>
```

Apache가 `Authorization` 헤더를 PHP에 전달하지 않는 환경에서는 서버 설정에
다음 규칙이 필요할 수 있습니다.

```apacheconf
SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1
```

### Nginx

아래 예제는 PHAST API 저장소를 `/var/www/phastapiv2`에 두고 `/api`로
서비스합니다. PHP-FPM 소켓은 서버 환경에 맞게 바꾸세요.

```nginx
server {
    listen 80;
    server_name api.example.com;
    root /var/www;

    location /api/ {
        alias /var/www/phastapiv2/;
        index index.php;
        try_files $uri $uri/ @phast;

        location ~ \.php$ {
            include fastcgi_params;
            fastcgi_param SCRIPT_FILENAME $request_filename;
            fastcgi_param HTTP_AUTHORIZATION $http_authorization;
            fastcgi_pass unix:/run/php/php8.1-fpm.sock;
        }
    }

    location = /api {
        return 301 /api/;
    }

    location @phast {
        rewrite ^/api/(.*)$ /api/index.php?/$1 last;
    }

    # index.php 외의 PHP 파일은 직접 실행하지 않습니다.
    location ~ \.php$ {
        return 404;
    }
}
```

`alias`와 `try_files`를 함께 사용할 때 `$document_root$fastcgi_script_name`은
잘못된 파일 경로가 될 수 있으므로 `$request_filename`을 사용합니다.
`Authorization`은 프레임워크가 `HTTP_AUTHORIZATION`과
`REDIRECT_HTTP_AUTHORIZATION` 모두에서 읽지만, 위처럼 FastCGI에 명시적으로
전달하는 편이 안전합니다.

## 앱 설정 덮어쓰기

`my-app/config.phastapi.override.php`:

```php
<?php

$G_PHASTAPI_DOMAIN_DIR = __DIR__ . "/domain";
$G_PHASTAPI_LOGPATH = __DIR__ . "/data/log";
$G_TIMEZONE = "Asia/Seoul";

$G_LOGGING_REQUEST = false;
$G_LOGGING_RESPONSE = false;
$G_SHOW_API_ENTRY = false;

$G_CROSS_ORIGIN_ENABLE = false;

include_all_files_in_dir(__DIR__ . "/attribute");
```

설정 덮어쓰기 파일이 없으면 프레임워크는 HTTP 500 JSON 응답을 반환합니다.

## PHP 8 Attribute 라우팅

지원 메서드는 `GET`, `POST`, `PUT`, `PATCH`, `DELETE`입니다.

```php
<?php

#[_GET_("/posts")]
function post_list()
{
    return ["posts" => []];
}

#[_GET_("/posts/{id}")]
function post_detail()
{
    $id = REQUEST::GetUargs()?->id;
    return ["id" => (int) $id];
}

#[_POST_("/posts")]
function post_create()
{
    $body = REQUEST::GetReqBody();
    return ["title" => $body?->title ?? ""];
}

#[_PATCH_("/posts/{id}")]
function post_update()
{
    return ["updated" => true];
}

#[_DELETE_("/posts/{id}")]
function post_delete()
{
    return ["deleted" => true];
}
```

하나의 함수에 여러 경로를 선언할 수 있습니다.

```php
#[_GET_("/profile")]
#[_GET_("/users/me")]
function current_profile()
{
    return ["name" => "user"];
}
```

### 범용 REQUEST Attribute

화이트리스트나 refresh token 옵션이 필요한 레거시 인증 구성에서는 범용
`REQUEST` Attribute를 사용할 수 있습니다.

```php
#[REQUEST(REQUEST::POST, "/auth/login", true, false)]
function login()
{
    // 세 번째 인자: access token 없이 호출 허용
    // 네 번째 인자: refresh token을 요구하는 화이트리스트
}
```

`$G_PHASTAPI_LEGACY_AUTH_ENABLE = true`일 때만 PHAST 자체 토큰 인증과
화이트리스트가 적용됩니다.

## PHP 7 스타일 라우팅

```php
PHASTAPI::get("/posts/{id}", function ($urlargs, $request_body, $raw_body, $query) {
    return [
        "id" => $urlargs->id,
        "body" => $request_body,
        "query" => $query,
    ];
});

PHASTAPI::post("/posts", $handler);
PHASTAPI::put("/posts/{id}", $handler);
PHASTAPI::patch("/posts/{id}", $handler);
PHASTAPI::delete("/posts/{id}", $handler);
```

헬퍼 함수 `_GET_`, `_POST_`, `_PUT_`, `_PATCH_`, `_DELETE_`도 사용할 수 있습니다.

## 요청 데이터

```php
$url_args = REQUEST::GetUargs();   // /posts/{id}
$body = REQUEST::GetReqBody();     // JSON 또는 form 필드 (stdClass|null)
$raw = REQUEST::GetData();         // 원본 body 문자열
$query = REQUEST::GetQstring();    // URL decode가 적용된 stdClass
```

### JSON

`Content-Type: application/json` 요청은 최상위 JSON 객체를 받습니다.
잘못된 JSON이나 최상위 배열은 `UNPROCESSABLE_ENTITY` 오류를 반환합니다.

```json
{
  "title": "Hello",
  "published": true
}
```

### URL encoded form

`application/x-www-form-urlencoded`와 charset이 붙은 Content-Type을 지원합니다.

### multipart/form-data

일반 필드는 `REQUEST::GetReqBody()`, 파일은 `REQUEST::GetFile()` 또는
`REQUEST::GetFiles()`로 조회합니다.

```php
#[_POST_("/uploads")]
function upload_file()
{
    $file = REQUEST::GetFile("image");
    if (!$file) {
        return [false, "FILE_REQUIRED"];
    }

    return [
        "name" => $file->name,
        "tmpPath" => $file->tmp_path,
        "size" => $file->size,
        "type" => $file->type,
        "error" => $file->error,
        "extension" => $file->ext,
    ];
}
```

복수 파일:

```php
$files = REQUEST::GetFiles("files");
```

각 파일 객체의 속성은 다음과 같습니다.

- `field`: form 필드명
- `name`: 원본 파일명
- `tmp_path`: PHP 임시 파일 경로
- `size`: 바이트 크기
- `type`: 클라이언트가 보낸 MIME type (보안 검증에 사용하면 안 됨)
- `error`: PHP upload error 코드
- `ext`: 원본 파일명의 소문자 확장자

파일과 JSON 객체를 함께 보낼 때는 `json_body` 필드에 JSON 문자열을 넣습니다.
파일 유무와 관계없이 `json_body`가 우선 파싱됩니다.

```javascript
const form = new FormData()
form.append('files[]', file)
form.append('json_body', JSON.stringify({ title: '문서' }))
```

업로드 파일의 MIME type은 `finfo`, 업로드 여부는 `is_uploaded_file()`로
애플리케이션에서 다시 검증해야 합니다.

## 필터

PHP 8에서는 `FILTER` Attribute로 IN/OUT 필터를 선언합니다.
IN 필터는 pre-router 존재 여부와 관계없이 항상 실행됩니다.

```php
#[FILTER(FILTER::IN, 10)]
function check_authorization()
{
    $args = FILTER::GetArgs();
    $handler = $args->func;

    $reflection = new ReflectionFunction($handler);
    $public = $reflection->getAttributes(PUBLIC_API::class);
    if (count($public) > 0) {
        return true;
    }

    return token_is_valid() ? true : [false, "UNAUTHORIZED"];
}
```

필터 인자:

- `req_type`
- `url_pattern`
- `req_body`
- `req_body_rare`
- `query_strings`
- `user_info`
- `list_param`
- `func`

OUT 필터는 핸들러 반환값을 받아 변환된 값을 반환합니다.

```php
#[FILTER(FILTER::OUT, 10)]
function add_response_metadata( $data )
{
    return [
        "data" => $data,
        "generatedAt" => date(DATE_ATOM),
    ];
}
```

필터는 `order` 오름차순으로 실행됩니다.

## 인증

PHAST API에는 두 가지 인증 구성이 있습니다.

### 애플리케이션 필터 인증 (PHP 8 권장)

앱에서 권한 Attribute와 IN 필터를 정의하는 방식입니다. JWT, 세션, API key 등
원하는 인증 체계를 사용할 수 있으며 프레임워크 토큰 형식에 종속되지 않습니다.

```php
#[Attribute]
class LOGIN_REQUIRED {}

#[FILTER(FILTER::IN, 10)]
function auth_filter()
{
    $handler = FILTER::GetArgs()->func;
    $reflection = new ReflectionFunction($handler);
    if (!$reflection->getAttributes(LOGIN_REQUIRED::class)) {
        return true;
    }
    return valid_app_token() ? true : [false, "UNAUTHORIZED"];
}

#[_GET_("/me"), LOGIN_REQUIRED]
function me() {}
```

### PHAST 레거시 토큰 인증

다음 설정으로 활성화합니다.

```php
$G_PHASTAPI_LEGACY_AUTH_ENABLE = true;
```

활성화하면 `index.phast.php`의 pre-router가 access/refresh token을 검사합니다.
공개 API는 범용 `REQUEST` Attribute의 세 번째 인자를 `true`로 설정합니다.

이 토큰은 기존 PHAST 애플리케이션 호환용 자체 형식이며 표준 JWT가 아닙니다.
신규 애플리케이션은 검증된 JWT/OAuth 라이브러리나 서버 세션을 앱 필터에서
사용하는 것을 권장합니다.

기본 SECRET 값은 예제용이므로 운영 환경에서 반드시 환경변수로 바꾸십시오.

```bash
PHASTAPI_ACCESS_TOKEN_KEY=...
PHASTAPI_REFRESH_TOKEN_KEY=...
PHASTAPI_SECRET_DATA=...
PHASTAPI_SECRET_KEY=...
```

## 응답

기본 `PReturn()`은 다음 형식으로 응답합니다.

성공:

```json
{
  "success": true,
  "error": { "code": 0, "message": "" },
  "data": {}
}
```

실패:

```php
return [false, "UNAUTHORIZED"];
```

```json
{
  "success": false,
  "error": {
    "code": 401,
    "message": "Unauthorized"
  }
}
```

### 사용자 정의 응답 포매터

기존 API 계약처럼 PHAST 기본 래핑을 사용하지 않아야 할 때
`$G_PHASTAPI_RESPONSE_FORMATTER`에 callable을 지정합니다.

```php
$G_PHASTAPI_RESPONSE_FORMATTER = function ($data, $false_message) {
    http_response_code($data === false ? 400 : 200);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode($data === false ? ["error" => $false_message] : $data);
    exit(0);
};
```

포매터는 응답을 출력하고 반드시 실행을 종료해야 합니다.

## 페이지 정보

요청 헤더 `X-Page-Param`:

```json
{
  "page": 1,
  "page_size": 20,
  "order": "created_at-,title+"
}
```

```php
$page = PHASTAPI_CORE::GetPageParams();
$limit_sql = $page->BuildLimitQuery();
$order_limit_sql = $page->BuildOrderLimitQuery();

PHASTAPI_CORE::SetPageParams(100);
PHASTAPI_CORE::SetPageParamsCustom([
    "page" => $page->page,
    "page_size" => $page->page_size,
    "total_count" => 100,
]);
```

응답 페이지 정보는 `X-Page-Param` 헤더로 전달됩니다.

## CORS

```php
$G_CROSS_ORIGIN_ENABLE = true;
$G_CROSS_ORIGIN_ALLOW_ORIGIN = "https://app.example.com";
$G_CROSS_ORIGIN_ALLOW_CREDENTIALS = true;

$G_CROSS_ORIGIN_ALLOW_HEADERS = [
    "X-Custom-Header",
];
$G_CROSS_ORIGIN_EXPOSE_HEADERS = [
    "X-Page-Param",
];
```

`Allow-Credentials=true`와 origin `*`는 함께 사용할 수 없으므로 이 조합에서는
프레임워크가 credentials를 자동으로 비활성화합니다.

## 로깅

```php
$G_PHASTAPI_LOGPATH = __DIR__ . "/data/log";
$G_LOGGING_REQUEST = true;
$G_LOGGING_RESPONSE = true;
```

multipart 요청은 파일 본문을 기록하지 않고 `$_POST`, `$_FILES` 메타데이터만
기록합니다. 토큰·비밀번호·개인정보가 로그에 남지 않도록 운영 설정을 점검하십시오.

## 자동 API 문서

```php
$G_SHOW_API_ENTRY = true;
```

- `/api/docs`: 주석 기반 API 문서
- `/api/entries`: 등록된 API 목록

운영 환경에서는 내부 경로와 API 구조가 노출될 수 있으므로 끄는 것을 권장합니다.

문서 주석 예시:

```php
/***
 * 글 상세
 * @ METHOD : GET
 * @ URL : /posts/{id}
 * @ URL ARGS
 *      id : [number] 글 번호
 * @ QUERY STRINGS
 *      -include : [string] 선택적 포함 데이터
 * @ RETURN
 *      post : [json] 글 데이터
 */
#[_GET_("/posts/{id}")]
function post_detail() {}
```

PlantUML을 문서 주석에 넣을 수 있습니다.

```php
/***
 * <plantuml>
 * Alice -> API : request
 * API --> Alice : response
 * </plantuml>
 */
```

```php
$G_SUPPORT_PLANTUML = true;
$G_PLANTUML_URL = "https://www.plantuml.com/plantuml/svg";
```

## 오류 처리

요청 파서는 `Throwable`을 처리하므로 `Exception`뿐 아니라 `TypeError`,
`Error`도 HTTP 500 응답으로 변환됩니다. 운영 환경에서는 사용자 정의 응답
포매터에서 내부 예외 메시지를 그대로 노출하지 않는 것을 권장합니다.

## 보안 점검

- 운영 환경에서 기본 토큰 SECRET을 사용하지 마십시오.
- 사용자 입력을 SQL 문자열에 직접 연결하지 말고 prepared statement를 사용하십시오.
- 업로드 파일명, MIME type, 크기, 실제 업로드 여부를 검증하십시오.
- API 문서와 entries는 운영 환경에서 비활성화하십시오.
- CORS credential을 사용할 때 허용 origin을 구체적으로 지정하십시오.
- 프록시 뒤에서 동작한다면 신뢰할 프록시 범위와 실제 클라이언트 IP 처리를
  애플리케이션에서 명시적으로 구성하십시오.
- 사용자 정의 응답 포매터에서 예외 상세정보를 운영 사용자에게 노출하지 마십시오.

## 주의사항

본 프레임워크는 **쉽고 빠른면서 자유로운 개발**을 위하여 **PSR**을 따르지 않습니다.<br/>
PSR 규격을 준수해야 하는 프로젝트에서는 사용을 자제하거나, 수정 사용하시기 바랍니다.<br/>
참고 : PSR은 PHP 프로그래밍 권고 사항이며, 따르지 않더라도 보안 등 치명적인 문제와는 관련없습니다<br/>
PSR 문서는 : https://www.php-fig.org/psr/ 를 참조하기 바랍니다.

## License 정보

본 프레임워크는 누구나, 어떤 단체든 자유롭게 사용, 수정 배포 가능합니다.<br/>
This framework can be used, modified or distributed by anyone or anygroup on freely.

Mobipin Techology(c), 2024
