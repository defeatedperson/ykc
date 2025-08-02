<?php
/**
 * 文件管理 API 接口
 * 提供完整的文件管理功能：查看、上传、删除、重命名、下载、搜索、文件夹操作
 * 
 * 所有操作都需要有效的 JWT 认证，只能操作用户自己的文件夹
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// 引入所需模块
require_once __DIR__ . '/../auth/jwt.php';
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/read.php';
require_once __DIR__ . '/upload.php';
require_once __DIR__ . '/update.php';
require_once __DIR__ . '/search.php';
require_once __DIR__ . '/download.php';

/**
 * 统一响应格式
 */
function api_response($success, $data = null, $message = '', $pagination = null) {
    $response = [
        'success' => $success,
        'message' => $message,
        'data' => $data,
        'timestamp' => time()
    ];
    
    if ($pagination) {
        $response['pagination'] = $pagination;
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 错误响应
 */
function api_error($message, $code = 400) {
    http_response_code($code);
    api_response(false, null, $message);
}

/**
 * 成功响应
 */
function api_success($data = null, $message = '操作成功', $pagination = null) {
    api_response(true, $data, $message, $pagination);
}

/**
 * 获取并验证用户身份
 */
function get_authenticated_user() {
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
        api_error('缺少访问令牌', 401);
    }
    
    // 获取用户IP
    $user_ip = get_real_ip();
    
    // 验证JWT并获取用户信息
    $user_info = get_user_info($jwt, $user_ip);
    if (!$user_info['valid']) {
        api_error('身份验证失败: ' . ($user_info['reason'] ?? 'unknown_error'), 401);
    }
    
    return [
        'user_id' => $user_info['user_id'],
        'jwt' => $jwt,
        'ip' => $user_ip
    ];
}

/**
 * 获取请求参数
 */
function get_param($key, $default = null, $required = false) {
    $value = $_POST[$key] ?? $_GET[$key] ?? $default;
    
    if ($required && ($value === null || $value === '')) {
        api_error("缺少必需参数: {$key}");
    }
    
    return $value;
}

// 获取操作类型
$action = get_param('action', '', true);
$user = get_authenticated_user();

try {
    switch ($action) {
        
        // ============ 文件查看 ============
        case 'list':
            $path = get_param('path', '');
            $page = (int)get_param('page', 1);
            $per_page = (int)get_param('per_page', 20);
            $force_refresh = get_param('force_refresh') === 'true';
            
            $result = get_directory_info($user['user_id'], $path, $page, $per_page, $force_refresh);
            
            if ($result['success']) {
                api_success($result['data'], '获取目录信息成功', $result['pagination']);
            } else {
                api_error('获取目录信息失败: ' . $result['reason']);
            }
            break;
            
        // ============ 文件上传 ============
        case 'upload':
            $path = get_param('path', '');
            
            if (empty($_FILES['file'])) {
                api_error('没有上传文件');
            }
            
            $result = upload_user_file($user['jwt'], $user['ip'], $_FILES['file'], $path);
            
            if ($result['success']) {
                api_success([
                    'file_path' => $result['file_path'],
                    'file_name' => $result['file_name']
                ], '文件上传成功');
            } else {
                // 提供友好的错误信息
                $error_messages = [
                    'invalid_upload' => '无效的文件上传',
                    'file_too_large_ini' => '文件大小超过服务器配置限制',
                    'file_too_large_form' => '文件大小超过表单限制',
                    'upload_partial' => '文件仅部分上传，请重试',
                    'no_file' => '没有选择文件',
                    'no_tmp_dir' => '服务器临时目录不可用',
                    'cant_write' => '文件写入失败',
                    'extension_blocked' => '文件扩展名被阻止',
                    'dangerous_extension' => '危险的文件类型，不允许上传',
                    'forbidden_extension' => '不支持的文件类型',
                    'filename_too_long' => '文件名过长',
                    'invalid_filename' => '无效的文件名',
                    'create_dir_failed' => '创建目录失败',
                    'file_exists' => '文件已存在，请重命名后上传',
                    'move_file_failed' => '文件保存失败',
                    'storage_limit_exceeded' => '存储空间不足，无法上传此文件',
                    'insufficient_disk_space' => '服务器磁盘空间不足，请稍后再试'
                ];
                
                $error_message = $error_messages[$result['reason']] ?? '文件上传失败: ' . $result['reason'];
                api_error($error_message);
            }
            break;
            
        // ============ 文件重命名 ============
        case 'rename_file':
            $file_path = get_param('file_path', '', true);
            $new_name = get_param('new_name', '', true);
            
            $result = rename_user_file($user['user_id'], $file_path, $new_name);
            
            if ($result['success']) {
                api_success([
                    'new_path' => $result['new_path']
                ], '文件重命名成功');
            } else {
                // 提供友好的错误信息
                $error_messages = [
                    'invalid_file_name' => '文件名称无效，请使用合法的文件名称',
                    'file_not_exists' => '要重命名的文件不存在',
                    'target_file_exists' => '目标文件名已被使用，请选择其他名称',
                    'rename_file_failed' => '文件重命名失败，请稍后重试'
                ];
                
                $error_message = $error_messages[$result['reason']] ?? '文件重命名失败: ' . $result['reason'];
                api_error($error_message);
            }
            break;
            
        // ============ 文件删除 ============
        case 'delete_file':
            $file_path = get_param('file_path', '', true);
            
            $result = delete_user_file($user['user_id'], $file_path);
            
            if ($result['success']) {
                api_success(null, '文件删除成功');
            } else {
                api_error('文件删除失败: ' . $result['reason']);
            }
            break;
            
        // ============ 文件夹创建 ============
        case 'create_folder':
            $parent_path = get_param('parent_path', '');
            $folder_name = get_param('folder_name', '', true);
            
            $result = create_user_folder($user['user_id'], $parent_path, $folder_name);
            
            if ($result['success']) {
                api_success([
                    'folder_path' => $result['folder_path']
                ], '文件夹创建成功');
            } else {
                // 提供友好的错误信息
                $error_messages = [
                    'invalid_folder_name' => '文件夹名称无效，请使用合法的文件夹名称',
                    'parent_dir_not_exists' => '上级目录不存在，无法创建文件夹',
                    'folder_already_exists' => '当前目录下已存在同名文件夹，请使用其他名称',
                    'create_folder_failed' => '文件夹创建失败，请稍后重试'
                ];
                
                $error_message = $error_messages[$result['reason']] ?? '文件夹创建失败: ' . $result['reason'];
                api_error($error_message);
            }
            break;
            
        // ============ 文件夹重命名 ============
        case 'rename_folder':
            $folder_path = get_param('folder_path', '', true);
            $new_name = get_param('new_name', '', true);
            
            $result = rename_user_folder($user['user_id'], $folder_path, $new_name);
            
            if ($result['success']) {
                api_success([
                    'new_path' => $result['new_path']
                ], '文件夹重命名成功');
            } else {
                // 提供友好的错误信息
                $error_messages = [
                    'invalid_folder_name' => '文件夹名称无效，请使用合法的文件夹名称',
                    'folder_not_exists' => '要重命名的文件夹不存在',
                    'target_folder_exists' => '目标文件夹名称已被使用，请选择其他名称',
                    'rename_folder_failed' => '文件夹重命名失败，请稍后重试'
                ];
                
                $error_message = $error_messages[$result['reason']] ?? '文件夹重命名失败: ' . $result['reason'];
                api_error($error_message);
            }
            break;
            
        // ============ 文件夹删除 ============
        case 'delete_folder':
            $folder_path = get_param('folder_path', '', true);
            $force_delete = get_param('force_delete') === 'true';
            
            $result = delete_user_folder($user['user_id'], $folder_path, $force_delete);
            
            if ($result['success']) {
                api_success(null, '文件夹删除成功');
            } else {
                api_error('文件夹删除失败: ' . $result['reason']);
            }
            break;
            
        // ============ 文件移动 ============
        case 'move_file':
            $file_path = get_param('file_path', '', true);
            $target_path = get_param('target_path', '', true);
            
            $result = move_user_file($user['user_id'], $file_path, $target_path);
            
            if ($result['success']) {
                api_success([
                    'new_path' => $result['new_path']
                ], '文件移动成功');
            } else {
                // 提供友好的错误信息
                $error_messages = [
                    'file_not_exists' => '要移动的文件不存在',
                    'target_dir_not_exists' => '目标目录不存在',
                    'target_file_exists' => '目标位置已存在同名文件，无法移动',
                    'same_location' => '目标位置与原位置相同',
                    'move_file_failed' => '文件移动失败，请稍后重试',
                    'invalid_target_path' => '目标路径无效'
                ];
                
                $error_message = $error_messages[$result['reason']] ?? '文件移动失败: ' . $result['reason'];
                api_error($error_message);
            }
            break;
            
        // ============ 文件夹移动 ============
        case 'move_folder':
            $folder_path = get_param('folder_path', '', true);
            $target_path = get_param('target_path', '', true);
            
            $result = move_user_folder($user['user_id'], $folder_path, $target_path);
            
            if ($result['success']) {
                api_success([
                    'new_path' => $result['new_path']
                ], '文件夹移动成功');
            } else {
                // 提供友好的错误信息
                $error_messages = [
                    'folder_not_exists' => '要移动的文件夹不存在',
                    'target_dir_not_exists' => '目标目录不存在',
                    'target_folder_exists' => '目标位置已存在同名文件夹，无法移动',
                    'same_location' => '目标位置与原位置相同',
                    'move_folder_failed' => '文件夹移动失败，请稍后重试',
                    'invalid_target_path' => '目标路径无效',
                    'cannot_move_to_subfolder' => '不能将文件夹移动到其子目录中'
                ];
                
                $error_message = $error_messages[$result['reason']] ?? '文件夹移动失败: ' . $result['reason'];
                api_error($error_message);
            }
            break;
            
        // ============ 文件搜索 ============
        case 'search':
            $path = get_param('path', '');
            $keyword = get_param('keyword', '', true);
            
            // 使用新的当前目录搜索函数
            $result = search_current_directory(
                $user['jwt'], 
                $user['ip'], 
                $keyword, 
                $path
            );
            
            if ($result['success']) {
                // 合并搜索结果
                $all_results = array_merge($result['results']['folders'], $result['results']['files']);
                
                // 手动分页搜索结果
                $page = (int)get_param('page', 1);
                $per_page = (int)get_param('per_page', 20);
                $total = count($all_results);
                $total_pages = ceil($total / $per_page);
                $offset = ($page - 1) * $per_page;
                $page_results = array_slice($all_results, $offset, $per_page);
                
                api_success($page_results, "在当前目录找到 {$total} 个匹配项", [
                    'page' => $page,
                    'per_page' => $per_page,
                    'total' => $total,
                    'total_pages' => $total_pages,
                    'has_next' => $page < $total_pages,
                    'has_prev' => $page > 1
                ]);
            } else {
                api_error('搜索失败: ' . $result['reason']);
            }
            break;
            
        // ============ 文件下载 ============
        case 'download':
            $file_path = get_param('file_path', '', true);
            $force_download = get_param('force_download', 'true') === 'true';
            
            // 直接输出文件内容并退出
            download_user_file($user['jwt'], $user['ip'], $file_path, $force_download);
            break;
            
        // ============ 获取文件信息 ============
        case 'file_info':
            $file_path = get_param('file_path', '', true);
            
            $result = get_file_info($user['jwt'], $user['ip'], $file_path);
            
            if ($result['success']) {
                api_success($result['file_info'], '获取文件信息成功');
            } else {
                api_error('获取文件信息失败: ' . $result['reason']);
            }
            break;
            
        // ============ 获取上传限制 ============
        case 'upload_limits':
            $limits = get_user_upload_limits($user['user_id']);
            api_success($limits, '获取上传限制成功');
            break;
            
        // ============ 获取存储配置信息（管理员功能） ============
        case 'storage_config':
            // 检查是否为管理员用户
            if (!is_unlimited_user($user['user_id'])) {
                api_error('权限不足：仅限管理员访问', 403);
            }
            
            require_once __DIR__ . '/config.php';
            $config_summary = FileConfig::getConfigSummary();
            api_success($config_summary, '获取存储配置成功');
            break;
            
        // ============ 未知操作 ============
        default:
            api_error('未知操作: ' . $action);
    }
    
} catch (Exception $e) {
    api_error('服务器内部错误: ' . $e->getMessage(), 500);
}
?>
