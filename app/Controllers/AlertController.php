<?php
/**
 * 告警与日报控制器
 *
 * 推送渠道：GET/POST /api/alerts/channels，PATCH/DELETE /api/alerts/channels/{id}，POST .../{id}/test
 * 告警规则：GET/POST /api/alerts/rules，PATCH/DELETE /api/alerts/rules/{id}
 * 告警历史：GET /api/alerts/logs
 * 日报订阅：GET/POST /api/alerts/reports，PATCH/DELETE /api/alerts/reports/{id}
 * 概览计数：GET /api/alerts/summary
 *
 * 权限：全部为用户私有资源（user_id 归属校验），站点参数仅允许自己名下的站点；
 *      只读协作成员看不到这些配置（它们属于「站点所有者」的运维设置）。
 */
declare(strict_types=1);

namespace Wstat\Controllers;

use Wstat\Http\Request;
use Wstat\Support\AlertEngine;
use Wstat\Support\Auth;
use Wstat\Support\Db;
use Wstat\Support\Notifier;
use Wstat\Support\SiteAccess;
use Wstat\Support\Util;

class AlertController
{
    private const MAX_CHANNELS = 20;
    private const MAX_RULES = 50;
    private const MAX_REPORTS = 20;

    /* ==================== 推送渠道 ==================== */

