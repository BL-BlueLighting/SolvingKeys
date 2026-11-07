<?php
/** @var int $status */
/** @var string $message */
$titles = [
    403 => '没有权限',
    404 => '页面不存在',
    419 => '页面已过期',
    405 => '请求方法不受支持',
    500 => '服务器错误',
];
$title = $titles[$status] ?? '出错了';
?>
<div class="sk-auth-wrap">
    <div class="sk-auth-card text-center">
        <div class="display-3 fw-bold mb-2" style="letter-spacing:-0.06em;"><?= e((string) $status) ?></div>
        <h1><?= e($title) ?></h1>
        <p class="sk-muted mb-4"><?= e($message) ?></p>
        <div class="d-flex justify-content-center gap-2">
            <a class="btn btn-primary" href="<?= e(url('')) ?>">返回首页</a>
            <a class="btn btn-outline-secondary" href="<?= e(url('login')) ?>">登录</a>
        </div>
    </div>
</div>
