<?php
/**
 * 分享信息管理模块  
 * 负责管理分享链接的创建、修改、删除等操作
 */

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/config.php';

/**
 * 创建分享
 * @param string $user_id 用户ID
 * @param int $file_id 分享文件ID
 * @param string $share_name 分享名称
 * @param string $access_password 访问密码（可选）
 * @param string $extension 拓展数据（可选）
 * @return array 操作结果
 */
function create_share($user_id, $file_id, $share_name, $access_password = '', $extension = '{}') {
    $pdo = get_share_db();
    if (!$pdo) {
        return ['success' => false, 'reason' => 'database_error'];
    }
    
    try {
        // 验证分享名称
        $name_validation = validate_share_name($share_name);
        if (!$name_validation['valid']) {
            return ['success' => false, 'reason' => $name_validation['reason']];
        }
        
        // 验证分享密码
        $password_validation = validate_share_password($access_password);
        if (!$password_validation['valid']) {
            return ['success' => false, 'reason' => $password_validation['reason']];
        }
        
        // 验证拓展数据
        $extension_validation = validate_extension($extension);
        if (!$extension_validation['valid']) {
            return ['success' => false, 'reason' => $extension_validation['reason']];
        }
        $normalized_extension = $extension_validation['normalized'];
        
        // 检查分享文件是否属于该用户
        $file_stmt = $pdo->prepare("SELECT id, file_name, file_path FROM share_files WHERE id = ? AND user_id = ?");
        $file_stmt->execute([$file_id, $user_id]);
        $file_info = $file_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$file_info) {
            return ['success' => false, 'reason' => 'file_not_found'];
        }
        
        // 生成唯一的分享代码
        $share_code = generate_share_code($pdo);
        
        // 创建分享记录
        $stmt = $pdo->prepare("
            INSERT INTO shares (share_name, share_code, file_id, created_time, view_count, download_count, access_password, extension)
            VALUES (?, ?, ?, datetime('now'), 0, 0, ?, ?)
        ");
        
        $stmt->execute([
            sanitize_input($share_name),
            $share_code,
            $file_id,
            $access_password,
            $normalized_extension
        ]);
        
        $share_id = $pdo->lastInsertId();
        
        // 更新文件的has_share状态
        $update_stmt = $pdo->prepare("UPDATE share_files SET has_share = 1 WHERE id = ?");
        $update_stmt->execute([$file_id]);
        
        return [
            'success' => true,
            'share' => [
                'id' => $share_id,
                'share_name' => $share_name,
                'share_code' => $share_code,
                'file_id' => $file_id,
                'created_time' => date('Y-m-d H:i:s'),
                'view_count' => 0,
                'download_count' => 0,
                'access_password' => $access_password,
                'extension' => $extension
            ]
        ];
        
    } catch (Exception $e) {
        error_log("创建分享失败: " . $e->getMessage());
        return ['success' => false, 'reason' => 'create_failed'];
    }
}

/**
 * 获取用户的分享列表
 * @param string $user_id 用户ID
 * @param int $page 页码
 * @param int $per_page 每页数量
 * @return array 分享列表
 */
function get_user_shares($user_id, $page = 1, $per_page = 20) {
    $pdo = get_share_db();
    if (!$pdo) {
        return ['success' => false, 'reason' => 'database_error'];
    }
    
    try {
        // 计算总数
        $count_stmt = $pdo->prepare("
            SELECT COUNT(*) FROM shares s 
            JOIN share_files sf ON s.file_id = sf.id 
            WHERE sf.user_id = ?
        ");
        $count_stmt->execute([$user_id]);
        $total = $count_stmt->fetchColumn();
        
        // 计算分页
        $total_pages = ceil($total / $per_page);
        $offset = ($page - 1) * $per_page;
        
        // 获取分享列表
        $stmt = $pdo->prepare("
            SELECT s.id, s.share_name, s.share_code, s.file_id, s.created_time, 
                   s.view_count, s.download_count, s.access_password, s.extension
            FROM shares s
            JOIN share_files sf ON s.file_id = sf.id
            WHERE sf.user_id = ?
            ORDER BY s.created_time DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$user_id, $per_page, $offset]);
        $shares = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'success' => true,
            'shares' => $shares,
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
        error_log("获取分享列表失败: " . $e->getMessage());
        return ['success' => false, 'reason' => 'query_failed'];
    }
}

/**
 * 搜索用户的分享（按分享名称搜索）
 * @param string $user_id 用户ID
 * @param string $keyword 搜索关键词
 * @param int $page 页码
 * @param int $per_page 每页数量
 * @return array 搜索结果
 */
function search_user_shares($user_id, $keyword, $page = 1, $per_page = 20) {
    $pdo = get_share_db();
    if (!$pdo) {
        return ['success' => false, 'reason' => 'database_error'];
    }
    
    try {
        $keyword = sanitize_input($keyword);
        $search_pattern = '%' . $keyword . '%';
        
        // 计算总数
        $count_stmt = $pdo->prepare("
            SELECT COUNT(*) FROM shares s 
            JOIN share_files sf ON s.file_id = sf.id 
            WHERE sf.user_id = ? AND s.share_name LIKE ?
        ");
        $count_stmt->execute([$user_id, $search_pattern]);
        $total = $count_stmt->fetchColumn();
        
        // 计算分页
        $total_pages = ceil($total / $per_page);
        $offset = ($page - 1) * $per_page;
        
        // 搜索分享
        $stmt = $pdo->prepare("
            SELECT s.id, s.share_name, s.share_code, s.file_id, s.created_time, 
                   s.view_count, s.download_count, s.access_password, s.extension
            FROM shares s
            JOIN share_files sf ON s.file_id = sf.id
            WHERE sf.user_id = ? AND s.share_name LIKE ?
            ORDER BY s.created_time DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$user_id, $search_pattern, $per_page, $offset]);
        $shares = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'success' => true,
            'shares' => $shares,
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
        error_log("搜索分享失败: " . $e->getMessage());
        return ['success' => false, 'reason' => 'search_failed'];
    }
}

