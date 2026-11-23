<?php
// 离线验证 Typecho 原生钩子和模拟 cURL 请求，不使用真实密钥或生产数据库。
namespace TyDogeCloudCheck {
    function extension_loaded($name) {
        return !in_array($name, $GLOBALS['dogeMissingExtensions'] ?? [], true) && \extension_loaded($name);
    }
    function curl_init($url) { return (object) ['url' => $url, 'options' => []]; }
    function curl_setopt_array($handle, $options) { $handle->options = $options; return true; }
    function curl_exec($handle) {
        $GLOBALS['dogeRequests'][] = $handle;
        return $GLOBALS['dogeResponse'];
    }
    function curl_getinfo($handle, $option) { return $GLOBALS['dogeStatus']; }
    function curl_errno($handle) { return $GLOBALS['dogeErrno']; }
}

namespace {
    function dogeCheck($ok, $message) { if (!$ok) throw new \RuntimeException($message); }
    function dogeThrows(callable $fn, $message) {
        try { $fn(); } catch (\Exception $e) { return; }
        throw new \RuntimeException($message);
    }
    function dogeLastRequest() { return $GLOBALS['dogeRequests'][count($GLOBALS['dogeRequests']) - 1]; }

    $root = rtrim((string) getenv('TYPECHO_ROOT'), '/');
    if ($root === '' || !is_file($root . '/var/Typecho/Common.php')) {
        fwrite(STDERR, "请设置 TYPECHO_ROOT，指向 Typecho 1.3 源码根目录。\n");
        exit(1);
    }
    define('__TYPECHO_ROOT_DIR__', $root);
    define('__TYPECHO_PLUGIN_DIR__', '/usr/plugins');
    define('__TYPECHO_CLASS_ALIASES__', ['Widget_Abstract_Contents' => '\\Widget\\Base\\Contents']);
    require $root . '/var/Typecho/Common.php';
    $pluginDir = dirname(__DIR__);
    $temp = sys_get_temp_dir() . '/tydogecloud-check-' . bin2hex(random_bytes(6));
    mkdir($temp, 0700);
    // Only replace transport functions, leaving production URI/signature/response logic intact.
    $source = file_get_contents($pluginDir . '/DogeCloudClient.php');
    $source = preg_replace('/\bcurl_(init|setopt_array|exec|getinfo|errno)\(/', '\\TyDogeCloudCheck\\curl_$1(', $source);
    $source = str_replace('extension_loaded(', '\\TyDogeCloudCheck\\extension_loaded(', $source);
    file_put_contents($temp . '/DogeCloudClient.php', $source);
    file_put_contents($temp . '/Plugin.php', str_replace('extension_loaded(', '\\TyDogeCloudCheck\\extension_loaded(',
        file_get_contents($pluginDir . '/Plugin.php')));
    copy($pluginDir . '/Url.php', $temp . '/Url.php');
    $previousLog = ini_get('error_log');
    ini_set('error_log', $temp . '/errors.log');
    $GLOBALS['dogeRequests'] = [];
    $GLOBALS['dogeResponse'] = '{"code":200,"msg":"OK"}';
    $GLOBALS['dogeStatus'] = 200;
    $GLOBALS['dogeErrno'] = 0;

