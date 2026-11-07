<?php
/** @var array $user */
/** @var array $keys */
/** @var array $projects */
/** @var array $publicProjects */
/** @var array $invitations */
/** @var array $notifications */
/** @var int $keyCount */
/** @var int $projectCount */
$unread = unread_notification_count();
$canWrite = user_can_write($user);
?>
<section class="sk-hero mb-4">
    <div class="row align-items-center g-4">
        <div class="col-lg-8">
            <div class="sk-kicker"><?= e(site_name()) ?></div>
            <h1>欢迎回来，<?= e($user['username']) ?>。</h1>
            <p class="mb-2"><?= e(site_description()) ?></p>
            <p class="sk-muted mb-3">
                把线索、推理与档案放进加密的 Keys，再用项目与同伴协作。
                内容密钥只在你和获授权的读者手中，服务端与管理员都无法读取。
            </p>
            <div class="d-flex flex-wrap gap-2">
                <?php if ($canWrite): ?>
                    <a class="btn btn-primary" href="<?= e(url('keys/new')) ?>">
                        <i class="bi bi-plus-lg me-1"></i>新建 Key
                    </a>
                    <a class="btn btn-outline-primary" href="<?= e(url('projects/new')) ?>">
                        <i class="bi bi-folder-plus me-1"></i>新建项目
                    </a>
                <?php else: ?>
                    <span class="sk-tag sk-tag-danger"><i class="bi bi-shield-lock"></i>账号只读</span>
                <?php endif; ?>
                <a class="btn btn-outline-secondary" href="<?= e(url('settings')) ?>">
                    <i class="bi bi-shield-lock me-1"></i>钥匙库设置
                </a>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="row g-3">
                <div class="col-6">
                    <div class="sk-stat">
                        <div class="sk-stat-value"><?= e((string) $keyCount) ?></div>
                        <div class="sk-stat-label">我的 Keys</div>
                    </div>
                </div>
                <div class="col-6">
                    <div class="sk-stat">
                        <div class="sk-stat-value"><?= e((string) $projectCount) ?></div>
                        <div class="sk-stat-label">参与项目</div>
                    </div>
                </div>
                <div class="col-6">
                    <div class="sk-stat">
                        <div class="sk-stat-value"><?= e((string) count($invitations)) ?></div>
                        <div class="sk-stat-label">待处理邀请</div>
                    </div>
                </div>
                <div class="col-6">
                    <div class="sk-stat">
                        <div class="sk-stat-value"><?= e((string) $unread) ?></div>
                        <div class="sk-stat-label">未读通知</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<?php if ($invitations): ?>
    <section class="mb-4">
        <div class="d-flex align-items-center justify-content-between mb-2">
            <h2 class="h5 mb-0">项目邀请</h2>
            <a class="small" href="<?= e(url('projects')) ?>">全部项目</a>
        </div>
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

