<?php
/**
 * 用户管理API接口（仅限管理员）
 * 
 * 支持的操作：
 * - list_users: 获取用户列表
 * - get_user: 获取用户详细信息
 * - create_user: 创建用户
 * - delete_user: 删除用户
 * - reset_password: 重置密码
 * - disable_mfa: 关闭MFA
 * - get_statistics: 获取统计信息
 */

// 只允许POST请求
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => '只允许POST请求']);
    exit;
}

require_once __DIR__ . '/ip-cail.php';
require_once __DIR__ . '/ip-ban.php';
require_once __DIR__ . '/jwt.php';
require_once __DIR__ . '/safe-input.php';
require_once __DIR__ . '/user-management.php';

// 获取真实IP并检测是否被封禁
$ip = get_real_ip();
$ban = is_ip_banned($ip);
if ($ban['banned']) {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'error',
        'message' => 'IP已被封禁',
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
        'status' => 'error',
        'message' => '访问JWT不存在，请重新登录',
        'code' => 'jwt_missing'
    ]);
    exit;
}

$visit_jwt = $matches[1];
$user_info = get_user_info($visit_jwt, $ip);

if (!$user_info['valid']) {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'visit_jwt_invalid',
        'message' => '访问JWT已过期或无效，需要刷新令牌',
        'action' => 'refresh_token'
    ]);
    exit;
}

// 检查是否为管理员
if (!$user_info['is_admin']) {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'error',
        'message' => '权限不足，仅限管理员操作',
        'code' => 'permission_denied'
    ]);
    exit;
}

// 获取操作类型
$action = isset($_POST['action']) ? validate_common_input($_POST['action']) : '';

if (!$action) {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'error',
        'message' => '缺少操作类型参数'
    ]);
    exit;
}

header('Content-Type: application/json');

// 路由处理
switch ($action) {
    case 'list_users':
        // 获取用户列表
        $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
        $limit = isset($_POST['limit']) ? intval($_POST['limit']) : 20;
        
        // 限制分页参数范围
        $page = max(1, $page);
        $limit = min(100, max(1, $limit)); // 最大100条，最小1条
        
        $result = get_all_users($page, $limit);
        echo json_encode($result);
        break;

    case 'get_user':
        // 获取用户详细信息
        $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
        
        if ($user_id <= 0) {
            echo json_encode(['status' => 'error', 'message' => '用户ID无效']);
            break;
        }
        
        $result = get_user_detail($user_id);
        echo json_encode($result);
        break;

    case 'create_user':
        // 创建用户
        $username = isset($_POST['username']) ? validate_common_input($_POST['username']) : '';
        $password = isset($_POST['password']) ? $_POST['password'] : ''; // 先不验证，保留原始密码用于验证
        $email = isset($_POST['email']) ? validate_common_input($_POST['email']) : '';
        
        // 验证用户名
        if (!$username) {
            echo json_encode(['status' => 'error', 'message' => '用户名格式不正确或为空']);
            break;
        }
        
        // 验证用户名格式（字母、数字、下划线，长度3-30）
        if (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
            echo json_encode(['status' => 'error', 'message' => '用户名格式不正确（3-30位字母、数字、下划线）']);
            break;
        }
        
        // 验证密码强度
        $validated_password = validate_password($password);
        if (!$validated_password) {
            echo json_encode(['status' => 'error', 'message' => '密码格式不正确（至少8位，包含大写、小写、数字、特殊字符）']);
            break;
        }
        
        // 验证邮箱（如果提供）
        if (!empty($email) && !validate_email($email)) {
            echo json_encode(['status' => 'error', 'message' => '邮箱格式不正确']);
            break;
        }
        
        $result = create_user($username, $validated_password, $email);
        echo json_encode($result);
        break;

    case 'delete_user':
        // 删除用户
        $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
        
        if ($user_id <= 0) {
            echo json_encode(['status' => 'error', 'message' => '用户ID无效']);
            break;
        }
        
        $result = delete_user($user_id);
        echo json_encode($result);
        break;

    case 'reset_password':
        // 重置密码
        $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
        $new_password = isset($_POST['new_password']) ? $_POST['new_password'] : ''; // 保留原始密码用于验证
        
        if ($user_id <= 0) {
            echo json_encode(['status' => 'error', 'message' => '用户ID无效']);
            break;
        }
        
        // 验证密码强度
        $validated_password = validate_password($new_password);
        if (!$validated_password) {
            echo json_encode(['status' => 'error', 'message' => '密码格式不正确（至少8位，包含大写、小写、数字、特殊字符）']);
            break;
        }
        
        $result = reset_user_password($user_id, $validated_password);
        echo json_encode($result);
        break;

    case 'disable_mfa':
        // 关闭MFA
        $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
        
        if ($user_id <= 0) {
            echo json_encode(['status' => 'error', 'message' => '用户ID无效']);
            break;
        }
        
        $result = disable_user_mfa($user_id);
        echo json_encode($result);
        break;

    case 'get_statistics':
        // 获取统计信息
        $result = get_user_statistics();
        echo json_encode($result);
        break;

    default:
        echo json_encode([
            'status' => 'error',
            'message' => '不支持的操作类型',
            'supported_actions' => [
                'list_users',
                'get_user',
                'create_user',
                'delete_user',
                'reset_password',
                'disable_mfa',
                'get_statistics'
            ]
        ]);
        break;
}

exit;
?>