    try {
        require $temp . '/Plugin.php';
        $settings = new \Typecho\Config([
            'bucket' => 'test-bucket', 'accessKey' => 'MY_ACCESS_KEY', 'secretKey' => 'MY_SECRET_KEY',
            'cdnDomain' => 'https://img.xiangming.site', 'pathPrefix' => '/typecho/uploads/',
            'mapLegacy' => '1', 'deleteRemote' => '0',
        ]);
        $options = (new \ReflectionClass(\Widget\Options::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(\Widget\Options::class, 'pluginConfig'))->setValue($options, ['TyDogeCloud' => $settings]);
        (new \ReflectionProperty(\Typecho\Widget::class, 'row'))->setValue($options,
            ['attachmentTypes' => 'png,jpg,txt', '#siteUrl' => 'https://blog.example/']);
        (new \ReflectionProperty(\Typecho\Widget::class, 'widgetPool'))->setValue(null,
            [\Typecho\Common::nativeClassName(\Widget\Options::class) => $options]);

        // Official HMAC vector, independently published in the API authentication documentation.
        $client = new \TyDogeCloud_DogeCloudClient('test-bucket', 'MY_ACCESS_KEY', 'MY_SECRET_KEY');
        $authorization = new \ReflectionMethod($client, 'authorization');
        dogeCheck($authorization->invoke($client, '/auth/upload.json?filename=a.mp4', '') ===
            'TOKEN MY_ACCESS_KEY:bf5ec167c882d6ffa8afa4a1d2c2ed8d622beadf', 'Official signature vector mismatch');

        $client->put('typecho/uploads/中文 图片+%.png', 'abc', 'image/png');
        $request = dogeLastRequest();
        $uri = '/oss/upload/put.json?bucket=test-bucket&key=typecho%2Fuploads%2F%E4%B8%AD%E6%96%87%20%E5%9B%BE%E7%89%87%2B%25.png';
        dogeCheck($request->url === 'https://api.dogecloud.com' . $uri, 'Query must be encoded once using RFC3986');
        dogeCheck($request->options[CURLOPT_CUSTOMREQUEST] === 'PUT' && $request->options[CURLOPT_POSTFIELDS] === 'abc', 'Upload must send binary body via PUT');
        $headers = $request->options[CURLOPT_HTTPHEADER];
        dogeCheck(in_array('Authorization: TOKEN MY_ACCESS_KEY:' . hash_hmac('sha1', $uri . "\n", 'MY_SECRET_KEY'), $headers, true), 'PUT signature must exclude body');
        dogeCheck(in_array('Content-Type: image/png', $headers, true) && in_array('Content-Length: 3', $headers, true), 'MIME/length headers missing');
        dogeCheck($request->options[CURLOPT_SSL_VERIFYPEER] === true && $request->options[CURLOPT_SSL_VERIFYHOST] === 2
            && $request->options[CURLOPT_FOLLOWLOCATION] === false && $request->options[CURLOPT_TIMEOUT] === 60, 'TLS/redirect/timeout settings changed');
        $client->delete('中文.png');
        $request = dogeLastRequest();
        $uri = '/oss/file/delete.json?bucket=test-bucket';
        $body = $request->options[CURLOPT_POSTFIELDS];
        dogeCheck($request->url === 'https://api.dogecloud.com' . $uri && $request->options[CURLOPT_CUSTOMREQUEST] === 'POST'
            && json_decode($body, true) === ['中文.png'], 'Delete endpoint/method/JSON mismatch');
        dogeCheck(in_array('Authorization: TOKEN MY_ACCESS_KEY:' . hash_hmac('sha1', $uri . "\n" . $body, 'MY_SECRET_KEY'),
            $request->options[CURLOPT_HTTPHEADER], true), 'Delete signature must include exact JSON bytes');

        foreach ([[200, '{"code":401}', 0], [503, '{"code":200}', 0], [200, '<html>bad</html>', 0],
            [200, 'null', 0], [200, '{}', 0], [200, false, 28]] as [$status, $response, $errno]) {
            $GLOBALS['dogeStatus'] = $status; $GLOBALS['dogeResponse'] = $response; $GLOBALS['dogeErrno'] = $errno;
            dogeThrows(fn() => $client->put('a.txt', 'abc', 'text/plain'), 'HTTP/API/JSON/transport failure accepted');
        }
        $GLOBALS['dogeStatus'] = 200; $GLOBALS['dogeResponse'] = '{"code":200}'; $GLOBALS['dogeErrno'] = 0;
        dogeThrows(fn() => new \TyDogeCloud_DogeCloudClient('', 'ak', 'sk'), 'Empty credentials accepted');

        // Run the real Typecho plugin dispatcher and settings form, without a database.
        $info = \Typecho\Plugin::parseInfo($pluginDir . '/Plugin.php');
        dogeCheck($info['activate'] && $info['deactivate'] && $info['config'],
            'Typecho metadata parser must expose activation, deactivation and settings controls');
        $GLOBALS['dogeMissingExtensions'] = ['fileinfo'];
        \Typecho\Plugin::init([]);
        \TyDogeCloud_Plugin::activate();
        $GLOBALS['dogeMissingExtensions'] = ['curl'];
        dogeThrows(fn() => \TyDogeCloud_Plugin::activate(), 'Missing cURL must prevent activation');
        dogeThrows(fn() => $client->put('a.txt', 'abc', 'text/plain'), 'Missing cURL must prevent upload');
        $GLOBALS['dogeMissingExtensions'] = [];
        \Typecho\Plugin::init(['activated' => ['TyUpyun' => []]]);
        dogeThrows(fn() => \TyDogeCloud_Plugin::activate(), 'Conflicting uploader activation allowed');
        \Typecho\Plugin::init([]);
        \TyDogeCloud_Plugin::activate();
        $form = new \Typecho\Widget\Helper\Form();
        ob_start();
        \TyDogeCloud_Plugin::config($form);
        ob_end_clean();
        dogeCheck($form->getValues()['cdnDomain'] === 'https://img.xiangming.site', 'Settings form default domain mismatch');
        foreach (['uploadHandle', 'modifyHandle', 'deleteHandle', 'attachmentHandle'] as $hook) {
            dogeCheck(isset(\Typecho\Plugin::export()['handles']['Widget_Upload:' . $hook]), 'Native hook missing: ' . $hook);
        }
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
        $uploaded = \Widget\Upload::uploadHandle(['name' => '../"图片".PNG', 'bytes' => $png, 'size' => 999]);
        dogeCheck(is_array($uploaded) && $uploaded['mime'] === 'image/png' && $uploaded['size'] === strlen($png)
            && $uploaded['name'] === '图片.PNG' && $uploaded['type'] === 'png', 'Actual MIME/name/size metadata incorrect');
        dogeCheck((bool) preg_match('#^typecho/uploads/\d{4}/\d{2}/[a-f0-9]{24}\.png$#', $uploaded['dogecloudKey']), 'Object path mismatch');
        $attachment = new \Typecho\Config($uploaded);
        dogeCheck(\Widget\Upload::attachmentHandle($attachment) === 'https://img.xiangming.site/' . $uploaded['dogecloudKey'], 'Native URL hook failed');

        $GLOBALS['dogeMissingExtensions'] = ['fileinfo'];
        $fallback = \Widget\Upload::uploadHandle(['name' => 'fallback.png', 'bytes' => $png]);
        dogeCheck(is_array($fallback) && $fallback['mime'] === 'image/png', 'Image upload without fileinfo failed');
        $unknown = \Widget\Upload::uploadHandle(['name' => 'fallback.txt', 'bytes' => 'hello']);
        dogeCheck(is_array($unknown) && $unknown['mime'] === 'application/octet-stream'
            && in_array('Content-Type:', dogeLastRequest()->options[CURLOPT_HTTPHEADER], true),
            'Unknown MIME without fileinfo must leave detection to DogeCloud');
        $GLOBALS['dogeMissingExtensions'] = [];

        $settings->pathPrefix = 'changed'; $settings->bucket = 'changed-bucket';
        $modified = \Widget\Upload::modifyHandle(['attachment' => $attachment], ['name' => 'replacement.png', 'bits' => $png]);
        dogeCheck($modified['dogecloudKey'] === $uploaded['dogecloudKey'] && $modified['dogecloudBucket'] === 'test-bucket'
            && $modified['name'] === $uploaded['name'], 'Replacement changed stored object location/title');
        dogeCheck(strpos(dogeLastRequest()->url, '?bucket=test-bucket&key=typecho%2Fuploads%2F') !== false, 'Replacement used current rather than recorded location');
        $count = count($GLOBALS['dogeRequests']);
        dogeCheck(\Widget\Upload::deleteHandle(['attachment' => $attachment]) === true && count($GLOBALS['dogeRequests']) === $count, 'Default delete must preserve cloud objects');
        $settings->deleteRemote = '1';
        dogeCheck(\Widget\Upload::deleteHandle(['attachment' => $attachment]) === true, 'Enabled deletion failed');
        dogeCheck(dogeLastRequest()->url === 'https://api.dogecloud.com/oss/file/delete.json?bucket=test-bucket'
            && json_decode(dogeLastRequest()->options[CURLOPT_POSTFIELDS], true) === [$uploaded['dogecloudKey']], 'Delete used wrong stored location');

        $settings->pathPrefix = '/typecho/uploads/'; $settings->bucket = 'test-bucket';
        $legacy = new \Typecho\Config(['name' => 'old.png', 'path' => '/usr/uploads/2026/10/2116638127.png', 'type' => 'png']);
        dogeCheck(\Widget\Upload::attachmentHandle($legacy) === 'https://img.xiangming.site/typecho/uploads/2026/10/2116638127.png', 'User-provided migrated image mapping incorrect');
        $legacyModified = \Widget\Upload::modifyHandle(['attachment' => $legacy], ['name' => 'old.png', 'bytes' => $png]);
        dogeCheck($legacyModified['dogecloudKey'] === 'typecho/uploads/2026/10/2116638127.png', 'Legacy replacement failed to record object location');
        $settings->mapLegacy = '0';
        dogeCheck(\Widget\Upload::attachmentHandle($legacy) === 'https://blog.example/usr/uploads/2026/10/2116638127.png', 'Disabled legacy mapping must retain local URL');
        dogeCheck(\Widget\Upload::modifyHandle(['attachment' => $legacy], ['name' => 'old.png', 'bytes' => $png]) === false, 'Unmapped legacy attachment overwritten');
        $settings->mapLegacy = '1';
        $encoded = new \Typecho\Config(['dogecloudBucket' => 'test-bucket', 'dogecloudKey' => 'typecho/uploads/中文 +%.png']);
        dogeCheck(\Widget\Upload::attachmentHandle($encoded) === 'https://img.xiangming.site/typecho/uploads/%E4%B8%AD%E6%96%87%20%2B%25.png', 'CDN path must encode each segment once');

        $count = count($GLOBALS['dogeRequests']);
        foreach ([['name' => 'bad.php', 'bytes' => 'x'], ['name' => 'empty.png'], ['name' => 'x.png', 'bytes' => $png, 'error' => UPLOAD_ERR_PARTIAL],
            ['name' => 'large.png', 'bytes' => str_repeat('x', \TyDogeCloud_DogeCloudClient::MAX_SIZE + 1), 'size' => 1]] as $badFile) {
            dogeCheck(\Widget\Upload::uploadHandle($badFile) === false, 'Invalid or oversized file accepted');
        }
        file_put_contents($temp . '/image.png', $png);
        dogeCheck(is_array(\Widget\Upload::uploadHandle(['name' => 'tmp.png', 'tmp_name' => $temp . '/image.png'])), 'Multipart temporary file failed');
        $count++;
        dogeCheck(\Widget\Upload::modifyHandle(['attachment' => $attachment], ['name' => 'bad.jpg', 'bytes' => $png]) === false, 'Mismatched replacement extension accepted');
        $settings->pathPrefix = '../bad';
        dogeCheck(\Widget\Upload::uploadHandle(['name' => 'x.png', 'bytes' => $png]) === false, 'Traversal prefix accepted');
        $settings->pathPrefix = 'typecho/uploads';
        $settings->cdnDomain = 'https://img.xiangming.site/typecho/uploads';
        dogeCheck(\Widget\Upload::uploadHandle(['name' => 'x.png', 'bytes' => $png]) === false, 'Domain with duplicate path accepted');
        $settings->cdnDomain = 'https://img.xiangming.site';
        dogeCheck(count($GLOBALS['dogeRequests']) === $count, 'Rejected input caused a network request');

        $GLOBALS['dogeResponse'] = '{"code":401,"msg":"MY_SECRET_KEY must not be logged"}';
        dogeCheck(\Widget\Upload::uploadHandle(['name' => 'x.png', 'bytes' => $png]) === false, 'Failed API response produced attachment metadata');
        dogeCheck(\Widget\Upload::modifyHandle(['attachment' => $attachment], ['name' => 'x.png', 'bytes' => $png]) === false, 'Failed replacement returned success');
        dogeCheck(\Widget\Upload::deleteHandle(['attachment' => $attachment]) === false, 'Failed deletion returned success');
        dogeCheck(strpos(file_get_contents($temp . '/errors.log'), 'MY_SECRET_KEY') === false, 'API response leaked to log');

        // Render-time rewriting: rule changes must never be saved into attachment URLs.
        $oldImage = 'https://yop.xiangming.site/typecho/uploads/2026/10/2735706219.png!syt?format=webp';
        $newImage = 'https://img.xiangming.site/typecho/uploads/2026/10/2735706219.png';
        $settings->imageRule = '';
        dogeCheck(\TyDogeCloud_Plugin::rewriteHtml('<img src="' . $oldImage . '">') === '<img src="' . $newImage . '">', 'Legacy host and Upyun suffix must be replaced');
        $settings->imageRule = '/syt';
        dogeCheck(\Widget\Upload::attachmentHandle($attachment) === 'https://img.xiangming.site/' . $uploaded['dogecloudKey'], 'Editor URL must remain an original image URL');
        $markup = '<img src="' . $oldImage . '"><img data-src="' . $newImage . '"><img srcset="' . $oldImage . ' 1x, ' . $newImage . ' 2x">'
            . '<style>.hero{background-image:url(' . $oldImage . ')}</style><script>const img="' . $oldImage . '";</script>';
        $rendered = \TyDogeCloud_Plugin::rewriteHtml($markup);
        dogeCheck(substr_count($rendered, $newImage . '/syt') === 6 && strpos($rendered, 'yop.xiangming.site') === false, 'HTML/theme/custom-field/image sources did not share one rule');
        dogeCheck(\TyDogeCloud_Plugin::rewriteHtml($rendered) === $rendered, 'Multiple rendering hooks duplicated the style');
        dogeCheck(\TyDogeCloud_Plugin::rewriteHtml('<img srcset="' . $oldImage . ',' . $newImage . '">') ===
            '<img srcset="' . $newImage . '/syt,' . $newImage . '/syt">', 'Comma-separated srcset without spaces failed');
        foreach (['https://external.example/a.png!syt?format=webp', 'https://yop.xiangming.site.evil/a.png',
            'https://yop.xiangming.site@external.example/a.png', 'https://yop.xiangming.site/a.pdf',
            'https://yop.xiangming.site/a.png/object.txt'] as $untouched) {
            dogeCheck(\TyDogeCloud_Plugin::rewriteHtml($untouched) === $untouched, 'External host/non-image modified: ' . $untouched);
        }
        dogeCheck(\TyDogeCloud_Plugin::rewriteHtml('//yop.xiangming.site/a.png/old') === 'https://img.xiangming.site/a.png/syt', 'Protocol-relative URL or previous DogeCloud style failed');
        dogeCheck(\TyDogeCloud_Plugin::rewriteHtml('<img src="https://yop.xiangming.site/wp-content/uploads/img/CDN_duojiyun.svg?v=2">') ===
            '<img src="https://img.xiangming.site/wp-content/uploads/img/CDN_duojiyun.svg?v=2">', 'SVG badge must change domain without bitmap rules');
        dogeCheck(\TyDogeCloud_Plugin::rewriteHtml('https://yop.xiangming.site/favicon.ico') === 'https://img.xiangming.site/favicon.ico',
            'ICO must change domain without bitmap rules');
        $settings->imageRule = '?imageMogr2/thumbnail/1600x/format/webp';
        $processed = \TyDogeCloud_Plugin::rewriteHtml('https://yop.xiangming.site/a.png!syt?v=2&amp;format=webp&amp;_upt=old#view');
        dogeCheck($processed === 'https://img.xiangming.site/a.png?v=2&amp;imageMogr2/thumbnail/1600x/format/webp#view', 'Query-mode style must preserve unrelated query and fragment');
        dogeCheck(\TyDogeCloud_Plugin::rewriteHtml($processed) === $processed, 'Query processing not idempotent');
        $contents = \Typecho\Plugin::factory(\Widget\Base\Contents::class);
        dogeCheck(strpos($contents->filter('contentEx', '<img src="' . $oldImage . '">'), '?imageMogr2/') !== false, 'Native content/RSS hook failed');
        dogeCheck(strpos($contents->filter('excerptEx', '<img src="' . $oldImage . '">'), '?imageMogr2/') !== false, 'Native excerpt hook failed');
        ob_start();
        \Typecho\Plugin::factory(\Widget\Archive::class)->call('beforeRender');
        echo '<img class="thumbnail" src="' . $oldImage . '"><img class="ad" src="' . $oldImage . '">';
        \Typecho\Plugin::factory(\Widget\Archive::class)->call('afterRender');
        $page = ob_get_clean();
        dogeCheck(substr_count($page, '?imageMogr2/thumbnail/1600x/format/webp') === 2, 'Native page hooks missed thumbnails/ad settings');
        $settings->rewriteImages = '0';
        dogeCheck(\TyDogeCloud_Plugin::rewriteHtml($oldImage) === $oldImage, 'Image rewrite off switch ignored');
        $settings->rewriteImages = '1';
        foreach (['syt', '/syt?format=webp', '?format=webp', '?imageMogr2/thumbnail/800x&other=1', '/x" onerror="bad', "?imageMogr2/\r\nX-Bad:1"] as $invalid) {
            dogeThrows(fn() => \TyDogeCloud_Url::rule($invalid), 'Unsafe/incorrect image rule accepted');
        }
        dogeCheck(\TyDogeCloud_Url::rule('!syt') === '!syt', 'Custom DogeCloud delimiter unsupported');

        // Actual Typecho settings writes with an isolated database; verify upgrade retains keys.
        $db = new \Typecho\Db('Pdo_SQLite', 'typecho_');
        $db->addServer(['file' => ':memory:'], \Typecho\Db::READ | \Typecho\Db::WRITE);
        \Typecho\Db::set($db);
        $pdo = $db->selectDb(\Typecho\Db::READ);
        (new \ReflectionProperty(\Typecho\Db::class, 'connectedPool'))->setValue($db,
            [\Typecho\Db::READ => $pdo, \Typecho\Db::WRITE => $pdo]);
        $pdo->exec('CREATE TABLE typecho_options (name TEXT, user INTEGER, value TEXT, PRIMARY KEY(name,user))');
        $insert = $pdo->prepare('INSERT INTO typecho_options VALUES (?,0,?)');
        $insert->execute(['plugin:TyDogeCloud', json_encode($settings->toArray())]);
        $insert->execute(['plugins', json_encode(['activated' => ['TyDogeCloud' => []], 'handles' => []])]);
        \Typecho\Plugin::init(['activated' => ['TyDogeCloud' => []], 'handles' => []]);
        $saved = $settings->toArray();
        $saved['cdnDomain'] = 'https://new-images.example';
        $saved['legacyDomains'] = 'yop.xiangming.site';
        \TyDogeCloud_Plugin::configHandle($saved, false);
        $saved = json_decode($pdo->query("SELECT value FROM typecho_options WHERE name='plugin:TyDogeCloud'")->fetchColumn(), true);
        dogeCheck($saved['secretKey'] === 'MY_SECRET_KEY' && strpos($saved['legacyDomains'], 'img.xiangming.site') !== false, 'Upgrade/domain change lost credentials or previous domain');
        $persisted = json_decode($pdo->query("SELECT value FROM typecho_options WHERE name='plugins'")->fetchColumn(), true);
        dogeCheck(isset($persisted['handles']['Widget_Archive:beforeRender']) && isset($persisted['handles']['Widget_Abstract_Contents:contentEx']),
            'Saving old installation did not persist hooks with the production class aliases');
        $settings->setDefault($saved, true);
        $changed = \TyDogeCloud_Plugin::rewriteHtml($newImage . '/syt');
        dogeCheck(strpos($changed, 'https://new-images.example/') === 0, 'Domain switch failed to update an old stored URL');
        \TyDogeCloud_Plugin::configHandle($saved, false);
        dogeCheck(count(\Typecho\Plugin::export()['handles']['Widget_Archive:beforeRender']) === 1, 'Repeated settings save duplicated hooks');
        dogeCheck(isset(\Typecho\Plugin::export()['handles'][\Typecho\Common::nativeClassName(\Widget\Contents\Attachment\Edit::class) . ':delete']), 'Pre-delete hook was not registered');
        // Exercise the actual Typecho deleteByIds flow: no production DB or cloud calls.
        class TyDogeCloudDeleteCheck extends \Widget\Contents\Attachment\Edit {
            public bool $writable = true;
            public static function pluginHandle(): \Typecho\Plugin { return \Typecho\Plugin::factory(\Widget\Contents\Attachment\Edit::class); }
            public function isWriteable(\Typecho\Db\Query $condition): bool { return $this->writable; }
            public function runDelete(int $cid): int { $count = 0; $this->deleteByIds([$cid], $count); return $count; }
        }
        \Typecho\Plugin::factory(TyDogeCloudDeleteCheck::class)->delete = [\TyDogeCloud_Plugin::class, 'beforeAttachmentDelete'];
        $pdo->exec('CREATE TABLE typecho_contents (cid INTEGER PRIMARY KEY, authorId INTEGER, type TEXT, text TEXT, parent INTEGER, created INTEGER)');
        $pdo->exec('CREATE TABLE typecho_comments (cid INTEGER)');
        $attachmentInsert = $pdo->prepare('INSERT INTO typecho_contents VALUES (?,1,?,?,0,?)');
        foreach ([9001, 9002, 9003] as $id) $attachmentInsert->execute([$id, 'attachment', json_encode($uploaded), time()]);
        $editor = (new \ReflectionClass(TyDogeCloudDeleteCheck::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(\Widget\Base::class, 'db'))->setValue($editor, $db);
        (new \ReflectionProperty(\Typecho\Widget::class, 'request'))->setValue($editor, new \Typecho\Widget\Request(\Typecho\Request::getInstance()));
        $settings->deleteRemote = '1';
        $GLOBALS['dogeResponse'] = '{"code":401,"msg":"MY_SECRET_KEY must not be displayed"}';
        dogeThrows(fn() => $editor->runDelete(9001), 'Cloud failure must abort the real core deletion');
        dogeCheck((int) $pdo->query('SELECT COUNT(*) FROM typecho_contents WHERE cid=9001')->fetchColumn() === 1, 'Cloud failure removed the attachment record');
        $GLOBALS['dogeResponse'] = '{"code":200}';
        $count = count($GLOBALS['dogeRequests']);
        dogeCheck($editor->runDelete(9001) === 1, 'Cloud success did not complete core deletion');
        dogeCheck(count($GLOBALS['dogeRequests']) === $count + 1, 'Core post-delete hook repeated the cloud deletion');
        dogeCheck((int) $pdo->query('SELECT COUNT(*) FROM typecho_contents WHERE cid=9001')->fetchColumn() === 0, 'Successful deletion kept the record');
        $editor->writable = false;
        $count = count($GLOBALS['dogeRequests']);
        dogeCheck($editor->runDelete(9002) === 0 && count($GLOBALS['dogeRequests']) === $count, 'Permission denial still deleted a cloud object');
        dogeCheck((int) $pdo->query('SELECT COUNT(*) FROM typecho_contents WHERE cid=9002')->fetchColumn() === 1, 'Permission denial removed the record');
        $editor->writable = true;
        $settings->deleteRemote = '0';
        dogeCheck($editor->runDelete(9003) === 1 && count($GLOBALS['dogeRequests']) === $count, 'Keep-files setting was ignored');
        dogeCheck(strpos(file_get_contents($temp . '/errors.log'), 'MY_SECRET_KEY') === false, 'Deletion failure leaked credentials');

        echo "检查通过：上传签名、设置钩子、类型兼容、旧路径、域名规则转换、升级保存，以及云端删除失败保留记录、权限校验和防重复请求。\n";
    } finally {
        ini_set('error_log', $previousLog);
        foreach (glob($temp . '/*') as $file) unlink($file);
        rmdir($temp);
    }
}
