<?php
if (basename($_SERVER['SCRIPT_FILENAME']) == basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * 获取允许的文件扩展名列表
 * @return array 允许的扩展名数组
 */
// 统一扩展名列表（常量）（所有扩展名）
const ALLOWED_EXTENSIONS = [
    'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'ico',
    'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
    'txt', 'md', 'rtf', 'csv',
    'zip', 'rar', '7z', 'tar', 'gz', 'bz2',
    'mp4', 'avi', 'mov', 'mkv', 'wmv', 'flv', 'webm', 'm4v',
    'mp3', 'wav', 'flac', 'aac', 'ogg', 'm4a',
    'html', 'css', 'js', 'php', 'py', 'java', 'cpp', 'c', 'cs', 'go', 'rs'
];

// 危险扩展名黑名单（常量）（禁止上传的扩展名）
const DANGEROUS_EXTENSIONS = [
    'php', 'php3', 'php4', 'php5', 'phtml', 'phps',
    'asp', 'aspx', 'jsp', 'js', 'vbs', 'wsf',
    'exe', 'bat', 'cmd', 'com', 'scr', 'msi',
    'sh', 'bash', 'csh', 'ksh', 'pl', 'py',
    'htaccess', 'htpasswd', 'ini', 'conf'
];

function get_allowed_extensions() {
    // 返回去除危险扩展名后的白名单（所有扩展名-禁止=允许上传的扩展名）
    return array_values(array_diff(ALLOWED_EXTENSIONS, DANGEROUS_EXTENSIONS));
}

/**
 * 文件管理通用功能模块
 *
 * 提供用户验证、文件安全校验、系统限制查询等基础功能
 */

/**
 * 获取用户真实IP（适配反向代理）
 * @return string
 */
function get_real_ip() {
    $ip = '';
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // 可能有多个IP，取第一个非unknown
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        foreach ($ips as $v) {
            $v = trim($v);
            if ($v && strtolower($v) !== 'unknown') {
                $ip = $v;
                break;
            }
        }
    }
    if (!$ip && !empty($_SERVER['HTTP_X_REAL_IP'])) {
        $ip = $_SERVER['HTTP_X_REAL_IP'];
    }
    if (!$ip && !empty($_SERVER['REMOTE_ADDR'])) {
        $ip = $_SERVER['REMOTE_ADDR'];
    }
    // 校验IP格式
    if (filter_var($ip, FILTER_VALIDATE_IP)) {
        return $ip;
    }
    return '0.0.0.0';
}

/**
 * 函数1：校验用户登录状态并获取用户信息（兼容新版JWT系统）
 * @param string $visit_jwt 访问JWT
 * @param string $ip 当前请求IP
 * @return array ['valid'=>bool, 'user_id'=>int|null, 'nickname'=>string|null, 'is_admin'=>bool, 'reason'=>string|null]
 */
function verify_user_auth($visit_jwt, $ip) {
    require_once __DIR__ . '/../auth/jwt.php';
    
    try {
        $result = get_user_info($visit_jwt, $ip);
        
        // 确保返回格式包含所有必要字段
        return [
            'valid' => $result['valid'] ?? false,
            'user_id' => $result['user_id'] ?? null,
            'nickname' => $result['nickname'] ?? null,
            'is_admin' => $result['is_admin'] ?? false,
            'reason' => $result['reason'] ?? null
        ];
    } catch (Exception $e) {
        // 记录错误日志
        error_log("File Common Auth Error: " . $e->getMessage());
        return [
            'valid' => false,
            'user_id' => null,
            'nickname' => null,
            'is_admin' => false,
            'reason' => 'auth_system_error'
        ];
    }
}

/**
 * 函数2：文件名称/扩展名安全校验
 * @param string $filename 文件名
 * @return array ['valid'=>bool, 'reason'=>string|null, 'safe_name'=>string|null]
 */
function validate_file_security($filename) {
    require_once __DIR__ . '/../auth/safe-input.php';
    
    // 基础输入校验
    $safe_name = validate_common_input($filename);
    if ($safe_name === false) {
        return ['valid'=>false, 'reason'=>'invalid_filename', 'safe_name'=>null];
    }
    
    // 获取扩展名
    $ext = strtolower(pathinfo($safe_name, PATHINFO_EXTENSION));
    
    // 危险扩展名黑名单（常量）
    if (in_array($ext, DANGEROUS_EXTENSIONS)) {
        return ['valid'=>false, 'reason'=>'dangerous_extension', 'safe_name'=>null];
    }
    
    // 允许的扩展名白名单（统一调用 get_allowed_extensions）
    $allowed_ext = get_allowed_extensions();
    if (!in_array($ext, $allowed_ext)) {
        return ['valid'=>false, 'reason'=>'forbidden_extension', 'safe_name'=>null];
    }
    
    // 检查文件名长度
    if (strlen($safe_name) > 255) {
        return ['valid'=>false, 'reason'=>'filename_too_long', 'safe_name'=>null];
    }
    
    return ['valid'=>true, 'reason'=>null, 'safe_name'=>$safe_name];
}

/**
 * 函数3：查询PHP上传限制
 * @return array ['max_files'=>int, 'max_size'=>int, 'max_post_size'=>int]
 */
function get_upload_limits() {
    // 最大同时上传文件数量
    $max_files = (int)ini_get('max_file_uploads');
    
    // 单个文件最大大小（字节）
    $max_file_size = _parse_size(ini_get('upload_max_filesize'));
    
    // POST最大大小（字节）
    $max_post_size = _parse_size(ini_get('post_max_size'));
    
    // 实际可用的最大文件大小是两者中的较小值
    $actual_max_size = min($max_file_size, $max_post_size);
    
    return [
        'max_files' => $max_files ?: 20, // 默认20个文件
        'max_size' => $actual_max_size,
        'max_post_size' => $max_post_size
    ];
}

/**
 * 辅助函数：解析PHP配置中的大小值（如2M, 1G等）
 * @param string $size_str
 * @return int 字节数
 */
function _parse_size($size_str) {
    $size_str = trim($size_str);
    $last = strtolower($size_str[strlen($size_str)-1]);
    $num = (int)$size_str;
    
    switch($last) {
        case 'g':
            $num *= 1024;
        case 'm':
            $num *= 1024;
        case 'k':
            $num *= 1024;
            break;
    }
    
    return $num;
}

/**
 * 辅助函数：清除目录缓存
 * @param int $user_id
 * @param string $path
 */
function _clear_directory_cache($user_id, $path) {
    try {
        $cache_dir = __DIR__ . '/cache/' . $user_id;
        $cache_name = $path ? str_replace(['/', '\\'], '_', $path) : 'root';
        $cache_file = $cache_dir . '/' . $cache_name . '.json';
        
        if (file_exists($cache_file)) {
            unlink($cache_file);
        }
    } catch (Exception $e) {
        // 缓存清除失败不影响主操作
    }
}



/**
 * 格式化字节数为人类可读格式
 * @param int $bytes 字节数
 * @param int $precision 小数位数
 * @return string 格式化后的大小
 */
function format_bytes($bytes, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB', 'PB');
    
    for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
        $bytes /= 1024;
    }
    
    return round($bytes, $precision) . ' ' . $units[$i];
}
?>
