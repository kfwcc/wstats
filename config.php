<?php
/**
 * 网站统计系统 - 全局配置
 *
 * 三层合并，后者覆盖前者：
 *   1) 下方 $defaults          出厂默认值（不含任何真实凭据，可直接对外发布）
 *   2) data/installed.php      安装向导写入的实际部署配置（打包发布时不含此文件）
 *   3) 环境变量 WSTAT_*        最高优先级，适合容器/K8s 等以环境变量注入的场景
 *
 * 未安装时本文件依然可用（返回默认值），安装向导依赖此特性在「无数据库」状态下运行。
 */
declare(strict_types=1);

$selfDir = __DIR__;   // server/

/* ===================== 0) 版本号：唯一事实来源 =====================
 * 从 app/version.php 读取（就是一个明文版本号，见该文件头）。
 *
 * 为什么不是写死在这里，也不从 data/installed.php 读：
 *   以前 version 有两个来源 —— 本文件的出厂值 与 installed.php 的安装快照，
 *   而快照在「手工覆盖代码 / 目录迁移」下永远不刷新，于是页脚长期显示旧版本、
 *   更新检查永远提示有新版本、点升级又被布局守卫拒绝（死循环）。
 *   现在版本号只由 app/version.php 提供；app/ 会被升级包覆盖 → 自动跟进，无需人工同步。
 * ========================================================================= */
$wstatVersion = '0.0.0';
$versionFile  = $selfDir . '/app/version.php';
if (is_file($versionFile)) {
    $v = require $versionFile;          // 纯数据文件：return '1.0.9';
    // 只接受 x.y / x.y.z（可带 -beta.1 这类后缀）；写坏了回落 0.0.0，
    // 不让页脚与更新检查拿着一个非法版本号去"胡说"。
    if (is_string($v) && preg_match('/^\d+\.\d+(?:\.\d+)?(?:[-+][0-9A-Za-z.\-]+)?$/', $v) === 1) {
        $wstatVersion = $v;
    }
}

