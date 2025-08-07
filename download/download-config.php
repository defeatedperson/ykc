<?php
/**
 * 下载模块配置文件
 * 负责下载相关的配置管理
 */

// 防止直接访问
if (basename($_SERVER['SCRIPT_FILENAME']) == basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden');
}

// ============= 路径配置 =============
define('DOWNLOAD_MODULE_DIR', __DIR__);
// 避免重复定义，检查常量是否已存在
if (!defined('SHARE_DB_PATH')) {
    define('SHARE_DB_PATH', dirname(__DIR__) . '/share/data/share.db');
}
define('FILE_DATA_ROOT', dirname(__DIR__) . '/file/data');
define('TEMP_TOKEN_DIR', __DIR__ . '/data/temp');

// ============= 下载配置 =============
define('MAX_DOWNLOAD_SIZE', 1024 * 1024 * 1024); // 1GB限制
define('SHARE_CODE_LENGTH', 8); // 分享代码长度

// ============= 临时Token配置 =============
define('TEMP_TOKEN_EXPIRY_HOURS', 12); // Token有效期（小时）
define('TEMP_TOKEN_MAX_USES', 5); // Token最大使用次数
define('TEMP_TOKEN_LENGTH', 32); // Token长度

// ============= 数据库配置 =============
define('TEMP_TOKEN_DB_PATH', TEMP_TOKEN_DIR . '/tokens.db');

/**
 * 获取分享数据库连接
 * @return PDO|null 数据库连接对象
 */
function get_share_database() {
    try {
        if (!file_exists(SHARE_DB_PATH)) {
            error_log("分享数据库文件不存在: " . SHARE_DB_PATH);
            return null;
        }

        $pdo = new PDO('sqlite:' . SHARE_DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        
        return $pdo;
    } catch (PDOException $e) {
        error_log("分享数据库连接失败: " . $e->getMessage());
        return null;
    }
}

/**
 * 获取临时Token数据库连接
 * @return PDO|null 数据库连接对象
 */
function get_temp_token_database() {
    try {
        // 确保目录存在
        $token_dir = dirname(TEMP_TOKEN_DB_PATH);
        if (!is_dir($token_dir)) {
            if (!mkdir($token_dir, 0755, true)) {
                error_log("无法创建Token目录: " . $token_dir);
                return null;
            }
        }

        $pdo = new PDO('sqlite:' . TEMP_TOKEN_DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        
        // 初始化Token表
        init_temp_token_table($pdo);
        
        return $pdo;
    } catch (PDOException $e) {
        error_log("Token数据库连接失败: " . $e->getMessage());
        return null;
    }
}

/**
 * 初始化临时Token数据表
 * @param PDO $pdo 数据库连接
 */
function init_temp_token_table($pdo) {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS temp_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                token TEXT NOT NULL UNIQUE,
                share_code TEXT NOT NULL,
                user_id TEXT NOT NULL,
                file_path TEXT NOT NULL,
                file_name TEXT NOT NULL,
                created_time DATETIME DEFAULT CURRENT_TIMESTAMP,
                expires_at DATETIME NOT NULL,
                max_uses INTEGER DEFAULT " . TEMP_TOKEN_MAX_USES . ",
                used_count INTEGER DEFAULT 0,
                is_active INTEGER DEFAULT 1
            )
        ");
        
        // 创建索引
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_temp_tokens_token ON temp_tokens(token)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_temp_tokens_expires ON temp_tokens(expires_at)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_temp_tokens_share_code ON temp_tokens(share_code)");
        
    } catch (PDOException $e) {
        error_log("初始化Token表失败: " . $e->getMessage());
        throw $e;
    }
}

/**
 * 验证分享代码格式
 * @param string $share_code 分享代码
 * @return bool 是否有效
 */
function validate_share_code_format($share_code) {
    return is_string($share_code) && 
           strlen($share_code) === SHARE_CODE_LENGTH && 
           preg_match('/^[a-zA-Z0-9]+$/', $share_code);
}

/**
 * 构建用户文件完整路径
 * @param string $user_id 用户ID
 * @param string $file_path 文件相对路径
 * @return string 完整文件路径
 */
function build_user_file_path($user_id, $file_path) {
    $base_dir = FILE_DATA_ROOT . '/' . $user_id;
    return $base_dir . '/' . ltrim($file_path, '/');
}

/**
 * 验证文件路径安全性
 * @param string $user_id 用户ID
 * @param string $file_path 文件相对路径
 * @return array ['valid' => bool, 'full_path' => string|null, 'reason' => string|null]
 */
function validate_file_path_security($user_id, $file_path) {
    $base_dir = FILE_DATA_ROOT . '/' . $user_id;
    $target_file = build_user_file_path($user_id, $file_path);
    
    // 检查基础目录是否存在
    if (!is_dir($base_dir)) {
        return ['valid' => false, 'full_path' => null, 'reason' => 'user_dir_not_exists'];
    }
    
    // 安全检查：确保路径在用户目录内
    $real_base = realpath($base_dir);
    $real_target = realpath($target_file);
    
    if (!$real_target || strpos($real_target, $real_base) !== 0) {
        return ['valid' => false, 'full_path' => null, 'reason' => 'invalid_path'];
    }
    
    // 检查文件是否存在
    if (!file_exists($target_file)) {
        return ['valid' => false, 'full_path' => null, 'reason' => 'file_not_exists'];
    }
    
    // 确保是文件而不是目录
    if (!is_file($target_file)) {
        return ['valid' => false, 'full_path' => null, 'reason' => 'not_a_file'];
    }
    
    // 检查文件是否可读
    if (!is_readable($target_file)) {
        return ['valid' => false, 'full_path' => null, 'reason' => 'file_not_readable'];
    }
    
    return ['valid' => true, 'full_path' => $real_target, 'reason' => null];
}
?>
