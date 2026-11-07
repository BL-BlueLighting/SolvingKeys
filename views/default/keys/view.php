<?php
/** @var array $key */
/** @var array $owner */
/** @var bool $isOwner */
$current = current_user();
$canWrite = $current !== null && user_can_write($current);
$ownerLabel = $isOwner ? '你' : $owner['username'];
?>
<div id="key-view-app" data-key-id="<?= e((string) $key['id']) ?>">
    <div class="sk-page-head">
        <div class="min-w-0">
            <div class="sk-kicker">Key #<?= e((string) $key['id']) ?></div>
            <h1 class="sk-ellipsis"><?= e($key['key_name']) ?></h1>
            <div class="sk-meta mt-2">
                <span>
                    <img class="sk-avatar sk-avatar-sm me-1" src="<?= e($owner['avatar_url']) ?>" alt="所有者头像"
                         width="28" height="28" loading="lazy" referrerpolicy="no-referrer">
                    <?= e($ownerLabel) ?>
                </span>
                <span><i class="bi bi-clock"></i>创建 <?= e(format_time($key['created_at'])) ?></span>
                <span><i class="bi bi-arrow-repeat"></i>更新 <span id="key-updated-at"><?= e(format_time($key['updated_at'])) ?></span></span>
                <span><i class="bi bi-layers"></i>版本 <span id="key-version"><?= e((string) $key['version']) ?></span></span>
                <span class="sk-tag sk-tag-accent"><i class="bi bi-lock"></i>AES-256-GCM</span>
            </div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-outline-secondary" href="<?= e(url('keys')) ?>">
                <i class="bi bi-arrow-left me-1"></i>返回
            </a>
            <?php if ($isOwner && $canWrite): ?>
                <a class="btn btn-primary" href="<?= e(url('keys/' . (int) $key['id'] . '/edit')) ?>">
                    <i class="bi bi-pencil me-1"></i>编辑
                </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="d-flex flex-wrap gap-2 mb-3">
        <button type="button" class="btn btn-sm btn-outline-primary" id="copy-key-button" data-requires-key disabled>
            <i class="bi bi-clipboard me-1"></i>复制密钥
        </button>
        <button type="button" class="btn btn-sm btn-outline-primary" id="share-key-button" data-requires-key disabled>
            <i class="bi bi-send me-1"></i>分享
        </button>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="lock-content-button" data-requires-key disabled>
            <i class="bi bi-lock me-1"></i>锁定本页
        </button>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="remove-vault-key-button">
            <i class="bi bi-key me-1"></i>从钥匙库移除
        </button>
        <?php if ($isOwner && $canWrite): ?>
            <button type="button" class="btn btn-sm btn-outline-danger" id="rotate-key-button" data-requires-key disabled>
                <i class="bi bi-arrow-repeat me-1"></i>轮换密钥
            </button>
            <button type="button" class="btn btn-sm btn-outline-danger" id="delete-key-button">
                <i class="bi bi-trash me-1"></i>删除
            </button>
        <?php endif; ?>
    </div>

    <div id="key-lock-panel" class="sk-locked">
        <div class="sk-locked-icon"><i class="bi bi-lock-fill"></i></div>
        <h2 class="h5">此 Key 的内容已加密</h2>
        <p class="sk-muted">
            输入 64 位十六进制内容密钥，或先解锁你的钥匙库。密钥只在浏览器中用于解密，不会发送到服务器。
        </p>
        <div class="d-flex flex-wrap gap-2 mb-3">
            <button type="button" class="btn btn-primary" id="unlock-vault-button">
                <i class="bi bi-shield-lock me-1"></i>解锁钥匙库
            </button>
        </div>
        <div class="row g-2 align-items-end">
            <div class="col-12 col-lg-8">
                <label class="form-label" for="key-input">内容密钥</label>
                <input class="form-control sk-key-value" id="key-input" type="text" maxlength="64"
                       placeholder="64 位十六进制，例如 3f2a…" autocomplete="off" spellcheck="false">
            </div>
            <div class="col-12 col-lg-4">
                <button type="button" class="btn btn-outline-primary w-100" id="key-submit">
                    使用密钥解锁
                </button>
            </div>
        </div>
        <div class="text-danger small mt-2" id="key-lock-error" hidden></div>
        <div class="small sk-muted mt-3">
            提示：首次输入密钥后，本标签页会记住它；如果保存到钥匙库，下次打开无需再次输入。
        </div>
    </div>

    <div id="key-content" hidden>
        <div class="d-flex flex-wrap gap-2 align-items-center mb-3" id="key-save-vault-wrap" hidden>
            <span class="sk-tag"><i class="bi bi-key"></i>此密钥尚未保存到钥匙库</span>
            <button class="btn btn-sm btn-outline-success" id="save-key-to-vault" type="button">
                保存到钥匙库
            </button>
        </div>

        <div class="sk-ref-banner mb-3" id="key-ref-banner" hidden>
            <div class="small fw-semibold mb-1" id="key-ref-line-title"></div>
            <code id="key-ref-line-text"></code>
        </div>

        <div id="key-sections"></div>

        <div class="sk-callout mt-3">
            <i class="bi bi-info-circle me-1"></i>
            提示：特殊语法 <code>@用户名</code> 提醒用户、<code>&amp;Key名称</code> 引用 Key、
            <code>&amp;^行号^Key</code> 引用某个 Key 的某一行、<code>$$LaTeX$$</code> 渲染公式。
        </div>
    </div>
</div>

<div class="modal fade" id="share-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h1 class="modal-title fs-5"><i class="bi bi-send me-2"></i>分享此 Key</h1>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="关闭"></button>
            </div>
            <div class="modal-body">
                <div class="sk-callout sk-callout-warning mb-3">
                    分享链接的 <code>#key=…</code> 片段包含明文内容密钥。
                    任何获得链接的人都能解密此 Key；请只发送给你信任的人。片段不会发送到服务器。
                </div>
                <label class="form-label" for="share-link-value">分享链接（含密钥）</label>
                <div class="sk-share-row mb-3">
                    <input class="form-control" id="share-link-value" type="text" readonly>
                    <button class="btn btn-outline-primary" id="copy-share-link" type="button">复制链接</button>
                </div>
                <label class="form-label" for="share-key-value">内容密钥</label>
                <div class="sk-share-row">
                    <input class="form-control" id="share-key-value" type="text" readonly>
                    <button class="btn btn-outline-primary" id="copy-share-key" type="button">复制密钥</button>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">关闭</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="new-key-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h1 class="modal-title fs-5"><i class="bi bi-arrow-repeat me-2"></i>新的内容密钥</h1>
            </div>
            <div class="modal-body">
                <div class="sk-callout sk-callout-warning mb-3" id="new-key-warning">
                    旧密钥已失效，请立即保存新密钥。
                </div>
                <div class="sk-key-value mb-3" id="new-key-value"></div>
                <div class="d-flex flex-wrap gap-2">
                    <button type="button" class="btn btn-outline-primary btn-sm" id="copy-new-key">
                        <i class="bi bi-clipboard me-1"></i>复制新密钥
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="download-new-key">
                        <i class="bi bi-download me-1"></i>下载新密钥
                    </button>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-primary" type="button" data-bs-dismiss="modal">我已保存</button>
            </div>
        </div>
    </div>
</div>
