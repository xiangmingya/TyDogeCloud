<?php

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/** 多吉云简单上传 API；不依赖 SDK，也不使用旧版 uploadToken。 */
class TyDogeCloud_DogeCloudClient
{
    public const MAX_SIZE = 10 * 1024 * 1024;

    private string $bucket;
    private string $accessKey;
    private string $secretKey;

    public function __construct(string $bucket, string $accessKey, string $secretKey)
    {
        $this->bucket = trim($bucket);
        $this->accessKey = trim($accessKey);
        $this->secretKey = trim($secretKey);
        if ($this->bucket === '' || $this->accessKey === '' || $this->secretKey === ''
            || preg_match('/[\x00-\x20\x7f]/', $this->accessKey)) {
            throw new RuntimeException('请填写存储空间名称、AccessKey 和 SecretKey');
        }
    }

    private function authorization(string $uri, string $body): string
    {
        return 'TOKEN ' . $this->accessKey . ':' . hash_hmac('sha1', $uri . "\n" . $body, $this->secretKey);
    }

    private function request(string $method, string $uri, string $body, string $mime): void
    {
        if (!extension_loaded('curl') || !function_exists('curl_init')) {
            throw new RuntimeException('PHP 缺少可用的 cURL 扩展');
        }
        // PUT 的签名正文必须为空；POST 删除则必须签入实际 JSON 正文。
        $authorization = $this->authorization($uri, $method === 'PUT' ? '' : $body);
        $ch = curl_init('https://api.dogecloud.com' . $uri);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => [
                'Authorization: ' . $authorization,
                // 空值抑制 cURL 默认表单类型，让多吉云自动识别文件 MIME。
                $mime !== '' ? 'Content-Type: ' . $mime : 'Content-Type:',
                'Content-Length: ' . strlen($body),
                'Expect:',
            ],
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $result = is_string($response) ? json_decode($response, true) : null;
        // 不输出响应正文或 cURL 原始错误，避免服务端回显凭据。
        if ($errno || $status < 200 || $status >= 300 || !is_array($result)
            || (int) ($result['code'] ?? 0) !== 200) {
            throw new RuntimeException('多吉云请求失败：HTTP ' . $status
                . '，API code ' . (int) ($result['code'] ?? 0) . '，cURL errno ' . $errno);
        }
    }

    public function put(string $key, string $body, string $mime): void
    {
        if (strlen($body) > self::MAX_SIZE) {
            throw new RuntimeException('多吉云简单上传接口仅支持 10 MiB 以内的文件');
        }
        $uri = '/oss/upload/put.json?' . http_build_query(
            ['bucket' => $this->bucket, 'key' => $key], '', '&', PHP_QUERY_RFC3986
        );
        $this->request('PUT', $uri, $body, $mime);
    }

    public function delete(string $key): void
    {
        $uri = '/oss/file/delete.json?' . http_build_query(
            ['bucket' => $this->bucket], '', '&', PHP_QUERY_RFC3986
        );
        $this->request('POST', $uri, json_encode([$key], JSON_THROW_ON_ERROR), 'application/json');
    }
}
