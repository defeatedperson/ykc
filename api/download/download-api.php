<?php
/**
 * 文件下载API接口
 * 提供分享文件的信息获取和下载功能
 * 使用新的4模块架构：分享验证、文件下载、统计记录、临时Token管理
 */

// 防止直接访问（除非通过API调用）
if (basename($_SERVER['SCRIPT_FILENAME']) == basename(__FILE__)) {
    // 设置响应头
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    
    // 处理OPTIONS预检请求
    if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
        http_response_code(200);
        exit;
    }
}

// 加载新的4个模块
try {
    require_once __DIR__ . '/download-config.php';
    require_once __DIR__ . '/share-validator.php';
    require_once __DIR__ . '/file-downloader.php';
    require_once __DIR__ . '/download-statistics.php';
    require_once __DIR__ . '/temp-token-manager.php';
} catch (Exception $e) {
    // 如果加载失败，返回错误信息
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => '系统模块加载失败: ' . $e->getMessage(),
        'data' => null
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
require_once __DIR__ . '/../share/common.php';

/**
 * 主API路由处理
 */
function handle_download_api() {
    // 只允许GET和POST请求
    if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'])) {
        share_api_error('不支持的请求方法', 405);
    }
    
    // 获取请求参数
    $params = get_request_params();
    
    // 获取操作类型
    $action = $params['action'] ?? 'get_info';
    
    // download_by_token 操作不需要 share_code 参数，因为 token 本身包含了所有信息
    if ($action !== 'download_by_token') {
        // 验证必需参数
        if (empty($params['share_code'])) {
            share_api_error('缺少分享代码参数');
        }
        $share_code = $params['share_code'];
        $password = $params['password'] ?? '';
    }
    
    // 根据action执行不同操作
    switch ($action) {
        case 'get_info':
            handle_get_file_info($share_code, $password);
            break;
            
        case 'download':
            handle_download_file($share_code, $password, $params);
            break;
            
        case 'get_download_token':
            handle_get_download_token($share_code, $password, $params);
            break;
            
        case 'download_by_token':
            handle_download_by_token($params);
            break;
            
        default:
            share_api_error('不支持的操作类型');
    }
}

/**
 * 获取请求参数
 * @return array 请求参数
 */
function get_request_params() {
    $params = [];
    
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $params = $_GET;
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // 处理JSON请求体
        $content_type = $_SERVER['CONTENT_TYPE'] ?? '';
        if (strpos($content_type, 'application/json') !== false) {
            $json_data = json_decode(file_get_contents('php://input'), true);
            $params = $json_data ?: [];
        } else {
            $params = $_POST;
        }
    }
    
    return $params;
}

/**
 * 处理获取文件信息请求
 * @param string $share_code 分享代码
 * @param string $password 访问密码
 */
function handle_get_file_info($share_code, $password) {
    // 使用新的分享验证模块
    $validation_result = validate_share_access($share_code, $password);
    
    if ($validation_result['success']) {
        // 增加访问统计
        $stats_result = increment_share_view_count($share_code);
        if (!$stats_result['success']) {
            error_log("增加访问统计失败: " . $stats_result['message']);
        }
        
        share_api_success($validation_result['data'], '获取文件信息成功');
    } else {
        $error_messages = [
            'invalid_share_code' => '无效的分享代码',
            'share_not_found' => '分享不存在或已失效',
            'password_required' => '该分享需要密码',
            'password_incorrect' => '访问密码错误',
            'file_not_exists' => '文件不存在',
            'file_not_readable' => '文件无法读取',
            'invalid_path' => '文件路径无效',
            'not_a_file' => '指定路径不是文件',
            'user_dir_not_exists' => '用户目录不存在',
            'database_error' => '数据库连接失败'
        ];
        
        $message = $error_messages[$validation_result['reason']] ?? '获取文件信息失败';
        
        // 如果需要密码，返回特殊状态码
        if ($validation_result['reason'] === 'password_required') {
            http_response_code(401);
            share_api_response(false, $validation_result['data'] ?? null, $message);
        } else {
            share_api_error($message);
        }
    }
}

/**
 * 处理获取下载Token请求
 * @param string $share_code 分享代码
 * @param string $password 访问密码
 * @param array $params 请求参数
 */
