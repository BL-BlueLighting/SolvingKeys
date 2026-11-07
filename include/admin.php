<?php
declare(strict_types=1);

if (!defined('SOLVINGKEYS')) {
    http_response_code(403);
    exit;
}

function admin_users_page(): void
{
    require_admin();
    $search = trim((string) ($_GET['q'] ?? ''));
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $pageSize = (int) config('limits.admin_page_size', 50);
    $total = User::countSearch($search);
    $pages = max(1, (int) ceil($total / $pageSize));
    $page = min($page, $pages);
    $users = User::paginate($pageSize, ($page - 1) * $pageSize, $search);

    render_view('admin/users', [
        'pageTitle' => '用户管理 · ' . config('app.name'),
        'bodyClass' => 'page-admin',
        'users' => $users,
        'search' => $search,
        'page' => $page,
        'pages' => $pages,
        'total' => $total,
    ]);
}

function admin_site_page(): void
{
    require_admin();
    render_view('admin/site', [
        'pageTitle' => '站点设置 · ' . config('app.name'),
        'bodyClass' => 'page-admin',
        'siteName' => site_name(),
        'siteLogo' => Site::get('site_logo', ''),
        'siteLogoUrl' => site_logo_url(),
        'siteDescription' => site_description(),
        'footerText' => site_footer_text(),
        'registrationOpen' => Site::registrationOpen(),
        'uploadMaxMb' => 2,
    ]);
}

function admin_site_post(): void
{
    require_admin();
    try {
        $siteName = validate_display_name(input_string('site_name'), '站点名称', 64);
        $description = trim(input_string('site_description'));
        $description = preg_replace('/\s+/u', ' ', $description) ?? '';
        if (mb_strlen($description, 'UTF-8') > 300) {
            abort(422, '站点描述不能超过 300 个字符。');
        }
        $footer = trim(input_string('footer_text'));
        $footer = preg_replace('/\s+/u', ' ', $footer) ?? '';
        if (mb_strlen($footer, 'UTF-8') > 300) {
            abort(422, '页脚文字不能超过 300 个字符。');
        }
        $registrationOpen = isset($_POST['registration_open']);

        $logo = trim(input_string('site_logo', ''));
        $newUpload = null;
        $file = $_FILES['logo_file'] ?? null;
        if (is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if (($file['error'] ?? 0) !== UPLOAD_ERR_OK) {
                abort(422, 'Logo 上传失败（文件可能超过服务器 upload_max_filesize 限制）。');
            }
            if ((int) ($file['size'] ?? 0) > 2 * 1024 * 1024) {
                abort(422, 'Logo 文件不能超过 2 MB。');
            }
            $tmp = (string) ($file['tmp_name'] ?? '');
            if ($tmp === '' || !is_uploaded_file($tmp)) {
                abort(422, 'Logo 上传失败：临时文件无效。');
            }
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($tmp) ?: '';
            $types = [
                'image/png' => 'png',
                'image/jpeg' => 'jpg',
                'image/webp' => 'webp',
                'image/gif' => 'gif',
            ];
            if (!isset($types[$mime])) {
                abort(422, 'Logo 仅支持 PNG、JPEG、WebP 或 GIF 图片。');
            }
            $info = @getimagesize($tmp);
            if ($info === false || !in_array($info[2] ?? 0, [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) {
                abort(422, '上传的文件不是有效的图片。');
            }
            $dir = sk_root('uploads/site');
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException('无法创建 Logo 上传目录，请检查 uploads/ 权限。');
            }
            $filename = 'site-logo-' . bin2hex(random_bytes(8)) . '.' . $types[$mime];
            $destination = $dir . '/' . $filename;
            if (!move_uploaded_file($tmp, $destination)) {
                throw new RuntimeException('无法保存 Logo 文件，请检查 uploads/site 权限。');
            }
            @chmod($destination, 0644);
            $newUpload = '/uploads/site/' . $filename;
        }

        if ($newUpload !== null) {
            $logo = $newUpload;
        } else {
            $logo = trim($logo);
            if ($logo !== '') {
                if (strlen($logo) > 2048) {
                    abort(422, 'Logo 地址过长。');
                }
                $isHttps = (bool) preg_match('#^https://#i', $logo);
                $isInternal = str_starts_with($logo, '/') && !str_starts_with($logo, '//');
                if (!$isHttps && !$isInternal) {
                    abort(422, 'Logo 地址必须是 https:// 链接或站内路径。');
                }
            }
        }

        $oldLogo = Site::get('site_logo', '');
        Site::setMany([
            'site_name' => $siteName,
            'site_logo' => $logo,
            'site_description' => $description,
            'footer_text' => $footer,
            'registration_open' => $registrationOpen ? '1' : '0',
        ]);

        if ($oldLogo !== '' && $oldLogo !== $logo && str_starts_with($oldLogo, '/uploads/site/')) {
            $root = realpath(sk_root('uploads/site'));
            $oldPath = realpath(sk_root(ltrim($oldLogo, '/')));
            if ($root !== false && $oldPath !== false && str_starts_with($oldPath, $root . DIRECTORY_SEPARATOR)) {
                @unlink($oldPath);
            }
        }

        flash('success', '站点信息已更新。');
    } catch (HttpException $error) {
        flash('error', $error->getMessage());
    } catch (Throwable $error) {
        sk_log($error);
        flash('error', '保存站点设置失败，请检查 uploads/ 目录权限。');
    }
    redirect('admin/site');
}

function admin_ban_post(int $id): void
{
    $admin = require_admin();
    $user = User::find($id);
    if ($user === null) {
        flash('error', '用户不存在。');
        redirect('admin/users');
    }
    if ((int) $user['id'] === (int) $admin['id']) {
        flash('error', '不能封禁自己。');
        redirect('admin/users');
    }
    if (User::isAdmin($user)) {
        flash('error', '不能封禁其他管理员。');
        redirect('admin/users');
    }

    $newState = !User::isBanned($user);
    User::setBan($id, $newState);
    flash('success', $user['username'] . ($newState ? ' 已被设为只读。' : ' 已解除只读状态。'));
    redirect('admin/users');
}

function admin_delete_post(int $id): void
{
    $admin = require_admin();
    $user = User::find($id);
    if ($user === null) {
        flash('error', '用户不存在。');
        redirect('admin/users');
    }
    if ((int) $user['id'] === (int) $admin['id']) {
        flash('error', '不能删除自己。');
        redirect('admin/users');
    }
    if (User::isAdmin($user)) {
        flash('error', '不能删除其他管理员账号。');
        redirect('admin/users');
    }

    try {
        User::deleteAccount($id);
        flash('success', '用户 ' . $user['username'] . ' 及其拥有的 Keys/项目已删除。');
    } catch (HttpException $error) {
        flash('error', $error->getMessage());
    }
    redirect('admin/users');
}