/* ===================== 1) 出厂默认值 ===================== */
$defaults = [
    // 生效版本号 = app/version.php（唯一事实来源）
    'version'    => $wstatVersion,
    // 页脚「Powered by Wstats」的品牌名与官网链接**写死在前端**
    // （frontend/src/components/PoweredBy.jsx 与安装向导页脚），服务端不再下发品牌：
    // 品牌是展示层的事，后端只负责提供版本号。
    // 在线更新服务地址（官方：https://wstats-update.kfw.cc/update.php）。
    // 协议：带 action=check&version=&php= 查询时返回最新版本、增量包地址与校验值；
    // 也兼容旧格式：不带参数直接返回 {"version":"x.y.z"}。
    // 空串 = 关闭在线更新（设置页仅显示当前版本）。
    // 私有化部署可指向自建服务（见 update-server/README.md）。
    // 说明：写成站点根地址（不带 /update.php）通常也能用 —— 站点根的 index.php 会转发到接口，
    // 但 index 文件的优先级随 Web 服务器配置而异，固定带 /update.php 最稳妥。
    'update_check_url' => getenv('WSTAT_UPDATE_URL') ?: 'https://wstats-update.kfw.cc/update.php',

    // 在线更新（一键升级）行为
    'update' => [
        'enabled'  => !((bool) getenv('WSTAT_UPDATE_OFF')),  // WSTAT_UPDATE_OFF=1 关闭一键升级（仅保留检查）
        'timeout'  => (int) (getenv('WSTAT_UPDATE_TIMEOUT') ?: 30),   // 远端请求/下载超时（秒）
        'keep_backup' => (int) (getenv('WSTAT_UPDATE_BACKUP') ?: 3),  // 保留最近 N 份升级前备份
        // 允许更新的文件前缀白名单（相对项目根；data/、上传目录等运行期数据永不覆盖）
        'allow'    => ['app/', 'public/', 'scripts/', 'sql/', 'sdk/', 'config.php', 'README.md', 'LICENSE'],
        // 无论如何都不得被更新包删除/覆盖的路径
        'preserve' => ['data/', 'config.php', 'public/install/local.php', '.git/', '.env'],
    ],
    'installed'  => false,          // 由 installed.php 置 true
    'debug'      => (bool) (getenv('WSTAT_DEBUG') ?: false),
    'timezone'   => getenv('WSTAT_TZ') ?: 'Asia/Shanghai',

    // 数据库（写库；明细大表按月分区）
    'db' => [
        'host'    => getenv('WSTAT_DB_HOST') ?: '127.0.0.1',
        'port'    => (int) (getenv('WSTAT_DB_PORT') ?: 3306),
        'name'    => getenv('WSTAT_DB_NAME') ?: '',
        'user'    => getenv('WSTAT_DB_USER') ?: '',
        'pass'    => getenv('WSTAT_DB_PASS') ?: '',
        'charset' => 'utf8mb4',
        // 每秒最多批量写多少事件（限流保护，防止 DB 被打满）
        'max_write_per_sec' => (int) (getenv('WSTAT_DB_MAXW') ?: 5000),
    ],

    // Redis（实时计数/队列/在线会话）。本库自带纯 PHP RESP 客户端，无需 phpredis 扩展
    'redis' => [
        // 无 Redis 模式开关：false 时采集数据直接写入 MySQL（events/sessions），
        // 统计全部走数据库聚合，无需部署 Redis 与 worker。安装向导「跳过 Redis」会写入 false。
        // 环境变量 WSTAT_NO_REDIS=1 可强制关闭（最高优先级，见文件末尾）。
        'enabled' => true,
        'host'    => getenv('WSTAT_REDIS_HOST') ?: '127.0.0.1',
        'port'    => (int) (getenv('WSTAT_REDIS_PORT') ?: 6379),
        'auth'    => getenv('WSTAT_REDIS_AUTH') ?: '',
        'db'      => (int) (getenv('WSTAT_REDIS_DB') ?: 0),
        'prefix'  => getenv('WSTAT_REDIS_PREFIX') ?: 'wstat:',
        'timeout' => 2.0,
        // 允许在 Redis 不可用时降级（直写 events 表），安装向导会写入检测结果
        'required' => false,
    ],

    // 会话 / 安全
    'security' => [
        'token_ttl'      => 30 * 86400,        // 登录 token 30 天
        'session_idle'   => 30 * 60,           // 会话 30 分钟无活动即结束
        'new_visitor_win'=> 90 * 86400,        // 90 天内未见 = 新访客
        'pwd_min'        => 8,
        'login_rate'     => 10,                // 10 分钟内最多登录尝试
        // 允许注册（安装后建议按需关闭，只保留管理员创建账号）
        'allow_register' => true,
    ],

    // 采集服务
    'collect' => [
        'max_body'       => 262144,            // 单次上报 256KB
        'beacon_method'  => 'POST',            // 预留
        // IP 地理解析驱动：none | http | xdb
        //   xdb   = 本地离线库（随发布包附带，推荐），**IPv4 / IPv6 两个库互相独立降级**：
        //           缺 v6 库只影响 IPv6 访客，IPv4 照常解析
        //   http  = 接口型，需自备可用服务，见下方 geo_http_url/key
        //   none  = 不解析（历史行为）
        'geo_driver'     => getenv('WSTAT_GEO_DRIVER') ?: 'xdb',
        'geo_xdb'        => getenv('WSTAT_GEO_XDB') ?: $selfDir . '/data/ip2region.xdb',      // IPv4 库
        'geo_xdb6'       => getenv('WSTAT_GEO_XDB6') ?: $selfDir . '/data/ip2region_v6.xdb',  // IPv6 库
        'geo_http_url'   => getenv('WSTAT_GEO_URL') ?: '',   // 形如 {ip} 占位
        'geo_http_key'   => getenv('WSTAT_GEO_KEY') ?: '',
        // 访客真实 IP 的取值来源（**手动指定，程序不做任何自动判断**；详见 Support\Util::ipTrace）：
        //   remote_addr     直接取 REMOTE_ADDR（TCP 对端，客户端无法伪造；但套 CDN 时是节点地址）
        //   x_real_ip       取 X-Real-IP 最右侧的合法 IP
        //   x_forwarded_for 取 X-Forwarded-For 最右侧的合法 IP
        //   custom          取下方 ip_source_header 指定的请求头（如 CF-Connecting-IP）
        // 优先级：管理端「系统设置 → 真实 IP 采集」保存的值（sys_settings.ip_source）> 这里 > remote_addr。
        // 设为某个头但该头本次不存在/非法时，会回落 REMOTE_ADDR 并只在诊断里标注，不会写出空 IP。
        'ip_source'        => getenv('WSTAT_IP_SOURCE') ?: 'remote_addr',
        'ip_source_header' => getenv('WSTAT_IP_HEADER') ?: '',
        'event_retention'=> 180,               // 原始明细保留天数
    ],

    // 站点验证（文件验证）
    'verify' => [
        'file_prefix' => 'wstat_verify_',      // 文件: {domain}/wstat_verify_{token}.txt
        'timeout'     => 8,
    ],

    // CORS
    'cors' => [
        'allow_origin' => '*',                 // 采集端任意站点跨域；管理端建议收紧为自身域名
        'allow_methods'=> 'GET,POST,PATCH,DELETE,OPTIONS',
        'allow_headers'=> 'Content-Type,Authorization',
        'max_age'      => 86400,
    ],
];

