<?php
if (basename($_SERVER['SCRIPT_FILENAME']) == basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden');
}
/**
 * 文件更新操作模块
 *
 * 提供文件重命名、删除等功能
 */

require_once __DIR__ . '/common.php';

/**
 * 函数1：重命名文件
 * @param int $user_id 用户ID
 * @param string $file_path 文件相对路径（如：2024/7/test.txt）
 * @param string $new_name 新文件名（如：newtest.txt）
 * @return array ['success'=>bool, 'reason'=>string|null, 'new_path'=>string|null]
 */
function rename_user_file($user_id, $file_path, $new_name) {
    // 验证新文件名安全性
    $security_check = validate_file_security($new_name);
    if (!$security_check['valid']) {
        return ['success'=>false, 'reason'=>$security_check['reason'], 'new_path'=>null];
    }
    
    $safe_new_name = $security_check['safe_name'];
    
    // 构建文件路径
    $base_dir = __DIR__ . '/data/' . $user_id;
    $old_file_path = $base_dir . '/' . ltrim($file_path, '/');
    
    // 检查原文件是否存在
    if (!file_exists($old_file_path) || !is_file($old_file_path)) {
        return ['success'=>false, 'reason'=>'file_not_exists', 'new_path'=>null];
    }
    
    // 构建新文件路径
    $dir_path = dirname($old_file_path);
    $new_file_path = $dir_path . '/' . $safe_new_name;
    
    // 检查新文件名是否已存在
    if (file_exists($new_file_path)) {
        return ['success'=>false, 'reason'=>'target_file_exists', 'new_path'=>null];
    }
    
    // 执行重命名
    if (rename($old_file_path, $new_file_path)) {
        // 计算新的相对路径
        $new_relative_path = str_replace($base_dir . '/', '', $new_file_path);
        
        // 清除相关缓存
        _clear_directory_cache($user_id, dirname($file_path));
        
        return [
            'success' => true,
            'reason' => null,
            'new_path' => $new_relative_path
        ];
    } else {
        return ['success'=>false, 'reason'=>'rename_failed', 'new_path'=>null];
    }
}

/**
 * 函数2：删除文件
 * @param int $user_id 用户ID
 * @param string $file_path 文件相对路径（如：2024/7/test.txt）
 * @return array ['success'=>bool, 'reason'=>string|null]
 */
function delete_user_file($user_id, $file_path) {
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
    
    // 删除文件
    if (unlink($target_file)) {
        // 清除相关缓存
        _clear_directory_cache($user_id, dirname($file_path));
        
        return ['success'=>true, 'reason'=>null];
    } else {
        return ['success'=>false, 'reason'=>'delete_failed'];
    }
}

/**
 * 函数3：创建文件夹
 * @param int $user_id 用户ID
 * @param string $parent_path 父目录相对路径（如：2024/7 或 '' 表示根目录）
 * @param string $folder_name 新文件夹名称
 * @return array ['success'=>bool, 'reason'=>string|null, 'folder_path'=>string|null]
 */
function create_user_folder($user_id, $parent_path, $folder_name) {
    // 验证文件夹名称安全性
    if (empty($folder_name) || strlen($folder_name) > 100) {
        return ['success'=>false, 'reason'=>'invalid_folder_name', 'folder_path'=>null];
    }
    
    // 过滤非法字符
    $safe_folder_name = preg_replace('/[<>:"|?*\\\\\/]/', '', $folder_name);
    $safe_folder_name = trim($safe_folder_name, '. ');
    
    if (empty($safe_folder_name)) {
        return ['success'=>false, 'reason'=>'invalid_folder_name', 'folder_path'=>null];
    }
    
    // 构建路径
    $base_dir = __DIR__ . '/data/' . $user_id;
    $parent_dir = $base_dir . '/' . ltrim($parent_path, '/');
    $new_folder_path = $parent_dir . '/' . $safe_folder_name;
    
    // 检查父目录是否存在
    if (!is_dir($parent_dir)) {
        return ['success'=>false, 'reason'=>'parent_dir_not_exists', 'folder_path'=>null];
    }
    
    // 检查文件夹是否已存在
    if (file_exists($new_folder_path)) {
        return ['success'=>false, 'reason'=>'folder_already_exists', 'folder_path'=>null];
    }
    
    // 创建文件夹
    if (mkdir($new_folder_path, 0777, false)) {
        // 计算相对路径
        $relative_path = trim($parent_path . '/' . $safe_folder_name, '/');
        
        // 清除父目录缓存
        _clear_directory_cache($user_id, $parent_path);
        
        return [
            'success' => true,
            'reason' => null,
            'folder_path' => $relative_path
        ];
    } else {
        return ['success'=>false, 'reason'=>'create_folder_failed', 'folder_path'=>null];
    }
}

