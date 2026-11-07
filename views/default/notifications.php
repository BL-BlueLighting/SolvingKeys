<?php
/** @var array $notifications */
/** @var int $unread */
$icons = [
    'mention' => 'bi-at',
    'invitation' => 'bi-envelope-plus',
    'invitation_result' => 'bi-envelope-check',
    'visibility' => 'bi-globe2',
    'visibility_result' => 'bi-globe',
];
?>
<div class="sk-page-head">
    <div>
        <div class="sk-kicker">Notifications</div>
        <h1>通知</h1>
        <p class="sk-muted mb-0 mt-2">@提及、项目邀请与公开化投票提醒。</p>
    </div>
    <?php if ($unread > 0): ?>
        <form method="post" action="<?= e(url('notifications/read')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="all" value="1">
            <button class="btn btn-outline-primary" type="submit">
                <i class="bi bi-check2-all me-1"></i>全部标为已读
            </button>
        </form>
    <?php endif; ?>
</div>

<?php if (!$notifications): ?>
    <div class="sk-empty">
        <i class="bi bi-bell"></i>
        <h2 class="h5">暂无通知</h2>
        <p class="mb-0">当有人在 Key 或项目聊天中 @ 你，或邀请你加入项目时，会在这里看到提醒。</p>
    </div>
<?php else: ?>
    <div class="card">
        <div class="list-group list-group-flush">
            <?php foreach ($notifications as $notification): ?>
                <?php
                $link = (string) ($notification['link_path'] ?? '');
                $href = $link !== '' && str_starts_with($link, '/') ? url(ltrim($link, '/')) : '';
                $isUnread = (int) $notification['is_read'] === 0;
                $icon = $icons[$notification['type']] ?? 'bi-bell';
                ?>
                <div class="list-group-item bg-transparent border-0 px-3 px-md-4 py-3 <?= $isUnread ? 'sk-notification-unread' : '' ?>">
                    <div class="d-flex gap-3 align-items-start">
                        <?php if ($notification['actor'] !== null): ?>
                            <img class="sk-avatar" src="<?= e($notification['actor']['avatar_url']) ?>" alt="触发者头像"
                                 width="42" height="42" loading="lazy" referrerpolicy="no-referrer">
                        <?php else: ?>
                            <span class="sk-avatar d-inline-flex align-items-center justify-content-center"
                                  style="width:42px;height:42px;">
                                <i class="bi <?= e($icon) ?>"></i>
                            </span>
                        <?php endif; ?>
                        <div class="flex-grow-1 min-w-0">
                            <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
                                <span class="sk-tag"><i class="bi <?= e($icon) ?>"></i><?= e($notification['type']) ?></span>
                                <?php if ($isUnread): ?>
                                    <span class="sk-tag sk-tag-accent">未读</span>
                                <?php endif; ?>
                                <span class="small sk-muted"><?= e(format_time($notification['created_at'])) ?></span>
                            </div>
                            <div class="<?= $isUnread ? 'fw-semibold' : '' ?>">
                                <?php if ($href !== ''): ?>
                                    <a href="<?= e($href) ?>" class="text-reset"><?= e($notification['message']) ?></a>
                                <?php else: ?>
                                    <?= e($notification['message']) ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="d-flex gap-2 align-items-center">
                            <?php if ($href !== ''): ?>
                                <a class="btn btn-sm btn-outline-secondary" href="<?= e($href) ?>" title="打开">
                                    <i class="bi bi-arrow-right"></i>
                                </a>
                            <?php endif; ?>
                            <?php if ($isUnread): ?>
                                <form method="post" action="<?= e(url('notifications/read')) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= e((string) $notification['id']) ?>">
                                    <button class="btn btn-sm btn-outline-primary" type="submit" title="标为已读">
                                        <i class="bi bi-check2"></i>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>
