<?php
if (basename($_SERVER['SCRIPT_FILENAME']) == basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden');
}
/**
 * 文件目录读取与缓存模块（带分页）
 *
 * 提供目录扫描、缓存生成、缓存查询等功能
 */

/**
 * 函数1：扫描用户目录并生成缓存（带分页）
 * @param int $user_id 用户ID
 * @param string $path 相对路径（如：2024/7 或 空字符串表示根目录）
 * @param int $page 页码（从1开始）
 * @param int $per_page 每页数量（默认20，最大50）
 * @return array ['success'=>bool, 'data'=>array|null, 'reason'=>string|null, 'pagination'=>array]
 */
function scan_user_directory($user_id, $path = '', $page = 1, $per_page = 20) {
    // 限制每页数量
    $per_page = min(max(1, $per_page), 50);
    $page = max(1, $page);
    
    // 构建实际目录路径
    $base_dir = __DIR__ . '/data/' . $user_id;
    $target_dir = $base_dir . ($path ? '/' . ltrim($path, '/') : '');
    
    // 检查目录是否存在，不存在则创建
    if (!is_dir($target_dir)) {
        // 尝试创建目录
        if (!is_dir($base_dir)) {
            mkdir($base_dir, 0755, true);
        }
        if (!empty($path) && !is_dir($target_dir)) {
            mkdir($target_dir, 0755, true);
        }
        
        // 如果还是不存在（创建失败），返回空目录结构而不是错误
        if (!is_dir($target_dir)) {
            return [
                'success'=>true, 
                'data'=>[
                    'items' => [],
                    'folders' => [],
                    'files' => [],
                    'total_folders' => 0,
                    'total_files' => 0,
                    'created_time' => time(),
                    'path' => $path
                ], 
                'reason'=>null,
                'pagination'=>[
                    'page'=>$page, 
                    'per_page'=>$per_page, 
                    'total'=>0, 
                    'total_pages'=>0,
                    'has_next'=>false,
                    'has_prev'=>false
                ]
            ];
        }
    }
    
    $files = [];
    $folders = [];
    
    try {
        $items = scandir($target_dir);
        if ($items === false) {
            return [
                'success'=>false, 
                'data'=>null, 
                'reason'=>'scan_failed',
                'pagination'=>['page'=>$page, 'per_page'=>$per_page, 'total'=>0, 'total_pages'=>0]
            ];
        }
        
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            
            $item_path = $target_dir . '/' . $item;
            $stat = stat($item_path);
            
            if (is_dir($item_path)) {
                // 文件夹信息
                $folders[] = [
                    'name' => $item,
                    'type' => 'folder',
                    'modified' => $stat['mtime']
                ];
            } else {
                // 文件信息
                $files[] = [
                    'name' => $item,
                    'type' => 'file',
                    'size' => $stat['size'],
                    'modified' => $stat['mtime'],
                    'extension' => strtolower(pathinfo($item, PATHINFO_EXTENSION))
                ];
            }
        }
        
        // 按修改时间排序（最新的在前）
        usort($folders, function($a, $b) {
            return $b['modified'] - $a['modified'];
        });
        usort($files, function($a, $b) {
            return $b['modified'] - $a['modified'];
        });
        
        // 合并文件夹和文件（文件夹在前）
        $all_items = array_merge($folders, $files);
        $total_items = count($all_items);
        
        // 计算分页
        $total_pages = ceil($total_items / $per_page);
        $offset = ($page - 1) * $per_page;
        $items_page = array_slice($all_items, $offset, $per_page);
        
        $result = [
            'items' => $items_page,
            'folders' => $folders,
            'files' => $files,
            'total_folders' => count($folders),
            'total_files' => count($files),
            'created_time' => time(),
            'path' => $path
        ];
        
        // 保存缓存
        $cache_saved = _save_directory_cache($user_id, $path, $result);
        
        return [
            'success' => true,
            'data' => $result,
            'reason' => null,
            'pagination' => [
                'page' => $page,
                'per_page' => $per_page,
                'total' => $total_items,
                'total_pages' => $total_pages,
                'has_next' => $page < $total_pages,
                'has_prev' => $page > 1
            ]
        ];
        
    } catch (Exception $e) {
        return [
            'success'=>false, 
            'data'=>null, 
            'reason'=>'scan_error: ' . $e->getMessage(),
            'pagination'=>['page'=>$page, 'per_page'=>$per_page, 'total'=>0, 'total_pages'=>0]
        ];
    }
}

