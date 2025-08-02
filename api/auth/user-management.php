<?php
if (basename($_SERVER['SCRIPT_FILENAME']) == basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * 用户管理模块（仅限管理员）
 * 
 * 功能说明：
 * 1. get_all_users($page, $limit) - 获取普通用户列表（分页）
 * 2. create_user($username, $password, $email) - 创建普通用户
 * 3. delete_user($user_id) - 删除普通用户
 * 4. reset_user_password($user_id, $new_password) - 重置用户密码
 * 5. disable_user_mfa($user_id) - 关闭用户MFA
 * 6. get_user_detail($user_id) - 获取用户详细信息
 */

if (!defined('DB_FILE')) {
    define('DB_FILE', __DIR__ . '/data/main.db');
}
if (!defined('ADMIN_TABLE')) {
    define('ADMIN_TABLE', 'users');
}

/**
 * 获取所有普通用户列表（分页）
 * @param int $page 页码（从1开始）
 * @param int $limit 每页数量
 * @return array
 */
function get_all_users($page = 1, $limit = 20) {
    if (!file_exists(DB_FILE)) {
        return ['status' => 'error', 'message' => '数据库不存在'];
    }
    
    $db = new SQLite3(DB_FILE);
    
    // 计算总数（仅普通用户）
    $total = $db->querySingle("SELECT COUNT(*) FROM " . ADMIN_TABLE . " WHERE is_admin = 0");
    
    // 计算偏移量
    $offset = ($page - 1) * $limit;
    
    // 查询用户列表
    $stmt = $db->prepare("SELECT id, username, email, mfa_enabled, ip, last_login FROM " . ADMIN_TABLE . " WHERE is_admin = 0 ORDER BY id DESC LIMIT ? OFFSET ?");
    $stmt->bindValue(1, $limit, SQLITE3_INTEGER);
    $stmt->bindValue(2, $offset, SQLITE3_INTEGER);
    $result = $stmt->execute();
    
    $users = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $users[] = [
            'id' => $row['id'],
            'username' => $row['username'],
            'email' => $row['email'] ?? '',
            'mfa_enabled' => (bool)$row['mfa_enabled'],
            'ip' => $row['ip'] ?? '',
            'last_login' => $row['last_login'] ?? ''
        ];
    }
    
    $db->close();
    
    return [
        'status' => 'ok',
        'data' => $users,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => ceil($total / $limit)
        ]
    ];
}

/**
 * 获取用户详细信息
 * @param int $user_id 用户ID
 * @return array
 */
