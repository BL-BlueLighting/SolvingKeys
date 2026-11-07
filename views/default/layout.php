<?php
/** @var string $content */
/** @var string $pageTitle */
/** @var string|null $pageEntry */
$user = current_user();
$unread = unread_notification_count();
$themeCookie = (string) ($_COOKIE['sk_theme'] ?? '');
$initialTheme = $themeCookie === 'dark' ? 'dark' : 'light';
$flashSuccess = flash('success');
$flashError = flash('error');
$siteName = site_name();
$siteLogo = site_logo_url();
$siteDescription = site_description();
$siteFooter = site_footer_text();
?>
<!doctype html>
<html lang="zh-CN"
      data-bs-theme="<?= e($initialTheme) ?>"
      data-base-url="<?= e(base_path()) ?>"
      data-csrf="<?= e(csrf_token()) ?>"
      data-app-name="<?= e(config('app.name', 'SolvingKeys')) ?>"
      data-vditor-cdn="<?= e(url('assets/vendor/vditor')) ?>"
      data-vault-iterations="<?= e((string) config('security.vault_iterations', 310000)) ?>"
      <?php if ($user !== null): ?>
      data-user-id="<?= e((string) $user['id']) ?>"
      data-username="<?= e($user['username']) ?>"
      data-permission="<?= e($user['permission']) ?>"
      data-banned="<?= e((string) ($user['ban'] ?? 0)) ?>"
      data-cloud-vault="<?= e((string) ($user['cloud_vault'] ?? 0)) ?>"
      <?php endif; ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="<?= e($siteName . ' — ' . $siteDescription) ?>">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <link rel="icon" type="image/svg+xml" href="<?= e(url('assets/favicon.svg')) ?>">
    <title><?= e($pageTitle ?? config('app.name')) ?></title>
    <script src="<?= e(url('assets/js/console-warning.js')) ?>"></script>
    <?= vite_asset_tags('src/app.js') ?>
    <?php if (!empty($pageEntry)): ?>
        <?= vite_asset_tags((string) $pageEntry) ?>
    <?php endif; ?>
</head>
<body class="<?= e($bodyClass ?? '') ?>">
<nav class="navbar navbar-expand-lg sk-navbar">
    <div class="container sk-container">
        <a class="navbar-brand d-flex align-items-center" href="<?= e(url('')) ?>">
            <?php if ($siteLogo !== ''): ?>
                <img class="sk-site-logo" src="<?= e($siteLogo) ?>" alt="<?= e($siteName) ?>"
                     referrerpolicy="no-referrer">
            <?php else: ?>
                <span class="sk-brand-mark"><i class="bi bi-key"></i></span>
            <?php endif; ?>
            <span><?= e($siteName) ?></span>
        </a>
        <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#sk-navbar"
                aria-controls="sk-navbar" aria-expanded="false" aria-label="切换导航">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="sk-navbar">
            <?php if ($user !== null): ?>
                <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                    <li class="nav-item">
                        <a class="nav-link <?= is_current('') ? 'active' : '' ?>" href="<?= e(url('')) ?>">首页</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= is_current('keys') ? 'active' : '' ?>" href="<?= e(url('keys')) ?>">我的 Keys</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= is_current('projects') ? 'active' : '' ?>" href="<?= e(url('projects')) ?>">项目</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= is_current('notifications') ? 'active' : '' ?>" href="<?= e(url('notifications')) ?>">
                            通知
                            <span class="badge rounded-pill text-bg-danger ms-1" data-notification-badge
                                  data-count="<?= e((string) $unread) ?>" <?= $unread > 0 ? '' : 'hidden' ?>><?= $unread > 99 ? '99+' : e((string) $unread) ?></span>
                        </a>
                    </li>
                    <?php if (User::isAdmin($user)): ?>
                        <li class="nav-item">
                            <a class="nav-link <?= (request_path() === 'admin' || is_current('admin/users')) ? 'active' : '' ?>"
                               href="<?= e(url('admin/users')) ?>">用户管理</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?= is_current('admin/site') ? 'active' : '' ?>"
                               href="<?= e(url('admin/site')) ?>">站点设置</a>
                        </li>
                    <?php endif; ?>
                </ul>
            <?php endif; ?>
            <div class="d-flex align-items-center gap-2 ms-auto">
                <button type="button" class="btn btn-sm btn-outline-secondary rounded-circle" data-theme-toggle
                        style="width: 34px; height: 34px;" aria-label="切换主题" title="切换主题">
                    <i class="bi <?= $initialTheme === 'dark' ? 'bi-sun' : 'bi-moon-stars' ?>"></i>
                </button>
                <?php if ($user !== null): ?>
                    <div class="dropdown">
                        <button class="btn btn-sm btn-outline-secondary d-flex align-items-center gap-2 dropdown-toggle"
                                type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <img class="sk-avatar sk-avatar-sm" src="<?= e(avatar_url($user, 64)) ?>" alt="头像"
                                 width="28" height="28" loading="lazy" referrerpolicy="no-referrer">
                            <span class="d-none d-sm-inline"><?= e($user['username']) ?></span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end sk-dropdown-menu">
                            <li><h6 class="dropdown-header">@<?= e($user['username']) ?></h6></li>
                            <?php if (User::isBanned($user)): ?>
                                <li><span class="dropdown-item-text small text-warning">账号处于只读状态</span></li>
                            <?php endif; ?>
                            <li><a class="dropdown-item" href="<?= e(url('settings')) ?>"><i class="bi bi-gear me-2"></i>设置与钥匙库</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <form method="post" action="<?= e(url('logout')) ?>" data-logout class="px-3 py-1">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-sm btn-outline-danger w-100">
                                        <i class="bi bi-box-arrow-right me-1"></i>退出登录
                                    </button>
                                </form>
                            </li>
                        </ul>
                    </div>
                <?php else: ?>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('login')) ?>">登录</a>
                    <a class="btn btn-sm btn-primary" href="<?= e(url('register')) ?>">注册</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>

<?php if (vite_assets_missing()): ?>
    <div class="container sk-container mt-3">
        <div class="alert alert-warning mb-0">
            <strong>前端资源尚未构建。</strong>
            请在 <code>frontend/</code> 目录运行 <code>npm install &amp;&amp; npm run build</code>。
        </div>
    </div>
<?php endif; ?>

<?php if ($user !== null && User::isBanned($user)): ?>
    <div class="container sk-container mt-3">
        <div class="alert alert-warning mb-0 d-flex align-items-center gap-2">
            <i class="bi bi-shield-lock"></i>
            你的账号当前为只读状态：可以查看内容，但不能创建或修改 Keys、项目与消息。
        </div>
    </div>
<?php endif; ?>

<?php if ($flashSuccess !== null || $flashError !== null): ?>
    <div class="container sk-container mt-3">
        <?php if ($flashSuccess !== null): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert" data-autohide>
                <?= e($flashSuccess) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="关闭"></button>
            </div>
        <?php endif; ?>
        <?php if ($flashError !== null): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?= e($flashError) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="关闭"></button>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<main class="sk-page">
    <div class="container sk-container">
        <?= $content ?>
    </div>
</main>

<footer class="sk-footer">
    <div class="container sk-container d-flex flex-wrap justify-content-between gap-2">
        <span><?= e($siteName) ?> v<?= e(config('app.version', '1.0.0')) ?> · <?= e(config('app.author', 'BL.BlueLighting')) ?></span>
        <span><i class="bi bi-shield-lock me-1"></i><?= e($siteFooter) ?></span>
    </div>
</footer>
</body>
</html>
