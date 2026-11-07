<?php
/** @var array $projects */
/** @var array $publicProjects */
/** @var array $invitations */
$user = current_user();
$canWrite = $user !== null && user_can_write($user);

$ownerOf = static function (int $ownerId): ?array {
    $owner = User::find($ownerId);
    return $owner === null ? null : public_user($owner);
};
?>
<div class="sk-page-head">
    <div>
        <div class="sk-kicker">Projects</div>
        <h1>项目</h1>
        <p class="sk-muted mb-0 mt-2">用项目聚合 Keys、成员和端到端加密的聊天记录。</p>
    </div>
    <?php if ($canWrite): ?>
        <a class="btn btn-primary" href="<?= e(url('projects/new')) ?>">
            <i class="bi bi-folder-plus me-1"></i>新建项目
        </a>
    <?php else: ?>
        <span class="sk-tag sk-tag-danger"><i class="bi bi-shield-lock"></i>只读状态</span>
    <?php endif; ?>
</div>

<?php if ($invitations): ?>
    <section class="mb-4">
        <h2 class="h5 mb-3">待处理邀请</h2>
        <div class="row g-3">
            <?php foreach ($invitations as $invitation): ?>
                <div class="col-md-6">
                    <div class="sk-invite h-100">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <img class="sk-avatar" src="<?= e($invitation['inviter']['avatar_url']) ?>" alt="邀请者头像"
                                 width="32" height="32" loading="lazy" referrerpolicy="no-referrer">
                            <div class="small">
                                <strong><?= e($invitation['inviter']['username']) ?></strong> 邀请你加入
                                <span class="fw-semibold"><?= e($invitation['project_name']) ?></span>
                            </div>
                        </div>
                        <?php if ($canWrite): ?>
                            <form method="post"
                                  action="<?= e(url('projects/' . (int) $invitation['project_id'] . '/invitations/' . (int) $invitation['id'])) ?>"
                                  class="d-flex gap-2">
                                <?= csrf_field() ?>
                                <button class="btn btn-sm btn-primary" type="submit" name="decision" value="accept">接受</button>
                                <button class="btn btn-sm btn-outline-secondary" type="submit" name="decision" value="decline">拒绝</button>
                            </form>
                        <?php else: ?>
                            <span class="small sk-muted">只读状态下无法接受邀请。</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<section class="mb-5">
    <h2 class="h5 mb-3">我参与的项目</h2>
    <?php if (!$projects): ?>
        <div class="sk-empty">
            <i class="bi bi-folder2-open"></i>
            <h3 class="h6">还没有项目</h3>
            <p class="mb-3">创建项目后可以邀请成员，并把你的 Keys 加入其中。</p>
            <?php if ($canWrite): ?>
                <a class="btn btn-primary" href="<?= e(url('projects/new')) ?>">创建项目</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach ($projects as $project): ?>
                <?php $owner = $ownerOf((int) $project['owner']); ?>
                <div class="col-md-6 col-xl-4">
                    <a class="sk-card-link" href="<?= e(url('projects/' . (int) $project['id'])) ?>">
                        <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                            <span class="sk-tag <?= (int) $project['visibility'] === 1 ? 'sk-tag-success' : '' ?>">
                                <i class="bi <?= (int) $project['visibility'] === 1 ? 'bi-globe2' : 'bi-lock' ?>"></i>
                                <?= (int) $project['visibility'] === 1 ? '公开' : '私密' ?>
                            </span>
                            <span class="sk-tag"><?= e((string) count($project['member_ids'])) ?> 成员</span>
                        </div>
                        <div class="sk-card-title sk-ellipsis"><?= e($project['project_name']) ?></div>
                        <div class="sk-meta mt-2">
                            <?php if ($owner !== null): ?>
                                <span>
                                    <img class="sk-avatar sk-avatar-sm me-1" src="<?= e($owner['avatar_url']) ?>" alt="所有者头像"
                                         width="22" height="22" loading="lazy" referrerpolicy="no-referrer">
                                    <?= e((int) $project['owner'] === (int) $user['id'] ? '你' : $owner['username']) ?>
                                </span>
                            <?php endif; ?>
                            <span><i class="bi bi-key"></i><?= e((string) count($project['key_ids'])) ?> 个 Keys</span>
                            <span><i class="bi bi-clock"></i><?= e(format_time($project['updated_at'])) ?></span>
                        </div>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section>
    <h2 class="h5 mb-3">公开项目</h2>
    <?php if (!$publicProjects): ?>
        <div class="sk-empty py-4">
            <i class="bi bi-globe2"></i>
            暂无公开项目。
        </div>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach ($publicProjects as $project): ?>
                <?php $owner = $ownerOf((int) $project['owner']); ?>
                <div class="col-md-6 col-xl-4">
                    <a class="sk-card-link" href="<?= e(url('projects/' . (int) $project['id'])) ?>">
                        <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                            <span class="sk-tag sk-tag-success"><i class="bi bi-globe2"></i>公开</span>
                            <span class="sk-tag"><?= e((string) count($project['member_ids'])) ?> 成员</span>
                        </div>
                        <div class="sk-card-title sk-ellipsis"><?= e($project['project_name']) ?></div>
                        <div class="sk-meta mt-2">
                            <?php if ($owner !== null): ?>
                                <span>
                                    <img class="sk-avatar sk-avatar-sm me-1" src="<?= e($owner['avatar_url']) ?>" alt="所有者头像"
                                         width="22" height="22" loading="lazy" referrerpolicy="no-referrer">
                                    <?= e($owner['username']) ?>
                                </span>
                            <?php endif; ?>
                            <span><i class="bi bi-key"></i><?= e((string) count($project['key_ids'])) ?> 个 Keys</span>
                        </div>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