/**
 * 修改分享信息
 * @param string $user_id 用户ID
 * @param int $share_id 分享ID
 * @param string $new_share_name 新的分享名称（可选）
 * @param string $new_password 新的访问密码（可选）
 * @param string $new_extension 新的拓展数据（可选）
 * @param int $new_file_id 新的文件ID（可选）
 * @return array 操作结果
 */
function update_share($user_id, $share_id, $new_share_name = null, $new_password = null, $new_extension = null, $new_file_id = null) {
    $pdo = get_share_db();
    if (!$pdo) {
        return ['success' => false, 'reason' => 'database_error'];
    }
    
    try {
        // 检查分享是否属于该用户
        $check_stmt = $pdo->prepare("
            SELECT s.share_name, s.access_password, s.extension 
            FROM shares s 
            JOIN share_files sf ON s.file_id = sf.id 
            WHERE s.id = ? AND sf.user_id = ?
        ");
        $check_stmt->execute([$share_id, $user_id]);
        $current_share = $check_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$current_share) {
            return ['success' => false, 'reason' => 'share_not_found'];
        }
        
        $updates = [];
        $params = [];
        
        // 更新分享名称
        if ($new_share_name !== null) {
            $name_validation = validate_share_name($new_share_name);
            if (!$name_validation['valid']) {
                return ['success' => false, 'reason' => $name_validation['reason']];
            }
            $updates[] = "share_name = ?";
            $params[] = sanitize_input($new_share_name);
        }
        
        // 更新访问密码（支持清空密码）
        if ($new_password !== null) {
            $password_validation = validate_share_password($new_password);
            if (!$password_validation['valid']) {
                return ['success' => false, 'reason' => $password_validation['reason']];
            }
            $updates[] = "access_password = ?";
            $params[] = $new_password; // 允许空字符串
        }
        
        // 更新拓展数据（支持清空为默认值）
        if ($new_extension !== null) {
            $extension_validation = validate_extension($new_extension);
            if (!$extension_validation['valid']) {
                return ['success' => false, 'reason' => $extension_validation['reason']];
            }
            $updates[] = "extension = ?";
            $params[] = $extension_validation['normalized']; // 使用标准化后的值
        }
        
        // 更新文件ID
        if ($new_file_id !== null && $new_file_id > 0) {
            // 检查新文件是否属于该用户
            $file_check_stmt = $pdo->prepare("SELECT id FROM share_files WHERE id = ? AND user_id = ?");
            $file_check_stmt->execute([$new_file_id, $user_id]);
            if (!$file_check_stmt->fetch()) {
                return ['success' => false, 'reason' => 'file_not_found'];
            }
            $updates[] = "file_id = ?";
            $params[] = $new_file_id;
        }
        
        if (empty($updates)) {
            return ['success' => false, 'reason' => 'no_updates'];
        }
        
        // 执行更新
        $params[] = $share_id;
        $update_sql = "UPDATE shares SET " . implode(', ', $updates) . " WHERE id = ?";
        $stmt = $pdo->prepare($update_sql);
        $stmt->execute($params);
        
        return ['success' => true];
        
    } catch (Exception $e) {
        error_log("更新分享信息失败: " . $e->getMessage());
        return ['success' => false, 'reason' => 'update_failed'];
    }
}

/**
 * 删除分享
 * @param string $user_id 用户ID
 * @param int $share_id 分享ID
 * @return array 操作结果
 */
function delete_share($user_id, $share_id) {
    $pdo = get_share_db();
    if (!$pdo) {
        return ['success' => false, 'reason' => 'database_error'];
    }
    
    try {
        $pdo->beginTransaction();
        
        // 获取分享信息
        $check_stmt = $pdo->prepare("
            SELECT s.file_id 
            FROM shares s 
            JOIN share_files sf ON s.file_id = sf.id 
            WHERE s.id = ? AND sf.user_id = ?
        ");
        $check_stmt->execute([$share_id, $user_id]);
        $share_info = $check_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$share_info) {
            $pdo->rollback();
            return ['success' => false, 'reason' => 'share_not_found'];
        }
        
        $file_id = $share_info['file_id'];
        
        // 删除分享记录
        $delete_stmt = $pdo->prepare("DELETE FROM shares WHERE id = ?");
        $delete_stmt->execute([$share_id]);
        
        // 检查该文件是否还有其他分享
        $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM shares WHERE file_id = ?");
        $count_stmt->execute([$file_id]);
        $remaining_shares = $count_stmt->fetchColumn();
        
        // 如果没有其他分享，更新has_share状态
        if ($remaining_shares == 0) {
            $update_stmt = $pdo->prepare("UPDATE share_files SET has_share = 0 WHERE id = ?");
            $update_stmt->execute([$file_id]);
        }
        
        $pdo->commit();
        
        return ['success' => true];
        
    } catch (Exception $e) {
        $pdo->rollback();
        error_log("删除分享失败: " . $e->getMessage());
        return ['success' => false, 'reason' => 'delete_failed'];
    }
}

/* 
 * ===============================================
 * 注意：分享访问与验证相关函数已移至download模块
 * 包括：get_share_by_code, verify_share_password, 
 *      increment_view_count, increment_download_count
 * ===============================================
 */
?>
