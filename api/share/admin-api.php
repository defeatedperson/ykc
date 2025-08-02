<?php
/**
 * 管理员分享管理API
 * 提供查看所有用户分享信息和删除分享的功能
 */

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/share-manager.php';
require_once __DIR__ . '/file-manager.php';

// 获取并验证管理员身份
function get_admin_user() {
    // 从 Header 或 POST 参数获取 JWT
    $jwt = null;
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $auth_header = $_SERVER['HTTP_AUTHORIZATION'];
        if (preg_match('/Bearer\s+(.+)/', $auth_header, $matches)) {
            $jwt = $matches[1];
        }
    } elseif (isset($_POST['jwt'])) {
        $jwt = $_POST['jwt'];
    } elseif (isset($_GET['jwt'])) {
        $jwt = $_GET['jwt'];
    }
    
    if (!$jwt) {
        share_api_error('缺少访问令牌', 401);
    }
    
    // 获取用户IP
    $user_ip = get_real_ip_for_share();
    
    // 验证JWT并获取用户信息
    $user_info = get_user_info($jwt, $user_ip);
    if (!$user_info['valid']) {
        share_api_error('身份验证失败: ' . ($user_info['reason'] ?? 'unknown_error'), 401);
    }
    
    // 检查是否为管理员
    if (!$user_info['is_admin']) {
        share_api_error('权限不足，需要管理员权限', 403);
    }
    
    return [
        'user_id' => $user_info['user_id'],
        'jwt' => $jwt,
        'ip' => $user_ip
    ];
}

// 获取所有用户的分享文件列表
function get_all_share_files($page = 1, $per_page = 20) {
    $pdo = get_share_db();
    if (!$pdo) {
        return ['success' => false, 'reason' => 'database_error'];
    }
    
    try {
        // 计算总数
        $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM share_files");
        $count_stmt->execute();
        $total = $count_stmt->fetchColumn();
        
        // 计算分页
        $total_pages = ceil($total / $per_page);
        $offset = ($page - 1) * $per_page;
        
        // 获取所有分享文件列表
        $stmt = $pdo->prepare("
            SELECT id, file_name, user_id, file_path, file_size, file_type, share_time, has_share
            FROM share_files 
            ORDER BY share_time DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$per_page, $offset]);
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
        error_log("获取所有分享文件列表失败: " . $e->getMessage());
        return ['success' => false, 'reason' => 'query_failed'];
    }
}

// 获取所有用户的分享列表
function get_all_shares($page = 1, $per_page = 20) {
    $pdo = get_share_db();
    if (!$pdo) {
        return ['success' => false, 'reason' => 'database_error'];
    }
    
    try {
        // 计算总数
        $count_stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM shares s
            JOIN share_files sf ON s.file_id = sf.id
        ");
        $count_stmt->execute();
        $total = $count_stmt->fetchColumn();
        
        // 计算分页
        $total_pages = ceil($total / $per_page);
        $offset = ($page - 1) * $per_page;
        
        // 获取所有分享列表
        $stmt = $pdo->prepare("
            SELECT s.id, s.share_name, s.share_code, s.created_time, s.view_count, 
                   s.download_count, s.access_password, s.extension,
                   sf.file_name, sf.user_id, sf.file_size, sf.file_type
            FROM shares s
            JOIN share_files sf ON s.file_id = sf.id
            ORDER BY s.created_time DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$per_page, $offset]);
        $shares = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 处理分享信息
        foreach ($shares as &$share) {
            // 直接返回密码值，空密码时返回空字符串
            $share['access_password'] = $share['access_password'] ?? '';
        }
        
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
        error_log("获取所有分享列表失败: " . $e->getMessage());
        return ['success' => false, 'reason' => 'query_failed'];
    }
}

// 删除指定分享（管理员权限）
function admin_delete_share($share_id) {
    $pdo = get_share_db();
    if (!$pdo) {
        return ['success' => false, 'reason' => 'database_error'];
    }
    
    try {
        $pdo->beginTransaction();
        
        // 检查分享是否存在
        $check_stmt = $pdo->prepare("SELECT id FROM shares WHERE id = ?");
        $check_stmt->execute([$share_id]);
        
        if (!$check_stmt->fetch()) {
            $pdo->rollback();
            return ['success' => false, 'reason' => 'share_not_found'];
        }
        
        // 删除分享
        $delete_stmt = $pdo->prepare("DELETE FROM shares WHERE id = ?");
        $delete_stmt->execute([$share_id]);
        
        $pdo->commit();
        
        return ['success' => true];
        
    } catch (Exception $e) {
        $pdo->rollback();
        error_log("删除分享失败: " . $e->getMessage());
        return ['success' => false, 'reason' => 'delete_failed'];
    }
}

// API路由处理
header('Content-Type: application/json; charset=utf-8');

try {
    // 验证管理员权限
    $admin = get_admin_user();
    
    // 解析JSON请求体
    $input = json_decode(file_get_contents('php://input'), true);
    
    // 获取操作类型
    $action = $input['action'] ?? $_POST['action'] ?? $_GET['action'] ?? '';
    
    switch ($action) {
        case 'get_all_share_files':
            // 获取所有用户的分享文件
            $page = (int)($input['page'] ?? $_POST['page'] ?? $_GET['page'] ?? 1);
            $per_page = min(100, max(1, (int)($input['per_page'] ?? $_POST['per_page'] ?? $_GET['per_page'] ?? 20)));
            $result = get_all_share_files($page, $per_page);
            share_api_response($result['success'], $result['files'] ?? null, '', $result['pagination'] ?? null);
            break;
            
        case 'get_all_shares':
            // 获取所有用户的分享列表
            $page = (int)($input['page'] ?? $_POST['page'] ?? $_GET['page'] ?? 1);
            $per_page = min(100, max(1, (int)($input['per_page'] ?? $_POST['per_page'] ?? $_GET['per_page'] ?? 20)));
            $result = get_all_shares($page, $per_page);
            share_api_response($result['success'], $result['shares'] ?? null, '', $result['pagination'] ?? null);
            break;
            
        case 'delete_share':
            // 删除分享
            $share_id = (int)($input['share_id'] ?? $_POST['share_id'] ?? $_GET['share_id'] ?? 0);
            if ($share_id <= 0) {
                share_api_error('缺少必需参数: share_id');
            }
            $result = admin_delete_share($share_id);
            if ($result['success']) {
                share_api_success(null, '分享删除成功');
            } else {
                share_api_error($result['reason'] ?? '删除失败');
            }
            break;
            
        default:
            share_api_error('未知操作');
    }
    
} catch (Exception $e) {
    error_log("管理员API错误: " . $e->getMessage());
    share_api_error('服务器内部错误', 500);
}
?>