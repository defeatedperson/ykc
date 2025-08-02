<?php
/**
 * 分享系统配置文件
 * 负责数据库连接和表结构管理
 */

// 数据库配置
if (!defined('SHARE_DB_PATH')) {
    define('SHARE_DB_PATH', __DIR__ . '/data/share.db');
}

/**
 * 获取数据库连接
 * @return PDO|null 数据库连接对象
 */
function get_share_db() {
    try {
        // 确保数据目录存在
        $data_dir = dirname(SHARE_DB_PATH);
        if (!is_dir($data_dir)) {
            if (!mkdir($data_dir, 0755, true)) {
                error_log("无法创建分享数据目录: " . $data_dir);
                return null;
            }
        }
        
        // 检查目录是否可写
        if (!is_writable($data_dir)) {
            error_log("分享数据目录不可写: " . $data_dir);
            return null;
        }
        
        $pdo = new PDO('sqlite:' . SHARE_DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // 创建表结构
        init_share_tables($pdo);
        
        return $pdo;
    } catch (PDOException $e) {
        error_log("分享数据库连接失败: " . $e->getMessage());
        return null;
    } catch (Exception $e) {
        error_log("分享数据库初始化失败: " . $e->getMessage());
        return null;
    }
}

/**
 * 初始化分享系统数据表
 * @param PDO $pdo 数据库连接
 */
function init_share_tables($pdo) {
    try {
        // 开始事务确保原子性
        $pdo->beginTransaction();
        
        // 分享文件列表数据表
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS share_files (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                file_name TEXT NOT NULL,
                user_id TEXT NOT NULL,
                file_path TEXT NOT NULL,
                file_size INTEGER DEFAULT 0,
                file_type TEXT DEFAULT 'other',
                share_time DATETIME DEFAULT CURRENT_TIMESTAMP,
                has_share INTEGER DEFAULT 0,
                UNIQUE(user_id, file_path)
            )
        ");
        
        // 分享列表数据表 
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS shares (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                share_name TEXT NOT NULL,
                share_code TEXT NOT NULL UNIQUE,
                file_id INTEGER NOT NULL,
                created_time DATETIME DEFAULT CURRENT_TIMESTAMP,
                view_count INTEGER DEFAULT 0,
                download_count INTEGER DEFAULT 0,
                access_password TEXT DEFAULT '',
                extension TEXT DEFAULT '{}',
                FOREIGN KEY (file_id) REFERENCES share_files(id) ON DELETE CASCADE
            )
        ");
        
        // 创建索引提高查询性能（如果不存在）
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_share_files_user ON share_files(user_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_share_files_path ON share_files(file_path)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_shares_code ON shares(share_code)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_shares_file ON shares(file_id)");
        
        $pdo->commit();
        
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollback();
        }
        error_log("初始化分享数据表失败: " . $e->getMessage());
        throw $e; // 重新抛出异常，让上层处理
    }
}

/**
 * 生成唯一的分享代码
 * @param PDO $pdo 数据库连接
 * @return string 分享代码
 */
function generate_share_code($pdo) {
    $max_attempts = 100;
    $attempt = 0;
    
    do {
        // 生成8位字母数字组合
        $code = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 8);
        
        // 检查是否已存在
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM shares WHERE share_code = ?");
        $stmt->execute([$code]);
        $exists = $stmt->fetchColumn() > 0;
        
        $attempt++;
    } while ($exists && $attempt < $max_attempts);
    
    if ($attempt >= $max_attempts) {
        throw new Exception("无法生成唯一的分享代码");
    }
    
    return $code;
}
?>