/**
 * 函数4：重命名文件夹
 * @param int $user_id 用户ID
 * @param string $folder_path 文件夹相对路径（如：2024/7/old_folder）
 * @param string $new_name 新文件夹名称
 * @return array ['success'=>bool, 'reason'=>string|null, 'new_path'=>string|null]
 */
function rename_user_folder($user_id, $folder_path, $new_name) {
    // 验证新文件夹名称安全性
    if (empty($new_name) || strlen($new_name) > 100) {
        return ['success'=>false, 'reason'=>'invalid_folder_name', 'new_path'=>null];
    }
    
    $safe_new_name = preg_replace('/[<>:"|?*\\\\\/]/', '', $new_name);
    $safe_new_name = trim($safe_new_name, '. ');
    
    if (empty($safe_new_name)) {
        return ['success'=>false, 'reason'=>'invalid_folder_name', 'new_path'=>null];
    }
    
    // 构建路径
    $base_dir = __DIR__ . '/data/' . $user_id;
    $old_folder_path = $base_dir . '/' . ltrim($folder_path, '/');
    
    // 检查原文件夹是否存在
    if (!is_dir($old_folder_path)) {
        return ['success'=>false, 'reason'=>'folder_not_exists', 'new_path'=>null];
    }
    
    // 构建新文件夹路径
    $parent_dir = dirname($old_folder_path);
    $new_folder_path = $parent_dir . '/' . $safe_new_name;
    
    // 检查新文件夹名是否已存在
    if (file_exists($new_folder_path)) {
        return ['success'=>false, 'reason'=>'target_folder_exists', 'new_path'=>null];
    }
    
    // 执行重命名
    if (rename($old_folder_path, $new_folder_path)) {
        // 计算新的相对路径
        $new_relative_path = str_replace($base_dir . '/', '', $new_folder_path);
        
        // 清除相关缓存
        _clear_directory_cache($user_id, dirname($folder_path));
        _clear_directory_cache($user_id, $folder_path); // 清除原文件夹缓存
        
        return [
            'success' => true,
            'reason' => null,
            'new_path' => $new_relative_path
        ];
    } else {
        return ['success'=>false, 'reason'=>'rename_folder_failed', 'new_path'=>null];
    }
}

/**
 * 函数5：删除文件夹
 * @param int $user_id 用户ID
 * @param string $folder_path 文件夹相对路径（如：2024/7/folder_to_delete）
 * @param bool $force_delete 是否强制删除非空文件夹
 * @return array ['success'=>bool, 'reason'=>string|null]
 */
function delete_user_folder($user_id, $folder_path, $force_delete = false) {
    // 构建路径
    $base_dir = __DIR__ . '/data/' . $user_id;
    $target_folder = $base_dir . '/' . ltrim($folder_path, '/');
    
    // 安全检查：确保路径在用户目录内
    $real_base = realpath($base_dir);
    $real_target = realpath($target_folder);
    
    if (!$real_target || strpos($real_target, $real_base) !== 0) {
        return ['success'=>false, 'reason'=>'invalid_path'];
    }
    
    // 检查文件夹是否存在
    if (!is_dir($target_folder)) {
        return ['success'=>false, 'reason'=>'folder_not_exists'];
    }
    
    // 检查是否为空文件夹
    $is_empty = (count(scandir($target_folder)) == 2); // 只有 . 和 ..
    
    if (!$is_empty && !$force_delete) {
        return ['success'=>false, 'reason'=>'folder_not_empty'];
    }
    
    // 删除文件夹
    $success = $is_empty ? rmdir($target_folder) : _delete_directory_recursive($target_folder);
    
    if ($success) {
        // 清除相关缓存
        _clear_directory_cache($user_id, dirname($folder_path));
        _clear_directory_cache($user_id, $folder_path);
        
        return ['success'=>true, 'reason'=>null];
    } else {
        return ['success'=>false, 'reason'=>'delete_folder_failed'];
    }
}

/**
 * 函数6：移动文件
 * @param int $user_id 用户ID
 * @param string $file_path 文件相对路径（如：2024/7/test.txt）
 * @param string $target_path 目标目录路径（如：2024/8）
 * @return array ['success'=>bool, 'reason'=>string|null, 'new_path'=>string|null]
 */
