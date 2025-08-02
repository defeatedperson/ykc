<?php
/**
 * 分享系统公共函数
 * 包含身份验证、输入验证、响应格式等通用功能
 */

require_once __DIR__ . '/../auth/jwt.php';
require_once __DIR__ . '/config.php';

/**
 * 统一响应格式
 * @param bool $success 操作是否成功
 * @param mixed $data 响应数据
 * @param string $message 响应消息
 * @param array|null $pagination 分页信息
 */
function share_api_response($success, $data = null, $message = '', $pagination = null) {
    $response = [
        'success' => $success,
        'message' => $message,
        'data' => $data,
        'timestamp' => time()
    ];
    
    if ($pagination) {
        $response['pagination'] = $pagination;
    }
    
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 错误响应
 * @param string $message 错误消息
 * @param int $code HTTP状态码
 */
function share_api_error($message, $code = 400) {
    http_response_code($code);
    share_api_response(false, null, $message);
}

/**
 * 成功响应
 * @param mixed $data 响应数据
 * @param string $message 成功消息
 * @param array|null $pagination 分页信息
 */
function share_api_success($data = null, $message = '操作成功', $pagination = null) {
    share_api_response(true, $data, $message, $pagination);
}

/**
 * 获取并验证用户身份（与file-api保持一致）
 * @return array 用户信息数组
 */
function get_authenticated_share_user() {
    // 从 Header 或 POST 参数获取 JWT
    $jwt = null;
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $auth_header = $_SERVER['HTTP_AUTHORIZATION'];
        if (preg_match('/Bearer\s+(.+)/', $auth_header, $matches)) {
            $jwt = $matches[1];
        }
    } elseif (isset($_POST['jwt'])) {
        $jwt = $_POST['jwt'];
    } elseif (isset($_GET['jwt'])) {
        $jwt = $_GET['jwt'];
    }
    
    if (!$jwt) {
        share_api_error('缺少访问令牌', 401);
    }
    
    // 获取用户IP
    $user_ip = get_real_ip_for_share();
    
    // 验证JWT并获取用户信息
    $user_info = get_user_info($jwt, $user_ip);
    if (!$user_info['valid']) {
        share_api_error('身份验证失败: ' . ($user_info['reason'] ?? 'unknown_error'), 401);
    }
    
    return [
        'user_id' => $user_info['user_id'],
        'jwt' => $jwt,
        'ip' => $user_ip
    ];
}

/**
 * 获取真实IP地址
 * @return string IP地址
 */