<div class="row g-4">
    <div class="col-lg-7">
        <section class="mb-4">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <h2 class="h5 mb-0">最近的 Keys</h2>
                <a class="small" href="<?= e(url('keys')) ?>">查看全部</a>
            </div>
            <?php if (!$keys): ?>
                <div class="sk-empty">
                    <i class="bi bi-key"></i>
                    还没有 Key。<?= $canWrite ? '创建一个，开始整理你的第一条线索。' : '' ?>
                </div>
            <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($keys as $key): ?>
                        <div class="col-sm-6">
                            <a class="sk-card-link" href="<?= e(url('keys/' . (int) $key['id'])) ?>">
                                <div class="d-flex align-items-center gap-2 mb-2">
                                    <span class="sk-tag sk-tag-accent"><i class="bi bi-lock"></i>E2EE</span>
                                    <span class="sk-tag">v<?= e((string) $key['version']) ?></span>
                                </div>
                                <div class="sk-card-title sk-ellipsis"><?= e($key['key_name']) ?></div>
                                <div class="sk-meta">
                                    <span><i class="bi bi-clock"></i><?= e(format_time($key['updated_at'])) ?></span>
                                </div>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section>
            <div class="d-flex align-items-center justify-content-between mb-2">
                <h2 class="h5 mb-0">我的项目</h2>
                <a class="small" href="<?= e(url('projects')) ?>">查看全部</a>
            </div>
            <?php if (!$projects): ?>
                <div class="sk-empty">
                    <i class="bi bi-folder2-open"></i>
                    暂无项目。项目可以聚合多个 Keys、成员与加密聊天。
                </div>
            <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($projects as $project): ?>
                        <div class="col-sm-6">
                            <a class="sk-card-link" href="<?= e(url('projects/' . (int) $project['id'])) ?>">
                                <div class="d-flex justify-content-between gap-2 mb-2">
                                    <span class="sk-tag <?= (int) $project['visibility'] === 1 ? 'sk-tag-success' : '' ?>">
                                        <i class="bi <?= (int) $project['visibility'] === 1 ? 'bi-globe2' : 'bi-lock' ?>"></i>
                                        <?= (int) $project['visibility'] === 1 ? '公开' : '私密' ?>
                                    </span>
                                    <span class="sk-tag"><?= e((string) count($project['member_ids'])) ?> 成员</span>
                                </div>
                                <div class="sk-card-title sk-ellipsis"><?= e($project['project_name']) ?></div>
                                <div class="sk-meta">
                                    <span><i class="bi bi-key"></i><?= e((string) count($project['key_ids'])) ?> 个 Keys</span>
                                    <span><i class="bi bi-clock"></i><?= e(format_time($project['updated_at'])) ?></span>
                                </div>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <div class="col-lg-5">
        <section class="mb-4">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <h2 class="h5 mb-0">公开项目</h2>
            </div>
            <?php if (!$publicProjects): ?>
                <div class="sk-empty py-4">
                    <i class="bi bi-globe2"></i>
                    暂无公开项目。
                </div>
            <?php else: ?>
                <div class="d-flex flex-column gap-2">
                    <?php foreach ($publicProjects as $project): ?>
                        <a class="sk-card-link py-3" href="<?= e(url('projects/' . (int) $project['id'])) ?>">
                            <div class="d-flex justify-content-between align-items-center gap-2">
                                <div class="min-w-0">
                                    <div class="fw-semibold sk-ellipsis"><?= e($project['project_name']) ?></div>
                                    <div class="small sk-muted">
                                        <?= e((string) count($project['member_ids'])) ?> 成员 ·
                                        <?= e((string) count($project['key_ids'])) ?> 个 Keys
                                    </div>
                                </div>
                                <i class="bi bi-arrow-right-short fs-4"></i>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section>
            <div class="d-flex align-items-center justify-content-between mb-2">
                <h2 class="h5 mb-0">最近通知</h2>
                <a class="small" href="<?= e(url('notifications')) ?>">查看全部</a>
            </div>
            <?php if (!$notifications): ?>
                <div class="sk-empty py-4">
                    <i class="bi bi-bell"></i>
                    暂无通知。
                </div>
            <?php else: ?>
                <div class="card">
                    <div class="list-group list-group-flush">
                        <?php foreach ($notifications as $notification): ?>
                            <?php
                            $link = (string) ($notification['link_path'] ?? '');
                            $href = $link !== '' && str_starts_with($link, '/') ? url(ltrim($link, '/')) : '#';
                            ?>
                            <a class="list-group-item list-group-item-action bg-transparent border-0 px-3 py-3 <?= (int) $notification['is_read'] === 0 ? 'fw-semibold' : '' ?>"
                               href="<?= e($href) ?>">
                                <div class="d-flex gap-2">
                                    <?php if ($notification['actor'] !== null): ?>
                                        <img class="sk-avatar sk-avatar-sm" src="<?= e($notification['actor']['avatar_url']) ?>"
                                             alt="头像" width="28" height="28" loading="lazy" referrerpolicy="no-referrer">
                                    <?php else: ?>
                                        <span class="sk-avatar sk-avatar-sm d-inline-flex align-items-center justify-content-center">
                                            <i class="bi bi-bell small"></i>
                                        </span>
                                    <?php endif; ?>
                                    <div class="min-w-0">
                                        <div class="small"><?= e($notification['message']) ?></div>
                                        <div class="small sk-muted mt-1"><?= e(format_time($notification['created_at'])) ?></div>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>
