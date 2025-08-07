<?php
/**
 * 模块3：统计模块
 * 负责记录和更新下载及访问统计数据
 */

// 防止直接访问
if (basename($_SERVER['SCRIPT_FILENAME']) == basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/download-config.php';
require_once __DIR__ . '/../share/config.php';

/**
 * 增加分享访问次数
 * @param string $share_code 分享代码
 * @return array 操作结果
 */
function increment_share_view_count($share_code) {
    if (empty($share_code)) {
        return [
            'success' => false,
            'reason' => 'invalid_share_code',
            'message' => '分享代码不能为空'
        ];
    }
    
    $pdo = get_share_db();
    if (!$pdo) {
        return [
            'success' => false,
            'reason' => 'database_error',
            'message' => '数据库连接失败'
        ];
    }
    
    try {
        // 开始事务
        $pdo->beginTransaction();
        
        // 检查分享是否存在
        $stmt = $pdo->prepare("SELECT id, view_count FROM shares WHERE share_code = ?");
        $stmt->execute([$share_code]);
        $share = $stmt->fetch();
        
        if (!$share) {
            $pdo->rollBack();
            return [
                'success' => false,
                'reason' => 'share_not_found',
                'message' => '分享不存在或已失效'
            ];
        }
        
        // 增加访问次数
        $stmt = $pdo->prepare("UPDATE shares SET view_count = view_count + 1 WHERE id = ?");
        $stmt->execute([$share['id']]);
        
        // 日志功能已移除，如需要可在此处添加简单的统计记录
        
        $pdo->commit();
        
        return [
            'success' => true,
            'previous_count' => $share['view_count'],
            'new_count' => $share['view_count'] + 1
        ];
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("增加访问次数数据库错误: " . $e->getMessage());
        return [
            'success' => false,
            'reason' => 'database_error',
            'message' => '数据库操作失败'
        ];
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("增加访问次数异常: " . $e->getMessage());
        return [
            'success' => false,
            'reason' => 'system_error',
            'message' => '系统错误'
        ];
    }
}

/**
 * 增加分享下载次数
 * @param string $share_code 分享代码
 * @param string $file_path 文件路径（可选，用于记录具体下载的文件）
 * @return array 操作结果
 */
function increment_share_download_count($share_code, $file_path = null) {
    if (empty($share_code)) {
        return [
            'success' => false,
            'reason' => 'invalid_share_code',
            'message' => '分享代码不能为空'
        ];
    }
    
    $pdo = get_share_db();
    if (!$pdo) {
        return [
            'success' => false,
            'reason' => 'database_error',
            'message' => '数据库连接失败'
        ];
    }
    
    try {
        // 开始事务
        $pdo->beginTransaction();
        
        // 检查分享是否存在
        $stmt = $pdo->prepare("SELECT id, download_count FROM shares WHERE share_code = ?");
        $stmt->execute([$share_code]);
        $share = $stmt->fetch();
        
        if (!$share) {
            $pdo->rollBack();
            return [
                'success' => false,
                'reason' => 'share_not_found',
                'message' => '分享不存在或已失效'
            ];
        }
        
        // 增加下载次数
        $stmt = $pdo->prepare("UPDATE shares SET download_count = download_count + 1 WHERE id = ?");
        $stmt->execute([$share['id']]);
        
        // 日志功能已移除，如需要可在此处添加简单的统计记录
        
        $pdo->commit();
        
        return [
            'success' => true,
            'previous_count' => $share['download_count'],
            'new_count' => $share['download_count'] + 1
        ];
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("增加下载次数数据库错误: " . $e->getMessage());
        return [
            'success' => false,
            'reason' => 'database_error',
            'message' => '数据库操作失败'
        ];
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("增加下载次数异常: " . $e->getMessage());
        return [
            'success' => false,
            'reason' => 'system_error',
            'message' => '系统错误'
        ];
    }
}

/**
 * 获取分享统计信息
 * @param string $share_code 分享代码
 * @return array 统计信息
 */
function get_share_statistics($share_code) {
    if (empty($share_code)) {
        return [
            'success' => false,
            'reason' => 'invalid_share_code',
            'message' => '分享代码不能为空'
        ];
    }
    
    $pdo = get_share_db();
    if (!$pdo) {
        return [
            'success' => false,
            'reason' => 'database_error',
            'message' => '数据库连接失败'
        ];
    }
    
    try {
        // 获取基本统计信息
        $stmt = $pdo->prepare("
            SELECT 
                share_code, view_count, download_count, 
                created_time
            FROM shares 
            WHERE share_code = ?
        ");
        
        $stmt->execute([$share_code]);
        $share_stats = $stmt->fetch();
        
        if (!$share_stats) {
            return [
                'success' => false,
                'reason' => 'share_not_found',
                'message' => '分享不存在或已失效'
            ];
        }
        
        return [
            'success' => true,
            'statistics' => $share_stats
        ];
        
    } catch (PDOException $e) {
        error_log("获取统计信息数据库错误: " . $e->getMessage());
        return [
            'success' => false,
            'reason' => 'database_error',
            'message' => '数据库查询失败'
        ];
    }
}
?>