/* ===================== 2) 安装器写入的配置 ===================== */
$installed = [];
$installedFile = $selfDir . '/data/installed.php';
if (is_file($installedFile)) {
    $loaded = require $installedFile;
    if (is_array($loaded)) {
        $installed = $loaded;
    }
}

/* 版本号不接受 installed.php 覆盖：
 *   installed.php 的 version 是「安装当刻的快照」，手工覆盖代码 / 目录迁移都不会刷新它，
 *   一旦参与合并就会让页脚长期显示旧版本、更新检查永远提示有新版本（真实故障）。
 *   旧版写过的 brand 键也一并忽略 —— 品牌已改为前端写死，服务端不再下发。
 * 老库里的这两个键会被安静忽略（不报错、也不需要用户改文件）。 */
unset($installed['version'], $installed['brand']);

/** 递归合并：$over 覆盖 $base（数组按 key 深入合并，标量直接覆盖） */
$merge = static function (array $base, array $over) use (&$merge): array {
    foreach ($over as $k => $v) {
        if (is_array($v) && isset($base[$k]) && is_array($base[$k])) {
            $base[$k] = $merge($base[$k], $v);
        } else {
            $base[$k] = $v;
        }
    }
    return $base;
};

$cfg = $merge($defaults, $installed);

/* ===================== 3) 环境变量最高优先级 ===================== */
$envOver = [
    'debug'                  => getenv('WSTAT_DEBUG'),
    'timezone'               => getenv('WSTAT_TZ'),
    'db.host'                => getenv('WSTAT_DB_HOST'),
    'db.port'                => getenv('WSTAT_DB_PORT'),
    'db.name'                => getenv('WSTAT_DB_NAME'),
    'db.user'                => getenv('WSTAT_DB_USER'),
    'db.pass'                => getenv('WSTAT_DB_PASS'),
    'db.max_write_per_sec'   => getenv('WSTAT_DB_MAXW'),
    'redis.host'             => getenv('WSTAT_REDIS_HOST'),
    'redis.port'             => getenv('WSTAT_REDIS_PORT'),
    'redis.auth'             => getenv('WSTAT_REDIS_AUTH'),
    'redis.db'               => getenv('WSTAT_REDIS_DB'),
    'redis.prefix'           => getenv('WSTAT_REDIS_PREFIX'),
    'collect.geo_driver'     => getenv('WSTAT_GEO_DRIVER'),
    'collect.geo_xdb'        => getenv('WSTAT_GEO_XDB'),
    'collect.geo_http_url'   => getenv('WSTAT_GEO_URL'),
    'collect.geo_http_key'   => getenv('WSTAT_GEO_KEY'),
];
foreach ($envOver as $path => $val) {
    if ($val === false || $val === '') {
        continue;
    }
    $segments = explode('.', $path);
    $node = &$cfg;
    foreach ($segments as $i => $seg) {
        if ($i === count($segments) - 1) {
            $node[$seg] = is_bool($node[$seg] ?? null) ? (bool) $val : (is_int($node[$seg] ?? null) ? (int) $val : $val);
        } else {
            if (!isset($node[$seg]) || !is_array($node[$seg])) {
                $node[$seg] = [];
            }
            $node = &$node[$seg];
        }
    }
    unset($node);
}

/* 已安装判定：数据库连接信息齐全即可运行（兼容 installed.php 与环境变量两种部署方式） */
$cfg['installed'] = ($cfg['db']['name'] ?? '') !== '' && ($cfg['db']['user'] ?? '') !== '';

/* 无 Redis 模式强制开关（最高优先级）：WSTAT_NO_REDIS=1 时无视 installed.php 的配置 */
if (getenv('WSTAT_NO_REDIS') === '1') {
    $cfg['redis']['enabled'] = false;
}

return $cfg;