function get_real_ip_for_share() {
    $ip_keys = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
    
    foreach ($ip_keys as $key) {
        if (!empty($_SERVER[$key])) {
            $ips = explode(',', $_SERVER[$key]);
            $ip = trim($ips[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }
    }
    
    return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

/**
 * 获取请求参数
 * @param string $key 参数名
 * @param mixed $default 默认值
 * @param bool $required 是否必需
 * @return mixed 参数值
 */
function get_share_param($key, $default = null, $required = false) {
    $value = $_POST[$key] ?? $_GET[$key] ?? $default;
    
    if ($required && ($value === null || $value === '')) {
        share_api_error("缺少必需参数: {$key}");
    }
    
    // 特殊处理：如果是字符串且看起来像JSON数组，尝试解析
    if (is_string($value) && (strpos($value, '[') === 0 || strpos($value, '{') === 0)) {
        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $value = $decoded;
        }
    }
    
    return $value;
}

/**
 * 验证文件路径安全性
 * @param string $file_path 文件路径
 * @return bool 是否安全
 */
function validate_file_path($file_path) {
    // 防止路径遍历攻击
    if (strpos($file_path, '..') !== false) {
        return false;
    }
    
    // 防止绝对路径
    if (strpos($file_path, '/') === 0 || preg_match('/^[a-zA-Z]:/', $file_path)) {
        return false;
    }
    
    // 防止特殊字符
    if (preg_match('/[<>:"|?*]/', $file_path)) {
        return false;
    }
    
    return true;
}

/**
 * 验证分享名称
 * @param string $share_name 分享名称
 * @return array 验证结果
 */
function validate_share_name($share_name) {
    if (empty($share_name)) {
        return ['valid' => false, 'reason' => '分享名称不能为空'];
    }
    
    // 检查长度（最多8个字符）
    if (mb_strlen($share_name, 'UTF-8') > 8) {
        return ['valid' => false, 'reason' => '分享名称不能超过8个字符'];
    }
    
    // 检查字符（仅允许中文、字母、数字）
    if (!preg_match('/^[\x{4e00}-\x{9fa5}a-zA-Z0-9]+$/u', $share_name)) {
        return ['valid' => false, 'reason' => '分享名称只能包含中文、字母和数字'];
    }
    
    return ['valid' => true];
}

/**
 * 验证分享密码
 * @param string $password 密码
 * @return array 验证结果
 */
function validate_share_password($password) {
    // 空密码是允许的
    if (empty($password)) {
        return ['valid' => true];
    }
    
    // 检查长度
    if (strlen($password) > 20) {
        return ['valid' => false, 'reason' => '分享密码不能超过20个字符'];
    }
    
    // 检查字符
    if (!preg_match('/^[a-zA-Z0-9!@#$%^&*()_+\-=\[\]{};:,.<>?]+$/', $password)) {
        return ['valid' => false, 'reason' => '分享密码包含不允许的字符'];
    }
    
    return ['valid' => true];
}

/**
 * 验证拓展数据
 * @param string $extension 拓展数据
 * @return array 验证结果
 */
function validate_extension($extension) {
    // 空拓展数据设置为默认值
    if (empty($extension)) {
        $extension = '{}';
    }
    
    // 如果传入的是数组，先转换为JSON字符串
    if (is_array($extension)) {
        $extension = json_encode($extension, JSON_UNESCAPED_UNICODE);
    }
    
    // 确保是字符串类型
    if (!is_string($extension)) {
        return ['valid' => false, 'reason' => '拓展数据格式错误'];
    }
    
    // 检查长度 - 调整为300字符，足够存储按钮名称、URL和图片URL
    if (strlen($extension) > 300) {
        return ['valid' => false, 'reason' => '拓展数据不能超过300个字符'];
    }
    
    // 验证是否为有效的JSON
    $decoded = json_decode($extension, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['valid' => false, 'reason' => '拓展数据必须是有效的JSON格式'];
    }
    
    // 检查是否包含危险字符
    if (preg_match('/<script|javascript:|on\w+\s*=|eval\(|expression\(/i', $extension)) {
        return ['valid' => false, 'reason' => '拓展数据包含危险字符'];
    }
    
    // 验证扩展数据的结构
    if ($decoded && is_array($decoded)) {
        // 验证按钮名称长度
        if (isset($decoded['btn']) && strlen($decoded['btn']) > 20) {
            return ['valid' => false, 'reason' => '按钮名称不能超过20个字符'];
        }
        
        // 验证URL格式
        if (isset($decoded['url']) && !empty($decoded['url'])) {
            if (!filter_var($decoded['url'], FILTER_VALIDATE_URL)) {
                return ['valid' => false, 'reason' => '按钮链接格式无效'];
            }
        }
        
        if (isset($decoded['image']) && !empty($decoded['image'])) {
            if (!filter_var($decoded['image'], FILTER_VALIDATE_URL)) {
                return ['valid' => false, 'reason' => '图片链接格式无效'];
            }
        }
    }
    
    return ['valid' => true, 'normalized' => $extension];
}

/**
 * 检查用户是否有权限访问指定文件
 * @param string $user_id 用户ID
 * @param string $file_path 文件路径
 * @return bool 是否有权限
 */
function check_file_permission($user_id, $file_path) {
    // 构建用户文件根目录
    $user_data_dir = __DIR__ . '/../file/data/' . $user_id;
    $full_file_path = $user_data_dir . '/' . ltrim($file_path, '/');
    
    // 检查文件是否存在
    if (!file_exists($full_file_path)) {
        return false;
    }
    
    // 检查路径是否在用户目录下
    $real_file_path = realpath($full_file_path);
    $real_user_dir = realpath($user_data_dir);
    
    if (!$real_file_path || !$real_user_dir) {
        return false;
    }
    
    return strpos($real_file_path, $real_user_dir) === 0;
}

/**
 * 安全地清理输入字符串
 * @param string $input 输入字符串
 * @return string 清理后的字符串
 */
function sanitize_input($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}
?>