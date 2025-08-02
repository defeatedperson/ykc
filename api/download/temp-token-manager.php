<?php
/**
 * 模块4：临时Token管理模块
 * 负责临时下载Token的生成、验证和管理
 */

// 防止直接访问
if (basename($_SERVER['SCRIPT_FILENAME']) == basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/download-config.php';

/**
 * 生成临时下载Token
 * @param string $share_code 分享代码
 * @param int $user_id 用户ID
 * @param string $file_path 文件路径
 * @param string $file_name 文件名
 * @param int $max_uses 最大使用次数（默认5次）
 * @param int $expiry_hours 有效期小时数（默认12小时）
 * @return array 生成结果
 */
function generate_temp_token($share_code, $user_id, $file_path, $file_name, $max_uses = null, $expiry_hours = null) {
    // 参数验证
    if (empty($share_code) || empty($user_id) || empty($file_path) || empty($file_name)) {
        return [
            'success' => false,
            'reason' => 'invalid_parameters',
            'message' => '参数不完整'
        ];
    }
    
    // 使用默认值
    $max_uses = $max_uses ?? TEMP_TOKEN_MAX_USES;
    $expiry_hours = $expiry_hours ?? TEMP_TOKEN_EXPIRY_HOURS;
    
    // 验证文件路径安全性
    $file_validation = validate_file_path_security($user_id, $file_path);
    if (!$file_validation['valid']) {
        return [
            'success' => false,
            'reason' => 'invalid_file_path',
            'message' => '文件路径无效或不安全'
        ];
    }
    
    $pdo = get_temp_token_database();
    if (!$pdo) {
        return [
            'success' => false,
            'reason' => 'database_error',
            'message' => '数据库连接失败'
        ];
    }
    
    try {
        // 清理该分享的旧Token（可选，避免积累过多无用Token）
        cleanup_expired_tokens_for_share($share_code);
        
        // 生成唯一Token
        $token = generate_unique_token($pdo);
        $expires_at = date('Y-m-d H:i:s', strtotime("+{$expiry_hours} hours"));
        
        // 插入Token记录
        $stmt = $pdo->prepare("
            INSERT INTO temp_tokens 
            (token, share_code, user_id, file_path, file_name, created_time, expires_at, max_uses, used_count, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 1)
        ");
        
        $stmt->execute([
            $token,
            $share_code,
            $user_id,
            $file_path,
            $file_name,
            date('Y-m-d H:i:s'),
            $expires_at,
            $max_uses
        ]);
        
        return [
            'success' => true,
            'token' => $token,
            'expires_at' => $expires_at,
            'max_uses' => $max_uses,
            'file_name' => $file_name
        ];
        
    } catch (PDOException $e) {
        error_log("生成临时Token数据库错误: " . $e->getMessage());
        return [
            'success' => false,
            'reason' => 'database_error',
            'message' => '数据库操作失败'
        ];
    } catch (Exception $e) {
        error_log("生成临时Token异常: " . $e->getMessage());
        return [
            'success' => false,
            'reason' => 'system_error',
            'message' => '系统错误'
        ];
    }
}

/**
 * 验证临时Token
 * @param string $token Token字符串
 * @return array 验证结果
 */
function verify_temp_token($token) {
    if (empty($token) || strlen($token) !== TEMP_TOKEN_LENGTH) {
        return [
            'success' => false,
            'reason' => 'invalid_token_format',
            'message' => 'Token格式无效'
        ];
    }
    
    $pdo = get_temp_token_database();
    if (!$pdo) {
        return [
            'success' => false,
            'reason' => 'database_error',
            'message' => '数据库连接失败'
        ];
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT 
                id, token, share_code, user_id, file_path, file_name,
                created_time, expires_at, max_uses, used_count, is_active
            FROM temp_tokens 
            WHERE token = ? AND is_active = 1
        ");
        
        $stmt->execute([$token]);
        $token_info = $stmt->fetch();
        
        if (!$token_info) {
            return [
                'success' => false,
                'reason' => 'token_not_found',
                'message' => 'Token不存在或已失效'
            ];
        }
        
        // 检查是否过期
        $now = new DateTime();
        $expires_at = new DateTime($token_info['expires_at']);
        
        if ($now > $expires_at) {
            // 标记为非活跃状态
            $stmt = $pdo->prepare("UPDATE temp_tokens SET is_active = 0 WHERE id = ?");
            $stmt->execute([$token_info['id']]);
            
            return [
                'success' => false,
                'reason' => 'token_expired',
                'message' => 'Token已过期'
            ];
        }
        
        // 检查使用次数
        if ($token_info['used_count'] >= $token_info['max_uses']) {
            // 标记为非活跃状态
            $stmt = $pdo->prepare("UPDATE temp_tokens SET is_active = 0 WHERE id = ?");
            $stmt->execute([$token_info['id']]);
            
            return [
                'success' => false,
                'reason' => 'token_exhausted',
                'message' => 'Token使用次数已达上限'
            ];
        }
        
        return [
            'success' => true,
            'token_info' => $token_info,
            'remaining_uses' => $token_info['max_uses'] - $token_info['used_count'],
            'expires_in_seconds' => $expires_at->getTimestamp() - $now->getTimestamp()
        ];
        
    } catch (PDOException $e) {
        error_log("验证临时Token数据库错误: " . $e->getMessage());
        return [
            'success' => false,
            'reason' => 'database_error',
            'message' => '数据库查询失败'
        ];
    } catch (Exception $e) {
        error_log("验证临时Token异常: " . $e->getMessage());
        return [
            'success' => false,
            'reason' => 'system_error',
            'message' => '系统错误'
        ];
    }
}

/**
 * 使用Token（增加使用次数）
 * @param string $token Token字符串
 * @return array 使用结果
 */
function use_temp_token($token) {
    $verification = verify_temp_token($token);
    if (!$verification['success']) {
        return $verification;
    }
    
    $pdo = get_temp_token_database();
    if (!$pdo) {
        return [
            'success' => false,
            'reason' => 'database_error',
            'message' => '数据库连接失败'
        ];
    }
    
    try {
        $token_info = $verification['token_info'];
        
        // 增加使用次数
        $stmt = $pdo->prepare("UPDATE temp_tokens SET used_count = used_count + 1 WHERE id = ?");
        $stmt->execute([$token_info['id']]);
        
        $new_used_count = $token_info['used_count'] + 1;
        $remaining_uses = $token_info['max_uses'] - $new_used_count;
        
        // 如果达到最大使用次数，标记为非活跃
        if ($remaining_uses <= 0) {
            $stmt = $pdo->prepare("UPDATE temp_tokens SET is_active = 0 WHERE id = ?");
            $stmt->execute([$token_info['id']]);
        }
        
        return [
            'success' => true,
            'used_count' => $new_used_count,
            'remaining_uses' => $remaining_uses,
            'token_exhausted' => $remaining_uses <= 0
        ];
        
    } catch (PDOException $e) {
        error_log("使用临时Token数据库错误: " . $e->getMessage());
        return [
            'success' => false,
            'reason' => 'database_error',
            'message' => '数据库操作失败'
        ];
    }
}

/**
 * 撤销Token
 * @param string $token Token字符串
 * @return array 撤销结果
 */
function revoke_temp_token($token) {
    if (empty($token)) {
        return [
            'success' => false,
            'reason' => 'invalid_token',
            'message' => 'Token不能为空'
        ];
    }
    
    $pdo = get_temp_token_database();
    if (!$pdo) {
        return [
            'success' => false,
            'reason' => 'database_error',
            'message' => '数据库连接失败'
        ];
    }
    
    try {
        $stmt = $pdo->prepare("UPDATE temp_tokens SET is_active = 0 WHERE token = ?");
        $stmt->execute([$token]);
        
        if ($stmt->rowCount() > 0) {
            return [
                'success' => true,
                'message' => 'Token已撤销'
            ];
        } else {
            return [
                'success' => false,
                'reason' => 'token_not_found',
                'message' => 'Token不存在'
            ];
        }
        
    } catch (PDOException $e) {
        error_log("撤销临时Token数据库错误: " . $e->getMessage());
        return [
            'success' => false,
            'reason' => 'database_error',
            'message' => '数据库操作失败'
        ];
    }
}

/**
 * 获取分享的所有Token
 * @param string $share_code 分享代码
 * @param bool $include_inactive 是否包含非活跃Token
 * @return array Token列表
 */
function get_tokens_for_share($share_code, $include_inactive = false) {
    if (empty($share_code)) {
        return [
            'success' => false,
            'reason' => 'invalid_share_code',
            'message' => '分享代码不能为空'
        ];
    }
    
    $pdo = get_temp_token_database();
    if (!$pdo) {
        return [
            'success' => false,
            'reason' => 'database_error',
            'message' => '数据库连接失败'
        ];
    }
    
    try {
        $where_clause = "WHERE share_code = ?";
        $params = [$share_code];
        
        if (!$include_inactive) {
            $where_clause .= " AND is_active = 1";
        }
        
        $stmt = $pdo->prepare("
            SELECT 
                token, file_path, file_name, created_time, expires_at,
                max_uses, used_count, is_active
            FROM temp_tokens 
            {$where_clause}
            ORDER BY created_time DESC
        ");
        
        $stmt->execute($params);
        $tokens = $stmt->fetchAll();
        
        return [
            'success' => true,
            'tokens' => $tokens,
            'count' => count($tokens)
        ];
        
    } catch (PDOException $e) {
        error_log("获取分享Token数据库错误: " . $e->getMessage());
        return [
            'success' => false,
            'reason' => 'database_error',
            'message' => '数据库查询失败'
        ];
    }
}

/**
 * 生成唯一Token
 * @param PDO $pdo 数据库连接
 * @return string 唯一Token
 */
function generate_unique_token($pdo) {
    $max_attempts = 10; // 最大尝试次数，避免无限循环
    
    for ($i = 0; $i < $max_attempts; $i++) {
        $token = bin2hex(random_bytes(TEMP_TOKEN_LENGTH / 2));
        
        // 检查Token是否已存在
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM temp_tokens WHERE token = ?");
        $stmt->execute([$token]);
        
        if ($stmt->fetchColumn() == 0) {
            return $token;
        }
    }
    
    // 如果多次尝试都失败，使用带时间戳的Token
    return bin2hex(random_bytes(TEMP_TOKEN_LENGTH / 2 - 4)) . dechex(time());
}

/**
 * 清理过期Token
 * @return array 清理结果
 */
function cleanup_expired_tokens() {
    $pdo = get_temp_token_database();
    if (!$pdo) {
        return [
            'success' => false,
            'reason' => 'database_error',
            'message' => '数据库连接失败'
        ];
    }
    
    try {
        // 标记过期Token为非活跃
        $stmt = $pdo->prepare("
            UPDATE temp_tokens 
            SET is_active = 0 
            WHERE expires_at < datetime('now') AND is_active = 1
        ");
        $stmt->execute();
        $expired_count = $stmt->rowCount();
        
        // 删除超过30天的旧记录
        $stmt = $pdo->prepare("
            DELETE FROM temp_tokens 
            WHERE created_time < datetime('now', '-30 days')
        ");
        $stmt->execute();
        $deleted_count = $stmt->rowCount();
        
        return [
            'success' => true,
            'expired_count' => $expired_count,
            'deleted_count' => $deleted_count
        ];
        
    } catch (PDOException $e) {
        error_log("清理过期Token数据库错误: " . $e->getMessage());
        return [
            'success' => false,
            'reason' => 'database_error',
            'message' => '数据库操作失败'
        ];
    }
}

/**
 * 清理指定分享的过期Token
 * @param string $share_code 分享代码
 * @return array 清理结果
 */
function cleanup_expired_tokens_for_share($share_code) {
    $pdo = get_temp_token_database();
    if (!$pdo) {
        return [
            'success' => false,
            'reason' => 'database_error',
            'message' => '数据库连接失败'
        ];
    }
    
    try {
        $stmt = $pdo->prepare("
            UPDATE temp_tokens 
            SET is_active = 0 
            WHERE share_code = ? AND expires_at < datetime('now') AND is_active = 1
        ");
        $stmt->execute([$share_code]);
        
        return [
            'success' => true,
            'cleaned_count' => $stmt->rowCount()
        ];
        
    } catch (PDOException $e) {
        error_log("清理分享过期Token数据库错误: " . $e->getMessage());
        return [
            'success' => false,
            'reason' => 'database_error',
            'message' => '数据库操作失败'
        ];
    }
}

/**
 * 获取Token统计信息
 * @return array 统计信息
 */
function get_token_statistics() {
    $pdo = get_temp_token_database();
    if (!$pdo) {
        return [
            'success' => false,
            'reason' => 'database_error',
            'message' => '数据库连接失败'
        ];
    }
    
    try {
        $stats = [];
        
        // 活跃Token数量
        $stmt = $pdo->query("SELECT COUNT(*) FROM temp_tokens WHERE is_active = 1");
        $stats['active_tokens'] = $stmt->fetchColumn();
        
        // 过期Token数量
        $stmt = $pdo->query("
            SELECT COUNT(*) FROM temp_tokens 
            WHERE expires_at < datetime('now') AND is_active = 1
        ");
        $stats['expired_tokens'] = $stmt->fetchColumn();
        
        // 已用尽Token数量
        $stmt = $pdo->query("
            SELECT COUNT(*) FROM temp_tokens 
            WHERE used_count >= max_uses AND is_active = 1
        ");
        $stats['exhausted_tokens'] = $stmt->fetchColumn();
        
        // 今日生成Token数量
        $stmt = $pdo->query("
            SELECT COUNT(*) FROM temp_tokens 
            WHERE DATE(created_time) = DATE('now')
        ");
        $stats['today_generated'] = $stmt->fetchColumn();
        
        // 今日使用Token数量
        $stmt = $pdo->query("
            SELECT COUNT(*) FROM temp_tokens 
            WHERE used_count > 0 AND DATE(created_time) = DATE('now')
        ");
        $stats['today_used'] = $stmt->fetchColumn();
        
        return [
            'success' => true,
            'statistics' => $stats
        ];
        
    } catch (PDOException $e) {
        error_log("获取Token统计数据库错误: " . $e->getMessage());
        return [
            'success' => false,
            'reason' => 'database_error', 
            'message' => '数据库查询失败'
        ];
    }
}
?>