    /** GET /api/alerts/channels */
    public function channelIndex(Request $req): void
    {
        $u = Auth::requireUser($req);
        $rows = Db::select(
            'SELECT id,name,type,config,is_active,last_status,last_sent_at,created_at
             FROM notify_channels WHERE user_id=? ORDER BY id',
            [(int) $u['id']]
        );
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['is_active'] = (int) $r['is_active'];
            $r['last_sent_at'] = (int) $r['last_sent_at'];
            $r['config'] = self::maskConfig(json_decode((string) $r['config'], true) ?: []);
        }
        unset($r);
        wstat_json(['items' => $rows, 'types' => Notifier::TYPES]);
    }

    /** POST /api/alerts/channels */
    public function channelStore(Request $req): void
    {
        $u = Auth::requireUser($req);
        $uid = (int) $u['id'];
        if ((int) Db::value('SELECT COUNT(*) FROM notify_channels WHERE user_id=?', [$uid]) >= self::MAX_CHANNELS) {
            wstat_err('推送渠道数量已达上限（' . self::MAX_CHANNELS . '）', 422);
        }
        [$name, $type, $cfg] = $this->channelInput($req);
        $id = Db::insert('notify_channels', [
            'user_id' => $uid,
            'name' => $name,
            'type' => $type,
            'config' => json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'is_active' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        wstat_json(['id' => $id]);
    }

    /** PATCH /api/alerts/channels/{id} */
    public function channelUpdate(Request $req): void
    {
        $ch = $this->ownChannel($req, (int) $req->param('id'));
        $fields = [];
        $name = trim((string) $req->input('name', ''));
        if ($name !== '') {
            if (mb_strlen($name) > 60) {
                wstat_err('渠道名称过长', 422);
            }
            $fields['name'] = $name;
        }
        $isActive = $req->input('is_active', null);
        if ($isActive !== null) {
            $fields['is_active'] = (int) (bool) $isActive;
        }
        // 传了 config 才更新（避免「只改名称」时把密钥擦掉）
        if ($req->input('config', null) !== null) {
            $type = (string) $req->input('type', (string) $ch['type']);
            if (!in_array($type, Notifier::TYPES, true)) {
                wstat_err('不支持的渠道类型', 422);
            }
            $cfg = $this->cfgArray($req->input('config'));
            $cfg = self::mergeConfig($type, $cfg, json_decode((string) $ch['config'], true) ?: []);
            if ($type !== (string) $ch['type']) {
                $fields['type'] = $type;
            }
            $fields['config'] = json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if (!$fields) {
            wstat_err('无有效字段', 422);
        }
        $set = [];
        $vals = [];
        foreach ($fields as $k => $v) {
            $set[] = "`$k`=?";
            $vals[] = $v;
        }
        $set[] = 'updated_at=' . time();
        $vals[] = (int) $ch['id'];
        Db::execute('UPDATE notify_channels SET ' . implode(',', $set) . ' WHERE id=?', $vals);
        wstat_json(['ok' => true]);
    }

    /** DELETE /api/alerts/channels/{id} */
    public function channelDelete(Request $req): void
    {
        $ch = $this->ownChannel($req, (int) $req->param('id'));
        Db::execute('DELETE FROM notify_channels WHERE id=?', [(int) $ch['id']]);
        wstat_json(['ok' => true]);
    }

    /** POST /api/alerts/channels/{id}/test  发送测试消息 */
    public function channelTest(Request $req): void
    {
        $ch = $this->ownChannel($req, (int) $req->param('id'));
        $title = '通道连通性测试';
        $text = "这是一条 WebStats 测试消息。\n"
            . '渠道：' . (string) $ch['name'] . '（' . (string) $ch['type'] . "）\n"
            . '若你能看到本条消息，说明该渠道配置正确。';
        $res = Notifier::send([$ch], $title, $text);
        $one = $res['results'][0] ?? ['ok' => false, 'msg' => '未知错误'];
        if (!$one['ok']) {
            wstat_json(['ok' => false, 'msg' => $one['msg']], 200, 0, '测试发送失败：' . $one['msg']);
        }
        wstat_json(['ok' => true]);
    }

    /* ==================== 告警规则 ==================== */

    /** GET /api/alerts/rules */
    public function ruleIndex(Request $req): void
    {
        $u = Auth::requireUser($req);
        $rows = Db::select(
            'SELECT r.*, s.name AS site_name, s.domain AS site_domain
             FROM alert_rules r LEFT JOIN sites s ON s.id=r.site_id
             WHERE r.user_id=? ORDER BY r.id DESC',
            [(int) $u['id']]
        );
        foreach ($rows as &$r) {
            foreach (['id', 'site_id', 'window_min', 'min_sample', 'cooldown_min', 'is_active', 'last_fired_at'] as $k) {
                $r[$k] = (int) $r[$k];
            }
            $r['threshold'] = (float) $r['threshold'];
            $r['channel_ids'] = array_values(array_filter(array_map('intval', explode(',', (string) $r['channels']))));
            // v1.3 新列（老库缺列时补默认值，前端无需判空）
            $r['recover_notify'] = (int) ($r['recover_notify'] ?? 0);
            $r['firing'] = (int) ($r['firing'] ?? 0);
            $r['quiet_start'] = (string) ($r['quiet_start'] ?? '');
            $r['quiet_end'] = (string) ($r['quiet_end'] ?? '');
        }
        unset($r);
        wstat_json([
            'items' => $rows,
            'metrics' => AlertEngine::METRICS,
            'compares' => AlertEngine::COMPARES,
            'baselines' => AlertEngine::BASELINES,
            'levels' => AlertEngine::LEVELS,
            'recovery_available' => self::ruleExtCols(),
        ]);
    }

    /** POST /api/alerts/rules */
    public function ruleStore(Request $req): void
    {
        $u = Auth::requireUser($req);
        $uid = (int) $u['id'];
        if ((int) Db::value('SELECT COUNT(*) FROM alert_rules WHERE user_id=?', [$uid]) >= self::MAX_RULES) {
            wstat_err('告警规则数量已达上限（' . self::MAX_RULES . '）', 422);
        }
        $d = $this->ruleInput($req);
        $now = time();
        $id = Db::insert('alert_rules', array_merge($d, [
            'user_id' => $uid,
            'is_active' => 1,
            'last_fired_at' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]));
        wstat_json(['id' => $id]);
    }

    /** PATCH /api/alerts/rules/{id} */
    public function ruleUpdate(Request $req): void
    {
        $rule = $this->ownRule($req, (int) $req->param('id'));
        $d = $this->ruleInput($req, $rule);
        $isActive = $req->input('is_active', null);
        if ($isActive !== null) {
            $d['is_active'] = (int) (bool) $isActive;
        }
        $set = [];
        $vals = [];
        foreach ($d as $k => $v) {
            $set[] = "`$k`=?";
            $vals[] = $v;
        }
        $set[] = 'updated_at=' . time();
        $vals[] = (int) $rule['id'];
        Db::execute('UPDATE alert_rules SET ' . implode(',', $set) . ' WHERE id=?', $vals);
        wstat_json(['ok' => true]);
    }

    /** DELETE /api/alerts/rules/{id} */
    public function ruleDelete(Request $req): void
    {
        $rule = $this->ownRule($req, (int) $req->param('id'));
        Db::execute('DELETE FROM alert_rules WHERE id=?', [(int) $rule['id']]);
        wstat_json(['ok' => true]);
    }

    /* ==================== 告警历史 ==================== */

    /** GET /api/alerts/logs?site_id=&level=&page=&size= */
    public function logs(Request $req): void
    {
        $u = Auth::requireUser($req);
        $page = max(1, (int) $req->input('page', 1));
        $size = min(100, max(1, (int) $req->input('size', 20)));

        $where = 'l.user_id=?';
        $args = [(int) $u['id']];
        $socialSite = (int) $req->input('site_id', 0);
        if ($socialSite > 0) {
            $where .= ' AND l.site_id=?';
            $args[] = $socialSite;
        }
        $level = trim((string) $req->input('level', ''));
        if (in_array($level, AlertEngine::LEVELS, true)) {
            $where .= ' AND l.level=?';
            $args[] = $level;
        }
        $total = (int) Db::value("SELECT COUNT(*) FROM alert_logs l WHERE $where", $args);
        $rows = Db::select(
            "SELECT l.*, r.name AS rule_name, s.name AS site_name, s.domain AS site_domain
             FROM alert_logs l
             LEFT JOIN alert_rules r ON r.id=l.rule_id
             LEFT JOIN sites s ON s.id=l.site_id
             WHERE $where ORDER BY l.id DESC LIMIT " . (($page - 1) * $size) . ",$size",
            $args
        );
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['rule_id'] = (int) $r['rule_id'];
            $r['site_id'] = (int) $r['site_id'];
            $r['value'] = (float) $r['value'];
            $r['baseline'] = (float) $r['baseline'];
            $r['created_at'] = (int) $r['created_at'];
        }
        unset($r);
        wstat_json(['items' => $rows, 'total' => $total, 'page' => $page, 'size' => $size]);
    }

    /* ==================== 日报订阅 ==================== */

    /** GET /api/alerts/reports */
    public function reportIndex(Request $req): void
    {
        $u = Auth::requireUser($req);
        $rows = Db::select(
            'SELECT r.*, s.name AS site_name, s.domain AS site_domain
             FROM report_subscriptions r LEFT JOIN sites s ON s.id=r.site_id
             WHERE r.user_id=? ORDER BY r.id DESC',
            [(int) $u['id']]
        );
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['site_id'] = (int) $r['site_id'];
            $r['hour'] = (int) $r['hour'];
            $r['is_active'] = (int) $r['is_active'];
            $r['channel_ids'] = array_values(array_filter(array_map('intval', explode(',', (string) $r['channels']))));
        }
        unset($r);
        wstat_json(['items' => $rows]);
    }

    /** POST /api/alerts/reports */
    public function reportStore(Request $req): void
    {
        $u = Auth::requireUser($req);
        $uid = (int) $u['id'];
        if ((int) Db::value('SELECT COUNT(*) FROM report_subscriptions WHERE user_id=?', [$uid]) >= self::MAX_REPORTS) {
            wstat_err('订阅数量已达上限（' . self::MAX_REPORTS . '）', 422);
        }
        $d = $this->reportInput($req);
        $now = time();
        $id = Db::insert('report_subscriptions', array_merge($d, [
            'user_id' => $uid,
            'is_active' => 1,
            'last_sent_day' => '',
            'last_status' => '',
            'created_at' => $now,
            'updated_at' => $now,
        ]));
        wstat_json(['id' => $id]);
    }

    /** PATCH /api/alerts/reports/{id} */
    public function reportUpdate(Request $req): void
    {
        $sub = $this->ownReport($req, (int) $req->param('id'));
        $d = $this->reportInput($req, $sub);
        $isActive = $req->input('is_active', null);
        if ($isActive !== null) {
            $d['is_active'] = (int) (bool) $isActive;
        }
        $set = [];
        $vals = [];
        foreach ($d as $k => $v) {
            $set[] = "`$k`=?";
            $vals[] = $v;
        }
        $set[] = 'updated_at=' . time();
        $vals[] = (int) $sub['id'];
        Db::execute('UPDATE report_subscriptions SET ' . implode(',', $set) . ' WHERE id=?', $vals);
        wstat_json(['ok' => true]);
    }

    /** DELETE /api/alerts/reports/{id} */
    public function reportDelete(Request $req): void
    {
        $sub = $this->ownReport($req, (int) $req->param('id'));
        Db::execute('DELETE FROM report_subscriptions WHERE id=?', [(int) $sub['id']]);
        wstat_json(['ok' => true]);
    }

    /* ==================== 概览计数 ==================== */

    /** GET /api/alerts/summary */
    public function summary(Request $req): void
    {
        $u = Auth::requireUser($req);
        $uid = (int) $u['id'];
        wstat_json([
            'channels' => (int) Db::value('SELECT COUNT(*) FROM notify_channels WHERE user_id=? AND is_active=1', [$uid]),
            'rules' => (int) Db::value('SELECT COUNT(*) FROM alert_rules WHERE user_id=? AND is_active=1', [$uid]),
            'reports' => (int) Db::value('SELECT COUNT(*) FROM report_subscriptions WHERE user_id=? AND is_active=1', [$uid]),
            'logs_24h' => (int) Db::value(
                'SELECT COUNT(*) FROM alert_logs WHERE user_id=? AND created_at>=?',
                [$uid, time() - 86400]
            ),
            'last_log' => Db::first(
                'SELECT id,level,message,created_at,status FROM alert_logs WHERE user_id=? ORDER BY id DESC LIMIT 1',
                [$uid]
            ),
        ]);
    }

    /* ==================== 内部：校验与取值 ==================== */

    private function ownChannel(Request $req, int $id): array
    {
        $u = Auth::requireUser($req);
        $ch = Db::first('SELECT * FROM notify_channels WHERE id=? AND user_id=? LIMIT 1', [$id, (int) $u['id']]);
        if ($ch === null) {
            wstat_err('推送渠道不存在', 404, 404);
        }
        return $ch;
    }

    private function ownRule(Request $req, int $id): array
    {
        $u = Auth::requireUser($req);
        $r = Db::first('SELECT * FROM alert_rules WHERE id=? AND user_id=? LIMIT 1', [$id, (int) $u['id']]);
        if ($r === null) {
            wstat_err('告警规则不存在', 404, 404);
        }
        return $r;
    }

    private function ownReport(Request $req, int $id): array
    {
        $u = Auth::requireUser($req);
        $r = Db::first('SELECT * FROM report_subscriptions WHERE id=? AND user_id=? LIMIT 1', [$id, (int) $u['id']]);
        if ($r === null) {
            wstat_err('订阅不存在', 404, 404);
        }
        return $r;
    }

    /** 渠道入参校验（新建） */
    private function channelInput(Request $req): array
    {
        $name = trim((string) $req->input('name', ''));
        $type = (string) $req->input('type', '');
        if ($name === '' || mb_strlen($name) > 60) {
            wstat_err('请填写渠道名称（≤60字）', 422);
        }
        if (!in_array($type, Notifier::TYPES, true)) {
            wstat_err('不支持的渠道类型', 422);
        }
        $cfg = $this->requiredConfig($type, $this->cfgArray($req->input('config')));
        return [$name, $type, $cfg];
    }

    private function cfgArray($v): array
    {
        if (is_array($v)) {
            return $v;
        }
        if (is_string($v) && trim($v) !== '') {
            $j = json_decode($v, true);
            return is_array($j) ? $j : [];
        }
        return [];
    }

    /** 必填项校验（按渠道类型） */
    private function requiredConfig(string $type, array $cfg): array
    {
        $need = static function (array $c, string $key, string $label): void {
            if (trim((string) ($c[$key] ?? '')) === '') {
                wstat_err('请填写' . $label, 422);
            }
        };
        switch ($type) {
            case 'webhook':
            case 'feishu':
            case 'dingtalk':
            case 'wecom':
                $need($cfg, 'url', 'Webhook 地址');
                break;
            case 'serverchan':
                $need($cfg, 'key', 'SendKey');
                break;
            case 'bark':
                $need($cfg, 'key', 'Bark Key');
                break;
            case 'email':
                $to = $cfg['to'] ?? '';
                $list = is_array($to) ? $to : array_filter(array_map('trim', explode(',', (string) $to)));
                if (!$list) {
                    wstat_err('请填写收件人邮箱', 422);
                }
                foreach ($list as $a) {
                    if (!Util::validEmail((string) $a)) {
                        wstat_err('收件人邮箱格式不正确: ' . $a, 422);
                    }
                }
                $cfg['to'] = array_values($list);
                break;
        }
        return $cfg;
    }

    /**
     * 合并旧配置：值为掩码「******」或空字符串的密钥字段沿用旧值，
     * 这样前端回显掩码、用户只改名称时不会把密钥清空。
     */
    private static function mergeConfig(string $type, array $incoming, array $existing): array
    {
        $secretKeys = ['secret', 'key', 'password', 'token'];
        foreach ($secretKeys as $k) {
            $v = (string) ($incoming[$k] ?? '');
            if ($v === '' || preg_match('/^\*+$/', $v)) {
                if (array_key_exists($k, $existing)) {
                    $incoming[$k] = $existing[$k];
                }
            }
        }
        if (($incoming['url'] ?? '') === '' && isset($existing['url'])) {
            $incoming['url'] = $existing['url'];
        }
        if ($type === 'email') {
            $to = $incoming['to'] ?? [];
            if (is_string($to)) {
                $to = array_filter(array_map('trim', explode(',', $to)));
            }
            if (!$to && isset($existing['to'])) {
                $incoming['to'] = $existing['to'];
            }
        }
        return $incoming;
    }

    /** 回显时把密钥打码（前端只看到掩码，保存时原样回传即可保留） */
    private static function maskConfig(array $cfg): array
    {
        foreach (['secret', 'key', 'password', 'token'] as $k) {
            if (isset($cfg[$k]) && (string) $cfg[$k] !== '') {
                $cfg[$k] = '******';
            }
        }
        return $cfg;
    }

    /**
     * 站点参数校验：0 = 全部站点；否则要求对该站点至少具备 $min 角色。
     * 规则写入场景默认要求「可编辑」——所有者与协作者可配，只读成员不可。
     */
    private function siteArg(Request $req, string $min = SiteAccess::EDITOR): int
    {
        $sid = (int) $req->input('site_id', 0);
        if ($sid <= 0) {
            return 0;
        }
        SiteAccess::require($req, $sid, $min);
        return $sid;
    }

    /** 渠道 id 列表校验（必须属于当前用户） */
    private function channelSpec(Request $req): string
    {
        $u = Auth::requireUser($req);
        $v = $req->input('channel_ids', $req->input('channels', null));
        if ($v === null) {
            return '';
        }
        $ids = is_array($v) ? $v : explode(',', (string) $v);
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return '';
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $owned = Db::select(
            "SELECT id FROM notify_channels WHERE user_id=? AND id IN ($ph)",
            [(int) $u['id'], ...$ids]
        );
        $ok = array_map(static fn ($r) => (int) $r['id'], $owned);
        return implode(',', $ok);
    }

    /** 规则入参（$base 非空时表示补丁更新：未提供的字段沿用旧值） */
    private function ruleInput(Request $req, array $base = []): array
    {
        $get = static function (string $k, $def = null) use ($req, $base) {
            $v = $req->input($k, null);
            if ($v !== null) {
                return $v;
            }
            return array_key_exists($k, $base) ? $base[$k] : $def;
        };

        $name = trim((string) $get('name', ''));
        if ($name === '' || mb_strlen($name) > 100) {
            wstat_err('请填写规则名称（≤100字）', 422);
        }
        $metric = (string) $get('metric', 'pv');
        if (!in_array($metric, AlertEngine::METRICS, true)) {
            wstat_err('不支持的指标', 422);
        }
        $compare = (string) $get('compare', 'ratio_down');
        if (!in_array($compare, AlertEngine::COMPARES, true)) {
            wstat_err('不支持的比较方式', 422);
        }
        $baseline = (string) $get('baseline', 'prev_day');
        if (!in_array($baseline, AlertEngine::BASELINES, true)) {
            wstat_err('不支持的基线', 422);
        }
        $level = (string) $get('level', 'warn');
        if (!in_array($level, AlertEngine::LEVELS, true)) {
            $level = 'warn';
        }
        $window = (int) $get('window_min', 60);
        if ($window < 1 || $window > 1440) {
            wstat_err('评估窗口需在 1~1440 分钟之间', 422);
        }
        $cooldown = (int) $get('cooldown_min', 60);
        if ($cooldown < 1 || $cooldown > 10080) {
            wstat_err('冷却时间需在 1~10080 分钟之间', 422);
        }
        $minSample = (int) $get('min_sample', 10);
        if ($minSample < 0 || $minSample > 100000000) {
            wstat_err('基线样本量不合法', 422);
        }
        $threshold = $get('threshold', 0);
        if (!is_numeric($threshold)) {
            wstat_err('阈值需为数字', 422);
        }
        $threshold = (float) $threshold;
        if ($compare === 'ratio_up' || $compare === 'ratio_down') {
            if ($threshold <= 0 || $threshold > 10000) {
                wstat_err('比例类比较的阈值需大于 0（百分比）', 422);
            }
        }

        $out = [
            'site_id' => $this->siteArg($req),
            'name' => $name,
            'metric' => $metric,
            'window_min' => $window,
            'compare' => $compare,
            'baseline' => $baseline,
            'threshold' => round($threshold, 2),
            'min_sample' => $minSample,
            'level' => $level,
            'cooldown_min' => $cooldown,
            'channels' => $this->channelSpec($req),
        ];

        // v1.3 新列（恢复通知 + 静默时段）：老库缺列时自动省略（优雅降级）
        if (self::ruleExtCols()) {
            $recover = (int) (bool) $get('recover_notify', array_key_exists('recover_notify', $base) ? $base['recover_notify'] : 0);
            $qs = trim((string) $get('quiet_start', array_key_exists('quiet_start', $base) ? $base['quiet_start'] : ''));
            $qe = trim((string) $get('quiet_end', array_key_exists('quiet_end', $base) ? $base['quiet_end'] : ''));
            $hm = static fn (string $s): bool => preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $s) === 1;
            if ($qs !== '' && !$hm($qs)) {
                wstat_err('静默开始时间格式应为 HH:MM（如 23:00）', 422);
            }
            if ($qe !== '' && !$hm($qe)) {
                wstat_err('静默结束时间格式应为 HH:MM（如 07:00）', 422);
            }
            if (($qs === '') !== ($qe === '')) {
                wstat_err('静默时段需同时填写开始与结束，或都留空', 422);
            }
            $out['recover_notify'] = $recover;
            $out['quiet_start'] = $qs;
            $out['quiet_end'] = $qe;
        }
        return $out;
    }

    /** alert_rules 新列（recover_notify 等）是否存在（进程内缓存，缺列=老库未跑增量脚本） */
    private static function ruleExtCols(): bool
    {
        static $ok = null;
        if ($ok === null) {
            try {
                // tableColumns() 返回键=列名，判列必须 array_key_exists
                $c = Db::tableColumns('alert_rules');
                $ok = array_key_exists('recover_notify', $c) && array_key_exists('quiet_start', $c);
            } catch (\Throwable $e) {
                $ok = false;
            }
        }
        return $ok;
    }

    /** 订阅入参 */
    private function reportInput(Request $req, array $base = []): array
    {
        $get = static function (string $k, $def = null) use ($req, $base) {
            $v = $req->input($k, null);
            if ($v !== null) {
                return $v;
            }
            return array_key_exists($k, $base) ? $base[$k] : $def;
        };

        $freq = (string) $get('freq', 'daily');
        if (!in_array($freq, ['daily', 'weekly'], true)) {
            wstat_err('推送频率只能是 daily 或 weekly', 422);
        }
        $hour = (int) $get('hour', 9);
        if ($hour < 0 || $hour > 23) {
            wstat_err('推送小时需在 0~23 之间', 422);
        }
        $daysRaw = $get('days', '1,2,3,4,5,6,7');
        $days = is_array($daysRaw) ? $daysRaw : explode(',', (string) $daysRaw);
        $days = array_values(array_unique(array_filter(array_map('intval', $days), static fn ($d) => $d >= 1 && $d <= 7)));
        sort($days);
        if ($freq === 'weekly' && !$days) {
            wstat_err('周报至少选择一天', 422);
        }
        $name = trim((string) $get('name', ''));
        if (mb_strlen($name) > 100) {
            wstat_err('订阅名称过长', 422);
        }

        return [
            'site_id' => $this->siteArg($req),
            'name' => $name !== '' ? $name : ($freq === 'weekly' ? '每周数据周报' : '每日数据日报'),
            'freq' => $freq,
            'days' => $days ? implode(',', $days) : '1,2,3,4,5,6,7',
            'hour' => $hour,
            'channels' => $this->channelSpec($req),
        ];
    }
}
