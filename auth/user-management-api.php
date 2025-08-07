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

require_once __DIR__ . '/admin_auth.php';
require_once __DIR__ . '/safe-input.php';
require_once __DIR__ . '/user-management.php';

// 执行管理员身份验证
$admin_info = admin_auth_check();

/**
 * 获取请求参数（支持JSON和FormData）
 */
function get_param($key, $default = null, $required = false) {
    $value = null;
    
    // 先尝试从POST JSON数据获取
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = file_get_contents('php://input');
        if ($input) {
            $json_data = json_decode($input, true);
            if ($json_data && isset($json_data[$key])) {
                $value = $json_data[$key];
            }
        }
    }
    
    // 如果JSON中没有，从POST/GET参数获取
    if ($value === null) {
        $value = $_POST[$key] ?? $_GET[$key] ?? $default;
    }
    
    if ($required && ($value === null || $value === '')) {
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'error',
            'message' => "缺少必需参数: {$key}"
        ]);
        exit;
    }
    
    return $value;
}

// 获取操作类型
$action = validate_common_input(get_param('action', '', true));

header('Content-Type: application/json');

// 路由处理
switch ($action) {
    case 'list_users':
        // 获取用户列表
        $page = intval(get_param('page', 1));
        $limit = intval(get_param('limit', 20));
        
        // 限制分页参数范围
        $page = max(1, $page);
        $limit = min(100, max(1, $limit)); // 最大100条，最小1条
        
        $result = get_all_users($page, $limit);
        echo json_encode($result);
        break;

    case 'get_user':
        // 获取用户详细信息
        $user_id = intval(get_param('user_id', 0, true));
        
        $result = get_user_detail($user_id);
        echo json_encode($result);
        break;

    case 'create_user':
        // 创建用户
        $username = validate_common_input(get_param('username', '', true));
        $password = get_param('password', '', true); // 先不验证，保留原始密码用于验证
        $email = validate_common_input(get_param('email', ''));
        
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
        $user_id = intval(get_param('user_id', 0, true));
        
        $result = delete_user($user_id);
        echo json_encode($result);
        break;

    case 'reset_password':
        // 重置密码
        $user_id = intval(get_param('user_id', 0, true));
        $new_password = get_param('new_password', '', true); // 保留原始密码用于验证
        
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
        $user_id = intval(get_param('user_id', 0, true));
        
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