/**
 * 函数2：获取目录信息（优先从缓存读取）
 * @param int $user_id 用户ID
 * @param string $path 相对路径
 * @param int $page 页码
 * @param int $per_page 每页数量
 * @param bool $force_refresh 是否强制刷新缓存
 * @return array ['success'=>bool, 'data'=>array|null, 'reason'=>string|null, 'pagination'=>array, 'from_cache'=>bool]
 */
function get_directory_info($user_id, $path = '', $page = 1, $per_page = 20, $force_refresh = false) {
    // 尝试从缓存读取
    if (!$force_refresh) {
        $cache_result = _load_directory_cache($user_id, $path);
        if ($cache_result['success']) {
            // 从缓存数据中重新分页
            $cached_data = $cache_result['data'];
            $all_items = array_merge($cached_data['folders'], $cached_data['files']);
            $total_items = count($all_items);
            
            // 计算分页
            $per_page = min(max(1, $per_page), 50);
            $page = max(1, $page);
            $total_pages = ceil($total_items / $per_page);
            $offset = ($page - 1) * $per_page;
            $items_page = array_slice($all_items, $offset, $per_page);
            
            $cached_data['items'] = $items_page;
            
            return [
                'success' => true,
                'data' => $cached_data,
                'reason' => null,
                'pagination' => [
                    'page' => $page,
                    'per_page' => $per_page,
                    'total' => $total_items,
                    'total_pages' => $total_pages,
                    'has_next' => $page < $total_pages,
                    'has_prev' => $page > 1
                ],
                'from_cache' => true
            ];
        }
    }
    
    // 缓存不存在或强制刷新，重新扫描
    $scan_result = scan_user_directory($user_id, $path, $page, $per_page);
    $scan_result['from_cache'] = false;
    return $scan_result;
}

/**
 * 辅助函数：保存目录缓存
 * @param int $user_id
 * @param string $path
 * @param array $data
 * @return bool
 */
function _save_directory_cache($user_id, $path, $data) {
    try {
        $cache_dir = __DIR__ . '/cache/' . $user_id;
        if (!is_dir($cache_dir)) {
            mkdir($cache_dir, 0777, true);
        }
        
        $cache_name = $path ? str_replace(['/', '\\'], '_', $path) : 'root';
        $cache_file = $cache_dir . '/' . $cache_name . '.json';
        
        return file_put_contents($cache_file, json_encode($data, JSON_UNESCAPED_UNICODE)) !== false;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * 辅助函数：加载目录缓存
 * @param int $user_id
 * @param string $path
 * @return array ['success'=>bool, 'data'=>array|null]
 */
function _load_directory_cache($user_id, $path) {
    try {
        $cache_dir = __DIR__ . '/cache/' . $user_id;
        $cache_name = $path ? str_replace(['/', '\\'], '_', $path) : 'root';
        $cache_file = $cache_dir . '/' . $cache_name . '.json';
        
        if (!file_exists($cache_file)) {
            return ['success'=>false, 'data'=>null];
        }
        
        // 检查缓存是否过期（30分钟）
        if (filemtime($cache_file) < time() - 1800) {
            return ['success'=>false, 'data'=>null];
        }
        
        $content = file_get_contents($cache_file);
        $data = json_decode($content, true);
        
        if ($data === null) {
            return ['success'=>false, 'data'=>null];
        }
        
        return ['success'=>true, 'data'=>$data];
    } catch (Exception $e) {
        return ['success'=>false, 'data'=>null];
    }
}
?>
