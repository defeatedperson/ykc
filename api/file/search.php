<?php
if (basename($_SERVER['SCRIPT_FILENAME']) == basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden');
}
/**
 * 文件搜索模块
 *
 * 提供目录内文件和文件夹搜索功能
 */

require_once __DIR__ . '/common.php';

/**
 * 搜索当前目录下的文件和文件夹（不递归搜索子目录）
 * @param string $visit_jwt 访问JWT
 * @param string $ip 用户IP
 * @param string $keyword 搜索关键字
 * @param string $search_path 搜索路径（相对路径，如：2024/7，空字符串表示根目录）
 * @return array ['success'=>bool, 'reason'=>string|null, 'results'=>array|null]
 */
function search_current_directory($visit_jwt, $ip, $keyword, $search_path = '') {
    // 验证用户身份
    $auth_result = verify_user_auth($visit_jwt, $ip);
    if (!$auth_result['valid']) {
        return ['success'=>false, 'reason'=>$auth_result['reason'], 'results'=>null];
    }
    
    $user_id = $auth_result['user_id'];
    
    // 验证搜索关键字
    require_once __DIR__ . '/../auth/safe-input.php';
    $safe_keyword = validate_common_input($keyword);
    if ($safe_keyword === false || empty(trim($safe_keyword))) {
        return ['success'=>false, 'reason'=>'invalid_keyword', 'results'=>null];
    }
    
    // 构建搜索目录路径
    $base_dir = __DIR__ . '/data/' . $user_id;
    $target_dir = $base_dir . ($search_path ? '/' . ltrim($search_path, '/') : '');
    
    // 检查目录是否存在
    if (!is_dir($target_dir)) {
        return ['success'=>false, 'reason'=>'directory_not_exists', 'results'=>null];
    }
    
    $results = [
        'folders' => [],
        'files' => [],
        'keyword' => $safe_keyword,
        'search_path' => $search_path,
        'search_time' => time()
    ];
    
    try {
        // 只扫描当前目录，不递归搜索子目录
        $items = scandir($target_dir);
        if ($items === false) {
            return ['success'=>false, 'reason'=>'scan_failed', 'results'=>null];
        }
        
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            
            // 检查文件名是否包含关键字（不区分大小写）
            if (stripos($item, $safe_keyword) === false) continue;
            
            $item_path = $target_dir . '/' . $item;
            
            // 确保是当前目录下的直接子项，不处理子目录内容
            if (!file_exists($item_path)) continue;
            
            $stat = stat($item_path);
            
            if (is_dir($item_path)) {
                // 文件夹匹配
                $results['folders'][] = [
                    'name' => $item,
                    'type' => 'folder',
                    'modified' => $stat['mtime'],
                    'path' => $search_path ? $search_path . '/' . $item : $item
                ];
            } else {
                // 文件匹配
                $results['files'][] = [
                    'name' => $item,
                    'type' => 'file',
                    'size' => $stat['size'],
                    'modified' => $stat['mtime'],
                    'extension' => strtolower(pathinfo($item, PATHINFO_EXTENSION)),
                    'path' => $search_path ? $search_path . '/' . $item : $item
                ];
            }
        }
        
        // 按修改时间排序（最新的在前）
        usort($results['folders'], function($a, $b) {
            return $b['modified'] - $a['modified'];
        });
        usort($results['files'], function($a, $b) {
            return $b['modified'] - $a['modified'];
        });
        
        // 添加统计信息
        $results['total_folders'] = count($results['folders']);
        $results['total_files'] = count($results['files']);
        $results['total_results'] = $results['total_folders'] + $results['total_files'];
        
        return [
            'success' => true,
            'reason' => null,
            'results' => $results
        ];
        
    } catch (Exception $e) {
        return ['success'=>false, 'reason'=>'search_error: ' . $e->getMessage(), 'results'=>null];
    }
}

