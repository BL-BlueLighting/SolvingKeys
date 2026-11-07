<?php
declare(strict_types=1);

if (!defined('SOLVINGKEYS')) {
    http_response_code(403);
    exit;
}

function route_allow(string ...$methods): void
{
    if (!in_array(request_method(), $methods, true)) {
        abort(405, '请求方法不受支持。', 'method_not_allowed');
    }
}

require SK_ROOT . '/include/auth.php';
require SK_ROOT . '/include/home.php';
require SK_ROOT . '/include/keys.php';
require SK_ROOT . '/include/projects.php';
require SK_ROOT . '/include/notifications.php';
require SK_ROOT . '/include/settings.php';
require SK_ROOT . '/include/admin.php';

$method = request_method();
$path = request_path();
$segments = $path === '' ? [] : explode('/', $path);

if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
    require_csrf();
}

if (($segments[0] ?? '') === 'api') {
    require SK_ROOT . '/include/api.php';
    dispatch_api(array_slice($segments, 1), $method);
    exit;
}

$first = $segments[0] ?? '';
$second = $segments[1] ?? '';
$third = $segments[2] ?? '';
$fourth = $segments[3] ?? '';
$fifth = $segments[4] ?? '';

switch ($first) {
    case '':
        route_allow('GET', 'HEAD');
        home_page();
        break;

    case 'login':
        route_allow('GET', 'POST');
        if ($method === 'GET') {
            auth_login_page();
        } else {
            auth_login_post();
        }
        break;

    case 'register':
        route_allow('GET');
        auth_register_page();
        break;

    case 'logout':
        route_allow('POST');
        auth_logout();
        break;

    case 'keys':
        require_login();
        if ($second === '') {
            route_allow('GET', 'HEAD');
            keys_index_page();
            break;
        }
        if ($second === 'new') {
            route_allow('GET', 'HEAD');
            keys_new_page();
            break;
        }
        $keyId = route_int($second);
        if ($third === '') {
            route_allow('GET', 'HEAD');
            keys_view_page($keyId);
            break;
        }
        if ($third === 'edit') {
            route_allow('GET', 'HEAD');
            keys_edit_page($keyId);
            break;
        }
        abort(404, '页面不存在。');
        break;

    case 'projects':
        require_login();
        if ($second === '') {
            route_allow('GET', 'HEAD', 'POST');
            if ($method === 'POST') {
                projects_create_post();
            } else {
                projects_index_page();
            }
            break;
        }
        if ($second === 'new') {
            route_allow('GET', 'HEAD');
            projects_create_page();
            break;
        }
        $projectId = route_int($second);
        if ($third === '') {
            route_allow('GET', 'HEAD');
            projects_view_page($projectId);
            break;
        }
        if ($third === 'rename') {
            route_allow('POST');
            projects_rename_post($projectId);
            break;
        }
        if ($third === 'delete') {
            route_allow('POST');
            projects_delete_post($projectId);
            break;
        }
        if ($third === 'invite') {
            route_allow('POST');
            projects_invite_post($projectId);
            break;
        }
        if ($third === 'leave') {
            route_allow('POST');
            projects_leave_post($projectId);
            break;
        }
        if ($third === 'invitations' && $fourth !== '' && $fifth === 'cancel') {
            route_allow('POST');
            projects_invitation_cancel_post($projectId, route_int($fourth));
            break;
        }
        if ($third === 'invitations' && $fourth !== '') {
            route_allow('POST');
            projects_invitation_respond_post($projectId, route_int($fourth));
            break;
        }
        if ($third === 'members' && $fourth !== '' && $fifth === 'remove') {
            route_allow('POST');
            projects_member_remove_post($projectId, route_int($fourth));
            break;
        }
        if ($third === 'keys') {
            if ($fourth === '') {
                route_allow('POST');
                projects_key_add_post($projectId);
                break;
            }
            if ($fifth === 'remove') {
                route_allow('POST');
                projects_key_remove_post($projectId, route_int($fourth));
                break;
            }
        }
        if ($third === 'visibility') {
            if ($fourth === 'request') {
                route_allow('POST');
                projects_visibility_request_post($projectId);
                break;
            }
            if ($fourth === 'vote') {
                route_allow('POST');
                projects_visibility_vote_post($projectId);
                break;
            }
            if ($fourth === 'cancel') {
                route_allow('POST');
                projects_visibility_cancel_post($projectId);
                break;
            }
            if ($fourth === 'private') {
                route_allow('POST');
                projects_visibility_private_post($projectId);
                break;
            }
        }
        abort(404, '页面不存在。');
        break;

    case 'notifications':
        require_login();
        if ($second === '') {
            route_allow('GET', 'HEAD');
            notifications_page();
            break;
        }
        if ($second === 'read') {
            route_allow('POST');
            notifications_read_post();
            break;
        }
        abort(404, '页面不存在。');
        break;

    case 'settings':
        require_login();
        if ($second === '') {
            route_allow('GET', 'HEAD');
            settings_page();
            break;
        }
        if ($second === 'profile') {
            route_allow('POST');
            settings_profile_post();
            break;
        }
        if ($second === 'password') {
            route_allow('POST');
            settings_password_post();
            break;
        }
        abort(404, '页面不存在。');
        break;

    case 'admin':
        require_admin();
        if ($second === 'site') {
            route_allow('GET', 'HEAD', 'POST');
            if ($method === 'POST') {
                admin_site_post();
            } else {
                admin_site_page();
            }
            break;
        }
        if ($second === '' || $second === 'users') {
            if ($second === 'users' && $third !== '' && $fourth === 'ban') {
                route_allow('POST');
                admin_ban_post(route_int($third));
                break;
            }
            if ($second === 'users' && $third !== '' && $fourth === 'delete') {
                route_allow('POST');
                admin_delete_post(route_int($third));
                break;
            }
            if ($third === '') {
                route_allow('GET', 'HEAD');
                admin_users_page();
                break;
            }
        }
        abort(404, '页面不存在。');
        break;

    default:
        abort(404, '页面不存在。');
}
