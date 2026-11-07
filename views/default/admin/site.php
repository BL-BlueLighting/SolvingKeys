<?php
/** @var string $siteName */
/** @var string $siteLogo */
/** @var string $siteLogoUrl */
/** @var string $siteDescription */
/** @var string $footerText */
/** @var bool $registrationOpen */
/** @var int $uploadMaxMb */
?>
<div class="sk-page-head">
    <div>
        <div class="sk-kicker">Administration</div>
        <h1>站点设置</h1>
        <p class="sk-muted mb-0 mt-2">设置站点名称、Logo、描述、页脚文字与注册开关。</p>
    </div>
    <a class="btn btn-outline-secondary" href="<?= e(url('admin/users')) ?>">
        <i class="bi bi-people me-1"></i>用户管理
    </a>
</div>

<ul class="nav nav-pills gap-2 mb-4">
    <li class="nav-item">
        <a class="nav-link" href="<?= e(url('admin/users')) ?>">用户管理</a>
    </li>
    <li class="nav-item">
        <a class="nav-link active" href="<?= e(url('admin/site')) ?>">站点设置</a>
    </li>
</ul>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-body p-4">
                <form method="post" action="<?= e(url('admin/site')) ?>" enctype="multipart/form-data">
                    <?= csrf_field() ?>

                    <div class="mb-4">
                        <label class="form-label" for="site-name">站点名称</label>
                        <input class="form-control" id="site-name" name="site_name" type="text"
                               maxlength="64" required value="<?= e($siteName) ?>">
                        <div class="form-text">用于导航栏、浏览器标题、登录页与页脚。</div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">站点 Logo</label>
                        <div class="sk-logo-preview mb-2">
                            <?php if ($siteLogoUrl !== ''): ?>
                                <img src="<?= e($siteLogoUrl) ?>" alt="<?= e($siteName) ?> Logo"
                                     referrerpolicy="no-referrer">
                            <?php else: ?>
                                <div class="text-center sk-muted">
                                    <span class="sk-brand-mark mb-2"><i class="bi bi-key"></i></span>
                                    <div class="small">未设置 Logo，当前使用默认钥匙图标。</div>
                                </div>
                            <?php endif; ?>
                        </div>
                        <label class="form-label small" for="site-logo">Logo URL（或站内路径）</label>
                        <input class="form-control mb-2" id="site-logo" name="site_logo" type="text"
                               maxlength="2048" value="<?= e($siteLogo) ?>"
                               placeholder="https://example.com/logo.png 或 /uploads/site/logo.png">
                        <label class="form-label small" for="logo-file">上传新 Logo</label>
                        <input class="form-control" id="logo-file" name="logo_file" type="file"
                               accept="image/png,image/jpeg,image/webp,image/gif">
                        <div class="form-text">
                            支持 PNG / JPEG / WebP / GIF，最大 <?= e((string) $uploadMaxMb) ?> MB；上传文件会覆盖 URL 字段。
                            清空 URL 且不上传即可恢复默认图标。外部地址仅允许 HTTPS。
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label" for="site-description">站点描述</label>
                        <textarea class="form-control" id="site-description" name="site_description"
                                  maxlength="300" rows="3"><?= e($siteDescription) ?></textarea>
                        <div class="form-text">用于登录页简介与页面 meta description，最多 300 个字符。</div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label" for="footer-text">页脚文字</label>
                        <textarea class="form-control" id="footer-text" name="footer_text"
                                  maxlength="300" rows="2"><?= e($footerText) ?></textarea>
                        <div class="form-text">显示在每页页脚右侧，最多 300 个字符；留空则使用默认加密说明。</div>
                    </div>

                    <div class="mb-4">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="registration-open"
                                   name="registration_open" <?= $registrationOpen ? 'checked' : '' ?>>
                            <label class="form-check-label" for="registration-open">开放注册</label>
                        </div>
                        <div class="form-text">
                            关闭后登录页仍可访问，但注册入口和注册 API 会被禁用。
                            如果系统中还没有任何用户，第一个管理员仍可注册。
                        </div>
                    </div>

                    <button class="btn btn-primary" type="submit">
                        <i class="bi bi-check2 me-1"></i>保存站点设置
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <section class="sk-section">
            <h2><i class="bi bi-info-circle me-2"></i>说明</h2>
            <ul class="small mb-0">
                <li>站点名称会立即应用到所有页面标题与导航栏。</li>
                <li>上传的 Logo 保存在 <code>uploads/site/</code>，仅允许图片类型。</li>
                <li>外部 Logo 必须使用 HTTPS，以符合内容安全策略。</li>
                <li>注册开关对注册页面与 <code>POST /api/register</code> 同时生效。</li>
                <li>管理员无法读取任何 Keys 或聊天明文，站点设置不影响加密数据。</li>
            </ul>
        </section>
    </div>
</div>
