<?php
/** @var array $key */
?>
<div id="key-edit-app" data-key-id="<?= e((string) $key['id']) ?>">
    <div class="sk-page-head">
        <div class="min-w-0">
            <div class="sk-kicker">编辑 Key #<?= e((string) $key['id']) ?></div>
            <h1 class="sk-ellipsis" id="key-name-heading"><?= e($key['key_name']) ?></h1>
            <div class="sk-meta mt-2">
                <span><i class="bi bi-layers"></i>版本 <span id="key-version"><?= e((string) $key['version']) ?></span></span>
                <span class="sk-tag sk-tag-accent"><i class="bi bi-lock"></i>浏览器端 AES-256-GCM</span>
            </div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-outline-secondary" href="<?= e(url('keys/' . (int) $key['id'])) ?>">
                <i class="bi bi-eye me-1"></i>查看
            </a>
            <button class="btn btn-outline-secondary" type="button" id="rename-key-button">
                <i class="bi bi-input-cursor-text me-1"></i>重命名
            </button>
        </div>
    </div>

    <div class="d-flex flex-wrap gap-2 mb-3">
        <button class="btn btn-sm btn-outline-primary" type="button" id="copy-key-button">
            <i class="bi bi-clipboard me-1"></i>复制密钥
        </button>
        <button class="btn btn-sm btn-outline-primary" type="button" id="share-key-button">
            <i class="bi bi-send me-1"></i>分享
        </button>
        <button class="btn btn-sm btn-outline-success" type="button" id="save-key-to-vault" hidden>
            保存密钥到钥匙库
        </button>
        <button class="btn btn-sm btn-outline-danger" type="button" id="rotate-key-button">
            <i class="bi bi-arrow-repeat me-1"></i>轮换密钥
        </button>
        <button class="btn btn-sm btn-outline-danger" type="button" id="delete-key-button">
            <i class="bi bi-trash me-1"></i>删除 Key
        </button>
    </div>

    <div id="edit-lock-panel" class="sk-locked">
        <div class="sk-locked-icon"><i class="bi bi-lock-fill"></i></div>
        <h2 class="h5">解锁后才能编辑</h2>
        <p class="sk-muted">内容密钥不会上传。输入 64 位十六进制密钥，或先解锁钥匙库。</p>
        <div class="d-flex flex-wrap gap-2 mb-3">
            <button type="button" class="btn btn-primary" id="edit-unlock-vault">
                <i class="bi bi-shield-lock me-1"></i>解锁钥匙库
            </button>
        </div>
        <div class="row g-2 align-items-end">
            <div class="col-12 col-lg-8">
                <label class="form-label" for="edit-key-input">内容密钥</label>
                <input class="form-control sk-key-value" id="edit-key-input" type="text" maxlength="64"
                       placeholder="64 位十六进制" autocomplete="off" spellcheck="false">
            </div>
            <div class="col-12 col-lg-4">
                <button type="button" class="btn btn-outline-primary w-100" id="edit-key-submit">使用密钥解锁</button>
            </div>
        </div>
        <div class="text-danger small mt-2" id="edit-lock-error" hidden></div>
    </div>

    <div id="edit-shell" hidden>
        <div class="alert alert-danger d-flex flex-wrap align-items-center justify-content-between gap-2" id="conflict-banner" hidden>
            <span><i class="bi bi-exclamation-triangle me-1"></i>内容已在其他位置被修改。请先复制你的更改，再重新加载。</span>
            <button class="btn btn-sm btn-outline-danger" type="button" id="reload-conflict">重新加载</button>
        </div>

        <div class="row g-4">
            <div class="col-lg-3">
                <div class="card sk-sidebar">
                    <div class="card-body">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <h2 class="h6 mb-0">段落</h2>
                            <button class="btn btn-sm btn-primary" type="button" id="add-section">
                                <i class="bi bi-plus-lg"></i>
                            </button>
                        </div>
                        <div class="sk-section-list" id="section-list"></div>
                        <div class="small sk-muted py-3" id="section-empty">
                            还没有段落。点击 + 添加第一段。
                        </div>
                        <div class="sk-divider"></div>
                        <div class="d-flex flex-wrap gap-2">
                            <button class="btn btn-sm btn-outline-secondary" type="button" id="move-section-up"
                                    title="上移当前段落"><i class="bi bi-arrow-up"></i></button>
                            <button class="btn btn-sm btn-outline-secondary" type="button" id="move-section-down"
                                    title="下移当前段落"><i class="bi bi-arrow-down"></i></button>
                            <button class="btn btn-sm btn-outline-danger" type="button" id="delete-section">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-9">
                <div class="mb-3">
                    <label class="form-label" for="section-title">段落标题</label>
                    <input class="form-control" id="section-title" type="text" maxlength="200" placeholder="段落标题">
                </div>

                <div class="sk-editor-toolbar">
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <span class="small sk-muted me-1">编辑器</span>
                        <div class="btn-group sk-mode-switch" id="editor-modes" role="group" aria-label="选择编辑器"></div>
                    </div>
                    <button class="btn btn-sm btn-outline-secondary" type="button" id="preview-toggle">
                        <i class="bi bi-eye me-1"></i>预览
                    </button>
                </div>

                <div id="editor-mount"></div>

                <div class="sk-preview-panel mt-3" id="preview-panel" hidden>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h2 class="h6 mb-0" id="preview-title">预览</h2>
                        <span class="sk-tag">只读</span>
                    </div>
                    <div class="sk-markdown" id="preview-content"></div>
                </div>

                <div class="sk-save-bar mt-4">
                    <div class="small">
                        <span id="save-state" class="sk-save-state sk-muted">已保存</span>
                        <span class="sk-muted ms-2">Ctrl/⌘ + S 保存</span>
                    </div>
                    <button class="btn btn-primary" type="button" id="save-button" disabled>
                        <i class="bi bi-cloud-arrow-up me-1"></i>保存并加密
                    </button>
                </div>

                <div class="sk-callout mt-3">
                    支持 <code>@用户名</code>、<code>&amp;Key名称</code>、<code>&amp;^行号^Key</code> 与
                    <code>$$LaTeX$$</code>。切换编辑器时内容会以 Markdown 同步；复杂语法可能被块编辑器轻微重排。
                </div>
            </div>
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
                    分享链接的 <code>#key=…</code> 片段包含明文内容密钥。只发送给你信任的人。
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
                <div class="sk-callout sk-callout-warning mb-3" id="new-key-warning">旧密钥已失效，请立即保存新密钥。</div>
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
