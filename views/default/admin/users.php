<?php
/** @var array $users */
/** @var string $search */
/** @var int $page */
/** @var int $pages */
/** @var int $total */
$me = current_user();
?>
<div class="sk-page-head">
    <div>
        <div class="sk-kicker">Administration</div>
        <h1>用户管理</h1>
        <p class="sk-muted mb-0 mt-2">
            共 <?= e((string) $total) ?> 位用户。管理员可封禁（只读）或删除用户，但无法读取任何加密内容。
        </p>
    </div>
    <form method="get" action="<?= e(url('admin/users')) ?>" class="d-flex gap-2">
        <input class="form-control form-control-sm" type="search" name="q" value="<?= e($search) ?>"
               placeholder="搜索用户名或邮箱" aria-label="搜索用户">
        <button class="btn btn-sm btn-outline-primary" type="submit">搜索</button>
    </form>
</div>

<ul class="nav nav-pills gap-2 mb-3">
    <li class="nav-item">
        <a class="nav-link active" href="<?= e(url('admin/users')) ?>">用户管理</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" href="<?= e(url('admin/site')) ?>">站点设置</a>
    </li>
</ul>

<div class="sk-callout mb-3">
    <i class="bi bi-shield-lock me-1"></i>
    出于安全模型，管理员看不到 Keys 内容、内容密钥或钥匙库明文。管理员账号之间也不能互相封禁或删除。
</div>

<?php if (!$users): ?>
    <div class="sk-empty">
        <i class="bi bi-people"></i>
        没有找到用户。
    </div>
<?php else: ?>
    <div class="card">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                <tr>
                    <th scope="col">用户</th>
                    <th scope="col">邮箱</th>
                    <th scope="col">权限</th>
                    <th scope="col">状态</th>
                    <th scope="col" class="text-end">Keys</th>
                    <th scope="col" class="text-end">项目</th>
                    <th scope="col">注册时间</th>
                    <th scope="col" class="text-end">操作</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($users as $userRow): ?>
                    <?php
                    $isAdminRow = $userRow['permission'] === User::PERMISSION_ADMIN;
                    $isSelf = (int) $userRow['id'] === (int) $me['id'];
                    $isBanned = (int) $userRow['ban'] === 1;
                    ?>
                    <tr>
                        <td>
                            <div class="sk-table-avatar">
                                <img class="sk-avatar" src="<?= e($userRow['avatar_url']) ?>" alt="头像"
                                     width="34" height="34" loading="lazy" referrerpolicy="no-referrer">
                                <div>
                                    <div class="fw-semibold"><?= e($userRow['username']) ?></div>
                                    <div class="small sk-muted">#<?= e((string) $userRow['id']) ?><?= $isSelf ? ' · 我' : '' ?></div>
                                </div>
                            </div>
                        </td>
                        <td class="small"><?= e($userRow['email']) ?></td>
                        <td>
                            <span class="sk-tag <?= $isAdminRow ? 'sk-tag-accent' : '' ?>">
                                <?= e($isAdminRow ? '管理员' : '用户') ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($isBanned): ?>
                                <span class="sk-tag sk-tag-danger">只读</span>
                            <?php else: ?>
                                <span class="sk-tag sk-tag-success">正常</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end"><?= e((string) $userRow['key_count']) ?></td>
                        <td class="text-end"><?= e((string) $userRow['project_count']) ?></td>
                        <td class="small sk-muted"><?= e(format_time($userRow['created_at'])) ?></td>
                        <td class="text-end">
                            <?php if ($isAdminRow || $isSelf): ?>
                                <span class="small sk-muted"><i class="bi bi-shield-check"></i> 受保护</span>
                            <?php else: ?>
                                <div class="d-flex justify-content-end gap-2">
                                    <form method="post" action="<?= e(url('admin/users/' . (int) $userRow['id'] . '/ban')) ?>"
                                          data-confirm="<?= $isBanned ? '解除该用户的只读状态？' : '将该用户设为只读？其将无法创建或修改 Keys、项目与消息。' ?>"
                                          data-confirm-title="<?= $isBanned ? '解除只读' : '设为只读' ?>"
                                          data-confirm-button="<?= $isBanned ? '解除' : '设为只读' ?>">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-sm <?= $isBanned ? 'btn-outline-success' : 'btn-outline-warning' ?>"
                                                type="submit">
                                            <?= $isBanned ? '解除只读' : '设为只读' ?>
                                        </button>
                                    </form>
                                    <form method="post" action="<?= e(url('admin/users/' . (int) $userRow['id'] . '/delete')) ?>"
                                          data-confirm="永久删除用户 <?= e($userRow['username']) ?>？其拥有的 Keys 与项目将被删除或转移，无法恢复。"
                                          data-confirm-title="删除用户" data-confirm-button="永久删除">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-sm btn-outline-danger" type="submit">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($pages > 1): ?>
        <nav class="mt-3" aria-label="分页">
            <ul class="pagination sk-pagination justify-content-center">
                <?php for ($i = 1; $i <= $pages; $i++): ?>
                    <?php $href = url('admin/users?' . http_build_query(['q' => $search, 'page' => $i])); ?>
                    <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                        <a class="page-link" href="<?= e($href) ?>"><?= e((string) $i) ?></a>
                    </li>
                <?php endfor; ?>
            </ul>
        </nav>
    <?php endif; ?>
<?php endif; ?>
