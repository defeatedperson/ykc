<?php
/**
 * 模块1：分享验证模块
 * 负责验证分享代码和密码，返回分享信息
 */

// 防止直接访问
if (basename($_SERVER['SCRIPT_FILENAME']) == basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/download-config.php';

/**
 * 验证分享代码和密码
 * @param string $share_code 分享代码
 * @param string $password 访问密码（可选）
 * @return array 验证结果
 */
function validate_share_access($share_code, $password = '') {
    // 1. 验证分享代码格式
    if (!validate_share_code_format($share_code)) {
        return [
            'success' => false,
            'reason' => 'invalid_share_code',
            'message' => '无效的分享代码格式'
        ];
    }
    
    // 2. 连接数据库
    $pdo = get_share_database();
    if (!$pdo) {
        return [
            'success' => false,
            'reason' => 'database_error',
            'message' => '数据库连接失败'
        ];
    }
    
    try {
        // 3. 查询分享信息（JOIN查询获取完整信息）
        $stmt = $pdo->prepare("
            SELECT 
                s.id as share_id,
                s.share_name,
                s.share_code,
                s.file_id,
                s.created_time,
                s.view_count,
                s.download_count,
                s.access_password,
                s.extension,
                sf.file_name,
                sf.user_id,
                sf.file_path,
                sf.file_size,
                sf.file_type
            FROM shares s
            JOIN share_files sf ON s.file_id = sf.id
            WHERE s.share_code = ?
        ");
        
        $stmt->execute([$share_code]);
        $share_info = $stmt->fetch();
        
        if (!$share_info) {
            return [
                'success' => false,
                'reason' => 'share_not_found',
                'message' => '分享不存在或已失效'
            ];
        }
        
        // 4. 检查是否需要密码
        $has_password = !empty($share_info['access_password']);
        
        if ($has_password) {
            if (empty($password)) {
                return [
                    'success' => false,
                    'reason' => 'password_required',
                    'message' => '此分享需要访问密码',
                    'data' => [
                        'share_name' => $share_info['share_name'],
                        'has_password' => true,
                        'file_name' => $share_info['file_name'],
                        'file_size' => $share_info['file_size'],
                        'created_time' => $share_info['created_time']
                    ]
                ];
            }
            
            // 5. 验证密码
            if ($share_info['access_password'] !== $password) {
                return [
                    'success' => false,
                    'reason' => 'password_incorrect',
                    'message' => '访问密码错误'
                ];
            }
        }
        
        // 6. 验证文件是否存在
        $file_validation = validate_file_path_security(
            $share_info['user_id'], 
            $share_info['file_path']
        );
        
        if (!$file_validation['valid']) {
            $error_messages = [
                'user_dir_not_exists' => '用户目录不存在',
                'invalid_path' => '文件路径无效',
                'file_not_exists' => '文件不存在',
                'not_a_file' => '目标不是文件',
                'file_not_readable' => '文件不可读'
            ];
            
            return [
                'success' => false,
                'reason' => 'file_error',
                'message' => $error_messages[$file_validation['reason']] ?? '文件访问错误'
            ];
        }
        
        // 7. 解析扩展数据
        $extension_data = [];
        if (!empty($share_info['extension'])) {
            $extension_data = json_decode($share_info['extension'], true) ?: [];
        }
        
        // 8. 返回完整的分享信息
        return [
            'success' => true,
            'message' => '分享验证成功',
            'data' => [
                'share_id' => $share_info['share_id'],
                'share_name' => $share_info['share_name'],
                'share_code' => $share_info['share_code'],
                'file_name' => $share_info['file_name'],
                'file_size' => $share_info['file_size'],
                'file_type' => $share_info['file_type'],
                'user_id' => $share_info['user_id'],
                'file_path' => $share_info['file_path'],
                'full_file_path' => $file_validation['full_path'],
                'created_time' => $share_info['created_time'],
                'view_count' => $share_info['view_count'],
                'download_count' => $share_info['download_count'],
                'has_password' => $has_password,
                'extension_data' => $extension_data
            ]
        ];
        
    } catch (PDOException $e) {
        error_log("分享验证数据库错误: " . $e->getMessage());
        return [
            'success' => false,
            'reason' => 'database_error',
            'message' => '数据库查询失败'
        ];
    } catch (Exception $e) {
        error_log("分享验证异常: " . $e->getMessage());
        return [
            'success' => false,
            'reason' => 'system_error',
            'message' => '系统错误'
        ];
    }
}

/**
 * 快速检查分享代码是否存在（不验证密码）
 * @param string $share_code 分享代码
 * @return array 检查结果
 */
function check_share_exists($share_code) {
    if (!validate_share_code_format($share_code)) {
        return [
            'success' => false,
            'reason' => 'invalid_share_code'
        ];
    }
    
    $pdo = get_share_database();
    if (!$pdo) {
        return [
            'success' => false,
            'reason' => 'database_error'
        ];
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT 
                s.share_name,
                s.access_password,
                sf.file_name,
                sf.file_size,
                s.created_time
            FROM shares s
            JOIN share_files sf ON s.file_id = sf.id
            WHERE s.share_code = ?
        ");
        
        $stmt->execute([$share_code]);
        $share_info = $stmt->fetch();
        
        if (!$share_info) {
            return [
                'success' => false,
                'reason' => 'share_not_found'
            ];
        }
        
        return [
            'success' => true,
            'data' => [
                'share_name' => $share_info['share_name'],
                'file_name' => $share_info['file_name'],
                'file_size' => $share_info['file_size'], 
                'created_time' => $share_info['created_time'],
                'has_password' => !empty($share_info['access_password'])
            ]
        ];
        
    } catch (Exception $e) {
        error_log("检查分享存在性失败: " . $e->getMessage());
        return [
            'success' => false,
            'reason' => 'query_failed'
        ];
    }
}
?>
