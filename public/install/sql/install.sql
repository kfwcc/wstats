-- =====================================================================
-- WebStats 全量安装脚本  (install.sql)
-- 引擎：MySQL 5.7+ / 8.0    字符集：utf8mb4
--
-- 使用方式（任选其一）：
--   A. 安装向导自动执行（推荐）：访问 http(s)://你的域名/install/
--   B. 命令行：
--        mysql -u<用户> -p <库名> < sql/install.sql
--      注意：命令行的分区占位标记需自行替换为分区定义（见文末说明），
--            或直接使用 public/install/cli.php 安装（会自动展开）。
--
-- 特性：
--   1. 全部 CREATE TABLE IF NOT EXISTS —— 幂等，可安全重复执行；
--      绝不包含 DROP/TRUNCATE，安装过程不会破坏已有数据。
--   2. 不含 CREATE DATABASE / USE，库名由安装器（或命令行）指定。
--   3. events 明细表按 day 做 RANGE 月分区；分区边界必须是可解析的日期字面量
--      （scripts/cron.php 的 partition/clean 依赖它），故不使用 MAXVALUE。
--
-- 表清单：
--   users        管理员/用户
--   sites        接入站点
--   auth_tokens  登录令牌
--   events       事件明细（大表，按月分区）
--   sessions     访客会话聚合
--   site_daily   站点每日汇总（cron rollup 生成）
--   funnels      转化漏斗定义
--   goals        转化目标（页面目标 / 自定义事件目标）
--   segments     命名分段（保存一组全局过滤条件，供各分析页复用）
--   api_tokens   开放 API 个人访问令牌（PAT，只读白名单接口）
--   site_members 站点协作成员（多用户只读/可编辑）
--   notify_channels / alert_rules / alert_logs / report_subscriptions / sys_settings
--                告警与日报（推送渠道 / 规则 / 记录 / 订阅）
--   spider_hits  蜘蛛抓取聚合（与访客口径隔离）
--   share_links  公开只读分享链接（免登录查看指定站点的汇总报表）
-- =====================================================================

-- ---------------------------------------------------------------------
-- 用户表
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `email`       VARCHAR(190)    NOT NULL,
  `password`    VARCHAR(255)    NOT NULL,                -- password_hash()
  `nickname`    VARCHAR(64)     NOT NULL DEFAULT '',
  `lang`        VARCHAR(10)     NOT NULL DEFAULT 'zh-CN',
  `status`      TINYINT         NOT NULL DEFAULT 1,      -- 1 正常 0 禁用
  `is_admin`    TINYINT         NOT NULL DEFAULT 0,      -- 1 系统管理员（可访问系统设置）
  `created_at`  INT UNSIGNED    NOT NULL,
  `updated_at`  INT UNSIGNED    NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 站点表（一个用户可管理多个站点）
