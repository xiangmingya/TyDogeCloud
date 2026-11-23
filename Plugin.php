<?php
/**
 * 多吉云附件上传与图片地址管理，统一切换域名、处理规则并兼容历史图片链接。
 *
 * @package TyDogeCloud
 * @author 湘铭呀
 * @version 1.2.0
 * @since 1.3.0
 * @link https://github.com/xiangmingya/TyDogeCloud
 */

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;

require_once __DIR__ . '/DogeCloudClient.php';
require_once __DIR__ . '/Url.php';

class TyDogeCloud_Plugin implements PluginInterface
{
    private static int $bufferLevel = 0;
    private static array $deletedAttachments = [];

    public static function activate()
    {
        if (!extension_loaded('curl') || !function_exists('curl_init')) {
            throw new \Typecho\Plugin\Exception('TyDogeCloud 缺少可用的 PHP cURL 扩展，请在博客使用的 PHP 版本中启用 cURL');
        }
        $plugins = \Typecho\Plugin::export();
        foreach (['TyUpyun', 'UpyunFile'] as $name) {
            if (isset($plugins['activated'][$name])) {
                throw new \Typecho\Plugin\Exception('请先停用 ' . $name . '，避免同时接管附件上传');
            }
        }
        self::registerHooks();
        return 'TyDogeCloud 已启用，请填写多吉云密钥和存储空间名称';
    }

    private static function registerHooks(): void
    {
        foreach (['uploadHandle', 'modifyHandle', 'deleteHandle', 'attachmentHandle'] as $hook) {
            \Typecho\Plugin::factory(\Widget\Upload::class)->{$hook} = [__CLASS__, $hook];
        }
        foreach (['contentEx', 'excerptEx'] as $hook) {
            \Typecho\Plugin::factory(\Widget\Base\Contents::class)->{$hook} = [__CLASS__, 'rewriteHtml'];
        }
        \Typecho\Plugin::factory(\Widget\Archive::class)->beforeRender = [__CLASS__, 'beforeRender'];
        \Typecho\Plugin::factory(\Widget\Archive::class)->afterRender = [__CLASS__, 'afterRender'];
        \Typecho\Plugin::factory(\Widget\Contents\Attachment\Edit::class)->delete = [__CLASS__, 'beforeAttachmentDelete'];
        \Typecho\Plugin::factory('admin/footer.php')->begin = [__CLASS__, 'editorFooter'];
    }

    public static function deactivate()
    {
        return 'TyDogeCloud 已停用，云端文件保留';
    }

    public static function config(Form $form)
    {
        foreach ([
            ['bucket', '', '存储空间名称（Bucket）', '填写多吉云控制台的空间名称，不是域名，也不是空间 ID。'],
            ['accessKey', '', 'AccessKey', '多吉云用户中心 → 密钥管理；密钥仅在服务器端使用。'],
            ['cdnDomain', 'https://img.xiangming.site', '文件访问域名', '只填 http(s)://域名，不要包含 /typecho/uploads 或文件名。'],
            ['pathPrefix', 'typecho/uploads', '存储路径前缀', '最终 Key：typecho/uploads/年/月/随机文件名；前后斜杠可省略。'],
        ] as [$name, $default, $label, $description]) {
            $input = new Form\Element\Text($name, null, $default, $label, $description);
            $form->addInput($input->addRule('required', '请填写' . $label));
        }
        $secret = new Form\Element\Password('secretKey', null, '', 'SecretKey', '多吉云永久 SecretKey，请勿填又拍云操作员密码。');
        $form->addInput($secret->addRule('required', '请填写 SecretKey'));
        $form->addInput(new Form\Element\Radio('mapLegacy', ['1' => '已迁移，使用新域名', '0' => '保持原本地地址'], '1',
            '旧附件路径映射', '默认按旧附件已迁移处理：/usr/uploads/年/月/文件 → 当前前缀/年/月/文件。不会搬运文件或修改文章正文。'));
        $form->addInput(new Form\Element\Radio('deleteRemote', ['0' => '保留云端文件', '1' => '同步删除云端文件'], '0',
            '删除附件时', '选择同步删除后，确认删除附件会同时删除桶中的文件，且不可恢复；云端删除失败时保留附件记录。也适用于已映射的旧附件。'));
        $form->addInput(new Form\Element\Radio('rewriteImages', ['1' => '开启', '0' => '关闭'], '1',
            '展示时统一图片地址', '统一处理文章、摘要、RSS，以及主题输出的缩略图和广告图；不修改数据库中的原文。'));
        $form->addInput(new Form\Element\Textarea('legacyDomains', null, "yop.xiangming.site\nimg.xiangming.site", '历史图片域名',
            '每行一个域名，也可填 http(s)://域名；只转换这些域名和当前访问域名的图片。保存新域名时自动保留上一次域名。'));
        $form->addInput(new Form\Element\Text('imageRule', null, '', '多吉云图片处理规则',
            '留空显示原图；填 /syt 使用控制台同名样式，或 ?imageMogr2/thumbnail/1600x/format/webp。默认分隔符为 /；! 分隔符需多吉云客服配置。格式转换放进样式或 imageMogr2 规则中。'));
        echo '<p>升级后直接保存本页即可注册新功能并保留已有密钥，无需先禁用插件。使用样式前需在多吉云开启图片处理并创建规则；参数模式需在 CDN 关闭“忽略参数”。</p>';
    }

