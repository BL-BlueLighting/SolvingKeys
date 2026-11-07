<?php
/** @var bool $registrationOpen */
/** @var bool $firstUser */
$authSiteName = site_name();
$authLogo = site_logo_url();
?>
<div class="sk-auth-wrap">
    <div class="sk-auth-card">
        <div class="mb-4">
            <?php if ($authLogo !== ''): ?>
                <img class="sk-auth-logo" src="<?= e($authLogo) ?>" alt="<?= e($authSiteName) ?>"
                     referrerpolicy="no-referrer">
            <?php else: ?>
                <span class="sk-brand-mark mb-3"><i class="bi bi-person-plus"></i></span>
            <?php endif; ?>
            <h1>创建 <?= e($authSiteName) ?> 账号</h1>
            <p class="sk-muted mb-0">
                <?= e(site_description()) ?> 登录密码使用 SM3 + 盐保存；Key 内容密钥与钥匙库由你的浏览器使用 AES-256 加密。
            </p>
        </div>

        <?php if (!$registrationOpen): ?>
            <div class="alert alert-warning">当前未开放注册。</div>
        <?php endif; ?>

        <?php if ($firstUser): ?>
            <div class="alert alert-info">这将是系统中的第一个账号，会自动获得管理员权限。</div>
        <?php endif; ?>

        <div id="register-error" class="alert alert-danger" hidden></div>

        <form id="register-form" novalidate>
            <div class="row g-3">
                <div class="col-12 col-sm-6">
                    <label class="form-label" for="username">用户名 / 昵称</label>
                    <input class="form-control" id="username" name="username" type="text" required
                           autocomplete="username" placeholder="2–32 个文字、数字、_ 或 -">
                    <div class="form-text">@提及会使用这个名称。</div>
                </div>
                <div class="col-12 col-sm-6">
                    <label class="form-label" for="email">邮箱</label>
                    <input class="form-control" id="email" name="email" type="email" required
                           autocomplete="email" placeholder="you@example.com">
                    <div class="form-text">用于 Gravatar 头像，不会公开显示。</div>
                </div>

                <div class="col-12 col-sm-6">
                    <label class="form-label" for="password">登录密码</label>
                    <input class="form-control" id="password" name="password" type="password" required
                           autocomplete="new-password" minlength="8">
                </div>
                <div class="col-12 col-sm-6">
                    <label class="form-label" for="password-confirm">确认登录密码</label>
                    <input class="form-control" id="password-confirm" name="password_confirm" type="password"
                           required autocomplete="new-password" minlength="8">
                </div>

                <div class="col-12">
                    <label class="form-label" for="avatar-mode">头像</label>
                    <select class="form-select" id="avatar-mode" name="avatar_mode">
                        <option value="gravatar">Gravatar（使用邮箱）</option>
                        <option value="qq">QQ 头像（q1.qlogo.cn）</option>
                        <option value="custom">自定义图片链接</option>
                    </select>
                </div>
                <div class="col-12" id="avatar-qq-wrap" hidden>
                    <label class="form-label" for="avatar-qq">QQ 号</label>
                    <input class="form-control" id="avatar-qq" name="avatar_qq" type="text"
                           inputmode="numeric" placeholder="例如 123456789">
                    <div class="form-text">如果留空，会尝试从 @qq.com 邮箱中提取 QQ 号。</div>
                </div>
                <div class="col-12" id="avatar-url-wrap" hidden>
                    <label class="form-label" for="avatar-url">图片链接</label>
                    <input class="form-control" id="avatar-url" name="avatar_url" type="url"
                           placeholder="https://example.com/avatar.png">
                </div>

                <div class="col-12">
                    <div class="sk-divider"></div>
                    <div class="sk-callout">
                        <strong><i class="bi bi-shield-lock me-1"></i>钥匙库数字密码</strong><br>
                        这是你自己设置的 <strong>16 位十进制数字</strong>密码，用来加密你的钥匙库（keys_key）。
                        它只在浏览器中使用，<strong>绝不上传</strong>，服务器也不保存其哈希。
                        忘记后，钥匙库中的内容密钥将无法恢复。
                    </div>
                </div>

                <div class="col-12 col-sm-6">
                    <label class="form-label" for="vault-password">16 位数字密码</label>
                    <input class="form-control sk-numeric-input" id="vault-password" name="vault_password"
                           type="password" inputmode="numeric" maxlength="16" autocomplete="new-password"
                           placeholder="0000000000000000" required>
                </div>
                <div class="col-12 col-sm-6">
                    <label class="form-label" for="vault-password-confirm">确认数字密码</label>
                    <input class="form-control sk-numeric-input" id="vault-password-confirm"
                           name="vault_password_confirm" type="password" inputmode="numeric" maxlength="16"
                           autocomplete="new-password" required>
                </div>

                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="cloud-vault" name="cloud_vault" checked>
                        <label class="form-check-label" for="cloud-vault">
                            使用云端保存加密后的钥匙库（推荐）
                        </label>
                        <div class="form-text">
                            关闭后，加密钥匙库只会保存在当前浏览器的 localStorage 中；清理浏览器数据会丢失它。
                        </div>
                    </div>
                </div>

                <div class="col-12">
                    <button class="btn btn-primary w-100" type="submit" id="register-submit"
                            <?= $registrationOpen ? '' : 'disabled' ?>>
                        创建账号并生成钥匙库
                    </button>
                </div>
            </div>
        </form>

        <div class="sk-auth-foot">
            已有账号？<a href="<?= e(url('login')) ?>">登录</a>
        </div>
    </div>
</div>