/**
 * 高级搜索函数（支持多种搜索条件）
 * @param string $visit_jwt 访问JWT
 * @param string $ip 用户IP
 * @param array $search_options 搜索选项
 * @return array ['success'=>bool, 'reason'=>string|null, 'results'=>array|null]
 */
function advanced_search_files($visit_jwt, $ip, $search_options) {
    // 验证用户身份
    $auth_result = verify_user_auth($visit_jwt, $ip);
    if (!$auth_result['valid']) {
        return ['success'=>false, 'reason'=>$auth_result['reason'], 'results'=>null];
    }
    
    $user_id = $auth_result['user_id'];
    
    // 解析搜索选项
    $keyword = isset($search_options['keyword']) ? $search_options['keyword'] : '';
    $extension = isset($search_options['extension']) ? $search_options['extension'] : '';
    $search_path = isset($search_options['path']) ? $search_options['path'] : '';
    $file_type = isset($search_options['type']) ? $search_options['type'] : 'all'; // all, file, folder
    
    // 验证输入
    require_once __DIR__ . '/../auth/safe-input.php';
    if (!empty($keyword)) {
        $keyword = validate_common_input($keyword);
        if ($keyword === false) {
            return ['success'=>false, 'reason'=>'invalid_keyword', 'results'=>null];
        }
    }
    
    if (!empty($extension)) {
        $extension = validate_common_input($extension);
        if ($extension === false) {
            return ['success'=>false, 'reason'=>'invalid_extension', 'results'=>null];
        }
        $extension = strtolower($extension);
    }
    
    // 构建搜索目录路径
    $base_dir = __DIR__ . '/data/' . $user_id;
    $target_dir = $base_dir . ($search_path ? '/' . ltrim($search_path, '/') : '');
    
    // 检查目录是否存在
    if (!is_dir($target_dir)) {
        return ['success'=>false, 'reason'=>'directory_not_exists', 'results'=>null];
    }
    
    $results = [
        'folders' => [],
        'files' => [],
        'search_options' => $search_options,
        'search_time' => time()
    ];
    
    try {
        $items = scandir($target_dir);
        if ($items === false) {
            return ['success'=>false, 'reason'=>'scan_failed', 'results'=>null];
        }
        
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            
            $item_path = $target_dir . '/' . $item;
            $stat = stat($item_path);
            $is_folder = is_dir($item_path);
            
            // 应用过滤条件
            if ($file_type === 'file' && $is_folder) continue;
            if ($file_type === 'folder' && !$is_folder) continue;
            
            // 关键字过滤
            if (!empty($keyword) && stripos($item, $keyword) === false) continue;
            
            // 扩展名过滤（仅对文件有效）
            if (!empty($extension) && !$is_folder) {
                $item_ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
                if ($item_ext !== $extension) continue;
            }
            
            if ($is_folder) {
                $results['folders'][] = [
                    'name' => $item,
                    'type' => 'folder',
                    'modified' => $stat['mtime'],
                    'path' => $search_path ? $search_path . '/' . $item : $item
                ];
            } else {
                $results['files'][] = [
                    'name' => $item,
                    'type' => 'file',
                    'size' => $stat['size'],
                    'modified' => $stat['mtime'],
                    'extension' => strtolower(pathinfo($item, PATHINFO_EXTENSION)),
                    'path' => $search_path ? $search_path . '/' . $item : $item
                ];
            }
        }
        
        // 按修改时间排序
        usort($results['folders'], function($a, $b) {
            return $b['modified'] - $a['modified'];
        });
        usort($results['files'], function($a, $b) {
            return $b['modified'] - $a['modified'];
        });
        
        // 添加统计信息
        $results['total_folders'] = count($results['folders']);
        $results['total_files'] = count($results['files']);
        $results['total_results'] = $results['total_folders'] + $results['total_files'];
        
        return [
            'success' => true,
            'reason' => null,
            'results' => $results
        ];
        
    } catch (Exception $e) {
        return ['success'=>false, 'reason'=>'search_error: ' . $e->getMessage(), 'results'=>null];
    }
}
?>
