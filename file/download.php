<?php
if (basename($_SERVER['SCRIPT_FILENAME']) == basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden');
}
/**
 * 文件下载模块
 *
 * 提供安全的文件下载功能（通过PHP中转）
 */

require_once __DIR__ . '/common.php';

/**
 * 下载用户文件函数
 * @param string $visit_jwt 访问JWT
 * @param string $ip 用户IP
 * @param string $file_path 文件相对路径（如：2024/7/test.txt）
 * @param bool $force_download 是否强制下载（true：下载，false：可能在浏览器中预览）
 * @return array ['success'=>bool, 'reason'=>string|null] 或直接输出文件内容
 */
function download_user_file($visit_jwt, $ip, $file_path, $force_download = true) {
    // 验证用户身份
    $auth_result = verify_user_auth($visit_jwt, $ip);
    if (!$auth_result['valid']) {
        return ['success'=>false, 'reason'=>$auth_result['reason']];
    }
    
    $user_id = $auth_result['user_id'];
    
    // 构建文件路径
    $base_dir = __DIR__ . '/data/' . $user_id;
    $target_file = $base_dir . '/' . ltrim($file_path, '/');
    
    // 安全检查：确保路径在用户目录内
    $real_base = realpath($base_dir);
    $real_target = realpath($target_file);
    
    if (!$real_target || strpos($real_target, $real_base) !== 0) {
        return ['success'=>false, 'reason'=>'invalid_path'];
    }
    
    // 检查文件是否存在
    if (!file_exists($target_file)) {
        return ['success'=>false, 'reason'=>'file_not_exists'];
    }
    
    // 确保是文件而不是目录
    if (!is_file($target_file)) {
        return ['success'=>false, 'reason'=>'not_a_file'];
    }
    
    // 检查文件是否可读
    if (!is_readable($target_file)) {
        return ['success'=>false, 'reason'=>'file_not_readable'];
    }
    
    // 获取文件信息
    $file_size = filesize($target_file);
    $file_name = basename($target_file);
    $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
    
    // 检查文件大小限制（可选，防止下载过大文件）
    $max_download_size = 100 * 1024 * 1024; // 100MB限制
    if ($file_size > $max_download_size) {
        return ['success'=>false, 'reason'=>'file_too_large'];
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
    
    if ($force_download) {
        // 强制下载
        header('Content-Disposition: attachment; filename="' . _encode_filename($file_name) . '"');
    } else {
        // 可能在浏览器中预览
        header('Content-Disposition: inline; filename="' . _encode_filename($file_name) . '"');
    }
    
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
        
        _output_file_range($target_file, $range['start'], $range['end']);
    } else {
        // 输出完整文件
        _output_file($target_file);
    }
    
    exit(); // 直接输出文件后退出
}

/**
 * 获取文件信息（不下载）
 * @param string $visit_jwt 访问JWT
 * @param string $ip 用户IP
 * @param string $file_path 文件相对路径
 * @return array ['success'=>bool, 'reason'=>string|null, 'file_info'=>array|null]
 */
function get_file_info($visit_jwt, $ip, $file_path) {
    // 验证用户身份
    $auth_result = verify_user_auth($visit_jwt, $ip);
    if (!$auth_result['valid']) {
        return ['success'=>false, 'reason'=>$auth_result['reason'], 'file_info'=>null];
    }
    
    $user_id = $auth_result['user_id'];
    
    // 构建文件路径
    $base_dir = __DIR__ . '/data/' . $user_id;
    $target_file = $base_dir . '/' . ltrim($file_path, '/');
    
    // 安全检查
    $real_base = realpath($base_dir);
    $real_target = realpath($target_file);
    
    if (!$real_target || strpos($real_target, $real_base) !== 0) {
        return ['success'=>false, 'reason'=>'invalid_path', 'file_info'=>null];
    }
    
    if (!file_exists($target_file) || !is_file($target_file)) {
        return ['success'=>false, 'reason'=>'file_not_exists', 'file_info'=>null];
    }
    
    // 获取文件详细信息
    $stat = stat($target_file);
    $file_name = basename($target_file);
    $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
    
    $file_info = [
        'name' => $file_name,
        'path' => $file_path,
        'size' => $stat['size'],
        'size_formatted' => _format_file_size($stat['size']),
        'extension' => $file_ext,
        'mime_type' => _get_mime_type($file_ext),
        'created_time' => $stat['ctime'],
        'modified_time' => $stat['mtime'],
        'accessed_time' => $stat['atime'],
        'is_readable' => is_readable($target_file),
        'is_writable' => is_writable($target_file)
    ];
    
    return [
        'success' => true,
        'reason' => null,
        'file_info' => $file_info
    ];
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
 * 格式化文件大小
 * @param int $size 字节数
 * @return string 格式化后的大小
 */
function _format_file_size($size) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $unit_index = 0;
    
    while ($size >= 1024 && $unit_index < count($units) - 1) {
        $size /= 1024;
        $unit_index++;
    }
    
    return round($size, 2) . ' ' . $units[$unit_index];
}

?>
