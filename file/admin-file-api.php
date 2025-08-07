<?php
/**
 * 管理员文件管理接口
 * 允许管理员浏览和删除/data目录中的所有文件
 * 
 * 功能：
 * - 浏览/data目录结构
 * - 删除指定文件或目录
 * - 图片预览/文件下载
 * 
 * 安全限制：
 * - 仅限管理员用户访问
 * - 使用访问JWT进行身份验证
 */

// 引入安全头文件
require_once __DIR__ . '/../auth/security-headers.php';

// 引入所需模块
require_once __DIR__ . '/../auth/jwt.php';
require_once __DIR__ . '/../auth/admin_auth.php';
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/config.php';

// 执行管理员身份验证
admin_auth_check();

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
 * 获取已认证的管理员信息
 */
function get_authenticated_admin() {
    // 从 Authorization 头获取 JWT
    $auth_header = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
    if (!$auth_header || !preg_match('/Bearer\s+(.*)$/i', $auth_header, $matches)) {
        api_error('缺少访问令牌', 401);
    }
    
    $jwt = $matches[1];
    $user_ip = get_real_ip();
    
    // 验证JWT并获取用户信息
    $user_info = get_user_info($jwt, $user_ip);
    if (!$user_info['valid']) {
        api_error('身份验证失败: ' . ($user_info['reason'] ?? 'unknown_error'), 401);
    }
    
    // 检查是否为管理员
    if (!$user_info['is_admin']) {
        api_error('权限不足，仅限管理员访问', 403);
    }
    
    return [
        'user_id' => $user_info['user_id'],
        'nickname' => $user_info['nickname'],
        'jwt' => $jwt,
        'ip' => $user_ip
    ];
}

/**
 * 获取请求参数
 */
function get_param($key, $default = null, $required = false) {
    // 先尝试从POST JSON数据获取
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = file_get_contents('php://input');
        if ($input) {
            $json_data = json_decode($input, true);
            if ($json_data && isset($json_data[$key])) {
                $value = $json_data[$key];
                if ($required && ($value === null || $value === '')) {
                    api_error("缺少必需参数: {$key}");
                }
                return $value;
            }
        }
    }
    
    // 从GET/POST参数获取
    $value = $_POST[$key] ?? $_GET[$key] ?? $default;
    
    if ($required && ($value === null || $value === '')) {
        api_error("缺少必需参数: {$key}");
    }
    
    return $value;
}

/**
 * 浏览/data目录结构（管理员专用）
 */
function browse_data_directory($relative_path = '', $page = 1, $per_page = 50) {
    $per_page = min(max(1, $per_page), 100);
    $page = max(1, $page);
    
    $base_dir = __DIR__ . '/data';
    
    // 处理路径，确保安全
    $relative_path = trim($relative_path, '/\\');
    $target_dir = $base_dir;
    
    if (!empty($relative_path)) {
        $target_dir = $base_dir . '/' . $relative_path;
    }
    
    // 安全检查：确保目标目录在data目录内
    $real_base = realpath($base_dir);
    $real_target = realpath($target_dir);
    
    if (!$real_base) {
        return [
            'success' => false,
            'message' => 'data目录不存在'
        ];
    }
    
    if (!$real_target || strpos($real_target, $real_base) !== 0) {
        return [
            'success' => false,
            'message' => '无效的路径访问'
        ];
    }
    
    if (!is_dir($real_target)) {
        return [
            'success' => false,
            'message' => '目录不存在'
        ];
    }
    
    // 读取目录内容
    $items = scandir($real_target);
    $directories = [];
    $files = [];
    
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        
        $item_path = $real_target . '/' . $item;
        $item_relative_path = $relative_path ? $relative_path . '/' . $item : $item;
        
        if (is_dir($item_path)) {
            $directories[] = [
                'name' => $item,
                'path' => $item_relative_path,
                'type' => 'directory',
                'is_file' => false,
                'modified' => filemtime($item_path),
                'size' => 0
            ];
        } else {
            $files[] = [
                'name' => $item,
                'path' => $item_relative_path,
                'full_path' => $item_path,
                'type' => pathinfo($item, PATHINFO_EXTENSION) ?: 'unknown',
                'is_file' => true,
                'size' => filesize($item_path),
                'modified' => filemtime($item_path)
            ];
        }
    }
    
    // 排序：目录在前，文件在后
    usort($directories, function($a, $b) {
        return strcmp($a['name'], $b['name']);
    });
    
    usort($files, function($a, $b) {
        return strcmp($a['name'], $b['name']);
    });
    
    // 合并结果
    $all_items = array_merge($directories, $files);
    
    // 分页
    $total = count($all_items);
    $total_pages = ceil($total / $per_page);
    $offset = ($page - 1) * $per_page;
    $paged_items = array_slice($all_items, $offset, $per_page);
    
    // 面包屑导航
    $breadcrumbs = [];
    if (!empty($relative_path)) {
        $path_parts = explode('/', $relative_path);
        $current_path = '';
        
        foreach ($path_parts as $part) {
            $current_path = $current_path ? $current_path . '/' . $part : $part;
            $breadcrumbs[] = [
                'name' => $part,
                'path' => $current_path
            ];
        }
    }
    
    return [
        'success' => true,
        'data' => [
            'items' => $paged_items,
            'current_path' => $relative_path,
            'breadcrumbs' => $breadcrumbs,
            'can_go_back' => !empty($relative_path)
        ],
        'pagination' => [
            'page' => $page,
            'per_page' => $per_page,
            'total' => $total,
            'total_pages' => $total_pages,
            'has_next' => $page < $total_pages,
            'has_prev' => $page > 1
        ]
    ];
}

