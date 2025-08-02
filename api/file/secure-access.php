<?php
/**
 * 安全文件访问端点
 * 使用JWT令牌验证文件访问权限
 */

require_once __DIR__ . '/../auth/jwt.php';
require_once __DIR__ . '/common.php';

// 设置响应头
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

try {
    // 获取JWT令牌
    $jwt = $_GET['jwt'] ?? $_POST['jwt'] ?? '';
    $file_path = $_GET['file_path'] ?? $_POST['file_path'] ?? '';
    $action = $_GET['action'] ?? $_POST['action'] ?? 'preview'; // preview 或 download
    
    if (empty($jwt)) {
        http_response_code(400);
        exit('缺少访问令牌');
    }
    
    if (empty($file_path)) {
        http_response_code(400);
        exit('缺少文件路径');
    }

    // 验证JWT令牌
    $client_ip = get_real_ip();
    $auth_result = verify_user_auth($jwt, $client_ip);
    
    if (!$auth_result['valid']) {
        http_response_code(403);
        exit('访问令牌无效: ' . $auth_result['reason']);
    }

    $user_id = $auth_result['user_id'];

    // 构建实际文件路径 - 与download.php保持一致
    $base_dir = __DIR__ . '/data/' . $user_id;
    $target_file = $base_dir . '/' . ltrim($file_path, '/');
    
    // 安全检查：确保路径在用户目录内
    $real_base = realpath($base_dir);
    $real_target = realpath($target_file);
    
    if (!$real_target || strpos($real_target, $real_base) !== 0) {
        http_response_code(403);
        exit('文件路径无效');
    }

    // 检查文件是否存在
    if (!file_exists($target_file) || !is_file($target_file)) {
        http_response_code(404);
        exit('文件不存在');
    }

    // 获取文件信息
    $file_size = filesize($target_file);
    $file_name = basename($file_path);
    $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

    // 根据操作类型设置响应头
    if ($action === 'download') {
        // 下载模式
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . addslashes($file_name) . '"');
        header('Cache-Control: no-cache, must-revalidate');
    } else {
        // 预览模式
        $mime_type = get_mime_type($file_ext);
        header('Content-Type: ' . $mime_type);
        
        // 对于图片和视频，允许缓存
        if (in_array($file_ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'webm'])) {
            header('Cache-Control: private, max-age=3600');
            header('ETag: "' . md5($file_path . filemtime($target_file)) . '"');
        } else {
            header('Cache-Control: no-cache, must-revalidate');
        }
    }

    // 设置通用响应头
    header('Content-Length: ' . $file_size);
    header('Accept-Ranges: bytes');

    // 支持断点续传
    $range = $_SERVER['HTTP_RANGE'] ?? '';
    if (!empty($range) && strpos($range, 'bytes=') === 0) {
        handle_range_request($target_file, $file_size, $range);
    } else {
        // 普通文件传输
        readfile_chunked($target_file);
    }

} catch (Exception $e) {
    error_log("安全文件访问失败: " . $e->getMessage());
    http_response_code(500);
    exit('服务器内部错误');
}

/**
 * 获取MIME类型
 */
function get_mime_type($extension) {
    $mime_types = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'avi' => 'video/x-msvideo',
        'mov' => 'video/quicktime',
        'pdf' => 'application/pdf',
        'txt' => 'text/plain',
        'zip' => 'application/zip',
    ];
    
    return $mime_types[$extension] ?? 'application/octet-stream';
}

/**
 * 分块读取文件（适用于大文件）
 */
function readfile_chunked($filename, $chunk_size = 8192) {
    $handle = fopen($filename, 'rb');
    if ($handle === false) {
        return false;
    }
    
    while (!feof($handle)) {
        $chunk = fread($handle, $chunk_size);
        echo $chunk;
        flush();
    }
    
    fclose($handle);
    return true;
}

/**
 * 处理HTTP Range请求（断点续传）
 */
function handle_range_request($file_path, $file_size, $range) {
    $ranges = explode('=', $range, 2);
    if (count($ranges) != 2 || $ranges[0] != 'bytes') {
        http_response_code(416);
        exit('Range Not Satisfiable');
    }
    
    $range_parts = explode('-', $ranges[1]);
    $start = intval($range_parts[0]);
    $end = isset($range_parts[1]) && !empty($range_parts[1]) ? intval($range_parts[1]) : $file_size - 1;
    
    if ($start > $end || $start >= $file_size) {
        http_response_code(416);
        exit('Range Not Satisfiable');
    }
    
    http_response_code(206);
    header("Content-Range: bytes $start-$end/$file_size");
    header("Content-Length: " . ($end - $start + 1));
    
    $handle = fopen($file_path, 'rb');
    fseek($handle, $start);
    
    $bytes_to_read = $end - $start + 1;
    $chunk_size = 8192;
    
    while ($bytes_to_read > 0 && !feof($handle)) {
        $read_size = min($chunk_size, $bytes_to_read);
        $chunk = fread($handle, $read_size);
        echo $chunk;
        flush();
        $bytes_to_read -= strlen($chunk);
    }
    
    fclose($handle);
}
?>