    public static function personalConfig(Form $form)
    {
    }

    public static function editorFooter(): void
    {
        $deleteRemote = (string) self::options()->deleteRemote === '1';
        $deleteUrl = \Widget\Security::alloc()->getIndex('/action/contents-attachment-edit');
        $cloudAttachments = [];
        $db = \Typecho\Db::get();
        $cid = (int) \Typecho\Request::getInstance()->get('cid', 0);
        $query = $db->select('cid', 'text')->from('table.contents')->where('type = ?', 'attachment');
        if ($cid) {
            $query->where('(parent = ? OR cid = ?)', $cid, $cid);
        } else {
            $query->where('parent = ?', 0)->where('authorId = ?', (int) \Widget\User::alloc()->uid);
        }
        foreach ($db->fetchAll($query) as $row) {
            try {
                $metadata = json_decode($row['text'], true);
                $cloudAttachments[$row['cid']] = is_array($metadata)
                    && self::location(new \Typecho\Config($metadata)) !== null;
            } catch (\RuntimeException $e) {
                $cloudAttachments[$row['cid']] = false;
            }
        }
        require __DIR__ . '/admin.php';
    }

    /** 原生钩子在数据库删除之前触发，先验证权限并完成云端删除。 */
    public static function beforeAttachmentDelete(int $cid, \Widget\Contents\Attachment\Edit $widget): void
    {
        if ((string) self::options()->deleteRemote !== '1' || isset(self::$deletedAttachments[$cid])) {
            return;
        }
        $db = \Typecho\Db::get();
        $condition = $db->sql()->where('cid = ?', $cid)->where('type = ?', 'attachment');
        if (!$widget->isWriteable(clone $condition)) {
            return;
        }
        $row = $db->fetchRow((clone $condition)->select('text')->from('table.contents'));
        $metadata = json_decode($row['text'] ?? '', true);
        try {
            if (is_array($metadata) && self::location(new \Typecho\Config($metadata)) === null) {
                return; // 未映射的本地附件不属于云端删除范围。
            }
            if (is_array($metadata) && self::deleteHandle(['attachment' => new \Typecho\Config($metadata)])) {
                self::$deletedAttachments[$cid] = true;
                return;
            }
        } catch (\RuntimeException $e) {
            error_log('[TyDogeCloud] pre-delete: ' . $e->getMessage());
        }
        $message = '多吉云文件删除失败，本次附件记录已保留。请检查插件密钥、存储空间和服务器日志后重试。';
        if ($widget->request->isAjax()) {
            $widget->response->setStatus(502)->throwJson(['code' => 502, 'message' => $message]);
        }
        throw new \Typecho\Widget\Exception($message, 502);
    }

    public static function configHandle(array $settings, bool $isInit)
    {
        try {
            $old = self::options()->toArray();
        } catch (\Typecho\Plugin\Exception $e) {
            $old = [];
        }
        try {
            $domain = TyDogeCloud_Url::domain((string) ($settings['cdnDomain'] ?? 'https://img.xiangming.site'));
            $hosts = TyDogeCloud_Url::hosts((string) ($settings['legacyDomains'] ?? "yop.xiangming.site\nimg.xiangming.site"));
            if (!empty($old['cdnDomain']) && $old['cdnDomain'] !== $domain) {
                $hosts = array_merge($hosts, TyDogeCloud_Url::hosts($old['cdnDomain']));
            }
            $settings['cdnDomain'] = $domain;
            $settings['legacyDomains'] = implode("\n", array_unique($hosts));
            $settings['imageRule'] = TyDogeCloud_Url::rule((string) ($settings['imageRule'] ?? ''));
            $settings['rewriteImages'] = (string) ($settings['rewriteImages'] ?? '1') === '0' ? '0' : '1';
        } catch (\RuntimeException $e) {
            throw new \Typecho\Plugin\Exception($e->getMessage());
        }
        \Widget\Plugins\Edit::configPlugin('TyDogeCloud', $settings);
        if (!$isInit && \Typecho\Plugin::exists('TyDogeCloud')) {
            // 升级只刷新自己的钩子，不走后台禁用流程，避免清除已有凭据。
            \Typecho\Plugin::deactivate('TyDogeCloud');
            self::registerHooks();
            \Typecho\Plugin::activate('TyDogeCloud');
            $db = \Typecho\Db::get();
            $db->query($db->update('table.options')->rows(['value' => json_encode(\Typecho\Plugin::export())])
                ->where('name = ?', 'plugins')->where('user = ?', 0));
        }
    }

