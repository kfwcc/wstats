<?php
/**
 * 在线更新（检查 + 一键升级）
 *
 * 远端协议见 update-server/README.md；本类只依赖 PHP 内置能力（curl/流、ZipArchive、PDO）。
 *
 * 设计要点（都是踩过的坑）：
 *  1. **只覆盖程序文件**：写包前先逐条校验 update.json 里的 sha256，任何一条不符即整体中止，
 *     不做「部分成功」的更新 —— 半个新版本比不更新更难排查。
 *  2. **先备份再写**：被覆盖/被删除的文件先复制到 data/backup/<版本>-<时间>/，
 *     并写入 rollback.json（含备份目录与文件清单），出问题可原样回滚。
 *  3. **运行期数据永不触碰**：data/ 、config.php 、public/install/local.php 一律保留；
 *     config.php 若被用户改过（与基线哈希不符）则只落 config.php.new，绝不覆盖。
 *  4. **跨目录布局不自动升级**：基线是旧布局（server/ 层）时，服务端会把更新条目指向完整包，
 *     客户端据此只提示「下载完整包手工迁移」——自动搬目录的风险远大于收益。
 *  5. 更新包内的 sql/upgrade-*.sql（相对基线新增的）在文件写入后执行；SQL 失败不自动回滚文件，
 *     但在结果里明确列出已执行语句与失败原因（数据库回滚无法保证，宁可如实报告）。
 */
declare(strict_types=1);

namespace Wstat\Support;

// 命名空间内 new ZipArchive() 会被解析成 Wstat\Support\ZipArchive（类引用没有全局回退），必须 use 进来
use ZipArchive;

final class Updater
{
    public const PRODUCT = 'webstats';

    /* ===================== 版本 / 布局 ===================== */

    /**
     * 当前已安装版本 —— 一律取**该目录树**的 app/version.php（明文版本号，见该文件头）。
     *
     * 传 $root 时读该目录树的版本；不传则读当前运行实例的版本。
     * 为什么需要这个参数：applyPackage() 可以写到任意 $opts['root']（自测沙箱、批量升级工具），
     * 而「本地版本不得低于增量包基线」这条守卫必须校验**将被修改的那棵树**，
     * 否则校验的是恰好正在运行的那棵树，约束形同虚设。
     *
     * 历史教训（别再改回去）：
     *   这里以前读 data/installed.php 的 version —— 那是「安装当刻的快照」，
     *   手工覆盖代码 / 目录迁移都不会刷新它，于是本地版本永远停在旧值：
     *   页脚显示旧版本、更新检查永远 has_update=true、点升级又被基线守卫拒绝（死循环）。
     *   现在 version 随代码走（app/ 会被升级包覆盖），并显式忽略 installed.php 的 version。
     */
    public static function localVersion(?string $root = null): string
    {
        if ($root !== null && $root !== '') {
            return self::versionOf($root);
        }
        $v = (string) wstat_config('version');
        return $v !== '' ? $v : '0.0.0';
    }

    /**
     * 读某棵目录树的版本号（app/version.php 就是一个明文 `return '1.0.9';`）。
     *
     * 打包脚本用它校验暂存包、自测脚本用它校验沙箱树 —— 必须读**目标树**上的那个文件，
     * 而不是恰好正在运行的那棵树，否则校验形同虚设。
     * 返回 '0.0.0' 表示文件缺失或内容不是合法版本号（调用方据此报错/降级）。
     */
    public static function versionOf(string $root): string
    {
        $p = rtrim($root, "/\\");
        foreach ([$p . '/app/version.php', $p . '/version.php'] as $f) {
            if (!is_file($f)) {
                continue;
            }
            $v = include $f;
            if (is_string($v) && preg_match('/^\d+\.\d+(?:\.\d+)?(?:[-+][0-9A-Za-z.\-]+)?$/', $v) === 1) {
                return $v;
            }
        }
        return '0.0.0';
    }

    /** 项目根目录（统一布局：含 app/ 与 config.php 的目录） */
    public static function root(): string
    {
        return defined('WSTAT_ROOT') ? (string) WSTAT_ROOT : dirname(__DIR__, 2);
    }