function handle_get_download_token($share_code, $password, $params) {
    // 1. 验证分享访问权限
    $validation_result = validate_share_access($share_code, $password);
    
    if (!$validation_result['success']) {
        $error_messages = [
            'invalid_share_code' => '无效的分享代码',
            'share_not_found' => '分享不存在或已失效',
            'password_required' => '该分享需要密码',
            'password_incorrect' => '访问密码错误',
            'database_error' => '数据库连接失败'
        ];
        
        $message = $error_messages[$validation_result['reason']] ?? '验证失败';
        
        if ($validation_result['reason'] === 'password_required') {
            http_response_code(401);
            share_api_response(false, null, $message);
        } else {
            share_api_error($message);
        }
        return;
    }
    
    // 2. 获取文件信息
    $share_data = $validation_result['data'];
    
    // 如果没有指定 file_path 参数，使用分享数据中的默认文件路径
    if (isset($params['file_path']) && !empty($params['file_path'])) {
        $file_path = $params['file_path'];
        // 这里可以添加验证逻辑，确保 file_path 与分享的文件匹配
        if ($file_path !== $share_data['file_path']) {
            share_api_error('指定的文件路径与分享不匹配');
            return;
        }
    } else {
        // 使用分享数据中的默认文件路径
        $file_path = $share_data['file_path'];
    }
    
    if (empty($file_path)) {
        share_api_error('无法获取文件路径信息');
        return;
    }
    
    // 3. 直接使用分享数据中的文件信息
    $file_name = $share_data['file_name'];
    
    // 4. 生成临时下载Token
    $token_result = generate_temp_token(
        $share_code,
        $share_data['user_id'],
        $file_path,
        $file_name
    );
    
    if ($token_result['success']) {
        share_api_success([
            'token' => $token_result['token'],
            'expires_at' => $token_result['expires_at'],
            'max_uses' => $token_result['max_uses'],
            'file_name' => $token_result['file_name']
        ], '下载Token生成成功');
    } else {
        share_api_error('Token生成失败: ' . $token_result['message']);
    }
}

/**
 * 处理通过Token下载文件请求
 * @param array $params 请求参数
 */
function handle_download_by_token($params) {
    $token = $params['token'] ?? '';
    $force_download = ($params['force_download'] ?? 'true') === 'true';
    
    if (empty($token)) {
        share_api_error('缺少下载Token');
        return;
    }
    
    // 1. 先验证Token并获取Token信息（用于统计）
    $token_verification = verify_temp_token($token);
    if (!$token_verification['success']) {
        $error_messages = [
            'invalid_token_format' => '无效的下载Token格式',
            'token_not_found' => '下载Token不存在或已失效',
            'token_expired' => '下载Token已过期',
            'token_exhausted' => '下载Token使用次数已达上限',
            'database_error' => '数据库连接失败',
            'system_error' => '系统错误'
        ];
        
        $message = $error_messages[$token_verification['reason']] ?? 'Token验证失败';
        share_api_error($message);
        return;
    }
    
    // 2. 获取Token信息用于统计
    $token_info = $token_verification['token_info'];
    $share_code = $token_info['share_code'];
    
    // 3. 增加下载统计
    $stats_result = increment_share_download_count($share_code, $token_info['file_path']);
    if (!$stats_result['success']) {
        error_log("增加下载统计失败: " . $stats_result['message']);
        // 不阻断下载流程，仅记录错误
    }
    
    // 4. 使用文件下载模块处理下载
    $download_result = handle_file_download($token, $force_download);
    
    if (!$download_result['success']) {
        $error_messages = [
            'invalid_token' => '无效的下载Token',
            'token_not_found' => '下载Token不存在或已失效',
            'token_expired' => '下载Token已过期',
            'token_exhausted' => '下载Token使用次数已达上限',
            'file_error' => '文件不存在或无法访问',
            'file_too_large' => '文件大小超过下载限制',
            'database_error' => '数据库连接失败',
            'system_error' => '系统错误'
        ];
        
        $message = $error_messages[$download_result['reason']] ?? '下载失败';
        share_api_error($message);
    }
    
    // 如果执行到这里说明下载成功，但通常不会到达这里因为文件输出会终止脚本
}

/**
 * 处理直接下载请求（旧版兼容）
 * @param string $share_code 分享代码
 * @param string $password 访问密码
 * @param array $params 请求参数
 */
function handle_download_file($share_code, $password, $params) {
    // 为了兼容旧的调用方式，直接推荐使用Token方式
    share_api_error('请使用 get_download_token 和 download_by_token 方式下载文件');
}

// 执行API路由
if (basename($_SERVER['SCRIPT_FILENAME']) == basename(__FILE__)) {
    try {
        handle_download_api();
    } catch (Exception $e) {
        error_log("下载API异常: " . $e->getMessage());
        share_api_error('系统内部错误', 500);
    }
}
?>