<?php
/** @var array $project */
/** @var array $members */
/** @var array $projectKeys */
/** @var array $ownKeys */
/** @var array|null $messagingKey */
/** @var array|null $activeVote */
/** @var array|null $myBallot */
/** @var array $invitations */
/** @var bool $isMember */
/** @var bool $isOwner */
/** @var bool $canWrite */
$user = current_user();
$projectUrl = url('projects/' . (int) $project['id']);
$visibilityLabel = (int) $project['visibility'] === 1 ? '公开' : '私密';
$myVote = $myBallot['vote'] ?? null;
$voteLabels = [
    'pending' => ['待表决', ''],
    'approve' => ['同意', 'sk-tag-success'],
    'reject' => ['拒绝', 'sk-tag-danger'],
];
?>
<div id="project-app" data-project-id="<?= e((string) $project['id']) ?>">
    <div class="sk-page-head">
        <div class="min-w-0">
            <div class="sk-kicker">项目 #<?= e((string) $project['id']) ?></div>
            <h1 class="sk-ellipsis"><?= e($project['project_name']) ?></h1>
            <div class="sk-meta mt-2">
                <span class="sk-tag <?= (int) $project['visibility'] === 1 ? 'sk-tag-success' : '' ?>">
                    <i class="bi <?= (int) $project['visibility'] === 1 ? 'bi-globe2' : 'bi-lock' ?>"></i>
                    <?= e($visibilityLabel) ?>
                </span>
                <span><i class="bi bi-people"></i><?= e((string) count($members)) ?> 位成员</span>
                <span><i class="bi bi-key"></i><?= e((string) count($projectKeys)) ?> 个 Keys</span>
                <span><i class="bi bi-clock"></i>更新于 <?= e(format_time($project['updated_at'])) ?></span>
            </div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-outline-secondary" href="<?= e(url('projects')) ?>">
                <i class="bi bi-arrow-left me-1"></i>返回
            </a>
            <a class="btn btn-primary" href="#chat-section">
                <i class="bi bi-chat-dots me-1"></i>前往聊天
            </a>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-8">
            <section class="sk-section mb-4">
                <div class="d-flex align-items-center justify-content-between gap-2 mb-3">
                    <h2 class="mb-0"><i class="bi bi-key me-2"></i>项目 Keys</h2>
                    <span class="sk-tag"><?= e((string) count($projectKeys)) ?></span>
                </div>

                <?php if (!$projectKeys): ?>
                    <div class="sk-empty py-4">
                        <i class="bi bi-key"></i>
                        项目中还没有 Key。
                    </div>
                <?php else: ?>
                    <div>
                        <?php foreach ($projectKeys as $key): ?>
                            <div class="sk-key-row">
                                <div class="sk-key-main min-w-0">
                                    <div class="d-flex align-items-center gap-2 flex-wrap">
                                        <a class="fw-semibold sk-ellipsis" href="<?= e(url('keys/' . (int) $key['id'])) ?>">
                                            <?= e($key['key_name']) ?>
                                        </a>
                                        <?php if ((int) ($project['messaging_key_id'] ?? 0) === (int) $key['id']): ?>
                                            <span class="sk-tag sk-tag-accent"><i class="bi bi-chat-lock"></i>消息密钥</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="sk-meta mt-1">
                                        <span>
                                            <img class="sk-avatar sk-avatar-sm me-1" src="<?= e($key['owner']['avatar_url']) ?>"
                                                 alt="Key 所有者头像" width="22" height="22" loading="lazy" referrerpolicy="no-referrer">
                                            <?= e((int) $key['owner_id'] === (int) $user['id'] ? '你' : $key['owner']['username']) ?>
                                        </span>
                                        <span><i class="bi bi-clock"></i><?= e(format_time($key['updated_at'])) ?></span>
                                    </div>
                                </div>
                                <div class="d-flex gap-2">
                                    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('keys/' . (int) $key['id'])) ?>">打开</a>
                                    <?php if ($canWrite && ($isOwner || (int) $key['owner_id'] === (int) $user['id']) && (int) ($project['messaging_key_id'] ?? 0) !== (int) $key['id']): ?>
                                        <form method="post" action="<?= e($projectUrl . '/keys/' . (int) $key['id'] . '/remove') ?>"
                                              data-confirm="从项目中移除此 Key？项目成员将无法再通过项目找到它。"
                                              data-confirm-title="移除 Key">
                                            <?= csrf_field() ?>
                                            <button class="btn btn-sm btn-outline-danger" type="submit" title="移除此 Key">
                                                <i class="bi bi-x-lg"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($isMember && $canWrite && $ownKeys): ?>
                    <div class="sk-divider"></div>
                    <form method="post" action="<?= e($projectUrl . '/keys') ?>" class="row g-2 align-items-end">
                        <?= csrf_field() ?>
                        <div class="col-12 col-sm-8">
                            <label class="form-label small" for="add-key-select">加入你拥有的 Key</label>
                            <select class="form-select form-select-sm" id="add-key-select" name="key_id" required>
                                <?php foreach ($ownKeys as $key): ?>
                                    <option value="<?= e((string) $key['id']) ?>"><?= e($key['key_name']) ?> (#<?= e((string) $key['id']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-sm-4">
                            <button class="btn btn-sm btn-primary w-100" type="submit">
                                <i class="bi bi-plus-lg me-1"></i>加入项目
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </section>

            <section class="sk-section" id="chat-section">
                <div class="d-flex align-items-start justify-content-between gap-2 mb-3">
                    <div>
                        <h2 class="mb-1" id="chat-title"><i class="bi bi-chat-lock me-2"></i>加密聊天</h2>
                        <div class="small sk-muted">
                            消息密钥：
                            <span id="chat-key-name"><?= e($messagingKey['key_name'] ?? '（未设置）') ?></span>
                        </div>
                    </div>
                </div>

                <div id="chat-loading" class="text-center py-5">
                    <span class="spinner-border spinner-border-sm me-2"></span>正在读取项目聊天…
                </div>

                <div id="chat-no-key" class="sk-locked" hidden>
                    <div class="sk-locked-icon"><i class="bi bi-chat-square-text"></i></div>
                    <h3 class="h6">尚未设置聊天消息密钥</h3>
                    <p class="sk-muted mb-2">
                        加密聊天使用项目内某个 Key 的内容密钥。只有项目所有者可以选择消息密钥。
                    </p>
                    <?php if ($isOwner): ?>
                        <a class="btn btn-sm btn-primary" href="#chat-settings">前往聊天设置</a>
                    <?php else: ?>
                        <span class="sk-tag">等待项目所有者设置</span>
                    <?php endif; ?>
                </div>

                <div id="chat-lock" class="sk-locked" hidden>
                    <div class="sk-locked-icon"><i class="bi bi-shield-lock"></i></div>
                    <h3 class="h6">需要消息密钥</h3>
                    <p class="sk-muted">
                        输入当前消息密钥（即项目内那个 Key 的 64 位内容密钥），或解锁你的钥匙库。
                    </p>
                    <div class="d-flex flex-wrap gap-2 mb-3">
                        <button type="button" class="btn btn-sm btn-primary" id="chat-unlock-vault">
                            <i class="bi bi-shield-lock me-1"></i>解锁钥匙库
                        </button>
                    </div>
                    <div class="row g-2 align-items-end">
                        <div class="col-12 col-lg-8">
                            <label class="form-label small" for="chat-key-input">消息密钥</label>
                            <input class="form-control form-control-sm sk-key-value" id="chat-key-input" type="text"
                                   maxlength="64" autocomplete="off" spellcheck="false" placeholder="64 位十六进制">
                        </div>
                        <div class="col-12 col-lg-4">
                            <button type="button" class="btn btn-sm btn-outline-primary w-100" id="chat-key-submit">
                                使用密钥解锁
                            </button>
                        </div>
                    </div>
                    <div class="text-danger small mt-2" id="chat-lock-error" hidden></div>
                </div>

                <div id="chat-panel" hidden>
                    <div class="sk-chat">
                        <div class="sk-chat-list" id="chat-messages"></div>
                        <div class="sk-empty py-4" id="chat-empty" hidden>
                            <i class="bi bi-chat-dots"></i>
                            还没有消息。发送第一条加密消息吧。
                        </div>
                    </div>
                    <div class="sk-chat-composer mt-3" id="chat-composer" hidden>
                        <div class="d-flex align-items-center justify-content-between gap-2 mb-2 flex-wrap">
                            <div class="d-flex align-items-center gap-2">
                                <span class="small sk-muted">编辑器</span>
                                <div class="btn-group sk-mode-switch" id="chat-editor-modes" role="group"
                                     aria-label="聊天编辑器"></div>
                            </div>
                            <button class="btn btn-sm btn-primary" type="button" id="chat-send">
                                <i class="bi bi-send me-1"></i>发送
                            </button>
                        </div>
                        <div id="chat-editor-mount"></div>
                        <div class="text-danger small mt-2" id="chat-composer-error" hidden></div>
                        <div class="small sk-muted mt-2">支持 Markdown、@提及、&amp;Key 引用与 $$LaTeX$$。Ctrl/⌘ + Enter 发送。</div>
                    </div>
                    <div class="sk-callout mt-3" id="chat-readonly" hidden>
                        <i class="bi bi-eye me-1"></i>当前为只读模式：你可以阅读聊天，但不能发送消息。
                    </div>
                </div>
            </section>
        </div>

        <div class="col-lg-4">
            <section class="sk-section mb-4">
                <h2><i class="bi bi-people me-2"></i>成员</h2>
                <?php foreach ($members as $member): ?>
                    <div class="sk-member">
                        <img class="sk-avatar" src="<?= e($member['avatar_url']) ?>" alt="成员头像"
                             width="38" height="38" loading="lazy" referrerpolicy="no-referrer">
                        <div class="flex-grow-1 min-w-0">
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <span class="fw-semibold sk-ellipsis"><?= e($member['username']) ?></span>
                                <?php if ($member['is_owner']): ?>
                                    <span class="sk-tag sk-tag-accent">所有者</span>
                                <?php endif; ?>
                                <?php if ($member['is_me']): ?>
                                    <span class="sk-tag">我</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if ($isOwner && $canWrite && !$member['is_me']): ?>
                            <form method="post"
                                  action="<?= e($projectUrl . '/members/' . (int) $member['id'] . '/remove') ?>"
                                  data-confirm="移除成员？该成员拥有的 Key 也会从项目中移除。"
                                  data-confirm-title="移除成员">
                                <?= csrf_field() ?>
                                <button class="btn btn-sm btn-outline-danger" type="submit" title="移除成员">
                                    <i class="bi bi-person-dash"></i>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <?php if ($isOwner && $canWrite): ?>
                    <div class="sk-divider"></div>
                    <form method="post" action="<?= e($projectUrl . '/invite') ?>" class="row g-2 align-items-end">
                        <?= csrf_field() ?>
                        <div class="col-8">
                            <label class="form-label small" for="invite-username">邀请用户</label>
                            <input class="form-control form-control-sm" id="invite-username" name="username"
                                   type="text" required placeholder="用户名">
                        </div>
                        <div class="col-4">
                            <button class="btn btn-sm btn-primary w-100" type="submit">邀请</button>
                        </div>
                    </form>
                <?php endif; ?>

                <?php if ($isOwner && $invitations): ?>
                    <div class="sk-divider"></div>
                    <div class="small fw-semibold mb-2">待处理邀请</div>
                    <?php foreach ($invitations as $invitation): ?>
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <img class="sk-avatar sk-avatar-sm" src="<?= e($invitation['invitee']['avatar_url']) ?>"
                                 alt="被邀请者头像" width="24" height="24" loading="lazy" referrerpolicy="no-referrer">
                            <span class="small flex-grow-1 sk-ellipsis"><?= e($invitation['invitee']['username']) ?></span>
                            <form method="post"
                                  action="<?= e($projectUrl . '/invitations/' . (int) $invitation['id'] . '/cancel') ?>"
                                  data-confirm="取消这条邀请？" data-confirm-title="取消邀请">
                                <?= csrf_field() ?>
                                <button class="btn btn-sm btn-outline-secondary" type="submit" title="取消邀请">
                                    <i class="bi bi-x"></i>
                                </button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <?php if ($isMember && !$isOwner && $canWrite): ?>
                    <div class="sk-divider"></div>
                    <form method="post" action="<?= e($projectUrl . '/leave') ?>"
                          data-confirm="退出项目？你拥有的 Key 会从项目中移除，聊天记录可能被停用。"
                          data-confirm-title="退出项目">
                        <?= csrf_field() ?>
                        <button class="btn btn-sm btn-outline-danger w-100" type="submit">退出项目</button>
                    </form>
                <?php elseif (!$isMember): ?>
                    <div class="sk-divider"></div>
                    <div class="small sk-muted">
                        你正在以公开访客身份查看此项目。公开项目的聊天仍需消息密钥才能阅读。
                    </div>
                <?php endif; ?>
            </section>

            <section class="sk-section mb-4">
                <h2><i class="bi bi-globe2 me-2"></i>可见性</h2>
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span class="sk-tag <?= (int) $project['visibility'] === 1 ? 'sk-tag-success' : '' ?>">
                        <?= e($visibilityLabel) ?>
                    </span>
                    <?php if ((int) $project['visibility'] === 0): ?>
                        <span class="small sk-muted">公开需要全体成员同意。</span>
                    <?php endif; ?>
                </div>

                <?php if ($activeVote !== null): ?>
                    <?php
                    $requester = User::find((int) $activeVote['requested_by']);
                    $requesterLabel = (int) $activeVote['requested_by'] === (int) $user['id']
                        ? '你'
                        : ($requester['username'] ?? '项目成员');
                    ?>
                    <div class="small fw-semibold mb-1">公开化投票进行中</div>
                    <div class="small sk-muted mb-2">
                        发起人：<?= e($requesterLabel) ?>
                    </div>
                    <?php foreach ($activeVote['ballots'] as $ballot): ?>
                        <?php $state = $voteLabels[$ballot['vote']] ?? ['待表决', '']; ?>
                        <div class="sk-vote-ballot">
                            <img class="sk-avatar sk-avatar-sm" src="<?= e($ballot['user']['avatar_url']) ?>"
                                 alt="投票成员头像" width="26" height="26" loading="lazy" referrerpolicy="no-referrer">
                            <span class="small flex-grow-1"><?= e($ballot['user']['username']) ?></span>
                            <span class="sk-tag <?= e($state[1]) ?>"><?= e($state[0]) ?></span>
                        </div>
                    <?php endforeach; ?>

                    <?php if ($isMember && $canWrite && $myVote === 'pending'): ?>
                        <div class="d-flex gap-2 mt-3">
                            <form method="post" action="<?= e($projectUrl . '/visibility/vote') ?>" class="flex-grow-1">
                                <?= csrf_field() ?>
                                <button class="btn btn-sm btn-success w-100" type="submit" name="vote" value="approve">
                                    同意公开
                                </button>
                            </form>
                            <form method="post" action="<?= e($projectUrl . '/visibility/vote') ?>" class="flex-grow-1">
                                <?= csrf_field() ?>
                                <button class="btn btn-sm btn-outline-danger w-100" type="submit" name="vote" value="reject">
                                    拒绝
                                </button>
                            </form>
                        </div>
                    <?php elseif ($myVote !== null): ?>
                        <div class="small sk-muted mt-2">你已投出「<?= e($voteLabels[$myVote][0] ?? $myVote) ?>」。</div>
                    <?php endif; ?>

                    <?php if ($isOwner && $canWrite): ?>
                        <form method="post" action="<?= e($projectUrl . '/visibility/cancel') ?>" class="mt-2"
                              data-confirm="取消公开化投票？" data-confirm-title="取消投票">
                            <?= csrf_field() ?>
                            <button class="btn btn-sm btn-outline-secondary w-100" type="submit">取消投票</button>
                        </form>
                    <?php endif; ?>
                <?php else: ?>
                    <?php if ($isOwner && $canWrite && (int) $project['visibility'] === 0): ?>
                        <form method="post" action="<?= e($projectUrl . '/visibility/request') ?>">
                            <?= csrf_field() ?>
                            <button class="btn btn-sm btn-outline-primary w-100" type="submit">
                                <i class="bi bi-globe2 me-1"></i>发起公开化投票
                            </button>
                        </form>
                    <?php elseif ($isOwner && $canWrite && (int) $project['visibility'] === 1): ?>
                        <form method="post" action="<?= e($projectUrl . '/visibility/private') ?>"
                              data-confirm="将项目设为私密？公开访客将无法再查看。" data-confirm-title="设为私密">
                            <?= csrf_field() ?>
                            <button class="btn btn-sm btn-outline-secondary w-100" type="submit">
                                <i class="bi bi-lock me-1"></i>设为私密
                            </button>
                        </form>
                    <?php else: ?>
                        <div class="small sk-muted">只有项目所有者可以发起公开化投票。</div>
                    <?php endif; ?>
                <?php endif; ?>
            </section>

            <?php if ($isOwner): ?>
                <section class="sk-section mb-4" id="chat-settings">
                    <h2><i class="bi bi-chat-lock me-2"></i>聊天设置</h2>
                    <p class="small sk-muted">
                        聊天记录会使用所选 Key 的内容密钥加密。更换消息密钥时，需要用旧密钥解密、再用新密钥重新加密现有记录。
                    </p>
                    <?php if (!$projectKeys): ?>
                        <div class="sk-callout sk-callout-warning">
                            请先向项目加入至少一个 Key，才能设置消息密钥。
                        </div>
                    <?php else: ?>
                        <div class="small mb-2">
                            当前消息密钥：
                            <?php if ($messagingKey !== null): ?>
                                <a href="<?= e(url('keys/' . (int) $messagingKey['id'])) ?>"><?= e($messagingKey['key_name']) ?></a>
                            <?php else: ?>
                                <span class="sk-muted">未设置</span>
                            <?php endif; ?>
                        </div>
                        <form id="messaging-key-form" class="row g-2 align-items-end">
                            <div class="col-12">
                                <label class="form-label small" for="messaging-key-select">选择项目内的 Key</label>
                                <select class="form-select form-select-sm" id="messaging-key-select" required>
                                    <?php foreach ($projectKeys as $key): ?>
                                        <option value="<?= e((string) $key['id']) ?>"
                                            <?= (int) ($project['messaging_key_id'] ?? 0) === (int) $key['id'] ? 'selected' : '' ?>>
                                            <?= e($key['key_name']) ?> (#<?= e((string) $key['id']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="discard-chat-history">
                                    <label class="form-check-label small" for="discard-chat-history">
                                        无法解密现有记录时，放弃历史消息并重新开始
                                    </label>
                                </div>
                                <div class="form-text">
                                    仅在旧消息密钥已丢失时使用。该操作会用新密钥初始化一条空的消息记录，旧记录无法恢复。
                                </div>
                            </div>
                            <div class="col-12">
                                <button class="btn btn-sm btn-primary w-100" type="submit" id="messaging-key-submit"
                                        <?= $canWrite ? '' : 'disabled' ?>>
                                    <i class="bi bi-shield-lock me-1"></i>设置 / 更换消息密钥
                                </button>
                            </div>
                        </form>
                        <div class="text-danger small mt-2" id="messaging-key-error" hidden></div>
                    <?php endif; ?>
                </section>

                <section class="sk-section">
                    <h2><i class="bi bi-gear me-2"></i>项目设置</h2>
                    <form method="post" action="<?= e($projectUrl . '/rename') ?>" class="row g-2 align-items-end mb-3">
                        <?= csrf_field() ?>
                        <div class="col-8">
                            <label class="form-label small" for="project-rename">重命名项目</label>
                            <input class="form-control form-control-sm" id="project-rename" name="project_name"
                                   type="text" maxlength="120" value="<?= e($project['project_name']) ?>" required>
                        </div>
                        <div class="col-4">
                            <button class="btn btn-sm btn-outline-primary w-100" type="submit">保存</button>
                        </div>
                    </form>
                    <form method="post" action="<?= e($projectUrl . '/delete') ?>"
                          data-confirm="删除项目？项目聊天记录与邀请会被删除，Keys 本身不会被删除。"
                          data-confirm-title="删除项目" data-confirm-button="永久删除">
                        <?= csrf_field() ?>
                        <button class="btn btn-sm btn-outline-danger w-100" type="submit">
                            <i class="bi bi-trash me-1"></i>删除项目
                        </button>
                    </form>
                </section>
            <?php endif; ?>
        </div>
    </div>
</div>
