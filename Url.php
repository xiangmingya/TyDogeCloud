<?php

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/** 只转换明确允许的图片域名；不访问网络、不改数据库。 */
class TyDogeCloud_Url
{
    public static function domain(string $value): string
    {
        $domain = rtrim(trim($value), '/');
        $parts = parse_url($domain);
        if (!$parts || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || !empty($parts['path']) || preg_match('/[\x00-\x20\x7f<>"\x27]/', $domain)) {
            throw new RuntimeException('文件访问域名必须为 http(s)://域名，不含路径、参数或账号');
        }
        return $domain;
    }

    public static function hosts(string $value): array
    {
        if (strlen($value) > 8192) {
            throw new RuntimeException('历史图片域名列表过长');
        }
        $hosts = [];
        foreach (preg_split('/[\s,，]+/u', trim($value), -1, PREG_SPLIT_NO_EMPTY) as $item) {
            $origin = self::domain(strpos($item, '://') === false ? 'https://' . $item : $item);
            $hosts[] = self::authority(parse_url($origin));
        }
        return array_values(array_unique($hosts));
    }

    private static function authority(array $parts): string
    {
        return strtolower($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    public static function rule(string $value): string
    {
        $rule = trim($value);
        if ($rule === '') {
            return '';
        }
        if (strlen($rule) > 4096 || preg_match('/[\x00-\x20\x7f<>"\x27`#\\\\]/', $rule)
            || (!preg_match('~^[/!][a-zA-Z0-9_-]+$~D', $rule)
                && !(preg_match('~^\?(?:imageView2|imageMogr2|watermark)/~', $rule) && strpos(substr($rule, 1), '?') === false && strpos($rule, '&') === false))) {
            throw new RuntimeException('图片规则请留空，或填写 /样式名、!样式名、?imageMogr2/... 等多吉云规则');
        }
        return $rule;
    }

    public static function rewrite(string $url, string $domain, array $hosts, string $rule): string
    {
        $escaped = strpos($url, '&amp;') !== false || strpos($url, '&#38;') !== false || strpos($url, '&#x26;') !== false;
        $decoded = str_replace(['&amp;', '&#38;', '&#x26;'], '&', $url);
        $parts = parse_url($decoded);
        if (!$parts || isset($parts['user']) || isset($parts['pass'])
            || (isset($parts['scheme']) && !in_array(strtolower($parts['scheme']), ['http', 'https'], true))
            || !in_array(self::authority($parts), $hosts, true)) {
            return $url;
        }
        $raster = preg_match('~^(/.*\.(?:jpe?g|png|gif|webp|avif|bmp|tiff?|heif|heic))(?:[!/][a-zA-Z0-9_-]+)?$~iD', $parts['path'] ?? '', $image);
        if (!$raster) {
            if (!preg_match('~^/.*\.(?:svg|ico)$~iD', $parts['path'] ?? '')) {
                return $url;
            }
            // SVG/ICO 只切换域名，不调用位图处理，也不改动它们的参数。
            $result = $domain . $parts['path'] . (isset($parts['query']) ? '?' . $parts['query'] : '')
                . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
            return $escaped ? str_replace('&', '&amp;', $result) : $result;
        }
        $query = [];
        foreach (explode('&', $parts['query'] ?? '') as $param) {
            if ($param === '') {
                continue;
            }
            $name = rawurldecode(explode('=', $param, 2)[0]);
            if (!preg_match('~^(?:(?:imageView2|imageMogr2|watermark)(?:/|$)|format$|_upt$|exif$|imageAve$|imageInfo$)~i', $name)) {
                $query[] = $param;
            }
        }
        $suffix = $rule;
        if (strpos($rule, '?') === 0) {
            $suffix = '';
            $query[] = substr($rule, 1);
        }
        $result = $domain . $image[1] . $suffix . ($query ? '?' . implode('&', $query) : '')
            . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
        return $escaped ? str_replace('&', '&amp;', $result) : $result;
    }

    public static function html(string $html, string $domain, array $hosts, string $rule): string
    {
        // 保持原有 HTML 结构；覆盖 src、data-src、srcset、链接、CSS url 和普通 JS 字符串。
        return preg_replace_callback('~(?<![a-zA-Z0-9_/@])(?:https?:)?//[^\s<>"\x27`,()\[\]{}\\\\]+~iu',
            static function (array $match) use ($domain, $hosts, $rule): string {
                $url = rtrim($match[0], ',;');
                return self::rewrite($url, $domain, $hosts, $rule) . substr($match[0], strlen($url));
            }, $html) ?? $html;
    }
}
