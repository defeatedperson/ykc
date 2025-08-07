<?php
/**
 * 分享系统主API接口
 * 提供文件分享和分享管理的完整功能
 */

// 引入安全头设置
require_once __DIR__ . '/../auth/security-headers.php';

// 引入所有必需的模块
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/file-manager.php';
require_once __DIR__ . '/share-manager.php';

// 获取操作类型
$action = get_share_param('action', '', true);

// 定义不需要身份验证的操作
$public_actions = ['get_share', 'verify_password', 'record_download'];

// 对于需要身份验证的操作，验证用户身份
$user = null;
if (!in_array($action, $public_actions)) {
    $user = get_authenticated_share_user();
}

try {
    switch ($action) {
        
        // ============ 分享文件管理 ============
        
        /**
         * 添加文件到分享列表
         * 参数: file_paths (数组) - 文件路径列表
         */
        case 'add_files':
            $file_paths = get_share_param('file_paths', [], true);
            
            if (!is_array($file_paths) || empty($file_paths)) {
                share_api_error('请提供要分享的文件路径列表');
            }
            
            $result = add_files_to_share($user['user_id'], $file_paths);
            
            if ($result['success']) {
                share_api_success([
                    'added_count' => count($result['added']),
                    'skipped_count' => count($result['skipped']),
                    'error_count' => count($result['errors']),
                    'details' => $result
                ], '文件添加到分享列表成功');
            } else {
                share_api_error('添加文件到分享列表失败: ' . $result['reason']);
            }
            break;
            
        /**
         * 获取用户的分享文件列表
         * 参数: page (可选) - 页码, per_page (可选) - 每页数量
         */
        case 'list_share_files':
            $page = (int)get_share_param('page', 1);
            $per_page = (int)get_share_param('per_page', 20);
            
            $result = get_user_share_files($user['user_id'], $page, $per_page);
            
            if ($result['success']) {
                share_api_success($result['files'], '获取分享文件列表成功', $result['pagination']);
            } else {
                share_api_error('获取分享文件列表失败: ' . $result['reason']);
            }
            break;
            
        /**
         * 搜索分享文件
         * 参数: keyword - 搜索关键词, page (可选) - 页码, per_page (可选) - 每页数量
         */
        case 'search_share_files':
            $keyword = get_share_param('keyword', '', true);
            $page = (int)get_share_param('page', 1);
            $per_page = (int)get_share_param('per_page', 20);
            
            $result = search_user_share_files($user['user_id'], $keyword, $page, $per_page);
            
            if ($result['success']) {
                share_api_success(
                    $result['files'], 
                    "搜索到 {$result['pagination']['total']} 个匹配的分享文件", 
                    $result['pagination']
                );
            } else {
                share_api_error('搜索分享文件失败: ' . $result['reason']);
            }
            break;
            
        /**
         * 删除分享文件（从分享列表中移除）
         * 参数: file_id - 分享文件ID
         */
        case 'remove_share_file':
            $file_id = (int)get_share_param('file_id', 0, true);
            
            $result = remove_share_file($user['user_id'], $file_id);
            
            if ($result['success']) {
                share_api_success(null, '分享文件已从列表中移除');
            } else {
                $error_messages = [
                    'file_not_found' => '分享文件不存在或无权限访问',
                    'delete_failed' => '删除分享文件失败，请稍后重试'
                ];
                $error_message = $error_messages[$result['reason']] ?? '删除分享文件失败: ' . $result['reason'];
                share_api_error($error_message);
            }
            break;
            
        // ============ 分享信息管理 ============
        
        /**
         * 创建分享
         * 参数: file_id - 分享文件ID, share_name - 分享名称, access_password (可选) - 访问密码, extension (可选) - 拓展数据
         */
        case 'create_share':
            $file_id = (int)get_share_param('file_id', 0, true);
            $share_name = get_share_param('share_name', '', true);
            $access_password = get_share_param('access_password', '');
            // 直接获取extension参数，不进行JSON解析
            $extension = $_POST['extension'] ?? $_GET['extension'] ?? '{}';
            
            $result = create_share($user['user_id'], $file_id, $share_name, $access_password, $extension);
            
            if ($result['success']) {
                share_api_success($result['share'], '分享创建成功');
            } else {
                share_api_error('创建分享失败: ' . $result['reason']);
            }
            break;
            
        /**
         * 获取用户的分享列表
         * 参数: page (可选) - 页码, per_page (可选) - 每页数量
         */
        case 'list_shares':
            $page = (int)get_share_param('page', 1);
            $per_page = (int)get_share_param('per_page', 20);
            
            $result = get_user_shares($user['user_id'], $page, $per_page);
            
            if ($result['success']) {
                share_api_success($result['shares'], '获取分享列表成功', $result['pagination']);
            } else {
                share_api_error('获取分享列表失败: ' . $result['reason']);
            }
            break;
            
        /**
         * 搜索分享（按分享名称搜索）
         * 参数: keyword - 搜索关键词, page (可选) - 页码, per_page (可选) - 每页数量
         */
        case 'search_shares':
            $keyword = get_share_param('keyword', '', true);
            $page = (int)get_share_param('page', 1);
            $per_page = (int)get_share_param('per_page', 20);
            
            $result = search_user_shares($user['user_id'], $keyword, $page, $per_page);
            
            if ($result['success']) {
                share_api_success(
                    $result['shares'], 
                    "搜索到 {$result['pagination']['total']} 个匹配的分享", 
                    $result['pagination']
                );
            } else {
                share_api_error('搜索分享失败: ' . $result['reason']);
            }
            break;
            
        /**
         * 修改分享信息
         * 参数: share_id - 分享ID, share_name (可选) - 新的分享名称, access_password (可选) - 新的访问密码, extension (可选) - 新的拓展数据, file_id (可选) - 新的文件ID
         */
        case 'update_share':
            $share_id = (int)get_share_param('share_id', 0, true);
            $new_share_name = get_share_param('share_name');
            $new_password = get_share_param('access_password');
            // 直接获取extension参数，不进行JSON解析
            $new_extension = $_POST['extension'] ?? $_GET['extension'] ?? null;
            $new_file_id = get_share_param('file_id') ? (int)get_share_param('file_id') : null;
            
            // 至少需要提供一个要更新的字段
            if ($new_share_name === null && $new_password === null && $new_extension === null && $new_file_id === null) {
                share_api_error('请提供要更新的分享信息');
            }
            
            $result = update_share($user['user_id'], $share_id, $new_share_name, $new_password, $new_extension, $new_file_id);
            
            if ($result['success']) {
                share_api_success(null, '分享信息更新成功');
            } else {
                $error_messages = [
                    'share_not_found' => '分享不存在或无权限访问',
                    'file_not_found' => '指定的文件不存在或无权限访问',
                    'no_updates' => '没有提供需要更新的信息',
                    'update_failed' => '更新分享信息失败，请稍后重试'
                ];
                $error_message = $error_messages[$result['reason']] ?? '更新分享信息失败: ' . $result['reason'];
                share_api_error($error_message);
            }
            break;
            
        /**
         * 删除分享
         * 参数: share_id - 分享ID
         */
        case 'delete_share':
            $share_id = (int)get_share_param('share_id', 0, true);
            
            $result = delete_share($user['user_id'], $share_id);
            
            if ($result['success']) {
                share_api_success(null, '分享删除成功');
            } else {
                $error_messages = [
                    'share_not_found' => '分享不存在或无权限访问',
                    'delete_failed' => '删除分享失败，请稍后重试'
                ];
                $error_message = $error_messages[$result['reason']] ?? '删除分享失败: ' . $result['reason'];
                share_api_error($error_message);
            }
            break;
                
        // ============ 未知操作 ============
        default:
            share_api_error('未知操作: ' . $action);
    }
    
} catch (Exception $e) {
    error_log("分享API异常: " . $e->getMessage());
    share_api_error('服务器内部错误', 500);
}
?>
