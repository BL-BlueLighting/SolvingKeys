<?php
/** @var string $oldUsername */
/** @var bool $registerOpen */
/** @var bool $noUsers */
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
                <span class="sk-brand-mark mb-3"><i class="bi bi-key"></i></span>
            <?php endif; ?>
            <h1>登录 <?= e($authSiteName) ?></h1>
            <p class="sk-muted mb-0"><?= e(site_description()) ?></p>
        </div>

        <?php if ($noUsers): ?>
            <div class="alert alert-info">
                系统尚无用户。第一个注册的账号将自动成为管理员。
            </div>
        <?php endif; ?>

        <form method="post" action="<?= e(url('login')) ?>">
            <?= csrf_field() ?>
            <div class="mb-3">
                <label class="form-label" for="username">用户名</label>
                <input class="form-control" id="username" name="username" type="text" required
                       autocomplete="username" value="<?= e($oldUsername) ?>" autofocus>
            </div>
            <div class="mb-3">
                <label class="form-label" for="password">登录密码</label>
                <div class="input-group">
                    <input class="form-control" id="password" name="password" type="password" required
                           autocomplete="current-password">
                    <button class="btn btn-outline-secondary" type="button" data-toggle-password data-target="#password"
                            aria-label="显示密码">
                        <i class="bi bi-eye"></i>
                    </button>
                </div>
            </div>
            <button class="btn btn-primary w-100" type="submit">登录</button>
        </form>

        <div class="sk-auth-foot">
            <?php if ($registerOpen): ?>
                还没有账号？<a href="<?= e(url('register')) ?>">注册</a>
            <?php else: ?>
                当前未开放注册。
            <?php endif; ?>
        </div>
    </div>
</div>
