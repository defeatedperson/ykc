<?php
if (basename($_SERVER['SCRIPT_FILENAME']) == basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden');
}
/**
 * 文件上传处理模块
 *
 * 提供文件上传、验证、保存等功能
 */

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/config.php';

/**
 * 文件上传函数：将用户上传的文件保存到指定目录
 * @param string $visit_jwt 访问JWT
 * @param string $ip 用户IP
 * @param array $file 上传文件信息 ($_FILES['file'])
 * @param string $target_path 目标相对路径（如：2024/7，空字符串表示根目录）
 * @return array ['success'=>bool, 'reason'=>string|null, 'file_info'=>array|null]
 */
function upload_user_file($visit_jwt, $ip, $file, $target_path = '') {
    // 验证用户身份
    $auth_result = verify_user_auth($visit_jwt, $ip);
    if (!$auth_result['valid']) {
        return ['success'=>false, 'reason'=>$auth_result['reason'], 'file_info'=>null];
    }
    
    $user_id = $auth_result['user_id'];
    
    // 检查文件上传是否正常
    if (!isset($file['name']) || !isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return ['success'=>false, 'reason'=>'invalid_upload', 'file_info'=>null];
    }
    
    // 检查上传错误
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error_messages = [
            UPLOAD_ERR_INI_SIZE => 'file_too_large_ini',
            UPLOAD_ERR_FORM_SIZE => 'file_too_large_form',
            UPLOAD_ERR_PARTIAL => 'upload_partial',
            UPLOAD_ERR_NO_FILE => 'no_file',
            UPLOAD_ERR_NO_TMP_DIR => 'no_tmp_dir',
            UPLOAD_ERR_CANT_WRITE => 'cant_write',
            UPLOAD_ERR_EXTENSION => 'extension_blocked'
        ];
        $reason = $error_messages[$file['error']] ?? 'upload_error';
        return ['success'=>false, 'reason'=>$reason, 'file_info'=>null];
    }
    
    // 验证文件名安全性
    $security_check = validate_file_security($file['name']);
    if (!$security_check['valid']) {
        return ['success'=>false, 'reason'=>$security_check['reason'], 'file_info'=>null];
    }
    
    $safe_filename = $security_check['safe_name'];
    
    // 检查存储空间限制（在文件操作之前）
    $storage_check = check_user_storage_space($user_id, $file['size']);
    if (!$storage_check['allowed']) {
        return ['success'=>false, 'reason'=>$storage_check['reason'], 'file_info'=>null];
    }
    
    // 构建目标目录
    $user_base_dir = get_user_data_dir($user_id);
    $target_dir = $user_base_dir . ($target_path ? '/' . ltrim($target_path, '/') : '');
    
    // 创建目录（如果不存在）
    if (!is_dir($target_dir)) {
        if (!mkdir($target_dir, 0777, true)) {
            return ['success'=>false, 'reason'=>'create_dir_failed', 'file_info'=>null];
        }
    }
    
    // 构建完整文件路径
    $target_file = $target_dir . '/' . $safe_filename;
    
    // 检查同名文件是否存在
    if (file_exists($target_file)) {
        return ['success'=>false, 'reason'=>'file_exists', 'file_info'=>null];
    }
    
    // 移动上传文件
    if (move_uploaded_file($file['tmp_name'], $target_file)) {
        // 获取文件信息
        $file_stat = stat($target_file);
        $relative_path = $target_path ? $target_path . '/' . $safe_filename : $safe_filename;
        
        // 清除相关缓存
        _clear_directory_cache($user_id, $target_path);
        
        $file_info = [
            'name' => $safe_filename,
            'path' => $relative_path,
            'size' => $file_stat['size'],
            'uploaded_time' => time(),
            'extension' => strtolower(pathinfo($safe_filename, PATHINFO_EXTENSION))
        ];
        
        return [
            'success' => true,
            'reason' => null,
            'file_info' => $file_info,
            'file_path' => $relative_path,
            'file_name' => $safe_filename
        ];
    } else {
        return ['success'=>false, 'reason'=>'move_file_failed', 'file_info'=>null];
    }
}

/**
 * 检查用户存储空间是否足够上传文件
 * @param int $user_id 用户ID
 * @param int $file_size 要上传的文件大小（字节）
 * @return array ['allowed'=>bool, 'reason'=>string|null, 'details'=>array]
 */
