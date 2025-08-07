<?php
/**
 * 分享文件管理模块
 * 负责管理用户的分享文件列表
 */

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/config.php';

/**
 * 添加文件到分享列表
 * @param string $user_id 用户ID
 * @param array $file_paths 文件路径数组
 * @return array 操作结果
 */
function add_files_to_share($user_id, $file_paths) {
    $pdo = get_share_db();
    if (!$pdo) {
        return ['success' => false, 'reason' => 'database_error'];
    }
    
    $added_files = [];
    $skipped_files = [];
    $error_files = [];
    
    try {
        $pdo->beginTransaction();
        
        foreach ($file_paths as $file_path) {
            $file_path = sanitize_input($file_path);
            
            // 验证文件路径安全性
            if (!validate_file_path($file_path)) {
                $error_files[] = ['path' => $file_path, 'reason' => '文件路径不安全'];
                continue;
            }
            
            // 检查用户是否有权限访问此文件
            if (!check_file_permission($user_id, $file_path)) {
                $error_files[] = ['path' => $file_path, 'reason' => '文件不存在或无权限访问'];
                continue;
            }
            
            // 检查文件是否已经添加到分享列表
            $check_stmt = $pdo->prepare("SELECT id FROM share_files WHERE user_id = ? AND file_path = ?");
            $check_stmt->execute([$user_id, $file_path]);
            
            if ($check_stmt->fetch()) {
                $skipped_files[] = ['path' => $file_path, 'reason' => '文件已在分享列表中'];
                continue;
            }
            
            // 获取文件信息
            $file_info = get_file_basic_info($user_id, $file_path);
            
            // 添加文件到分享列表
            $stmt = $pdo->prepare("
                INSERT INTO share_files (file_name, user_id, file_path, file_size, file_type, share_time, has_share)
                VALUES (?, ?, ?, ?, ?, datetime('now'), 0)
            ");
            
            $stmt->execute([
                $file_info['file_name'],
                $user_id,
                $file_path,
                $file_info['file_size'],
                $file_info['file_type']
            ]);
            
            $added_files[] = [
                'id' => $pdo->lastInsertId(),
                'file_name' => $file_info['file_name'],
                'file_path' => $file_path,
                'file_size' => $file_info['file_size'],
                'file_type' => $file_info['file_type'],
                'share_time' => date('Y-m-d H:i:s'),
                'has_share' => 0
            ];
        }
        
        $pdo->commit();
        
        return [
            'success' => true,
            'added' => $added_files,
            'skipped' => $skipped_files,
            'errors' => $error_files
        ];
        
    } catch (Exception $e) {
        $pdo->rollback();
        error_log("添加分享文件失败: " . $e->getMessage());
        return ['success' => false, 'reason' => 'operation_failed'];
    }
}

/**
 * 获取用户的分享文件列表
 * @param string $user_id 用户ID
 * @param int $page 页码
 * @param int $per_page 每页数量
 * @return array 分享文件列表
 */
function get_user_share_files($user_id, $page = 1, $per_page = 20) {
    $pdo = get_share_db();
    if (!$pdo) {
        return ['success' => false, 'reason' => 'database_error'];
    }
    
    try {
        // 计算总数
        $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM share_files WHERE user_id = ?");
        $count_stmt->execute([$user_id]);
        $total = $count_stmt->fetchColumn();
        
        // 计算分页
        $total_pages = ceil($total / $per_page);
        $offset = ($page - 1) * $per_page;
        
        // 获取分享文件列表
        $stmt = $pdo->prepare("
            SELECT id, file_name, user_id, file_path, file_size, file_type, share_time, has_share
            FROM share_files 
            WHERE user_id = ?
            ORDER BY share_time DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$user_id, $per_page, $offset]);
        $files = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 处理文件信息
        foreach ($files as &$file) {
            $file['has_share'] = (bool)$file['has_share'];
        }
        
        return [
            'success' => true,
            'files' => $files,
            'pagination' => [
                'page' => $page,
                'per_page' => $per_page,
                'total' => $total,
                'total_pages' => $total_pages,
                'has_next' => $page < $total_pages,
                'has_prev' => $page > 1
            ]
        ];
        
    } catch (Exception $e) {
        error_log("获取分享文件列表失败: " . $e->getMessage());
        return ['success' => false, 'reason' => 'query_failed'];
    }
}

/**
 * 搜索用户的分享文件
 * @param string $user_id 用户ID
 * @param string $keyword 搜索关键词
 * @param int $page 页码
 * @param int $per_page 每页数量
 * @return array 搜索结果
 */