function get_user_detail($user_id) {
    if (!file_exists(DB_FILE)) {
        return ['status' => 'error', 'message' => '数据库不存在'];
    }
    
    $db = new SQLite3(DB_FILE);
    $stmt = $db->prepare("SELECT id, username, email, mfa_enabled, ip, last_login, is_admin FROM " . ADMIN_TABLE . " WHERE id = ?");
    $stmt->bindValue(1, $user_id, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $user = $result->fetchArray(SQLITE3_ASSOC);
    $db->close();
    
    if (!$user) {
        return ['status' => 'error', 'message' => '用户不存在'];
    }
    
    // 不允许查看管理员信息
    if ($user['is_admin']) {
        return ['status' => 'error', 'message' => '无权限查看管理员信息'];
    }
    
    return [
        'status' => 'ok',
        'data' => [
            'id' => $user['id'],
            'username' => $user['username'],
            'email' => $user['email'] ?? '',
            'mfa_enabled' => (bool)$user['mfa_enabled'],
            'ip' => $user['ip'] ?? '',
            'last_login' => $user['last_login'] ?? ''
        ]
    ];
}

/**
 * 创建普通用户
 * @param string $username 用户名
 * @param string $password 密码
 * @param string $email 邮箱（可选）
 * @return array
 */
function create_user($username, $password, $email = '') {
    if (!file_exists(DB_FILE)) {
        return ['status' => 'error', 'message' => '数据库不存在'];
    }
    
    // 注意：输入验证应该在API层使用safe-input.php完成
    // 这里只做基本的空值检查
    if (empty($username) || empty($password)) {
        return ['status' => 'error', 'message' => '用户名和密码不能为空'];
    }
    
    $db = new SQLite3(DB_FILE);
    
    // 检查用户名是否已存在
    $stmt = $db->prepare("SELECT id FROM " . ADMIN_TABLE . " WHERE username = ?");
    $stmt->bindValue(1, $username, SQLITE3_TEXT);
    $result = $stmt->execute();
    if ($result->fetchArray()) {
        $db->close();
        return ['status' => 'error', 'message' => '用户名已存在'];
    }
    
    // 加密密码
    $hash = password_hash($password, PASSWORD_DEFAULT);
    
    // 插入新用户（is_admin默认为0，即普通用户）
    $stmt = $db->prepare("INSERT INTO " . ADMIN_TABLE . " (username, password, email, is_admin) VALUES (?, ?, ?, 0)");
    $stmt->bindValue(1, $username, SQLITE3_TEXT);
    $stmt->bindValue(2, $hash, SQLITE3_TEXT);
    $stmt->bindValue(3, $email, SQLITE3_TEXT);
    
    $result = $stmt->execute();
    $user_id = $db->lastInsertRowID();
    $db->close();
    
    if ($result) {
        return [
            'status' => 'ok',
            'message' => '用户创建成功',
            'data' => [
                'user_id' => $user_id,
                'username' => $username,
                'email' => $email
            ]
        ];
    } else {
        return ['status' => 'error', 'message' => '用户创建失败'];
    }
}

/**
 * 删除普通用户
 * @param int $user_id 用户ID
 * @return array
 */
function delete_user($user_id) {
    if (!file_exists(DB_FILE)) {
        return ['status' => 'error', 'message' => '数据库不存在'];
    }
    
    $db = new SQLite3(DB_FILE);
    
    // 检查用户是否存在并获取用户信息
    $stmt = $db->prepare("SELECT id, username, is_admin FROM " . ADMIN_TABLE . " WHERE id = ?");
    $stmt->bindValue(1, $user_id, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $user = $result->fetchArray(SQLITE3_ASSOC);
    
    if (!$user) {
        $db->close();
        return ['status' => 'error', 'message' => '用户不存在'];
    }
    
    // 不允许删除管理员
    if ($user['is_admin']) {
        $db->close();
        return ['status' => 'error', 'message' => '不能删除管理员用户'];
    }
    
    // 删除用户
    $stmt = $db->prepare("DELETE FROM " . ADMIN_TABLE . " WHERE id = ? AND is_admin = 0");
    $stmt->bindValue(1, $user_id, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $changes = $db->changes();
    $db->close();
    
    if ($changes > 0) {
        return [
            'status' => 'ok',
            'message' => '用户删除成功',
            'data' => [
                'deleted_user' => $user['username']
            ]
        ];
    } else {
        return ['status' => 'error', 'message' => '用户删除失败'];
    }
}

/**
 * 重置用户密码
 * @param int $user_id 用户ID
 * @param string $new_password 新密码
 * @return array
 */
function reset_user_password($user_id, $new_password) {
    if (!file_exists(DB_FILE)) {
        return ['status' => 'error', 'message' => '数据库不存在'];
    }
    
    // 验证新密码
    if (empty($new_password) || strlen($new_password) < 6) {
        return ['status' => 'error', 'message' => '新密码长度不能少于6位'];
    }
    
    $db = new SQLite3(DB_FILE);
    
    // 检查用户是否存在并获取用户信息
    $stmt = $db->prepare("SELECT id, username, is_admin FROM " . ADMIN_TABLE . " WHERE id = ?");
    $stmt->bindValue(1, $user_id, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $user = $result->fetchArray(SQLITE3_ASSOC);
    
    if (!$user) {
        $db->close();
        return ['status' => 'error', 'message' => '用户不存在'];
    }
    
    // 不允许重置管理员密码
    if ($user['is_admin']) {
        $db->close();
        return ['status' => 'error', 'message' => '不能重置管理员密码'];
    }
    
    // 加密新密码
    $hash = password_hash($new_password, PASSWORD_DEFAULT);
    
    // 更新密码
    $stmt = $db->prepare("UPDATE " . ADMIN_TABLE . " SET password = ? WHERE id = ? AND is_admin = 0");
    $stmt->bindValue(1, $hash, SQLITE3_TEXT);
    $stmt->bindValue(2, $user_id, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $changes = $db->changes();
    $db->close();
    
    if ($changes > 0) {
        return [
            'status' => 'ok',
            'message' => '密码重置成功',
            'data' => [
                'username' => $user['username']
            ]
        ];
    } else {
        return ['status' => 'error', 'message' => '密码重置失败'];
    }
}

/**
 * 关闭用户MFA
 * @param int $user_id 用户ID
 * @return array
 */
function disable_user_mfa($user_id) {
    if (!file_exists(DB_FILE)) {
        return ['status' => 'error', 'message' => '数据库不存在'];
    }
    
    $db = new SQLite3(DB_FILE);
    
    // 检查用户是否存在并获取用户信息
    $stmt = $db->prepare("SELECT id, username, is_admin, mfa_enabled FROM " . ADMIN_TABLE . " WHERE id = ?");
    $stmt->bindValue(1, $user_id, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $user = $result->fetchArray(SQLITE3_ASSOC);
    
    if (!$user) {
        $db->close();
        return ['status' => 'error', 'message' => '用户不存在'];
    }
    
    // 不允许操作管理员MFA
    if ($user['is_admin']) {
        $db->close();
        return ['status' => 'error', 'message' => '不能操作管理员MFA'];
    }
    
    // 检查MFA是否已经关闭
    if (!$user['mfa_enabled']) {
        $db->close();
        return ['status' => 'ok', 'message' => 'MFA已经是关闭状态'];
    }
    
    // 关闭MFA
    $stmt = $db->prepare("UPDATE " . ADMIN_TABLE . " SET mfa_enabled = 0, mfa_secret = NULL WHERE id = ? AND is_admin = 0");
    $stmt->bindValue(1, $user_id, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $changes = $db->changes();
    $db->close();
    
    if ($changes > 0) {
        return [
            'status' => 'ok',
            'message' => 'MFA已关闭',
            'data' => [
                'username' => $user['username']
            ]
        ];
    } else {
        return ['status' => 'error', 'message' => 'MFA关闭失败'];
    }
}

/**
 * 获取用户统计信息
 * @return array
 */
function get_user_statistics() {
    if (!file_exists(DB_FILE)) {
        return ['status' => 'error', 'message' => '数据库不存在'];
    }
    
    $db = new SQLite3(DB_FILE);
    
    // 统计普通用户数量
    $total_users = $db->querySingle("SELECT COUNT(*) FROM " . ADMIN_TABLE . " WHERE is_admin = 0");
    
    // 统计启用MFA的用户数量
    $mfa_enabled_users = $db->querySingle("SELECT COUNT(*) FROM " . ADMIN_TABLE . " WHERE is_admin = 0 AND mfa_enabled = 1");
    
    // 统计有邮箱的用户数量
    $users_with_email = $db->querySingle("SELECT COUNT(*) FROM " . ADMIN_TABLE . " WHERE is_admin = 0 AND email IS NOT NULL AND email != ''");
    
    $db->close();
    
    return [
        'status' => 'ok',
        'data' => [
            'total_users' => $total_users,
            'mfa_enabled_users' => $mfa_enabled_users,
            'users_with_email' => $users_with_email
        ]
    ];
}
?>