/**
 * 删除指定文件或目录（管理员专用）
 */
function delete_admin_file($file_path) {
    // 安全检查：确保文件路径在data目录内
    $base_dir = __DIR__ . '/data';
    $full_path = $base_dir . '/' . ltrim($file_path, '/');
    
    // 规范化路径，防止目录遍历攻击
    $real_base = realpath($base_dir);
    $real_file = realpath($full_path);
    
    if (!$real_base) {
        return ['success' => false, 'reason' => 'base_dir_not_found'];
    }
    
    if (!$real_file || strpos($real_file, $real_base) !== 0) {
        return ['success' => false, 'reason' => 'invalid_path'];
    }
    
    if (!file_exists($real_file)) {
        return ['success' => false, 'reason' => 'file_not_found'];
    }
    
    if (is_dir($real_file)) {
        // 删除目录（递归删除）
        if (delete_directory_recursive($real_file)) {
            return ['success' => true];
        } else {
            return ['success' => false, 'reason' => 'delete_failed'];
        }
    }
    
    // 删除文件
    if (unlink($real_file)) {
        // 清理空的父目录
        $parent_dir = dirname($real_file);
        while ($parent_dir !== $real_base && is_dir($parent_dir) && count(scandir($parent_dir)) <= 2) {
            rmdir($parent_dir);
            $parent_dir = dirname($parent_dir);
        }
        
        return ['success' => true];
    } else {
        return ['success' => false, 'reason' => 'delete_failed'];
    }
}

/**
 * 递归删除目录
 */
function delete_directory_recursive($dir) {
    if (!is_dir($dir)) {
        return false;
    }
    
    $files = array_diff(scandir($dir), ['.', '..']);
    
    foreach ($files as $file) {
        $file_path = $dir . '/' . $file;
        if (is_dir($file_path)) {
            delete_directory_recursive($file_path);
        } else {
            unlink($file_path);
        }
    }
    
    return rmdir($dir);
}



/**
 * 获取MIME类型
 * @param string $extension 文件扩展名
 * @return string MIME类型
 */
function _get_mime_type($extension) {
    $mime_types = [
        // 图片
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'bmp' => 'image/bmp',
        'svg' => 'image/svg+xml',
        
        // 文档
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        
        // 文本
        'txt' => 'text/plain',
        'md' => 'text/markdown',
        'rtf' => 'application/rtf',
        'csv' => 'text/csv',
        
        // 压缩包
        'zip' => 'application/zip',
        'rar' => 'application/x-rar-compressed',
        '7z' => 'application/x-7z-compressed',
        'tar' => 'application/x-tar',
        'gz' => 'application/gzip',
        
        // 音视频
        'mp4' => 'video/mp4',
        'avi' => 'video/x-msvideo',
        'mov' => 'video/quicktime',
        'mkv' => 'video/x-matroska',
        'mp3' => 'audio/mpeg',
        'wav' => 'audio/wav',
        'flac' => 'audio/flac'
    ];
    
    return isset($mime_types[$extension]) ? $mime_types[$extension] : 'application/octet-stream';
}

/**
 * 编码文件名（支持中文）
 * @param string $filename 文件名
 * @return string 编码后的文件名
 */
function _encode_filename($filename) {
    return rawurlencode($filename);
}

/**
 * 处理范围请求（断点续传）
 * @param int $file_size 文件大小
 * @return array|false 范围信息或false
 */
function _handle_range_request($file_size) {
    if (!isset($_SERVER['HTTP_RANGE'])) {
        return false;
    }
    
    $range = $_SERVER['HTTP_RANGE'];
    if (!preg_match('/bytes=(\d+)-(\d*)/', $range, $matches)) {
        return false;
    }
    
    $start = intval($matches[1]);
    $end = empty($matches[2]) ? $file_size - 1 : intval($matches[2]);
    
    if ($start >= $file_size || $end >= $file_size || $start > $end) {
        return false;
    }
    
    return ['start' => $start, 'end' => $end];
}

