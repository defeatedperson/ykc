<?php
if (basename($_SERVER['SCRIPT_FILENAME']) == basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * 文件管理系统配置文件
 * 
 * 包含用户存储空间限制、管理员配置等设置
 * 扩展名设置，在common.php文件当中配置
 */

/**
 * 用户存储空间配置
 */
class FileConfig {
    
    /**
     * 默认用户存储空间限制（字节）
     * 1GB = 1024 * 1024 * 1024
     * 可以根据需要调整：
     * - 100MB: 100 * 1024 * 1024
     * - 500MB: 500 * 1024 * 1024
     * - 2GB: 2 * 1024 * 1024 * 1024
     * - 5GB: 5 * 1024 * 1024 * 1024
     * 注意：此值可通过site.json中的disk配置覆盖
     */
    const DEFAULT_USER_STORAGE_LIMIT = 50 * 1024 * 1024 * 1024; // 50GB
    
    /**
     * site.json配置文件路径
     */
    const SITE_CONFIG_PATH = '../data/site.json';

    /**
     * 不受存储限制的用户ID列表
     * 通常包括管理员账户
     */
    const UNLIMITED_USERS = [
        1,    // 管理员账户
        // 可以添加更多不受限制的用户ID
        // 2, 3, 4
    ];
    
    /**
     * 特定用户的自定义存储限制
     * 格式：用户ID => 存储限制（字节）
     */
    const CUSTOM_USER_LIMITS = [
        // 示例：
        // 2 => 5 * 1024 * 1024 * 1024,  // 用户ID 2: 5GB
        // 3 => 500 * 1024 * 1024,       // 用户ID 3: 500MB
        // 10 => 2 * 1024 * 1024 * 1024, // 用户ID 10: 2GB
    ];
    
    /**
     * 文件上传安全配置
     */
    const UPLOAD_SECURITY = [
        // 单个文件最大大小检查（是否启用额外检查）
        'enable_size_check' => true,
        
        // 检查磁盘可用空间（防止服务器磁盘满）
        'check_disk_space' => true,
        
        // 保留的最小磁盘空间（字节）- 当磁盘剩余空间小于此值时拒绝上传
        'min_disk_space' => 100 * 1024 * 1024, // 100MB
        
        // 上传前预检查（计算总大小）
        'pre_upload_check' => true,
    ];
    
    /**
     * 存储路径配置
     */
    const STORAGE_PATHS = [
        // 用户文件存储根目录（相对于当前文件目录）
        'user_data_dir' => 'data',
        
        // 临时文件目录
        'temp_dir' => 'temp',
        
        // 缓存目录
        'cache_dir' => 'cache',
    ];
    
    /**
     * 从site.json读取磁盘空间配置
     * @return int|null 返回配置的GB数，如果无效则返回null
     */
    private static function getSiteConfigDisk() {
        $config_path = __DIR__ . '/' . self::SITE_CONFIG_PATH;
        
        // 检查文件是否存在
        if (!file_exists($config_path)) {
            return null;
        }
        
        // 读取并解析JSON文件
        $json_content = file_get_contents($config_path);
        if ($json_content === false) {
            return null;
        }
        
        $config = json_decode($json_content, true);
        if ($config === null || !isset($config['disk'])) {
            return null;
        }
        
        $disk_value = $config['disk'];
        
        // 验证disk值是否为正整数
        if (is_numeric($disk_value) && intval($disk_value) > 0 && intval($disk_value) == $disk_value) {
            return intval($disk_value);
        }
        
        return null;
    }
    
    /**
     * 获取默认用户存储空间限制
     * 优先从site.json读取disk配置，如果无效则使用常量定义的默认值
     * @return int 存储限制（字节）
     */
    public static function getDefaultStorageLimit() {
        $site_disk_gb = self::getSiteConfigDisk();
        
        if ($site_disk_gb !== null) {
            // 将GB转换为字节
            return $site_disk_gb * 1024 * 1024 * 1024;
        }
        
        // 如果site.json中没有有效配置，使用默认值
        return self::DEFAULT_USER_STORAGE_LIMIT;
    }
    
    /**
     * 获取用户的存储空间限制
     * @param int $user_id 用户ID
     * @return int|null 存储限制（字节），null表示无限制
     */
    public static function getUserStorageLimit($user_id) {
        // 检查是否为不受限制的用户
        if (in_array($user_id, self::UNLIMITED_USERS)) {
            return null; // 无限制
        }
        
        // 检查是否有自定义限制
        if (isset(self::CUSTOM_USER_LIMITS[$user_id])) {
            return self::CUSTOM_USER_LIMITS[$user_id];
        }
        
        // 返回默认限制（优先从site.json读取）
        return self::getDefaultStorageLimit();
    }
    
    /**
     * 检查用户是否有无限制权限
     * @param int $user_id 用户ID
     * @return bool
     */
    public static function isUnlimitedUser($user_id) {
        return in_array($user_id, self::UNLIMITED_USERS);
    }
    
    /**
     * 获取用户数据目录路径
     * @param int $user_id 用户ID
     * @return string 绝对路径
     */
    public static function getUserDataDir($user_id) {
        return __DIR__ . '/' . self::STORAGE_PATHS['user_data_dir'] . '/' . $user_id;
    }
    
    /**
     * 获取格式化的存储限制显示
     * @param int $user_id 用户ID
     * @return string
     */
    public static function getFormattedStorageLimit($user_id) {
        $limit = self::getUserStorageLimit($user_id);
        
        if ($limit === null) {
            return '无限制';
        }
        
        return self::formatBytes($limit);
    }
    
    /**
     * 格式化字节数为人类可读格式
     * @param int $bytes
     * @param int $precision
     * @return string
     */
    private static function formatBytes($bytes, $precision = 2) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }
    
    /**
     * 获取配置信息摘要（用于调试和管理）
     * @return array
     */
    public static function getConfigSummary() {
        $site_disk_gb = self::getSiteConfigDisk();
        $effective_limit = self::getDefaultStorageLimit();
        
        return [
            'default_limit' => self::formatBytes($effective_limit),
            'site_config_disk_gb' => $site_disk_gb,
            'using_site_config' => ($site_disk_gb !== null),
            'fallback_limit' => self::formatBytes(self::DEFAULT_USER_STORAGE_LIMIT),
            'unlimited_users' => self::UNLIMITED_USERS,
            'custom_limits_count' => count(self::CUSTOM_USER_LIMITS),
            'security_enabled' => self::UPLOAD_SECURITY['enable_size_check'],
            'disk_check_enabled' => self::UPLOAD_SECURITY['check_disk_space'],
            'min_disk_space' => self::formatBytes(self::UPLOAD_SECURITY['min_disk_space'])
        ];
    }
}

/**
 * 快速访问函数（向后兼容）
 */

/**
 * 获取默认用户存储限制
 * @return int
 */
function get_default_storage_limit() {
    return FileConfig::getDefaultStorageLimit();
}

/**
 * 获取用户存储限制
 * @param int $user_id
 * @return int|null
 */
function get_user_storage_limit($user_id) {
    return FileConfig::getUserStorageLimit($user_id);
}

/**
 * 检查用户是否无限制
 * @param int $user_id
 * @return bool
 */
function is_unlimited_user($user_id) {
    return FileConfig::isUnlimitedUser($user_id);
}

/**
 * 获取用户数据目录
 * @param int $user_id
 * @return string
 */
function get_user_data_dir($user_id) {
    return FileConfig::getUserDataDir($user_id);
}
?>