function search_user_share_files($user_id, $keyword, $page = 1, $per_page = 20) {
    $pdo = get_share_db();
    if (!$pdo) {
        return ['success' => false, 'reason' => 'database_error'];
    }
    
    try {
        $keyword = sanitize_input($keyword);
        $search_pattern = '%' . $keyword . '%';
        
        // 计算总数
        $count_stmt = $pdo->prepare("
            SELECT COUNT(*) FROM share_files 
            WHERE user_id = ? AND (file_name LIKE ? OR file_path LIKE ?)
        ");
        $count_stmt->execute([$user_id, $search_pattern, $search_pattern]);
        $total = $count_stmt->fetchColumn();
        
        // 计算分页
        $total_pages = ceil($total / $per_page);
        $offset = ($page - 1) * $per_page;
        
        // 搜索分享文件
        $stmt = $pdo->prepare("
            SELECT id, file_name, user_id, file_path, file_size, file_type, share_time, has_share
            FROM share_files 
            WHERE user_id = ? AND (file_name LIKE ? OR file_path LIKE ?)
            ORDER BY share_time DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$user_id, $search_pattern, $search_pattern, $per_page, $offset]);
        $files = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 处理文件信息
        foreach ($files as &$file) {
            $file['has_share'] = (bool)$file['has_share'];
        }
        
        return [
            'success' => true,
            'files' => $files,
            'keyword' => $keyword,
            'pagination' => [
                'page' => $page,
                'per_page' => $per_page,
                'total' => $total,
                'total_pages' => $total_pages,
                'has_next' => $page < $total_pages,
                'has_prev' => $page > 1
            ]
        ];
        
    } catch (Exception $e) {
        error_log("搜索分享文件失败: " . $e->getMessage());
        return ['success' => false, 'reason' => 'search_failed'];
    }
}

/**
 * 删除分享文件（从分享列表中移除，但不删除原文件）
 * @param string $user_id 用户ID
 * @param int $file_id 分享文件ID
 * @return array 操作结果
 */
function remove_share_file($user_id, $file_id) {
    $pdo = get_share_db();
    if (!$pdo) {
        return ['success' => false, 'reason' => 'database_error'];
    }
    
    try {
        $pdo->beginTransaction();
        
        // 检查文件是否属于该用户
        $check_stmt = $pdo->prepare("SELECT id FROM share_files WHERE id = ? AND user_id = ?");
        $check_stmt->execute([$file_id, $user_id]);
        
        if (!$check_stmt->fetch()) {
            $pdo->rollback();
            return ['success' => false, 'reason' => 'file_not_found'];
        }
        
        // 删除关联的分享信息
        $delete_shares_stmt = $pdo->prepare("DELETE FROM shares WHERE file_id = ?");
        $delete_shares_stmt->execute([$file_id]);
        
        // 删除分享文件记录
        $delete_file_stmt = $pdo->prepare("DELETE FROM share_files WHERE id = ? AND user_id = ?");
        $delete_file_stmt->execute([$file_id, $user_id]);
        
        $pdo->commit();
        
        return ['success' => true];
        
    } catch (Exception $e) {
        $pdo->rollback();
        error_log("删除分享文件失败: " . $e->getMessage());
        return ['success' => false, 'reason' => 'delete_failed'];
    }
}

/**
 * 获取文件基本信息
 * @param string $user_id 用户ID
 * @param string $file_path 文件路径
 * @return array 文件信息
 */
function get_file_basic_info($user_id, $file_path) {
    $user_data_dir = __DIR__ . '/../file/data/' . $user_id;
    $full_file_path = $user_data_dir . '/' . ltrim($file_path, '/');
    
    if (!file_exists($full_file_path)) {
        return [
            'file_name' => basename($file_path),
            'file_size' => 0,
            'file_type' => 'other'
        ];
    }
    
    return [
        'file_name' => basename($file_path),
        'file_size' => filesize($full_file_path),
        'file_type' => get_file_type($full_file_path)
    ];
}

/**
 * 获取文件类型
 * @param string $file_path 文件路径
 * @return string 文件类型
 */
function get_file_type($file_path) {
    $extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
    
    $type_map = [
        'jpg' => 'image', 'jpeg' => 'image', 'png' => 'image', 'gif' => 'image', 'webp' => 'image',
        'mp4' => 'video', 'avi' => 'video', 'mkv' => 'video', 'mov' => 'video', 'wmv' => 'video',
        'mp3' => 'audio', 'wav' => 'audio', 'flac' => 'audio', 'aac' => 'audio', 'm4a' => 'audio',
        'pdf' => 'document', 'doc' => 'document', 'docx' => 'document', 'xls' => 'document', 'xlsx' => 'document',
        'txt' => 'text', 'md' => 'text', 'log' => 'text',
        'zip' => 'archive', 'rar' => 'archive', '7z' => 'archive', 'tar' => 'archive'
    ];
    
    return $type_map[$extension] ?? 'other';
}
?>