<?php
/** @var array $user */
/** @var string $avatarUrl */
/** @var bool $hasVault */
?>
<div class="sk-page-head">
    <div>
        <div class="sk-kicker">Settings</div>
        <h1>设置</h1>
        <p class="sk-muted mb-0 mt-2">管理个人资料、登录密码与本地/云端加密钥匙库。</p>
    </div>
    <a class="btn btn-outline-secondary" href="<?= e(url('')) ?>">
        <i class="bi bi-arrow-left me-1"></i>返回首页
    </a>
</div>

<div class="row g-4">
    <div class="col-lg-6">
        <section class="sk-section mb-4">
            <h2><i class="bi bi-person me-2"></i>个人资料</h2>
            <div class="d-flex align-items-center gap-3 mb-3">
                <img class="sk-avatar" src="<?= e($avatarUrl) ?>" alt="当前头像" width="72" height="72"
                     loading="lazy" referrerpolicy="no-referrer">
                <div class="small sk-muted">
                    头像支持 Gravatar、QQ 头像或自定义 URL。<br>
                    修改邮箱会同时改变 Gravatar 头像。
                </div>
            </div>
            <form method="post" action="<?= e(url('settings/profile')) ?>">
                <?= csrf_field() ?>
                <div class="mb-3">
                    <label class="form-label" for="settings-username">用户名</label>
                    <input class="form-control" id="settings-username" name="username" type="text"
                           maxlength="32" required value="<?= e($user['username']) ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="settings-email">邮箱</label>
                    <input class="form-control" id="settings-email" name="email" type="email"
                           maxlength="254" required value="<?= e($user['email']) ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="settings-avatar">头像</label>
                    <input class="form-control" id="settings-avatar" name="avatar" type="text"
                           maxlength="2048" value="<?= e($user['avatar']) ?>">
                    <div class="form-text">
                        <code>-1</code> 使用 Gravatar · <code>-2</code> 使用 QQ 头像（从 @qq.com 邮箱提取）·
                        <code>-2:123456789</code> 指定 QQ 号 · 或填写 <code>https://…</code> 图片链接。
                    </div>
                </div>
                <button class="btn btn-primary" type="submit">保存个人资料</button>
            </form>
        </section>

        <section class="sk-section">
            <h2><i class="bi bi-key me-2"></i>登录密码</h2>
            <p class="small sk-muted">登录密码使用 SM3 + 随机盐保存；与钥匙库的 16 位数字密码不同。</p>
            <form method="post" action="<?= e(url('settings/password')) ?>">
                <?= csrf_field() ?>
                <div class="mb-3">
                    <label class="form-label" for="current-password">当前密码</label>
                    <input class="form-control" id="current-password" name="current_password" type="password"
                           autocomplete="current-password" required>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="new-password">新密码</label>
                    <input class="form-control" id="new-password" name="new_password" type="password"
                           autocomplete="new-password" minlength="8" required>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="new-password-confirm">确认新密码</label>
                    <input class="form-control" id="new-password-confirm" name="new_password_confirm" type="password"
                           autocomplete="new-password" minlength="8" required>
                </div>
                <button class="btn btn-outline-primary" type="submit">更新登录密码</button>
            </form>
        </section>
    </div>

    <div class="col-lg-6">
        <section class="sk-section mb-4">
            <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                <h2 class="mb-0"><i class="bi bi-shield-lock me-2"></i>钥匙库</h2>
                <span id="vault-mode-badge" class="sk-tag <?= (int) $user['cloud_vault'] === 1 ? 'sk-tag-accent' : '' ?>">
                    <?= (int) $user['cloud_vault'] === 1 ? '云端钥匙库' : '本地钥匙库' ?>
                </span>
            </div>
            <p class="small sk-muted mb-2" id="vault-status-text">
                <?= $hasVault ? '钥匙库已加密保存。数字密码只用于浏览器本地解密，从不上传。' : '尚未创建钥匙库。创建后，Keys 的内容密钥会加密保存在这里。' ?>
            </p>
            <div class="small sk-muted mb-3" id="vault-key-count">
                <?= $hasVault ? '解锁后可查看已保存的密钥数量' : '' ?>
            </div>

            <div class="d-flex flex-wrap gap-2 mb-3">
                <button class="btn btn-sm btn-primary" type="button" id="vault-create" <?= $hasVault ? 'hidden' : '' ?>>
                    <i class="bi bi-plus-lg me-1"></i>创建钥匙库
                </button>
                <button class="btn btn-sm btn-outline-primary" type="button" id="vault-unlock" <?= $hasVault ? '' : 'hidden' ?>>
                    <i class="bi bi-unlock me-1"></i>解锁钥匙库
                </button>
                <button class="btn btn-sm btn-outline-secondary" type="button" id="vault-change-password" <?= $hasVault ? '' : 'hidden' ?>>
                    <i class="bi bi-pencil me-1"></i>修改数字密码
                </button>
            </div>

            <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" role="switch" id="vault-cloud-toggle"
                       <?= (int) $user['cloud_vault'] === 1 ? 'checked' : '' ?> <?= $hasVault ? '' : 'disabled' ?>>
                <label class="form-check-label" for="vault-cloud-toggle">使用云端保存加密钥匙库</label>
            </div>
            <div class="small sk-muted mb-2">
                云端只保存 AES-256-GCM 加密后的钥匙库（keys_key）。明文内容密钥与 16 位数字密码永远不会上传。
                关闭后仅保存在当前浏览器 localStorage 中。
            </div>
            <div class="small mt-2" id="vault-feedback" hidden></div>

            <div class="sk-callout sk-callout-warning mt-3">
                <i class="bi bi-exclamation-triangle me-1"></i>
                <strong>无法找回：</strong>如果你忘记了 16 位数字密码，钥匙库中的内容密钥将无法解密。
                请像对待主密码一样保存它。单个 Key 的 64 位内容密钥也可以单独备份。
            </div>
        </section>

        <section class="sk-section">
            <h2><i class="bi bi-info-circle me-2"></i>隐私模型</h2>
            <ul class="small mb-0">
                <li>登录密码由服务器使用 <strong>SM3 + 随机盐</strong>校验。</li>
                <li>Key 内容与项目消息在浏览器端使用 <strong>AES-256-GCM</strong> 加密，服务器只保存密文。</li>
                <li>钥匙库（Keys.id → 内容密钥）由你的 16 位数字密码派生密钥加密；服务器不保存该密码或其哈希。</li>
                <li>管理员可以管理账号与项目秩序，但无法读取任何 Keys 内容或聊天明文。</li>
            </ul>
        </section>
    </div>
</div>