/**
 * 输出文件内容
 * @param string $file_path 文件路径
 */
function _output_file($file_path) {
    $handle = fopen($file_path, 'rb');
    if ($handle) {
        while (!feof($handle)) {
            echo fread($handle, 8192);
            flush();
        }
        fclose($handle);
    }
}

/**
 * 输出文件范围内容
 * @param string $file_path 文件路径
 * @param int $start 开始位置
 * @param int $end 结束位置
 */
function _output_file_range($file_path, $start, $end) {
    $handle = fopen($file_path, 'rb');
    if ($handle) {
        fseek($handle, $start);
        $remaining = $end - $start + 1;
        
        while ($remaining > 0 && !feof($handle)) {
            $read_size = min(8192, $remaining);
            echo fread($handle, $read_size);
            $remaining -= $read_size;
            flush();
        }
        fclose($handle);
    }
}

/**
 * 下载文件（管理员专用）
 */
function download_admin_file($file_path) {
    // 安全检查：确保文件路径在data目录内
    $base_dir = __DIR__ . '/data';
    $full_path = $base_dir . '/' . ltrim($file_path, '/');
    
    // 规范化路径，防止目录遍历攻击
    $real_base = realpath($base_dir);
    $real_file = realpath($full_path);
    
    if (!$real_base) {
        return ['success' => false, 'reason' => 'base_dir_not_found'];
    }
    
    if (!$real_file || strpos($real_file, $real_base) !== 0) {
        return ['success' => false, 'reason' => 'invalid_path'];
    }
    
    if (!file_exists($real_file)) {
        return ['success' => false, 'reason' => 'file_not_found'];
    }
    
    if (is_dir($real_file)) {
        return ['success' => false, 'reason' => 'cannot_download_directory'];
    }
    
    // 检查文件是否可读
    if (!is_readable($real_file)) {
        return ['success' => false, 'reason' => 'file_not_readable'];
    }
    
    // 获取文件信息
    $file_size = filesize($real_file);
    $file_name = basename($real_file);
    $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
    
    // 检查文件大小限制（可选，防止下载过大文件）
    $max_download_size = 100 * 1024 * 1024; // 100MB限制
    if ($file_size > $max_download_size) {
        return ['success' => false, 'reason' => 'file_too_large'];
    }
    
    // 获取MIME类型
    $mime_type = _get_mime_type($file_ext);
    
    // 清除输出缓冲
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    // 设置下载头信息
    header('Content-Type: ' . $mime_type);
    header('Content-Length: ' . $file_size);
    header('Accept-Ranges: bytes');
    
    // 强制下载
    header('Content-Disposition: attachment; filename="' . _encode_filename($file_name) . '"');
    
    // 缓存控制
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Expires: 0');
    
    // 支持断点续传
    $range = _handle_range_request($file_size);
    
    if ($range) {
        // 处理范围请求
        header('HTTP/1.1 206 Partial Content');
        header('Content-Range: bytes ' . $range['start'] . '-' . $range['end'] . '/' . $file_size);
        header('Content-Length: ' . ($range['end'] - $range['start'] + 1));
        
        _output_file_range($real_file, $range['start'], $range['end']);
    } else {
        // 输出完整文件
        _output_file($real_file);
    }
    
    exit;
}

// 获取操作类型
$action = get_param('action', '', true);
$admin = get_authenticated_admin();

try {
    switch ($action) {
        
        // ============ 浏览目录结构 ============
        case 'browse_directory':
            $path = get_param('path', '');
            $page = (int)get_param('page', 1);
            $per_page = (int)get_param('per_page', 50);
            
            $result = browse_data_directory($path, $page, $per_page);
            
            if ($result['success']) {
                api_success($result['data'], '获取目录内容成功', $result['pagination']);
            } else {
                api_error($result['message'] ?? '获取目录内容失败');
            }
            break;
            
        // ============ 删除指定文件/目录 ============
        case 'delete_file':
            $file_path = get_param('file_path', '', true);
            
            $result = delete_admin_file($file_path);
            
            if ($result['success']) {
                api_success(null, '文件删除成功');
            } else {
                $error_messages = [
                    'base_dir_not_found' => 'data目录不存在',
                    'invalid_path' => '无效的文件路径',
                    'file_not_found' => '文件不存在',
                    'delete_failed' => '删除操作失败'
                ];
                
                api_error($error_messages[$result['reason']] ?? '删除文件失败');
            }
            break;   
        // ============ 下载文件 ============
        case 'download_file':
            $file_path = get_param('file_path', '', true);
            
            // 对于下载操作，直接输出文件内容
            download_admin_file($file_path);
            break;
            
        default:
            api_error('未知的操作类型: ' . $action);
    }
    
} catch (Exception $e) {
    // 记录错误日志
    error_log("Admin File API Error: " . $e->getMessage());
    api_error('系统错误，请稍后重试');
}
?>