    public static function rewriteHtml(?string $html): ?string
    {
        if ($html === null || $html === '') {
            return $html;
        }
        $options = self::options();
        if ((string) ($options->rewriteImages ?? '1') === '0') {
            return $html;
        }
        try {
            $domain = self::domain();
            $hosts = TyDogeCloud_Url::hosts((string) ($options->legacyDomains ?? "yop.xiangming.site\nimg.xiangming.site") . "\n" . $domain);
            return TyDogeCloud_Url::html($html, $domain, $hosts, TyDogeCloud_Url::rule((string) ($options->imageRule ?? '')));
        } catch (\RuntimeException $e) {
            error_log('[TyDogeCloud] image URL: ' . $e->getMessage());
            return $html;
        }
    }

    public static function thumbnailUrl(string $displayUrl, int $edge): ?string
    {
        $options = self::options();
        if ((string) ($options->rewriteImages ?? '1') === '0') {
            return null;
        }
        try {
            return TyDogeCloud_Url::thumbnail($displayUrl, self::domain(),
                TyDogeCloud_Url::rule((string) ($options->imageRule ?? '')), $edge);
        } catch (\RuntimeException $e) {
            return null;
        }
    }

    public static function beforeRender(): void
    {
        if (self::$bufferLevel === 0) {
            ob_start([__CLASS__, 'rewriteHtml']);
            self::$bufferLevel = ob_get_level();
        }
    }

    public static function afterRender(): void
    {
        if (self::$bufferLevel > 0 && ob_get_level() === self::$bufferLevel) {
            ob_end_flush();
            self::$bufferLevel = 0;
        }
    }

    private static function options(): \Typecho\Config
    {
        return \Widget\Options::alloc()->plugin('TyDogeCloud');
    }

    private static function client(string $bucket): TyDogeCloud_DogeCloudClient
    {
        $options = self::options();
        return new TyDogeCloud_DogeCloudClient($bucket, (string) $options->accessKey, (string) $options->secretKey);
    }

    private static function domain(): string
    {
        return TyDogeCloud_Url::domain((string) self::options()->cdnDomain);
    }

