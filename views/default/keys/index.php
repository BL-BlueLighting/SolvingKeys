<?php
/** @var array $keys */
$user = current_user();
$canWrite = $user !== null && user_can_write($user);
?>
<div class="sk-page-head">
    <div>
        <div class="sk-kicker">Keys</div>
        <h1>我的 Keys</h1>
        <p class="sk-muted mb-0 mt-2">每个 Key 使用独立的 64 位十六进制内容密钥，服务端只保存 AES-256 密文。</p>
    </div>
    <?php if ($canWrite): ?>
        <a class="btn btn-primary" href="<?= e(url('keys/new')) ?>">
            <i class="bi bi-plus-lg me-1"></i>新建 Key
        </a>
    <?php else: ?>
        <span class="sk-tag sk-tag-danger"><i class="bi bi-shield-lock"></i>只读状态</span>
    <?php endif; ?>
</div>

<?php if (!$keys): ?>
    <div class="sk-empty">
        <i class="bi bi-key"></i>
        <h2 class="h5">还没有 Key</h2>
        <p class="mb-3">Key 是 SolvingKeys 中存放加密内容的基本单位。</p>
        <?php if ($canWrite): ?>
            <a class="btn btn-primary" href="<?= e(url('keys/new')) ?>">创建第一个 Key</a>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="row g-3">
        <?php foreach ($keys as $key): ?>
            <div class="col-md-6 col-xl-4">
                <a class="sk-card-link" href="<?= e(url('keys/' . (int) $key['id'])) ?>">
                    <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                        <span class="sk-tag sk-tag-accent"><i class="bi bi-lock"></i>加密</span>
                        <span class="sk-tag">v<?= e((string) $key['version']) ?></span>
                    </div>
                    <div class="sk-card-title sk-ellipsis"><?= e($key['key_name']) ?></div>
                    <div class="sk-meta mt-2">
                        <span><i class="bi bi-hash"></i><?= e((string) $key['id']) ?></span>
                        <span><i class="bi bi-clock"></i><?= e(format_time($key['updated_at'])) ?></span>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