function move_user_file($user_id, $file_path, $target_path) {
    // 构建路径
    $base_dir = __DIR__ . '/data/' . $user_id;
    $source_file = $base_dir . '/' . ltrim($file_path, '/');
    $target_dir = $base_dir . '/' . ltrim($target_path, '/');
    
    // 检查源文件是否存在
    if (!file_exists($source_file) || !is_file($source_file)) {
        return ['success'=>false, 'reason'=>'file_not_exists', 'new_path'=>null];
    }
    
    // 检查目标目录是否存在
    if (!is_dir($target_dir)) {
        return ['success'=>false, 'reason'=>'target_dir_not_exists', 'new_path'=>null];
    }
    
    // 构建新文件路径
    $filename = basename($source_file);
    $new_file_path = $target_dir . '/' . $filename;
    
    // 检查目标文件是否已存在
    if (file_exists($new_file_path)) {
        return ['success'=>false, 'reason'=>'target_file_exists', 'new_path'=>null];
    }
    
    // 执行移动
    if (rename($source_file, $new_file_path)) {
        // 计算新的相对路径
        $new_relative_path = ltrim($target_path, '/') . '/' . $filename;
        
        // 清除相关缓存
        _clear_directory_cache($user_id, dirname($file_path));
        _clear_directory_cache($user_id, $target_path);
        
        return [
            'success' => true,
            'reason' => null,
            'new_path' => $new_relative_path
        ];
    } else {
        return ['success'=>false, 'reason'=>'move_failed', 'new_path'=>null];
    }
}

/**
 * 函数7：移动文件夹
 * @param int $user_id 用户ID
 * @param string $folder_path 文件夹相对路径（如：2024/7/folder）
 * @param string $target_path 目标目录路径（如：2024/8）
 * @return array ['success'=>bool, 'reason'=>string|null, 'new_path'=>string|null]
 */
function move_user_folder($user_id, $folder_path, $target_path) {
    // 构建路径
    $base_dir = __DIR__ . '/data/' . $user_id;
    $source_folder = $base_dir . '/' . ltrim($folder_path, '/');
    $target_dir = $base_dir . '/' . ltrim($target_path, '/');
    
    // 检查源文件夹是否存在
    if (!is_dir($source_folder)) {
        return ['success'=>false, 'reason'=>'folder_not_exists', 'new_path'=>null];
    }
    
    // 检查目标目录是否存在
    if (!is_dir($target_dir)) {
        return ['success'=>false, 'reason'=>'target_dir_not_exists', 'new_path'=>null];
    }
    
    // 构建新文件夹路径
    $folder_name = basename($source_folder);
    $new_folder_path = $target_dir . '/' . $folder_name;
    
    // 检查目标文件夹是否已存在
    if (file_exists($new_folder_path)) {
        return ['success'=>false, 'reason'=>'target_folder_exists', 'new_path'=>null];
    }
    
    // 检查是否试图移动到自己的子目录（防止无限循环）
    $real_source = realpath($source_folder);
    $real_target = realpath($target_dir);
    if (strpos($real_target, $real_source) === 0) {
        return ['success'=>false, 'reason'=>'cannot_move_to_subdirectory', 'new_path'=>null];
    }
    
    // 执行移动
    if (rename($source_folder, $new_folder_path)) {
        // 计算新的相对路径
        $new_relative_path = ltrim($target_path, '/') . '/' . $folder_name;
        
        // 清除相关缓存
        _clear_directory_cache($user_id, dirname($folder_path));
        _clear_directory_cache($user_id, $target_path);
        _clear_directory_cache($user_id, $folder_path);
        
        return [
            'success' => true,
            'reason' => null,
            'new_path' => $new_relative_path
        ];
    } else {
        return ['success'=>false, 'reason'=>'move_failed', 'new_path'=>null];
    }
}

/**
 * 辅助函数：递归删除目录
 * @param string $dir
 * @return bool
 */
function _delete_directory_recursive($dir) {
    if (!is_dir($dir)) {
        return false;
    }
    
    $files = array_diff(scandir($dir), ['.', '..']);
    foreach ($files as $file) {
        $file_path = $dir . '/' . $file;
        if (is_dir($file_path)) {
            _delete_directory_recursive($file_path);
        } else {
            unlink($file_path);
        }
    }
    
    return rmdir($dir);
}
?>