-- site_key   : SDK 初始化使用的公开站点标识
-- verify_token: 文件验证令牌，验证文件 = {domain}/wstat_verify_{token}.txt
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sites` (
  `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `user_id`       INT UNSIGNED  NOT NULL,
  `name`          VARCHAR(100)  NOT NULL,
  `domain`        VARCHAR(190)  NOT NULL,                -- 仅主机名，不含协议/路径
  `site_key`      VARCHAR(16)   NOT NULL,
  `timezone`      VARCHAR(64)   NOT NULL DEFAULT 'Asia/Shanghai',
  `status`        TINYINT       NOT NULL DEFAULT 1,
  `verify_token`  VARCHAR(32)   NOT NULL DEFAULT '',
  `verified_at`   INT UNSIGNED  NOT NULL DEFAULT 0,
  `created_at`    INT UNSIGNED  NOT NULL,
  `updated_at`    INT UNSIGNED  NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_site_key` (`site_key`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 登录令牌表（随机 256bit token，服务端存储原文，无需 JWT 依赖）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `auth_tokens` (
  `token`       CHAR(64)      NOT NULL,
  `user_id`     INT UNSIGNED  NOT NULL,
  `expires_at`  INT UNSIGNED  NOT NULL,
  `created_at`  INT UNSIGNED  NOT NULL,
  PRIMARY KEY (`token`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 事件明细流水（核心大表，只追加、按月分区）
-- 覆盖：pageview / event / perf / click / scroll ...（payload 存细节）
-- day = 站点时区下的本地日期（用于分区与按日聚合）
-- 主键必须包含分区列 day => PRIMARY KEY(id, day)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `events` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `day`         DATE            NOT NULL,
  `site_id`     INT UNSIGNED    NOT NULL,
  `session_id`  VARCHAR(40)     NOT NULL DEFAULT '',
  `visitor_id`  VARCHAR(40)     NOT NULL DEFAULT '',
  `end_user`    VARCHAR(128)    NOT NULL DEFAULT '',     -- 终端用户标识(SDK identify 上报，登录用户分析)
  `type`        VARCHAR(16)     NOT NULL,                -- pageview|event|perf|click|scroll|hb|outlink|download|search
  `ts`          INT UNSIGNED    NOT NULL,                -- 服务端接收时间(unix)
  `url`         VARCHAR(600)    NOT NULL DEFAULT '',
  `title`       VARCHAR(255)    NOT NULL DEFAULT '',
  `ref`         VARCHAR(600)    NOT NULL DEFAULT '',
  `browser`     VARCHAR(40)     NOT NULL DEFAULT '',
  `os`          VARCHAR(40)     NOT NULL DEFAULT '',
  `device`      VARCHAR(20)     NOT NULL DEFAULT '',     -- desktop|mobile|tablet|bot
  `screen`      VARCHAR(24)     NOT NULL DEFAULT '',
  `lang`        VARCHAR(16)     NOT NULL DEFAULT '',
  `ip`          VARCHAR(45)     NOT NULL DEFAULT '',     -- 原始IP(用于独立IP统计/地理解析)
  `country`     VARCHAR(64)     NOT NULL DEFAULT '',
  `province`    VARCHAR(64)     NOT NULL DEFAULT '',
  `city`        VARCHAR(64)     NOT NULL DEFAULT '',
  `source`      VARCHAR(32)     NOT NULL DEFAULT '',     -- direct|search|social|link|paid|utm
  `medium`      VARCHAR(32)     NOT NULL DEFAULT '',
  `utm_source`  VARCHAR(128)    NOT NULL DEFAULT '',     -- 归因 UTM source/medium 原样落库
  `utm_medium`  VARCHAR(128)    NOT NULL DEFAULT '',
  `click_source` VARCHAR(64)    NOT NULL DEFAULT '',     -- 广告平台名(Google Ads/Meta Ads...)
  `ref_host`    VARCHAR(255)    NOT NULL DEFAULT '',     -- 外链 referrer 主机名(去重聚合用)
  `kw`          VARCHAR(255)    NOT NULL DEFAULT '',     -- 搜索引擎来路关键词(source=search 时从 ref 提取；拿不到置空=未提供)
  `campaign`    VARCHAR(128)    NOT NULL DEFAULT '',
  `content`     VARCHAR(128)    NOT NULL DEFAULT '',
  `term`        VARCHAR(128)    NOT NULL DEFAULT '',
  `click_id`    VARCHAR(128)    NOT NULL DEFAULT '',     -- gclid/msclkid/fbclid/ttclid...
  `payload`     JSON            NULL,                    -- 附加数据(性能指标、事件参数)
  PRIMARY KEY (`id`, `day`),
  KEY `idx_site_day_type` (`site_id`, `day`, `type`),
  KEY `idx_site_day`      (`site_id`, `day`),
  KEY `idx_session`       (`session_id`, `ts`),
  KEY `idx_visitor`       (`site_id`, `visitor_id`, `ts`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
PARTITION BY RANGE (TO_DAYS(`day`)) (
__PARTITIONS__
);

-- ---------------------------------------------------------------------
-- 会话表（一次访问=一个 session，服务端聚合落库）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sessions` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `site_id`     INT UNSIGNED    NOT NULL,
  `session_id`  VARCHAR(40)     NOT NULL,
  `visitor_id`  VARCHAR(40)     NOT NULL DEFAULT '',
  `end_user`    VARCHAR(128)    NOT NULL DEFAULT '',     -- 终端用户标识(会话内首个携带值)
  `start_ts`    INT UNSIGNED    NOT NULL,
  `end_ts`      INT UNSIGNED    NOT NULL,
  `pageviews`   SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `duration`    INT UNSIGNED    NOT NULL DEFAULT 0,      -- 秒
  `bounce`      TINYINT         NOT NULL DEFAULT 0,      -- 1=跳出(单页会话)
  `is_new`      TINYINT         NOT NULL DEFAULT 0,      -- 1=新访客(90天内未见)
  `entry_url`   VARCHAR(600)    NOT NULL DEFAULT '',
  `exit_url`    VARCHAR(600)    NOT NULL DEFAULT '',
  `source`      VARCHAR(32)     NOT NULL DEFAULT 'direct',
  `medium`      VARCHAR(32)     NOT NULL DEFAULT '',
  `campaign`    VARCHAR(128)    NOT NULL DEFAULT '',
  `content`     VARCHAR(128)    NOT NULL DEFAULT '',
  `term`        VARCHAR(128)    NOT NULL DEFAULT '',
  `click_id`    VARCHAR(128)    NOT NULL DEFAULT '',
  `browser`     VARCHAR(40)     NOT NULL DEFAULT '',
  `os`          VARCHAR(40)     NOT NULL DEFAULT '',
  `device`      VARCHAR(20)     NOT NULL DEFAULT '',
  `screen`      VARCHAR(24)     NOT NULL DEFAULT '',
  `lang`        VARCHAR(16)     NOT NULL DEFAULT '',
  `ip`          VARCHAR(45)     NOT NULL DEFAULT '',     -- 会话首访 IP
  `country`     VARCHAR(64)     NOT NULL DEFAULT '',
  `province`    VARCHAR(64)     NOT NULL DEFAULT '',
  `city`        VARCHAR(64)     NOT NULL DEFAULT '',
  `created_at`  INT UNSIGNED    NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_session` (`site_id`, `session_id`),
  KEY `idx_site_start` (`site_id`, `start_ts`),
  KEY `idx_visitor`    (`visitor_id`, `site_id`),
  KEY `idx_euser`      (`site_id`, `end_user`),
  KEY `idx_new`        (`site_id`, `is_new`, `start_ts`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 站点每日汇总（cron rollup 生成，概览趋势读取此表）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `site_daily` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `site_id`    INT UNSIGNED NOT NULL,
  `day`        DATE         NOT NULL,
  `pv`         INT UNSIGNED NOT NULL DEFAULT 0,
  `uv`         INT UNSIGNED NOT NULL DEFAULT 0,
  `ipc`        INT UNSIGNED NOT NULL DEFAULT 0,
  `visits`     INT UNSIGNED NOT NULL DEFAULT 0,
  `bounce`     INT UNSIGNED NOT NULL DEFAULT 0,
  `duration`   BIGINT UNSIGNED NOT NULL DEFAULT 0,       -- 当日会话时长合计(秒)
  `new_users`  INT UNSIGNED NOT NULL DEFAULT 0,
  `updated_at` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_site_day` (`site_id`, `day`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 转化漏斗定义
--   steps      : JSON 数组，按顺序的漏斗步骤（URL 匹配串），2~8 步
--   match_type : prefix = 前缀匹配（默认，/product 匹配 /product/123）
--                contains = 包含匹配   exact = 精确匹配
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `funnels` (
  `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `site_id`    INT UNSIGNED  NOT NULL,
  `name`       VARCHAR(100)  NOT NULL,
  `steps`      TEXT          NOT NULL,
  `match_type` VARCHAR(10)   NOT NULL DEFAULT 'prefix',
  `is_active`  TINYINT       NOT NULL DEFAULT 1,
  `created_at` INT UNSIGNED  NOT NULL,
  `updated_at` INT UNSIGNED  NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_site` (`site_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 转化目标（Goal）
--   type: page  = 页面目标（到达某 URL，按路径匹配 match_type: prefix|contains|exact）
--         event = 自定义事件目标（SDK WStat.track('事件名') 上报，payload.n 精确匹配）
--   target: page 目标存路径片段；event 目标存事件名
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `goals` (
  `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `site_id`    INT UNSIGNED  NOT NULL,
  `name`       VARCHAR(100)  NOT NULL,
  `type`       VARCHAR(10)   NOT NULL DEFAULT 'page',
  `target`     VARCHAR(255)  NOT NULL,
  `match_type` VARCHAR(10)   NOT NULL DEFAULT 'contains',
  `is_active`  TINYINT       NOT NULL DEFAULT 1,
  `created_at` INT UNSIGNED  NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_site` (`site_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 命名分段（Segments）：把一组「全局过滤条件」存成可复用的切片
--   params: 条件数组的 JSON，与 URL 上的 ?f= 参数**完全同构**（可直接互换）
--     例 [{"f":"device","o":"in","v":["mobile"]},{"f":"country","o":"eq","v":"中国"}]
--   只存条件、不存数据快照：换个时间区间即可复算，因此分段永不过期。
--   维度与操作符白名单见 Support\Filter::COLS / OPS（非法项在写入与读取时都会被丢弃）。
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `segments` (
  `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `site_id`    INT UNSIGNED  NOT NULL,
  `name`       VARCHAR(100)  NOT NULL,
  `params`     TEXT          NOT NULL,
  `created_by` INT UNSIGNED  NOT NULL DEFAULT 0,
  `created_at` INT UNSIGNED  NOT NULL,
  `updated_at` INT UNSIGNED  NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_site` (`site_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 开放 API 个人访问令牌（PAT）
--   与 auth_tokens（登录会话）隔离；仅可用于 /open/v1/* 只读接口白名单。
--   expires_at = 0 表示永不过期；last_used_at 记录最近调用时间。
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `api_tokens` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      INT UNSIGNED NOT NULL,
  `name`         VARCHAR(60)  NOT NULL DEFAULT '',
  `token`        CHAR(64)     NOT NULL,
  `expires_at`   INT UNSIGNED NOT NULL DEFAULT 0,
  `last_used_at` INT UNSIGNED NOT NULL DEFAULT 0,
  `is_active`    TINYINT      NOT NULL DEFAULT 1,
  `created_at`   INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_token` (`token`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 站点协作成员（多用户只读/可编辑授权）
--   role: viewer = 只读（可看全部报表，不可写）
--         editor = 可编辑（可管理漏斗等站点内分析对象）
--   站点所有者（sites.user_id）不在此表，天然为 owner。
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `site_members` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `site_id`    INT UNSIGNED NOT NULL,
  `user_id`    INT UNSIGNED NOT NULL,
  `role`       VARCHAR(10)  NOT NULL DEFAULT 'viewer',
  `invited_by` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_site_user` (`site_id`, `user_id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 推送渠道（告警与日报共用）
--   type: webhook 通用 | feishu 飞书 | dingtalk 钉钉 | wecom 企业微信
--         serverchan Server酱 | bark | email 邮件
--   config: JSON，按 type 取键，例如
--     webhook   {"url":"https://...","secret":""}
--     feishu    {"url":"https://open.feishu.cn/open-apis/bot/v2/hook/xxx"}
--     dingtalk  {"url":"https://oapi.dingtalk.com/robot/send?access_token=xxx","secret":"SEC..."}
--     email     {"to":["a@b.com"]}
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `notify_channels` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL,
  `name`       VARCHAR(60)  NOT NULL,
  `type`       VARCHAR(16)  NOT NULL,
  `config`     TEXT         NOT NULL,
  `is_active`  TINYINT      NOT NULL DEFAULT 1,
  `last_status` VARCHAR(200) NOT NULL DEFAULT '',    -- 最近一次发送结果（便于自检）
  `last_sent_at` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` INT UNSIGNED NOT NULL,
  `updated_at` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 告警规则
--   site_id   : 0 = 应用到该用户全部站点
--   metric    : pv | uv | visits | online | bounce_rate | avg_duration
--   window_min: 评估窗口（分钟），如 30 = 最近30分钟
--   compare   : ratio_up   较基线上升超过 threshold%（暴涨）
--               ratio_down 较基线下降超过 threshold%（暴跌）
--               abs_over   绝对值高于 threshold
--               abs_under  绝对值低于 threshold
--               zero       窗口内该指标为 0（如站点挂掉）
--   baseline  : prev_day = 昨日同窗口（默认） | prev_window = 上一等长窗口
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `alert_rules` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED  NOT NULL,
  `site_id`     INT UNSIGNED  NOT NULL DEFAULT 0,
  `name`        VARCHAR(100)  NOT NULL,
  `metric`      VARCHAR(20)   NOT NULL,
  `window_min`  INT UNSIGNED  NOT NULL DEFAULT 60,
  `compare`     VARCHAR(16)   NOT NULL DEFAULT 'ratio_down',
  `baseline`    VARCHAR(16)   NOT NULL DEFAULT 'prev_day',
  `threshold`   DECIMAL(12,2) NOT NULL DEFAULT 50.00,
  `min_sample`  INT UNSIGNED  NOT NULL DEFAULT 10,   -- 基线样本下限，避免夜间噪声误报
  `level`       VARCHAR(10)   NOT NULL DEFAULT 'warn',
  `cooldown_min` INT UNSIGNED NOT NULL DEFAULT 60,   -- 同规则重复触发最小间隔
  `channels`    VARCHAR(255)  NOT NULL DEFAULT '',   -- 渠道 id，逗号分隔；空=用户全部启用渠道
  `recover_notify` TINYINT    NOT NULL DEFAULT 0,    -- 恢复通知：触发后恢复正常时补发一条
  `firing`      TINYINT       NOT NULL DEFAULT 0,    -- 当前是否处于触发态（恢复判定用，引擎维护）
  `quiet_start` VARCHAR(8)    NOT NULL DEFAULT '',   -- 静默时段开始 HH:MM（站点本地时区），空=不启用
  `quiet_end`   VARCHAR(8)    NOT NULL DEFAULT '',   -- 静默时段结束 HH:MM（支持跨零点，如 23:00~07:00）
  `is_active`   TINYINT       NOT NULL DEFAULT 1,
  `last_fired_at` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`  INT UNSIGNED  NOT NULL,
  `updated_at`  INT UNSIGNED  NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_site` (`site_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 告警触发记录（留痕 + 前端历史）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `alert_logs` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `rule_id`     INT UNSIGNED NOT NULL,
  `user_id`     INT UNSIGNED NOT NULL DEFAULT 0,
  `site_id`     INT UNSIGNED NOT NULL DEFAULT 0,
  `level`       VARCHAR(10)  NOT NULL DEFAULT 'warn',
  `metric`      VARCHAR(20)  NOT NULL DEFAULT '',
  `value`       DECIMAL(14,2) NOT NULL DEFAULT 0,
  `baseline`    DECIMAL(14,2) NOT NULL DEFAULT 0,
  `message`     VARCHAR(500) NOT NULL DEFAULT '',
  `channel_ids` VARCHAR(255) NOT NULL DEFAULT '',
  `status`      VARCHAR(16)  NOT NULL DEFAULT 'pending',  -- sent | failed | skipped
  `error`       VARCHAR(255) NOT NULL DEFAULT '',
  `created_at`  INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_rule_time` (`rule_id`, `created_at`),
  KEY `idx_site_time` (`site_id`, `created_at`),
  KEY `idx_user_time` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 日报/周报订阅
--   freq: daily 每日 | weekly 每周（days 为 1=周一 … 7=周日，逗号分隔）
--   hour: 发送小时（站点本地时区，0-23）
--   last_sent_day: 幂等标记，避免同一天重复发送
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `report_subscriptions` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      INT UNSIGNED NOT NULL,
  `site_id`      INT UNSIGNED NOT NULL DEFAULT 0,   -- 0 = 该用户全部站点（合并一封）
  `name`         VARCHAR(100) NOT NULL DEFAULT '',
  `freq`         VARCHAR(10)  NOT NULL DEFAULT 'daily',
  `days`         VARCHAR(20)  NOT NULL DEFAULT '1,2,3,4,5,6,7',
  `hour`         TINYINT UNSIGNED NOT NULL DEFAULT 9,
  `channels`     VARCHAR(255) NOT NULL DEFAULT '',
  `is_active`    TINYINT      NOT NULL DEFAULT 1,
  `last_sent_day` VARCHAR(10) NOT NULL DEFAULT '',
  `last_status`  VARCHAR(200) NOT NULL DEFAULT '',
  `created_at`   INT UNSIGNED NOT NULL,
  `updated_at`   INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 用户操作日志（个人中心「我的日志」与用户管理审计；只追加，量级=人工操作）
--   action: login|logout|profile_update|password_change|password_reset
--         | user_create|user_update|user_disable|user_enable|user_delete|user_reset_password
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_op_logs` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED    NOT NULL,
  `action`     VARCHAR(40)     NOT NULL,
  `detail`     VARCHAR(500)    NOT NULL DEFAULT '',
  `ip`         VARCHAR(45)     NOT NULL DEFAULT '',
  `ua`         VARCHAR(255)    NOT NULL DEFAULT '',
  `created_at` INT UNSIGNED    NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_time` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 系统设置（键值表；管理员可改：注册开关 / 采集开关 / 告警开关 / SMTP 邮件）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sys_settings` (
  `name`       VARCHAR(64)  NOT NULL,
  `value`      TEXT         NULL,
  `updated_at` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 蜘蛛抓取聚合表（蜘蛛爬虫统计；与访客口径完全隔离，不进 events/sessions）
-- 一条 = 某站点某天某爬虫抓某个 URL 的累计次数；命中即 hits+1（upsert）
-- url_md5 参与主键：utf8mb4 下把 VARCHAR(255) 放进主键在老 MariaDB(767B) 上会超限
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `spider_hits` (
  `site_id`  INT UNSIGNED      NOT NULL,
  `day`      DATE              NOT NULL,                 -- 站点本地日期
  `spider`   VARCHAR(48)       NOT NULL,                 -- 归一化爬虫名（见 Support\Spider）
  `url_md5`  CHAR(32)          NOT NULL,                 -- md5(被抓取路径)，主键用
  `url`      VARCHAR(255)      NOT NULL DEFAULT '',      -- 被抓取路径（已清洗，不含协议/主机/锚点）
  `hits`     INT UNSIGNED      NOT NULL DEFAULT 0,       -- 抓取次数
  `bytes`    BIGINT UNSIGNED   NOT NULL DEFAULT 0,       -- 累计响应字节（拿不到为 0）
  `status`   SMALLINT UNSIGNED NOT NULL DEFAULT 0,       -- 最近一次响应状态码（拿不到为 0）
  `first_ts` INT UNSIGNED      NOT NULL DEFAULT 0,       -- 首次抓取时间
  `last_ts`  INT UNSIGNED      NOT NULL DEFAULT 0,       -- 最近抓取时间
  PRIMARY KEY (`site_id`, `day`, `spider`, `url_md5`),
  KEY `idx_site_day`    (`site_id`, `day`),
  KEY `idx_site_spider` (`site_id`, `spider`, `day`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 关于上面 events 表的分区占位行（安装器会自动展开为真实分区定义）：
--   手工执行时，请把该行替换为下面结构（按实际月份调整）：
--     PARTITION p202608 VALUES LESS THAN (TO_DAYS('2026-09-01')),
--     PARTITION p202609 VALUES LESS THAN (TO_DAYS('2026-10-01')),
--     PARTITION p202610 VALUES LESS THAN (TO_DAYS('2026-11-01')),
--     PARTITION p202611 VALUES LESS THAN (TO_DAYS('2026-12-01')),
--     PARTITION p202612 VALUES LESS THAN (TO_DAYS('2027-01-01'))
--   分区名规范：pYYYYMM；最后一个边界必须是日期（供 cron partition/clean 解析），
--   不要使用 MAXVALUE，否则自动预建分区与清理逻辑将失效。
--
-- 安装后的定时任务（crontab / 宝塔计划任务）—— 命令在**项目根目录**下执行：
--   * * * * *   php {path}/scripts/cron.php session    # 每1分钟：回收空闲会话
--   0 * * * *   php {path}/scripts/cron.php rollup      # 每小时：生成 site_daily
--   0 3 * * *   php {path}/scripts/cron.php partition   # 每天：预建未来分区
--   0 4 * * *   php {path}/scripts/cron.php clean       # 每天：清理过期明细
-- worker 常驻：php {path}/scripts/worker.php（建议 supervisor / 宝塔守护进程）
-- =====================================================================