    private static function validKey(string $key): string
    {
        if ($key === '' || strlen($key) > 750 || preg_match('/[\\\\\x00-\x1f\x7f]/', $key)) {
            throw new RuntimeException('存储路径无效或过长');
        }
        foreach (explode('/', $key) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException('存储路径不能包含空目录、. 或 ..');
            }
        }
        return $key;
    }

    private static function mappedKey(string $path): ?string
    {
        $uploadDir = defined('__TYPECHO_UPLOAD_DIR__') ? __TYPECHO_UPLOAD_DIR__ : \Widget\Upload::UPLOAD_DIR;
        $root = rtrim($uploadDir, '/') . '/';
        if (strpos($path, $root) !== 0) {
            return null;
        }
        $prefix = trim(trim((string) self::options()->pathPrefix), '/');
        return self::validKey(($prefix === '' ? '' : $prefix . '/') . substr($path, strlen($root)));
    }

    /** 新附件固定保存实际 bucket/key；更改前缀不会改变它们的替换或删除目标。 */
    private static function location(\Typecho\Config $attachment): ?array
    {
        if (isset($attachment->dogecloudKey) && isset($attachment->dogecloudBucket)) {
            return [(string) $attachment->dogecloudBucket, self::validKey((string) $attachment->dogecloudKey)];
        }
        if ((string) self::options()->mapLegacy === '1') {
            $key = self::mappedKey((string) $attachment->path);
            if ($key !== null) {
                return [trim((string) self::options()->bucket), $key];
            }
        }
        return null;
    }

    private static function safeName(string &$name): string
    {
        $name = str_replace(['"', '<', '>'], '', $name);
        $name = str_replace('\\', '/', $name);
        $name = false === strpos($name, '/') ? ('a' . $name) : str_replace('/', '/a', $name);
        $info = pathinfo($name);
        $name = substr($info['basename'], 1);
        return isset($info['extension']) ? strtolower($info['extension']) : '';
    }

    private static function body(array $file): string
    {
        if (isset($file['error']) && (int) $file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('PHP 上传失败，错误码 ' . (int) $file['error']);
        }
        if (isset($file['tmp_name'])) {
            // 限长读取，避免先把超大文件全部载入内存；实际长度优先于客户端 size。
            $body = @file_get_contents($file['tmp_name'], false, null, 0, TyDogeCloud_DogeCloudClient::MAX_SIZE + 1);
        } else {
            $body = $file['bytes'] ?? $file['bits'] ?? false;
        }
        if (!is_string($body)) {
            throw new RuntimeException('无法读取上传文件');
        }
        if (strlen($body) > TyDogeCloud_DogeCloudClient::MAX_SIZE) {
            throw new RuntimeException('多吉云简单上传接口仅支持 10 MiB 以内的文件');
        }
        return $body;
    }

    private static function store(array $file, string $name, string $ext, string $path, array $location): array
    {
        self::domain(); // 先检查 URL 配置，避免上传成功后无法返回附件地址。
        $body = self::body($file);
        $mime = '';
        if (extension_loaded('fileinfo') && class_exists('finfo')) {
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($body) ?: '';
        } elseif (function_exists('getimagesizefromstring')) {
            $image = @getimagesizefromstring($body);
            $mime = $image['mime'] ?? '';
        }
        [$bucket, $key] = $location;
        self::client($bucket)->put($key, $body, $mime);
        return ['name' => $name, 'path' => $path, 'size' => strlen($body), 'type' => $ext, 'mime' => $mime ?: 'application/octet-stream',
            'dogecloudBucket' => $bucket, 'dogecloudKey' => $key];
    }

    public static function uploadHandle(array $file)
    {
        if (empty($file['name'])) {
            return false;
        }
        $name = $file['name'];
        $ext = self::safeName($name);
        if (!\Widget\Upload::checkFileType($ext)) {
            return false;
        }
        try {
            $date = new \Typecho\Date();
            $path = (defined('__TYPECHO_UPLOAD_DIR__') ? __TYPECHO_UPLOAD_DIR__ : \Widget\Upload::UPLOAD_DIR)
                . '/' . $date->year . '/' . $date->month . '/' . bin2hex(random_bytes(12)) . '.' . $ext;
            return self::store($file, $name, $ext, $path,
                [trim((string) self::options()->bucket), self::mappedKey($path)]);
        } catch (\RuntimeException $e) {
            error_log('[TyDogeCloud] upload: ' . $e->getMessage());
            return false;
        }
    }

    public static function modifyHandle(array $content, array $file)
    {
        if (empty($file['name'])) {
            return false;
        }
        $name = $file['name'];
        $ext = self::safeName($name);
        $attachment = $content['attachment'];
        if ($ext !== $attachment->type || !\Widget\Upload::checkFileType($ext)) {
            return false;
        }
        try {
            $location = self::location($attachment);
            if ($location === null) {
                return false;
            }
            return self::store($file, $attachment->name, $ext, $attachment->path, $location);
        } catch (\RuntimeException $e) {
            error_log('[TyDogeCloud] modify: ' . $e->getMessage());
            return false;
        }
    }

    public static function deleteHandle(array $content): bool
    {
        if (isset($content['cid'], self::$deletedAttachments[$content['cid']])) {
            return true; // 已在数据库删除前完成，避免核心后置钩子重复请求。
        }
        if ((string) self::options()->deleteRemote !== '1') {
            return true;
        }
        try {
            $location = self::location($content['attachment']);
            if ($location === null) {
                return false;
            }
            self::client($location[0])->delete($location[1]);
            return true;
        } catch (\RuntimeException $e) {
            error_log('[TyDogeCloud] delete: ' . $e->getMessage());
            return false;
        }
    }

    public static function attachmentHandle(\Typecho\Config $attachment): string
    {
        $location = self::location($attachment);
        if ($location !== null) {
            return self::domain() . '/' . implode('/', array_map('rawurlencode', explode('/', $location[1])));
        }
        return \Typecho\Common::url($attachment->path,
            defined('__TYPECHO_UPLOAD_URL__') ? __TYPECHO_UPLOAD_URL__ : \Widget\Options::alloc()->siteUrl);
    }
}
