<?php
$user = current_user();
$canWrite = $user !== null && user_can_write($user);
?>
<div class="sk-page-head">
    <div>
        <div class="sk-kicker">新建</div>
        <h1>创建项目</h1>
        <p class="sk-muted mb-0 mt-2">项目默认私密。公开需要全体成员同意。</p>
    </div>
    <a class="btn btn-outline-secondary" href="<?= e(url('projects')) ?>">
        <i class="bi bi-arrow-left me-1"></i>返回
    </a>
</div>

<?php if (!$canWrite): ?>
    <div class="alert alert-warning">账号处于只读状态，无法创建项目。</div>
<?php else: ?>
    <div class="row justify-content-center">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-body p-4">
                    <form method="post" action="<?= e(url('projects')) ?>">
                        <?= csrf_field() ?>
                        <div class="mb-4">
                            <label class="form-label" for="project-name">项目名称</label>
                            <input class="form-control form-control-lg" id="project-name" name="project_name"
                                   type="text" maxlength="120" required autofocus placeholder="例如：午夜图书馆谜题">
                        </div>
                        <div class="sk-callout mb-4">
                            <strong><i class="bi bi-people me-1"></i>项目如何工作</strong>
                            <ul class="mb-0 mt-2 small">
                                <li>只有你拥有的 Key 才能由你加入项目。</li>
                                <li>聊天记录使用项目内某个 Key 的内容密钥加密。</li>
                                <li>将项目公开需要全体成员逐一点头同意。</li>
                            </ul>
                        </div>
                        <div class="d-flex gap-2">
                            <button class="btn btn-primary" type="submit">
                                <i class="bi bi-folder-plus me-1"></i>创建项目
                            </button>
                            <a class="btn btn-outline-secondary" href="<?= e(url('projects')) ?>">取消</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>