function check_user_storage_space($user_id, $file_size) {
    // 获取用户存储限制
    $storage_limit = get_user_storage_limit($user_id);
    
    // 如果用户无限制，直接允许
    if ($storage_limit === null) {
        return [
            'allowed' => true,
            'reason' => null,
            'details' => [
                'unlimited' => true,
                'file_size' => $file_size,
                'file_size_formatted' => format_bytes($file_size)
            ]
        ];
    }
    
    // 获取用户当前使用的存储空间
    $user_dir = get_user_data_dir($user_id);
    $used_space = 0;
    if (is_dir($user_dir)) {
        $used_space = get_directory_size($user_dir);
    }
    
    // 计算上传后的总大小
    $total_after_upload = $used_space + $file_size;
    
    // 检查是否超出限制
    if ($total_after_upload > $storage_limit) {
        $available_space = max(0, $storage_limit - $used_space);
        return [
            'allowed' => false,
            'reason' => 'storage_limit_exceeded',
            'details' => [
                'file_size' => $file_size,
                'file_size_formatted' => format_bytes($file_size),
                'used_space' => $used_space,
                'used_space_formatted' => format_bytes($used_space),
                'storage_limit' => $storage_limit,
                'storage_limit_formatted' => format_bytes($storage_limit),
                'available_space' => $available_space,
                'available_space_formatted' => format_bytes($available_space),
                'needed_space' => $file_size,
                'needed_space_formatted' => format_bytes($file_size),
                'overflow' => $total_after_upload - $storage_limit,
                'overflow_formatted' => format_bytes($total_after_upload - $storage_limit)
            ]
        ];
    }
    
    // 检查磁盘可用空间（如果启用）
    if (FileConfig::UPLOAD_SECURITY['check_disk_space']) {
        $disk_free = disk_free_space(__DIR__);
        $min_disk_space = FileConfig::UPLOAD_SECURITY['min_disk_space'];
        
        if ($disk_free !== false && ($disk_free - $file_size) < $min_disk_space) {
            return [
                'allowed' => false,
                'reason' => 'insufficient_disk_space',
                'details' => [
                    'file_size' => $file_size,
                    'file_size_formatted' => format_bytes($file_size),
                    'disk_free' => $disk_free,
                    'disk_free_formatted' => format_bytes($disk_free),
                    'min_disk_space' => $min_disk_space,
                    'min_disk_space_formatted' => format_bytes($min_disk_space)
                ]
            ];
        }
    }
    
    // 检查通过
    $available_space = $storage_limit - $used_space;
    return [
        'allowed' => true,
        'reason' => null,
        'details' => [
            'file_size' => $file_size,
            'file_size_formatted' => format_bytes($file_size),
            'used_space' => $used_space,
            'used_space_formatted' => format_bytes($used_space),
            'storage_limit' => $storage_limit,
            'storage_limit_formatted' => format_bytes($storage_limit),
            'available_space' => $available_space,
            'available_space_formatted' => format_bytes($available_space),
            'remaining_after_upload' => $available_space - $file_size,
            'remaining_after_upload_formatted' => format_bytes($available_space - $file_size)
        ]
    ];
}

/**
 * 获取用户上传限制信息（扩展版本）
 * @param string $user_id 用户ID
 * @return array 上传限制信息
 */
function get_user_upload_limits($user_id) {
    // 使用 common.php 中的基础限制获取
    $basic_limits = get_upload_limits();
    
    // 获取用户存储限制（使用配置文件）
    $storage_limit = get_user_storage_limit($user_id);
    $is_unlimited = is_unlimited_user($user_id);
    
    // 获取用户目录使用情况
    $user_dir = get_user_data_dir($user_id);
    $used_space = 0;
    if (is_dir($user_dir)) {
        $used_space = get_directory_size($user_dir);
    }
    
    return [
        'max_file_size' => $basic_limits['max_size'],
        'max_file_size_formatted' => format_bytes($basic_limits['max_size']),
        'max_files_per_upload' => $basic_limits['max_files'],
        'upload_max_filesize' => ini_get('upload_max_filesize'),
        'post_max_size' => ini_get('post_max_size'),
        'user_storage_limit' => $storage_limit,
        'user_storage_limit_formatted' => $is_unlimited ? '无限制' : format_bytes($storage_limit),
        'is_unlimited_user' => $is_unlimited,
        'used_space' => $used_space,
        'used_space_formatted' => format_bytes($used_space),
        'available_space' => $is_unlimited ? null : max(0, $storage_limit - $used_space),
        'available_space_formatted' => $is_unlimited ? '无限制' : format_bytes(max(0, $storage_limit - $used_space)),
        'allowed_extensions' => get_allowed_extensions(),
        'storage_usage_percentage' => $is_unlimited ? 0 : ($storage_limit > 0 ? round(($used_space / $storage_limit) * 100, 2) : 0)
    ];
}

/**
 * 获取目录大小
 */
function get_directory_size($dir) {
    $size = 0;
    if (is_dir($dir)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }
    }
    return $size;
}
?>
