<?php
/**
 * 模块2：文件下载模块
 * 负责基于临时Token的安全文件下载
 */

// 防止直接访问
if (basename($_SERVER['SCRIPT_FILENAME']) == basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/download-config.php';

/**
 * 验证并处理文件下载
 * @param string $token 临时下载Token
 * @param bool $force_download 是否强制下载
 * @return array 处理结果或直接输出文件
 */
function handle_file_download($token, $force_download = true) {
    // 1. 验证Token格式
    if (empty($token) || strlen($token) !== TEMP_TOKEN_LENGTH) {
        return [
            'success' => false,
            'reason' => 'invalid_token',
            'message' => '无效的下载Token'
        ];
    }
    
    // 2. 连接Token数据库
    $pdo = get_temp_token_database();
    if (!$pdo) {
        return [
            'success' => false,
            'reason' => 'database_error',
            'message' => '数据库连接失败'
        ];
    }
    
    try {
        // 3. 查询Token信息
        $stmt = $pdo->prepare("
            SELECT 
                id, token, share_code, user_id, file_path, file_name,
                created_time, expires_at, max_uses, used_count, is_active
            FROM temp_tokens 
            WHERE token = ? AND is_active = 1
        ");
        
        $stmt->execute([$token]);
        $token_info = $stmt->fetch();
        
        if (!$token_info) {
            return [
                'success' => false,
                'reason' => 'token_not_found',
                'message' => '下载Token不存在或已失效'
            ];
        }
        
        // 4. 检查Token是否过期
        $now = new DateTime();
        $expires_at = new DateTime($token_info['expires_at']);
        
        if ($now > $expires_at) {
            // 标记Token为非活跃状态
            $stmt = $pdo->prepare("UPDATE temp_tokens SET is_active = 0 WHERE id = ?");
            $stmt->execute([$token_info['id']]);
            
            return [
                'success' => false,
                'reason' => 'token_expired',
                'message' => '下载Token已过期'
            ];
        }
        
        // 5. 检查使用次数限制
        if ($token_info['used_count'] >= $token_info['max_uses']) {
            // 标记Token为非活跃状态
            $stmt = $pdo->prepare("UPDATE temp_tokens SET is_active = 0 WHERE id = ?");
            $stmt->execute([$token_info['id']]);
            
            return [
                'success' => false,
                'reason' => 'token_exhausted',
                'message' => '下载Token使用次数已达上限'
            ];
        }
        
        // 6. 验证文件是否存在和可访问
        $file_validation = validate_file_path_security(
            $token_info['user_id'], 
            $token_info['file_path']
        );
        
        if (!$file_validation['valid']) {
            return [
                'success' => false,
                'reason' => 'file_error',
                'message' => '文件不存在或无法访问'
            ];
        }
        
        $target_file = $file_validation['full_path'];
        
        // 7. 检查文件大小限制
        $file_size = filesize($target_file);
        if ($file_size > MAX_DOWNLOAD_SIZE) {
            return [
                'success' => false,
                'reason' => 'file_too_large',
                'message' => '文件大小超过下载限制'
            ];
        }
        
        // 8. 更新Token使用次数
        $stmt = $pdo->prepare("UPDATE temp_tokens SET used_count = used_count + 1 WHERE id = ?");
        $stmt->execute([$token_info['id']]);
        
        // 9. 输出文件内容
        output_file_content($target_file, $token_info['file_name'], $force_download);
        
        // 如果执行到这里说明文件输出成功（通常不会到达这里，因为文件输出会终止脚本）
        return ['success' => true];
        
    } catch (PDOException $e) {
        error_log("Token验证数据库错误: " . $e->getMessage());
        return [
            'success' => false,
            'reason' => 'database_error',
            'message' => '数据库查询失败'
        ];
    } catch (Exception $e) {
        error_log("文件下载异常: " . $e->getMessage());
        return [
            'success' => false,
            'reason' => 'system_error',
            'message' => '系统错误'
        ];
    }
}

/**
 * 输出文件内容到浏览器
 * @param string $file_path 文件完整路径
 * @param string $file_name 文件名
 * @param bool $force_download 是否强制下载
 */
function output_file_content($file_path, $file_name, $force_download = true) {
    $file_size = filesize($file_path);
    $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
    
    // 获取MIME类型
    $mime_type = get_mime_type($file_ext);
    
    // 清除输出缓冲
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    // 设置基本头信息
    header('Content-Type: ' . $mime_type);
    header('Content-Length: ' . $file_size);
    header('Accept-Ranges: bytes');
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');
    
    if ($force_download) {
        // 强制下载
        header('Content-Disposition: attachment; filename="' . addslashes($file_name) . '"');
    } else {
        // 内联显示（如果可能）
        header('Content-Disposition: inline; filename="' . addslashes($file_name) . '"');
    }
    
    // 处理Range请求（支持断点续传）
    if (isset($_SERVER['HTTP_RANGE'])) {
        handle_range_request($file_path, $file_size, $_SERVER['HTTP_RANGE']);
    } else {
        // 普通下载
        header('HTTP/1.1 200 OK');
        readfile_chunked($file_path);
    }
    
    exit; // 终止脚本执行
}

/**
 * 获取文件MIME类型
 * @param string $extension 文件扩展名
 * @return string MIME类型
 */
function get_mime_type($extension) {
    $mime_types = [
        // 图片
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        'bmp' => 'image/bmp',
        'ico' => 'image/x-icon',
        
        // 文档
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'txt' => 'text/plain',
        'rtf' => 'application/rtf',
        
        // 音频
        'mp3' => 'audio/mpeg',
        'wav' => 'audio/wav',
        'ogg' => 'audio/ogg',
        'flac' => 'audio/flac',
        'm4a' => 'audio/mp4',
        
        // 视频
        'mp4' => 'video/mp4',
        'avi' => 'video/x-msvideo',
        'mkv' => 'video/x-matroska',
        'mov' => 'video/quicktime',
        'wmv' => 'video/x-ms-wmv',
        'flv' => 'video/x-flv',
        'webm' => 'video/webm',
        
        // 压缩包
        'zip' => 'application/zip',
        'rar' => 'application/x-rar-compressed',
        '7z' => 'application/x-7z-compressed',
        'tar' => 'application/x-tar',
        'gz' => 'application/gzip',
        
        // 代码
        'html' => 'text/html',
        'css' => 'text/css',
        'js' => 'application/javascript',
        'json' => 'application/json',
        'xml' => 'application/xml',
        'php' => 'text/plain',
        'py' => 'text/plain',
        'java' => 'text/plain',
        'cpp' => 'text/plain',
        'c' => 'text/plain',
    ];
    
    return $mime_types[$extension] ?? 'application/octet-stream';
}

/**
 * 分块读取文件
 * @param string $filename 文件路径
 * @param int $chunk_size 块大小
 */
function readfile_chunked($filename, $chunk_size = 8192) {
    $handle = fopen($filename, 'rb');
    if ($handle === false) {
        return false;
    }
    
    while (!feof($handle)) {
        $buffer = fread($handle, $chunk_size);
        echo $buffer;
        flush(); // 刷新输出缓冲区
    }
    
    fclose($handle);
    return true;
}

/**
 * 处理HTTP Range请求（断点续传）
 * @param string $file_path 文件路径
 * @param int $file_size 文件大小
 * @param string $range Range头信息
 */
function handle_range_request($file_path, $file_size, $range) {
    // 解析Range头
    if (preg_match('/bytes=(\d+)-(\d*)/', $range, $matches)) {
        $start = intval($matches[1]);
        $end = !empty($matches[2]) ? intval($matches[2]) : $file_size - 1;
        
        if ($start >= $file_size || $end >= $file_size || $start > $end) {
            header('HTTP/1.1 416 Requested Range Not Satisfiable');
            header('Content-Range: bytes */' . $file_size);
            exit;
        }
        
        $length = $end - $start + 1;
        
        header('HTTP/1.1 206 Partial Content');
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $file_size);
        header('Content-Length: ' . $length);
        
        // 读取指定范围的文件内容
        $handle = fopen($file_path, 'rb');
        fseek($handle, $start);
        
        $remaining = $length;
        while ($remaining > 0 && !feof($handle)) {
            $chunk_size = min(8192, $remaining);
            $buffer = fread($handle, $chunk_size);
            echo $buffer;
            flush();
            $remaining -= strlen($buffer);
        }
        
        fclose($handle);
    } else {
        // 无效的Range头，返回完整文件
        header('HTTP/1.1 200 OK');
        readfile_chunked($file_path);
    }
}
?>