    /**
     * 当前部署的目录布局：
     *   unified       v1.0.1+ 统一布局（包根=站点目录，public/ 为运行目录）
     *   legacy-nested ≤v1.0.0 标准包（Web 根 = <包根>/server/public），后端目录名为 server/
     *   legacy-flat   ≤v1.0.0 宝塔扁平包（包根即 Web 根，无 server 层、无 public/ 子目录）
     *
     * ⚠️ 只靠「有没有 public/」区分不了前两者 —— 两者在 WSTAT_ROOT 层看结构完全一样
     *    （都有 app/、config.php、public/），差别只在**上一层有没有 server/ 与 sql/**。
     *    判错方向的代价不对称：把统一布局误判成旧布局只是多一次手工迁移提示；
     *    把旧布局误判成统一布局则会让「迁移到统一布局」这件事被静默跳过
     *    （文件写进了 <包根>/server/，站点运行目录还指着 server/public）。
     *    因此这里用「sql/ 是否与本目录同层」+「目录名是否叫 server」两个硬信号，拿不准时偏向旧布局。
     */
    public static function layout(): string
    {
        return self::layoutOf(self::root());
    }

    /** 布局判定（可指定根目录，便于自测） */
    public static function layoutOf(string $root): string
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        if (!is_dir($root . '/app')) {
            return 'unknown';
        }
        if (basename($root) === 'server') {
            return 'legacy-nested';
        }
        // 旧包把 sql/、deploy/、docs/ 放在 <包根>，统一布局放在与 app/ 同层
        if (is_dir(dirname($root) . '/sql') && !is_dir($root . '/sql')) {
            return 'legacy-nested';
        }
        return is_dir($root . '/public') ? 'unified' : 'legacy-flat';
    }

    /* ===================== 远端检查 ===================== */

    /** 远端更新服务地址（config.php update_check_url，环境变量 WSTAT_UPDATE_URL 可覆盖） */
    public static function checkUrl(): string
    {
        return trim((string) wstat_config('update_check_url'));
    }

    /**
     * 检查更新。返回结构：
     *   ok(bool) version latest has_update can_auto requires_full reason
     *   changelog[] notes update_url update_sha256 update_size full_url full_size released_at php_ok
     */
    public static function check(): array
    {
        $cur = self::localVersion();
        $url = self::checkUrl();
        $layout = self::layout();

        $out = [
            'ok' => false,
            'version' => $cur,
            'layout' => $layout,
            'latest' => '',
            'has_update' => false,
            'can_auto' => false,
            'requires_full' => false,
            'reason' => '',
            'check_url' => $url,
            'changelog' => [],
            'notes' => '',
            'update_url' => '',
            'update_sha256' => '',
            'update_size' => 0,
            'update_type' => '',
            'full_url' => '',
            'full_size' => 0,
            'released_at' => '',
            'php_ok' => true,
            'update_enabled' => (bool) wstat_config('update.enabled'),
        ];
        if ($url === '') {
            $out['reason'] = '未配置更新服务地址（config.php 的 update_check_url）';
            return $out;
        }

        $q = $url . (strpos($url, '?') === false ? '?' : '&')
            . http_build_query(['action' => 'check', 'version' => $cur, 'php' => PHP_VERSION]);
        $body = self::httpGet($q, (int) (wstat_config('update.timeout') ?: 30));
        $j = is_string($body) ? json_decode($body, true) : null;
        if (!is_array($j)) {
            $out['reason'] = '更新服务不可达或返回内容无法解析';
            return $out;
        }
        // 兼容两种形态：{"code":0,"data":{...}} 与旧版 {"version":"x.y.z"}
        $d = isset($j['data']) && is_array($j['data']) ? $j['data'] : $j;
        $latest = (string) ($d['latest'] ?? $d['version'] ?? '');
        if ($latest === '') {
            $out['reason'] = '更新服务未返回有效版本号';
            return $out;
        }

        $out['ok'] = true;
        $out['latest'] = $latest;
        $out['has_update'] = version_compare($latest, $cur, '>');
        $out['changelog'] = array_values(array_map('strval', (array) ($d['changelog'] ?? [])));
        $out['notes'] = (string) ($d['notes'] ?? '');
        $out['released_at'] = (string) ($d['released_at'] ?? '');
        $out['update_url'] = (string) ($d['update_url'] ?? '');
        $out['update_sha256'] = strtolower((string) ($d['update_sha256'] ?? ''));
        $out['update_size'] = (int) ($d['update_size'] ?? 0);
        $out['update_type'] = (string) ($d['update_type'] ?? '');
        $out['full_url'] = (string) ($d['full_url'] ?? '');
        $out['full_size'] = (int) ($d['full_size'] ?? 0);
        $out['php_ok'] = version_compare(PHP_VERSION, (string) ($d['php_min'] ?? '7.4.0'), '>=');

        if (!$out['has_update']) {
            $out['reason'] = '已是最新版本';
            return $out;
        }
        if (!(bool) wstat_config('update.enabled')) {
            $out['reason'] = '本站已关闭一键升级（config.php: update.enabled=false），可手动下载完整包';
            return $out;
        }
        if (!$out['php_ok']) {
            $out['requires_full'] = true;
            $out['reason'] = '新版本要求 PHP ≥ ' . (string) ($d['php_min'] ?? '7.4.0') . '，当前为 ' . PHP_VERSION;
            return $out;
        }
        if (!class_exists('ZipArchive')) {
            $out['requires_full'] = true;
            $out['reason'] = 'PHP 未启用 zip 扩展，无法解压更新包（可手动下载覆盖）';
            return $out;
        }
        // 包文件名是随机串（防猜测下载地址），不能再用「-update.zip」文件名启发式判断；
        // 增量/完整以更新服务端 versions.json 的 update_type 为准，且必须有 sha256 供下载后校验。
        if ($out['update_type'] !== 'incremental' || $out['update_url'] === '' || $out['update_sha256'] === '') {
            $out['requires_full'] = true;
            $out['reason'] = '本次发布包含目录结构调整，请下载完整包按 docs/deploy.md「6.1 升级」手工迁移（保留 data/ 目录）';
            return $out;
        }
        if ($layout !== 'unified') {
            $out['requires_full'] = true;
            $out['reason'] = '本站为旧目录布局（' . $layout . '），无法自动升级；请用完整包按 docs/deploy.md「6.1 升级」迁移到统一布局';
            return $out;
        }
        if (!self::writable(self::root())) {
            $out['requires_full'] = true;
            $out['reason'] = '项目目录不可写（Web 进程无写权限），无法自动升级';
            return $out;
        }

        $out['can_auto'] = true;
        $out['reason'] = '可以自动升级到 v' . $latest;
        return $out;
    }

    /* ===================== 一键升级 ===================== */

    /**
     * 下载并应用更新。$downloadUrl / $sha256 由 check() 提供。
     * @return array{ok:bool,version:string,notice:string,files:int,deleted:int,sql:int,backup:string,msgs:array}
     */
    public static function apply(string $downloadUrl, string $sha256 = ''): array
    {
        if (!(bool) wstat_config('update.enabled')) {
            throw new \RuntimeException('本站已关闭一键升级（config.php: update.enabled=false）');
        }
        if (!preg_match('#^https?://#i', $downloadUrl)) {
            throw new \RuntimeException('非法的下载地址：' . $downloadUrl);
        }
        $tmpDir = self::root() . '/data/tmp';
        if (!is_dir($tmpDir) && !@mkdir($tmpDir, 0755, true)) {
            throw new \RuntimeException('无法创建临时目录：' . $tmpDir);
        }
        $zipFile = $tmpDir . '/update-' . date('YmdHis') . '-' . bin2hex(random_bytes(3)) . '.zip';
        self::download($downloadUrl, $zipFile, (int) (wstat_config('update.timeout') ?: 30));

        try {
            if ($sha256 !== '') {
                $actual = hash_file('sha256', $zipFile);
                if (!hash_equals(strtolower($sha256), strtolower((string) $actual))) {
                    throw new \RuntimeException('下载的更新包校验失败（sha256 不一致），已中止以免写入损坏文件');
                }
            }
            return self::applyPackage($zipFile);
        } finally {
            @unlink($zipFile);
        }
    }

    /**
     * 应用一个已落地的更新包（zip 内必须含 update.json 清单）。
     *
     * @param array{root?:string,run_sql?:bool,dry_run?:bool} $opts
     * @return array{ok:bool,version:string,from:string,notice:string,files:int,deleted:int,sql:int,backup:string,msgs:array}
     */
    public static function applyPackage(string $zipFile, array $opts = []): array
    {
        if (!is_file($zipFile)) {
            throw new \RuntimeException('更新包不存在：' . $zipFile);
        }
        if (!class_exists('ZipArchive')) {
            throw new \RuntimeException('PHP 未启用 zip 扩展，无法解压更新包');
        }
        $root = rtrim(str_replace('\\', '/', $opts['root'] ?? self::root()), '/');
        $runSql  = (bool) ($opts['run_sql'] ?? true);
        $dryRun  = (bool) ($opts['dry_run'] ?? false);

        $zip = new ZipArchive();
        if ($zip->open($zipFile) !== true) {
            throw new \RuntimeException('无法打开更新包（文件损坏或不是 zip）');
        }

        $rawJson = $zip->getFromName('update.json');
        if (!is_string($rawJson) || $rawJson === '') {
            $zip->close();
            throw new \RuntimeException('这不是在线更新包（缺少 update.json 清单），请改用完整包手工覆盖');
        }
        $mf = json_decode($rawJson, true);
        if (!is_array($mf) || !isset($mf['files']) || !is_array($mf['files'])) {
            $zip->close();
            throw new \RuntimeException('更新包清单格式不正确');
        }

        $msgs = [];
        $version = (string) ($mf['version'] ?? '');
        $from    = (string) ($mf['from'] ?? '');
        $titles  = 'v' . ($version !== '' ? $version : '?') . ($from !== '' ? "（基线 v{$from}）" : '');

        // ---- 布局与清单校验（先全部校验，再动任何文件）----
        if ((string) ($mf['layout'] ?? 'unified') !== 'unified') {
            $zip->close();
            throw new \RuntimeException('该更新包面向非统一布局，不支持自动升级');
        }
        $curLayout = self::layoutOf($root);
        if ($curLayout !== 'unified') {
            $zip->close();
            throw new \RuntimeException('本站当前目录布局为 ' . $curLayout . '，与更新包（unified）不一致，请用完整包手工迁移');
        }
        if ($version === '' || !preg_match('/^[0-9]+(\.[0-9]+){1,3}/', $version)) {
            $zip->close();
            throw new \RuntimeException('更新包未声明有效版本号');
        }
        $localVer = self::localVersion($root);
        if ($from !== '' && version_compare($localVer, $from, '<')) {
            $zip->close();
            throw new \RuntimeException("本站版本 v{$localVer} 低于该增量包的基线 v{$from}，请先升级到 v{$from} 或改用完整包");
        }

        $preserve = self::preservePatterns($mf);

        // config.php 的归属：清单声明了基线哈希才允许替换，否则一律保守保留
        $cfgPath = (string) ($mf['config']['path'] ?? '');
        if ($cfgPath === '') {
            $cfgPath = 'config.php';
        }
        $cfgBaseSha = strtolower((string) ($mf['config']['baseline_sha256'] ?? ''));

        $files = [];
        foreach ($mf['files'] as $f) {
            if (!is_array($f)) {
                continue;
            }
            $rel = self::safeRel((string) ($f['path'] ?? ''));
            if ($rel === null) {
                continue;
            }
            // config.php 走下面的「是否被本地改过」判定，不受 preserve 名单拦截
            if ($rel !== $cfgPath && self::isPreserved($rel, $preserve)) {
                $msgs[] = '跳过受保护路径：' . $rel;
                continue;
            }
            $files[$rel] = ['sha256' => strtolower((string) ($f['sha256'] ?? '')), 'size' => (int) ($f['size'] ?? 0)];
        }
        if (!$files) {
            $zip->close();
            throw new \RuntimeException('更新包没有需要写入的文件');
        }

        // 逐条比对 zip 内实际内容：任何一条不符 → 整体中止
        foreach ($files as $rel => $meta) {
            $stream = $zip->getStream($rel);
            if (!is_resource($stream)) {
                // 允许「仅删除」的清单项不带实体文件
                if ($meta['sha256'] === '' && $meta['size'] === 0) {
                    unset($files[$rel]);
                    continue;
                }
                $zip->close();
                throw new \RuntimeException('更新包缺少文件：' . $rel);
            }
            $ctx = hash_init('sha256');
            $len = 0;
            while (!feof($stream)) {
                $chunk = (string) fread($stream, 262144);
                $len += strlen($chunk);
                hash_update($ctx, $chunk);
            }
            fclose($stream);
            $sha = hash_final($ctx);
            if ($meta['sha256'] !== '' && !hash_equals($meta['sha256'], $sha)) {
                $zip->close();
                throw new \RuntimeException('更新包内文件校验失败：' . $rel . '（期望 ' . substr($meta['sha256'], 0, 12) . '…，实际 ' . substr($sha, 0, 12) . '…）');
            }
            if ($meta['size'] > 0 && $meta['size'] !== $len) {
                $zip->close();
                throw new \RuntimeException('更新包内文件大小不符：' . $rel);
            }
            $files[$rel] = ['sha256' => $sha, 'size' => $len];
        }

        // config.php：只有「与发布基线逐字节一致」才允许替换（说明用户没动过它）；
        // 否则一律保留原文件，把新版本默认值落到 config.php.new 由用户自行合并。
        $keepConfig = false;
        if (isset($files[$cfgPath])) {
            $localFile = $root . '/' . $cfgPath;
            if ($cfgBaseSha === '') {
                $keepConfig = true;
                unset($files[$cfgPath]);
                $msgs[] = '更新包未声明 config.php 基线哈希，保留本地 config.php，新版本默认值写入 config.php.new';
            } elseif (is_file($localFile)) {
                $localSha = strtolower((string) hash_file('sha256', $localFile));
                if ($localSha !== $cfgBaseSha) {
                    $keepConfig = true;
                    unset($files[$cfgPath]);
                    $msgs[] = 'config.php 已被本地修改，保留原文件，新版本默认值写入 config.php.new';
                }
            }
        }

        $deleted = [];
        foreach ((array) ($mf['deleted'] ?? []) as $d) {
            $rel = self::safeRel((string) $d);
            if ($rel === null || $rel === $cfgPath || self::isPreserved($rel, $preserve)) {
                continue;
            }
            $deleted[] = $rel;
        }

        // ---- 备份 ----
        $backup = $root . '/data/backup/' . ($version !== '' ? $version : 'unknown') . '-' . date('YmdHis');
        $backedUp = [];
        foreach (array_keys($files) as $rel) {
            $abs = $root . '/' . $rel;
            if (is_file($abs)) {
                if (!self::copyTo($abs, $backup . '/' . $rel)) {
                    $zip->close();
                    throw new \RuntimeException('备份失败（目录不可写？）：' . $rel);
                }
                $backedUp[] = $rel;
            }
        }
        foreach ($deleted as $rel) {
            $abs = $root . '/' . $rel;
            if (is_file($abs)) {
                if (!self::copyTo($abs, $backup . '/' . $rel)) {
                    $zip->close();
                    throw new \RuntimeException('备份失败（目录不可写？）：' . $rel);
                }
                $backedUp[] = $rel;
            }
        }

        if ($dryRun) {
            $zip->close();
            return [
                'ok' => true, 'dry_run' => true, 'version' => $version, 'from' => $from,
                'notice' => '预演：将写入 ' . count($files) . ' 个文件、删除 ' . count($deleted) . ' 个文件',
                'files' => count($files), 'deleted' => count($deleted), 'sql' => count((array) ($mf['sql'] ?? [])),
                'backup' => '', 'msgs' => $msgs,
            ];
        }

        // ---- 写入 ----
        $written = [];
        try {
            foreach ($files as $rel => $meta) {
                $abs = $root . '/' . $rel;
                if (!is_dir(dirname($abs)) && !@mkdir(dirname($abs), 0755, true)) {
                    throw new \RuntimeException('无法创建目录：' . dirname($rel));
                }
                $stream = $zip->getStream($rel);
                if (!is_resource($stream)) {
                    throw new \RuntimeException('读取更新包内文件失败：' . $rel);
                }
                $out = @fopen($abs, 'wb');
                if ($out === false) {
                    fclose($stream);
                    throw new \RuntimeException('写入失败（权限不足）：' . $rel);
                }
                while (!feof($stream)) {
                    fwrite($out, (string) fread($stream, 262144));
                }
                fclose($out);
                fclose($stream);
                @chmod($abs, 0644);
                $written[] = $rel;
            }
            // config.php.new（用户改过配置时）
            if ($keepConfig && $cfgPath !== '') {
                $stream = $zip->getStream($cfgPath);
                if (is_resource($stream)) {
                    file_put_contents($root . '/' . $cfgPath . '.new', (string) stream_get_contents($stream));
                    fclose($stream);
                }
            }
            // 删除
            foreach ($deleted as $rel) {
                @unlink($root . '/' . $rel);
            }
        } catch (\Throwable $e) {
            $zip->close();
            // 尽力回滚已写入的文件
            $restored = 0;
            foreach ($written as $rel) {
                $bak = $backup . '/' . $rel;
                if (is_file($bak)) {
                    self::copyTo($bak, $root . '/' . $rel);
                    $restored++;
                } else {
                    @unlink($root . '/' . $rel);
                }
            }
            self::writeRollback($backup, $version, $from, $backedUp, $deleted, [], $e->getMessage());
            throw new \RuntimeException('写入失败：' . $e->getMessage() . '（已回滚 ' . $restored . ' 个文件，备份位于 data/backup/）');
        }
        $zip->close();

        // ---- 执行新增的增量 SQL ----
        // 注意：run_sql=false 的判定必须在循环之前 —— 写在循环后等于没写（SQL 已经执行完了）
        $sqlRan = [];
        $sqlErr = '';
        $sqlList = [];
        foreach ((array) ($mf['sql'] ?? []) as $rel) {
            $rel = self::safeRel((string) $rel);
            if ($rel !== null) {
                $sqlList[] = $rel;
            }
        }
        if ($sqlList && $runSql === false) {
            $msgs[] = '已按 run_sql=false 跳过增量 SQL：' . implode('、', $sqlList);
        }
        if ($runSql !== false) {
            foreach ($sqlList as $rel) {
                if (!is_file($root . '/' . $rel)) {
                    continue;
                }
                try {
                    $sqlRan[$rel] = self::runSqlFile($root . '/' . $rel);
                } catch (\Throwable $e) {
                    $sqlErr = $rel . '：' . $e->getMessage();
                    $msgs[] = '增量 SQL 执行失败（文件已更新，请手工执行 ' . $rel . '）：' . $e->getMessage();
                    break;
                }
            }
        }

        // ---- 记账：写升级流水与回滚清单 ----
        self::writeVersionRecord($root, $version, $from);
        self::writeRollback($backup, $version, $from, $backedUp, $deleted, $sqlRan, $sqlErr);
        self::gcBackups($root);
        self::flushOpcode();

        return [
            'ok' => true,
            'dry_run' => false,
            'version' => $version,
            'from' => $from,
            'notice' => '已升级到 ' . $titles . '：写入 ' . count($written) . ' 个文件，删除 ' . count($deleted) . ' 个，执行增量 SQL ' . count($sqlRan) . ' 个',
            'files' => count($written),
            'deleted' => count($deleted),
            'sql' => count($sqlRan),
            'backup' => 'data/backup/' . basename($backup),
            'msgs' => $msgs,
        ];
    }

    /** 备份列表（供设置页展示；按时间倒序） */
    public static function backups(int $limit = 5): array
    {
        $dir = self::root() . '/data/backup';
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (scandir($dir) ?: [] as $d) {
            if ($d === '.' || $d === '..' || !is_dir($dir . '/' . $d)) {
                continue;
            }
            $meta = [];
            $rf = $dir . '/' . $d . '/rollback.json';
            if (is_file($rf)) {
                $meta = (array) json_decode((string) file_get_contents($rf), true);
            }
            $out[] = [
                'name' => $d,
                'created_at' => (string) ($meta['created_at'] ?? date('Y-m-d H:i:s', (int) @filemtime($dir . '/' . $d))),
                'from' => (string) ($meta['from'] ?? ''),
                'to' => (string) ($meta['to'] ?? ''),
                'files' => isset($meta['files']) ? count((array) $meta['files']) : 0,
                'sql' => isset($meta['sql']) ? count((array) $meta['sql']) : 0,
                'error' => (string) ($meta['error'] ?? ''),
            ];
        }
        usort($out, static fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
        return array_slice($out, 0, $limit);
    }

    /* ===================== 内部工具 ===================== */

    /** 归一化清单内的相对路径；含 .. 或绝对路径一律拒绝（防 zip 目录穿越） */
    private static function safeRel(string $rel): ?string
    {
        $rel = str_replace('\\', '/', trim($rel));
        $rel = ltrim($rel, '/');
        if ($rel === '' || strpos($rel, "\0") !== false) {
            return null;
        }
        if (preg_match('#^[A-Za-z]:#', $rel)) {
            return null;
        }
        foreach (explode('/', $rel) as $seg) {
            if ($seg === '..' || $seg === '') {
                return null;
            }
        }
        return $rel;
    }

    /** 受保护路径：config 的 update.preserve ∪ 包清单的 preserve */
    private static function preservePatterns(array $mf): array
    {
        $cfg = (array) (wstat_config('update.preserve') ?: []);
        $mfP = (array) ($mf['preserve'] ?? []);
        $out = [];
        foreach (array_merge($cfg, $mfP) as $p) {
            $p = str_replace('\\', '/', trim((string) $p));
            if ($p !== '') {
                $out[] = $p;
            }
        }
        // 硬编码兜底：绝不允许被更新包删除/覆盖
        // 注意：config.php 不在此列 —— 它由清单的 config.baseline_sha256 单独判定
        // （与基线一致=用户没动过，可以替换；不一致=用户改过，只落 config.php.new）。
        // 若放进这里会让 config.php 在进入该判定前就被过滤掉，.new 永远不会生成。
        foreach (['data/', 'public/install/local.php', '.git/', '.env'] as $p) {
            $out[] = $p;
        }
        return array_values(array_unique($out));
    }

    private static function isPreserved(string $rel, array $patterns): bool
    {
        foreach ($patterns as $p) {
            if (substr($p, -1) === '/') {
                if (strpos($rel, $p) === 0) {
                    return true;
                }
            } elseif ($rel === $p) {
                return true;
            }
        }
        return false;
    }

    private static function writable(string $dir): bool
    {
        return is_dir($dir) && @is_writable($dir);
    }

    /** 复制文件（自动建目录）；失败返回 false */
    private static function copyTo(string $src, string $dst): bool
    {
        if (!is_dir(dirname($dst)) && !@mkdir(dirname($dst), 0755, true)) {
            return false;
        }
        return @copy($src, $dst);
    }

    /**
     * 记录本次升级结果到 data/version.json。
     *
     * ⚠️ 刻意**不再回写** data/installed.php 的 version：
     *   那里的 version 是「安装当刻的快照」（语义 = 这个库是哪一版装的），
     *   升级时改写它会抹掉这条信息，而且它压根不参与版本判定（config.php 已显式忽略它）。
     *   生效版本一律看 app/version.php（明文版本号）—— 本次升级已经把新版本的文件写进去了，
     *   所以这里不需要（也不应该）代改版本号。
     *   本文件只是「升级流水」，供排错与客服问「你什么时候升的」时查阅。
     */
    private static function writeVersionRecord(string $root, string $version, string $from): void
    {
        $file = $root . '/data/version.json';
        $prev = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
        $hist = [];
        foreach ((array) (is_array($prev) ? ($prev['history'] ?? []) : []) as $h) {
            if (is_array($h) && isset($h['version'])) {
                $hist[] = $h;
            }
        }
        $hist[] = ['version' => $version, 'from' => $from, 'at' => date('c'), 'via' => 'auto'];
        if (count($hist) > 20) {          // 只留最近 20 条，运行期数据不无限膨胀
            $hist = array_slice($hist, -20);
        }
        @file_put_contents($file, json_encode([
            'version'    => $version,     // 最近一次升级到的版本（仅记录，不参与版本判定）
            'from'       => $from,
            'updated_at' => date('c'),
            'history'    => $hist,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
    }

    /** 写回滚清单：记录备份了哪些文件、删了哪些、执行了哪些 SQL */
    private static function writeRollback(string $backup, string $version, string $from, array $files, array $deleted, array $sql, string $error): void
    {
        if (!is_dir($backup) && !@mkdir($backup, 0755, true)) {
            return;
        }
        $data = [
            'created_at' => date('Y-m-d H:i:s'),
            'to' => $version,
            'from' => $from,
            'files' => array_values($files),
            'deleted' => array_values($deleted),
            'sql' => $sql,
            'error' => $error,
            'rollback_hint' => '把本目录下的文件按相同相对路径复制回项目根即可还原被覆盖/删除的文件',
        ];
        @file_put_contents($backup . '/rollback.json', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    /** 只保留最近 N 份备份（config: update.keep_backup） */
    private static function gcBackups(string $root): void
    {
        $keep = (int) (wstat_config('update.keep_backup') ?: 3);
        if ($keep <= 0) {
            return;
        }
        $dir = $root . '/data/backup';
        if (!is_dir($dir)) {
            return;
        }
        $dirs = [];
        foreach (scandir($dir) ?: [] as $d) {
            if ($d !== '.' && $d !== '..' && is_dir($dir . '/' . $d)) {
                $dirs[$d] = (int) @filemtime($dir . '/' . $d);
            }
        }
        if (count($dirs) <= $keep) {
            return;
        }
        arsort($dirs);
        $i = 0;
        foreach ($dirs as $d => $t) {
            if (++$i <= $keep) {
                continue;
            }
            self::rrmdir($dir . '/' . $d);
        }
    }

    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $p = $dir . '/' . $f;
            is_dir($p) ? self::rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    /** 执行 sql 文件（按 ; 拆分，跳过 -- 注释），返回执行的语句数 */
    private static function runSqlFile(string $file): int
    {
        $sql = (string) file_get_contents($file);
        $stmts = self::splitSql($sql);
        $pdo = Db::pdo();
        $n = 0;
        foreach ($stmts as $s) {
            $pdo->exec($s);
            $n++;
        }
        return $n;
    }

    /** 拆分 SQL：跳过 -- 行注释，尊重引号内的分号（与安装器同一套规则） */
    public static function splitSql(string $sql): array
    {
        $stmts = [];
        $buf = '';
        $len = strlen($sql);
        $inStr = false;
        $strCh = '';
        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            if (!$inStr && $ch === '-' && ($sql[$i + 1] ?? '') === '-') {
                while ($i < $len && $sql[$i] !== "\n") {
                    $i++;
                }
                continue;
            }
            if ($inStr) {
                if ($ch === '\\') {
                    $buf .= $ch . ($sql[$i + 1] ?? '');
                    $i++;
                    continue;
                }
                if ($ch === $strCh) {
                    $inStr = false;
                }
                $buf .= $ch;
                continue;
            }
            if ($ch === "'" || $ch === '"') {
                $inStr = true;
                $strCh = $ch;
                $buf .= $ch;
                continue;
            }
            if ($ch === ';') {
                if (trim($buf) !== '') {
                    $stmts[] = trim($buf);
                }
                $buf = '';
                continue;
            }
            $buf .= $ch;
        }
        if (trim($buf) !== '') {
            $stmts[] = trim($buf);
        }
        return $stmts;
    }

    /** 刷新 opcache：常驻 FPM 下改了 PHP 文件必须失效，否则仍旧代码 */
    private static function flushOpcode(): void
    {
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate(self::root() . '/app/bootstrap.php', true);
        }
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }

    /* ===================== HTTP ===================== */

    private static function httpGet(string $url, int $timeout): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_USERAGENT      => 'Wstats-Updater/' . self::localVersion(),
            ]);
            $body = curl_exec($ch);
            curl_close($ch);
            return is_string($body) && $body !== '' ? $body : null;
        }
        $ctx = stream_context_create([
            'http' => ['timeout' => $timeout, 'user_agent' => 'Wstats-Updater/' . self::localVersion()],
            'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        return $body === false ? null : $body;
    }

    /** 下载到文件（大文件流式写盘，避免整包进内存） */
    private static function download(string $url, string $dst, int $timeout): void
    {
        $out = @fopen($dst, 'wb');
        if ($out === false) {
            throw new \RuntimeException('无法写入下载文件：' . $dst);
        }
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_FILE           => $out,
                CURLOPT_TIMEOUT        => max(60, $timeout),
                CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_USERAGENT      => 'Wstats-Updater/' . self::localVersion(),
            ]);
            $ok = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            fclose($out);
            if ($ok === false || $code >= 400) {
                @unlink($dst);
                throw new \RuntimeException('下载更新包失败（HTTP ' . $code . '）：' . $err);
            }
            return;
        }
        $ctx = stream_context_create([
            'http' => ['timeout' => max(60, $timeout), 'user_agent' => 'Wstats-Updater/' . self::localVersion()],
            'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);
        $in = @fopen($url, 'rb', false, $ctx);
        if ($in === false) {
            fclose($out);
            @unlink($dst);
            throw new \RuntimeException('下载更新包失败：无法打开远端地址');
        }
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
        if (filesize($dst) <= 0) {
            @unlink($dst);
            throw new \RuntimeException('下载更新包失败：内容为空');
        }
    }
}
