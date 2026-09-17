<?php
/**
 * 版本号（运行期唯一事实来源，明文）
 *
 * 为什么单独放一个文件、而不是写在 config.php 里：
 *   app/ 目录会被升级包覆盖、且不在 preserve 保护名单里，所以版本号能随代码自动跟进。
 *   旧版把版本号存在 data/installed.php（安装当刻的快照），手工覆盖代码 / 目录迁移都不会
 *   刷新它，于是页脚永远显示旧版本、更新检查永远提示有新版本、点升级又被基线守卫拒绝。
 *
 * 改版本号（发版流程，见 docs/deploy.md）：
 *   1) 改 install/lib.php 的 Installer::VERSION —— 安装向导页脚用的那处；
 *   2) 执行 php scripts/write-version.php，把本文件同步成同一个版本号。
 *   两处不一致时发版脚本会直接中止构建；也可以直接手改本文件，但同样要保持一致。
 */
declare(strict_types=1);

return '1.0.11';
