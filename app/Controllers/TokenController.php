<?php
/**
 * 开放 API 令牌管理（登录态；令牌仅当前用户可见可用）
 */
declare(strict_types=1);

namespace Wstat\Controllers;

use Wstat\Http\Request;
use Wstat\Support\Auth;
use Wstat\Support\PAT;

class TokenController
{
    /** GET /api/tokens  当前用户令牌列表（脱敏） */
    public function index(Request $req): void
    {
        $u = Auth::requireUser($req);
        wstat_json(['items' => PAT::listOf((int) $u['id'])]);
    }

    /** POST /api/tokens  创建令牌 {name, expires_in_days?} → 明文 token 仅返回一次 */
    public function store(Request $req): void
    {
        $u = Auth::requireUser($req);
        $name = trim((string) $req->input('name', ''));
        if ($name === '' || mb_strlen($name) > 60) {
            wstat_err('令牌名称必填且不超过 60 字', 422);
        }
        $days = (int) $req->input('expires_in_days', 0);
        if ($days < 0 || $days > 3650) {
            wstat_err('有效期应为 0（永久）~3650 天', 422);
        }
        $expiresAt = $days > 0 ? time() + $days * 86400 : 0;
        $token = PAT::issue((int) $u['id'], $name, $expiresAt);
        wstat_json([
            'token'      => $token,
            'expires_at' => $expiresAt,
            'hint'       => '令牌仅此一次完整显示，请立即复制保存',
        ]);
    }

    /** DELETE /api/tokens/{id}  撤销令牌 */
    public function destroy(Request $req): void
    {
        $u = Auth::requireUser($req);
        $id = (int) $req->param('id');
        if (!PAT::revoke((int) $u['id'], $id)) {
            wstat_err('令牌不存在', 404, 404);
        }
        wstat_json(['ok' => true]);
    }
}
