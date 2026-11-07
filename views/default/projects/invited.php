<?php
/** @var array $project */
/** @var array $invitation */
/** @var array|null $owner */
/** @var bool $canWrite */
?>
<div class="sk-page-head">
    <div>
        <div class="sk-kicker">项目邀请</div>
        <h1><?= e($project['project_name']) ?></h1>
        <p class="sk-muted mb-0 mt-2">你被邀请加入这个私密项目。</p>
    </div>
    <a class="btn btn-outline-secondary" href="<?= e(url('projects')) ?>">
        <i class="bi bi-arrow-left me-1"></i>返回项目
    </a>
</div>

<div class="row justify-content-center">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-body p-4 text-center">
                <?php if ($owner !== null): ?>
                    <img class="sk-avatar mb-3" src="<?= e($owner['avatar_url']) ?>" alt="所有者头像"
                         width="72" height="72" loading="lazy" referrerpolicy="no-referrer">
                    <div class="sk-muted mb-1">项目所有者</div>
                    <div class="h5"><?= e($owner['username']) ?></div>
                <?php endif; ?>
                <p class="mt-3">接受邀请后，你将能看到项目成员、项目内的 Keys 与加密聊天。</p>
                <p class="small sk-muted">
                    项目内的 Key 内容仍需对应的内容密钥才能解密；聊天需要项目的消息密钥。
                </p>
                <?php if ($canWrite): ?>
                    <form method="post"
                          action="<?= e(url('projects/' . (int) $project['id'] . '/invitations/' . (int) $invitation['id'])) ?>"
                          class="d-flex justify-content-center gap-2">
                        <?= csrf_field() ?>
                        <button class="btn btn-primary" type="submit" name="decision" value="accept">
                            <i class="bi bi-check-lg me-1"></i>接受邀请
                        </button>
                        <button class="btn btn-outline-secondary" type="submit" name="decision" value="decline">
                            拒绝
                        </button>
                    </form>
                <?php else: ?>
                    <div class="alert alert-warning mb-0">账号处于只读状态，无法接受邀请。</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
