<?php
$user = current_user();
$canWrite = $user !== null && user_can_write($user);
?>
<div class="sk-page-head">
    <div>
        <div class="sk-kicker">新建</div>
        <h1>创建一个 Key</h1>
        <p class="sk-muted mb-0 mt-2">创建时会由浏览器生成随机的 AES-256 内容密钥，并立即显示一次。</p>
    </div>
    <a class="btn btn-outline-secondary" href="<?= e(url('keys')) ?>">
        <i class="bi bi-arrow-left me-1"></i>返回
    </a>
</div>

<?php if (!$canWrite): ?>
    <div class="alert alert-warning">账号处于只读状态，无法创建 Key。</div>
<?php else: ?>
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-body p-4">
                    <div id="key-create-error" class="alert alert-danger" hidden></div>
                    <form id="key-create-form" novalidate>
                        <div class="mb-3">
                            <label class="form-label" for="key-name">Key 名称</label>
                            <input class="form-control form-control-lg" id="key-name" name="key_name"
                                   type="text" maxlength="120" required placeholder="例如：第四章 · 暗号本" autofocus>
                            <div class="form-text">名称不加密，会显示在链接、项目列表与 &amp; 引用中。</div>
                        </div>
                        <div class="sk-callout mb-4">
                            <strong><i class="bi bi-key me-1"></i>内容密钥只显示一次</strong><br>
                            创建后会生成一个 64 位十六进制的 Key 内容密钥。它不会以明文形式保存到服务器；
                            如果丢失，任何人都无法恢复加密内容。
                        </div>
                        <div class="d-flex gap-2">
                            <button class="btn btn-primary" type="submit" id="key-create-submit">
                                <i class="bi bi-shield-lock me-1"></i>生成密钥并创建
                            </button>
                            <a class="btn btn-outline-secondary" href="<?= e(url('keys')) ?>">取消</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="key-created-modal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h1 class="modal-title fs-5"><i class="bi bi-check-circle text-success me-2"></i>Key 已创建</h1>
                </div>
                <div class="modal-body">
                    <div class="sk-callout sk-callout-warning mb-3">
                        <strong>请立即保存下面的内容密钥。</strong><br>
                        这是解密此 Key 的唯一凭证。服务器不保存明文密钥，丢失后无法找回。
                        你可以将它保存在密码管理器中，或下载为文本文件。
                    </div>
                    <label class="form-label">内容密钥（64 位十六进制）</label>
                    <div class="sk-key-value mb-2" id="created-key-value"></div>
                    <div class="d-flex flex-wrap gap-2 mb-3">
                        <button type="button" class="btn btn-outline-primary btn-sm" id="copy-created-key">
                            <i class="bi bi-clipboard me-1"></i>复制密钥
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="download-created-key">
                            <i class="bi bi-download me-1"></i>下载密钥
                        </button>
                        <button type="button" class="btn btn-outline-success btn-sm" id="save-created-key">
                            保存到钥匙库
                        </button>
                    </div>
                    <div class="small" id="created-key-save-state"></div>
                </div>
                <div class="modal-footer">
                    <a class="btn btn-primary" id="continue-to-edit" href="<?= e(url('keys')) ?>">
                        我已安全保存，开始编辑
                    </a>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>
