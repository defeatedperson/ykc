<?php
// 站点配置管理API - 管理员专用接口
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'method_not_allowed']);
    exit;
}

require_once __DIR__ . '/auth/ip-cail.php';
require_once __DIR__ . '/auth/ip-ban.php';
require_once __DIR__ . '/auth/jwt.php';
require_once __DIR__ . '/auth/safe-input.php';

// 获取真实IP并检测是否被封禁
$ip = get_real_ip();
$ban = is_ip_banned($ip);
if ($ban['banned']) {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'ip_banned',
        'ip' => $ip,
        'until' => $ban['until']
    ]);
    exit;
}

// 验证访问JWT - 从Authorization头获取
$auth_header = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
if (!$auth_header || !preg_match('/Bearer\s+(.*)$/i', $auth_header, $matches)) {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'visit_jwt_missing',
        'message' => '访问JWT不存在，需要刷新令牌',
        'action' => 'refresh_token'
    ]);
    exit;
}

$visit_jwt = $matches[1];
$info = get_user_info($visit_jwt, $ip);
if (!$info['valid']) {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'visit_jwt_invalid',
        'message' => '访问JWT已过期或无效，需要刷新令牌',
        'action' => 'refresh_token'
    ]);
    exit;
}

// 验证管理员权限
if (!$info['is_admin']) {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'permission_denied',
        'message' => '仅管理员可以修改站点配置'
    ]);
    exit;
}

$action = isset($_POST['action']) ? validate_common_input($_POST['action']) : '';
$data_dir = __DIR__ . '/data/';

header('Content-Type: application/json');

switch ($action) {
    case 'get_site_config':
        // 获取站点配置
        $site_file = $data_dir . 'site.json';
        if (file_exists($site_file)) {
            $content = file_get_contents($site_file);
            $config = json_decode($content, true);
            if ($config !== null) {
                echo json_encode(['status' => 'ok', 'data' => $config]);
            } else {
                echo json_encode(['status' => 'error', 'message' => '站点配置文件格式错误']);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => '站点配置文件不存在']);
        }
        break;

    case 'update_site_config':
        // 更新站点配置
        $config_data = isset($_POST['config']) ? $_POST['config'] : '';
        if (!$config_data) {
            echo json_encode(['status' => 'error', 'message' => '配置数据不能为空']);
            break;
        }
        
        // 验证JSON格式
        $config = json_decode($config_data, true);
        if ($config === null) {
            echo json_encode(['status' => 'error', 'message' => 'JSON格式错误']);
            break;
        }
        
        // 保存到文件
        $site_file = $data_dir . 'site.json';
        if (file_put_contents($site_file, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
            echo json_encode(['status' => 'ok', 'message' => '站点配置更新成功']);
        } else {
            echo json_encode(['status' => 'error', 'message' => '保存站点配置失败']);
        }
        break;

    case 'get_menu_config':
        // 获取菜单配置
        $menu_file = $data_dir . 'menu.json';
        if (file_exists($menu_file)) {
            $content = file_get_contents($menu_file);
            $config = json_decode($content, true);
            if ($config !== null) {
                echo json_encode(['status' => 'ok', 'data' => $config]);
            } else {
                echo json_encode(['status' => 'error', 'message' => '菜单配置文件格式错误']);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => '菜单配置文件不存在']);
        }
        break;

    case 'update_menu_config':
        // 更新菜单配置
        $config_data = isset($_POST['config']) ? $_POST['config'] : '';
        if (!$config_data) {
            echo json_encode(['status' => 'error', 'message' => '配置数据不能为空']);
            break;
        }
        
        // 验证JSON格式
        $config = json_decode($config_data, true);
        if ($config === null) {
            echo json_encode(['status' => 'error', 'message' => 'JSON格式错误']);
            break;
        }
        
        // 保存到文件
        $menu_file = $data_dir . 'menu.json';
        if (file_put_contents($menu_file, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
            echo json_encode(['status' => 'ok', 'message' => '菜单配置更新成功']);
        } else {
            echo json_encode(['status' => 'error', 'message' => '保存菜单配置失败']);
        }
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => '无效的操作']);
        break;
}
exit;
?>
