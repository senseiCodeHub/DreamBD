<?php
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/security.php';
// AJAX endpoint for saving user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_user'])) {
    header('Content-Type: application/json');
    dream_start_session();
    if (empty($_SESSION['user_id']) || empty($_SESSION['role']) || !in_array($_SESSION['role'], ['admin','moderator','super_admin'])) {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
        exit;
    }
    try {
        $db = Database::getInstance()->getConnection();
        $userId = (int) ($_POST['user_id'] ?? 0);
        $fullName = trim($_POST['full_name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $status = in_array($_POST['status'] ?? '', ['active','inactive','suspended']) ? $_POST['status'] : 'active';
        $password = $_POST['password'] ?? '';
        
        if (empty($fullName) || empty($username) || empty($email)) {
            echo json_encode(['status' => 'error', 'message' => 'Name, username, and email are required']);
            exit;
        }
        
        $stmt = $db->prepare("UPDATE users SET full_name = ?, username = ?, email = ?, phone = ?, status = ? WHERE id = ?");
        $stmt->execute([$fullName, $username, $email, $phone, $status, $userId]);
        
        if (!empty($password) && strlen($password) >= 8) {
            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
            $stmt->execute([$hash, $userId]);
        }
        
        echo json_encode(['status' => 'success', 'message' => 'User updated successfully']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}
// AJAX endpoint for changing user role
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_role'])) {
    header('Content-Type: application/json');
    dream_start_session();
    if (empty($_SESSION['user_id']) || empty($_SESSION['role']) || !in_array($_SESSION['role'], ['admin','moderator','super_admin'])) {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
        exit;
    }
    try {
        $db = Database::getInstance()->getConnection();
        $userId = (int)($_POST['user_id'] ?? 0);
        $newRole = trim($_POST['new_role'] ?? '');
        if (!in_array($newRole, ['user','agent','moderator','merchant','seller'])) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid role']);
            exit;
        }
        $stmt = $db->prepare("UPDATE users SET role = ?, agent_verified_at = IF(? = 'agent', NOW(), NULL) WHERE id = ?");
        $stmt->execute([$newRole, $newRole, $userId]);
        echo json_encode(['status' => 'success', 'message' => 'Role updated successfully']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// AJAX endpoint for user details
if (isset($_GET["get_user"])) {
    header("Content-Type: application/json");
    $userId = (int)$_GET["get_user"];
    try {
        $dbh = Database::getInstance()->getConnection();
        $stmt = $dbh->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if ($user) {
            echo json_encode(["status" => "success", "data" => ["user" => $user]]);
        } else {
            echo json_encode(["status" => "error", "message" => "User not found"]);
        }
    } catch (Exception $e) {
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
    exit;
}

$pageTitle = "User Manager";
$pageHeading = "User Manager";
$currentPage = "users";
$breadcrumbs = [
    ["label" => "Dashboard", "url" => "index.php"],
    ["label" => "User Manager"]
];

require_once __DIR__ . "/layout/header.php";

$db = Database::getInstance()->getConnection();

// Get admin users
$adminUsers = $db->query("
    SELECT au.*, u.username, u.full_name, u.email, u.status, u.registered_at
    FROM admin_users au
    JOIN users u ON au.user_id = u.id
    ORDER BY au.created_at DESC
")->fetchAll();

// Get moderators
$moderators = $db->query("
    SELECT u.* FROM users u
    LEFT JOIN admin_users au ON u.id = au.user_id
    WHERE au.role = \"moderator\"
    ORDER BY u.registered_at DESC
")->fetchAll();

// Get agents
$agents = $db->query("
    SELECT * FROM users WHERE role = 'agent'
    ORDER BY agent_verified_at DESC
")->fetchAll();

// Get merchants
$merchants = $db->query("
    SELECT * FROM users WHERE role = 'merchant'
    ORDER BY registered_at DESC
")->fetchAll();

// Get regular users
$regularUsers = $db->query("
    SELECT u.* FROM users u
    LEFT JOIN admin_users au ON u.id = au.user_id
    WHERE au.id IS NULL AND u.role NOT IN ('agent','merchant','admin','super_admin')
    ORDER BY u.registered_at DESC
    LIMIT 50
")->fetchAll();

// Get counts for stats
$totalUsers = count($regularUsers) + count($adminUsers) + count($moderators) + count($agents) + count($merchants);
$activeUsers = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
?>

<!-- Stats Cards -->
<div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-5 gap-6 mb-8 slide-in">
    <div class="stat-card bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700">
        <div class="flex items-center justify-between mb-4">
            <div class="p-3 bg-blue-100 dark:bg-blue-900/30 rounded-xl">
                <i class="fas fa-users text-2xl text-blue-600 dark:text-blue-400"></i>
            </div>
            <span class="text-sm text-green-600 dark:text-green-400 flex items-center">
                <span class="w-2 h-2 bg-green-500 rounded-full mr-2 animate-pulse"></span>Live
            </span>
        </div>
        <h3 class="text-3xl font-bold text-gray-800 dark:text-white"><?php echo $totalUsers; ?></h3>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Total Users</p>
    </div>

    <div class="stat-card bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700">
        <div class="flex items-center justify-between mb-4">
            <div class="p-3 bg-green-100 dark:bg-green-900/30 rounded-xl">
                <i class="fas fa-user-check text-2xl text-green-600 dark:text-green-400"></i>
            </div>
        </div>
        <h3 class="text-3xl font-bold text-gray-800 dark:text-white"><?php echo $activeUsers; ?></h3>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Active Users</p>
    </div>

    <div class="stat-card bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700">
        <div class="flex items-center justify-between mb-4">
            <div class="p-3 bg-teal-100 dark:bg-teal-900/30 rounded-xl">
                <i class="fas fa-store text-2xl text-teal-600 dark:text-teal-400"></i>
            </div>
        </div>
        <h3 class="text-3xl font-bold text-gray-800 dark:text-white"><?php echo count($merchants); ?></h3>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Merchants</p>
    </div>

    <div class="stat-card bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700">
        <div class="flex items-center justify-between mb-4">
            <div class="p-3 bg-purple-100 dark:bg-purple-900/30 rounded-xl">
                <i class="fas fa-shield-alt text-2xl text-purple-600 dark:text-purple-400"></i>
            </div>
        </div>
        <h3 class="text-3xl font-bold text-gray-800 dark:text-white"><?php echo count($adminUsers); ?></h3>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Administrators</p>
    </div>

    <div class="stat-card bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700">
        <div class="flex items-center justify-between mb-4">
            <div class="p-3 bg-yellow-100 dark:bg-yellow-900/30 rounded-xl">
                <i class="fas fa-user-shield text-2xl text-yellow-600 dark:text-yellow-400"></i>
            </div>
        </div>
        <h3 class="text-3xl font-bold text-gray-800 dark:text-white"><?php echo count($moderators); ?></h3>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Moderators</p>
    </div>

    <div class="stat-card bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700">
        <div class="flex items-center justify-between mb-4">
            <div class="p-3 bg-amber-100 dark:bg-amber-900/30 rounded-xl">
                <i class="fas fa-crown text-2xl text-amber-600 dark:text-amber-400"></i>
            </div>
        </div>
        <h3 class="text-3xl font-bold text-gray-800 dark:text-white"><?php echo count($agents); ?></h3>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Agents</p>
    </div>
</div>

<!-- Search and Filter Bar -->
<div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6 mb-8 slide-in">
    <div class="flex flex-col md:flex-row gap-4">
        <div class="flex-1">
            <div class="relative">
                <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                <input type="text" id="userSearch" onkeyup="filterUsers()" placeholder="Search users by name, email, or username..."
                    class="w-full pl-10 pr-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-gray-200 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
            </div>
        </div>
        <div class="flex gap-2">
            <select id="statusFilter" onchange="filterUsers()" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-gray-200">
                <option value="">All Status</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
                <option value="suspended">Suspended</option>
            </select>
            <button onclick="exportUsers()" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                <i class="fas fa-download mr-2"></i>Export
            </button>
        </div>
    </div>
</div>

<!-- Tabs -->
<div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden slide-in">
    <div class="border-b border-gray-200 dark:border-gray-700">
        <nav class="flex space-x-8 px-6" aria-label="Tabs">
            <button onclick="switchTab('admin')" class="tab-btn active py-4 px-1 border-b-2 border-blue-500 font-medium text-sm text-blue-600 dark:text-blue-400" data-tab="admin">
                <i class="fas fa-shield-alt mr-2"></i>Administrators
                <span class="ml-2 bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400 px-2.5 py-0.5 rounded-full text-xs font-medium"><?php echo count($adminUsers); ?></span>
            </button>
            <button onclick="switchTab('moderator')" class="tab-btn py-4 px-1 border-b-2 border-transparent font-medium text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200" data-tab="moderator">
                <i class="fas fa-user-shield mr-2"></i>Moderators
                <span class="ml-2 bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400 px-2.5 py-0.5 rounded-full text-xs font-medium"><?php echo count($moderators); ?></span>
            </button>
            <button onclick="switchTab('agents')" class="tab-btn py-4 px-1 border-b-2 border-transparent font-medium text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200" data-tab="agents">
                <i class="fas fa-crown mr-2"></i>Agents
                <span class="ml-2 bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400 px-2.5 py-0.5 rounded-full text-xs font-medium"><?php echo count($agents); ?></span>
            </button>
            <button onclick="switchTab('merchants')" class="tab-btn py-4 px-1 border-b-2 border-transparent font-medium text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200" data-tab="merchants">
                <i class="fas fa-store mr-2"></i>Merchants
                <span class="ml-2 bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400 px-2.5 py-0.5 rounded-full text-xs font-medium"><?php echo count($merchants); ?></span>
            </button>
            <button onclick="switchTab('regular')" class="tab-btn py-4 px-1 border-b-2 border-transparent font-medium text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200" data-tab="regular">
                <i class="fas fa-users mr-2"></i>Regular Users
                <span class="ml-2 bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400 px-2.5 py-0.5 rounded-full text-xs font-medium"><?php echo count($regularUsers); ?></span>
            </button>
        </nav>
    </div>

    <!-- Admin Users Tab -->
    <div id="tab-admin" class="tab-content p-6">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead>
                    <tr class="text-left border-b border-gray-200 dark:border-gray-700">
                        <th class="pb-3 text-sm font-semibold text-gray-600 dark:text-gray-400">User</th>
                        <th class="pb-3 text-sm font-semibold text-gray-600 dark:text-gray-400">Role</th>
                        <th class="pb-3 text-sm font-semibold text-gray-600 dark:text-gray-400">Permissions</th>
                        <th class="pb-3 text-sm font-semibold text-gray-600 dark:text-gray-400">Status</th>
                        <th class="pb-3 text-sm font-semibold text-gray-600 dark:text-gray-400">Joined</th>
                        <th class="pb-3 text-sm font-semibold text-gray-600 dark:text-gray-400">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($adminUsers as $admin): ?>
                    <tr class="user-row border-b border-gray-100 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors" 
                        data-status="<?php echo $admin['status'] ?? 'active'; ?>"
                        data-search="<?php echo strtolower($admin['username'] . ' ' . $admin['full_name'] . ' ' . $admin['email']); ?>">
                        <td class="py-4">
                            <div class="flex items-center space-x-3">
                                <div class="w-10 h-10 rounded-full bg-gradient-to-br from-blue-500 to-purple-600 flex items-center justify-center text-white font-bold">
                                    <?php echo strtoupper(substr($admin['full_name'], 0, 1)); ?>
                                </div>
                                <div>
                                    <div class="font-medium text-gray-800 dark:text-white"><?php echo htmlspecialchars($admin['full_name']); ?></div>
                                    <div class="text-sm text-gray-500 dark:text-gray-400">@<?php echo htmlspecialchars($admin['username']); ?></div>
                                </div>
                            </div>
                        </td>
                        <td class="py-4">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium 
                                <?php echo $admin['role'] === 'super_admin' ? 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400' : 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400'; ?>">
                                <i class="fas fa-shield-alt mr-1"></i>
                                <?php echo ucfirst(str_replace('_', ' ', $admin['role'])); ?>
                            </span>
                        </td>
                        <td class="py-4">
                            <div class="flex flex-wrap gap-1">
                                <?php 
                                $perms = json_decode($admin['permissions'], true) ?: [];
                                $permLabels = [
                                    'manage_users' => 'Users',
                                    'manage_content' => 'Content',
                                    'manage_settings' => 'Settings',
                                    'view_analytics' => 'Analytics'
                                ];
                                foreach (array_slice($perms, 0, 3) as $perm): 
                                ?>
                                <span class="text-xs bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400 px-2 py-1 rounded">
                                    <?php echo $permLabels[$perm] ?? $perm; ?>
                                </span>
                                <?php endforeach; ?>
                                <?php if (count($perms) > 3): ?>
                                <span class="text-xs bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400 px-2 py-1 rounded">
                                    +<?php echo count($perms) - 3; ?>
                                </span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="py-4">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium 
                                 <?php echo ($admin['status'] ?? 'active') === 'active' ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400' : 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400'; ?>">
                                <i class="fas fa-circle mr-1 text-[6px] <?php echo ($admin['status'] ?? 'active') === 'active' ? 'text-green-500' : 'text-red-500'; ?>"></i>
                                <?php echo ucfirst($admin['status'] ?? 'active'); ?>
                            </span>
                        </td>
                        <td class="py-4 text-sm text-gray-600 dark:text-gray-400">
                            <?php echo date('M d, Y', strtotime($admin['registered_at'])); ?>
                        </td>
                        <td class="py-4">
                            <div class="flex items-center space-x-2">
                                <button onclick="viewUser(<?php echo $admin['user_id']; ?>)" class="p-2 text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/20 rounded-lg transition-colors" title="View Details">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <button onclick="editUser(<?php echo $admin['user_id']; ?>)" class="p-2 text-green-600 hover:bg-green-50 dark:hover:bg-green-900/20 rounded-lg transition-colors" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <?php if ($admin['role'] !== 'super_admin'): ?>
                                <button onclick="deleteUser(<?php echo $admin['user_id']; ?>, '<?php echo addslashes($admin['full_name']); ?>')" class="p-2 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg transition-colors" title="Delete">
                                    <i class="fas fa-trash"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Moderators Tab -->
    <div id="tab-moderator" class="tab-content p-6 hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead>
                    <tr class="text-left border-b border-gray-200 dark:border-gray-700">
                        <th class="pb-3 text-sm font-semibold text-gray-600 dark:text-gray-400">User</th>
                        <th class="pb-3 text-sm font-semibold text-gray-600 dark:text-gray-400">Email</th>
                        <th class="pb-3 text-sm font-semibold text-gray-600 dark:text-gray-400">Status</th>
                        <th class="pb-3 text-sm font-semibold text-gray-600 dark:text-gray-400">Joined</th>
                        <th class="pb-3 text-sm font-semibold text-gray-600 dark:text-gray-400">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($moderators as $mod): ?>
                    <tr class="user-row border-b border-gray-100 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors"
                        data-status="<?php echo $mod['status'] ?? 'active'; ?>"
                        data-search="<?php echo strtolower($mod['username'] . ' ' . $mod['full_name'] . ' ' . $mod['email']); ?>">
                        <td class="py-4">
                            <div class="flex items-center space-x-3">
                                <div class="w-10 h-10 rounded-full bg-gradient-to-br from-yellow-400 to-orange-500 flex items-center justify-center text-white font-bold">
                                    <?php echo strtoupper(substr($mod['full_name'], 0, 1)); ?>
                                </div>
                                <div>
                                    <div class="font-medium text-gray-800 dark:text-white"><?php echo htmlspecialchars($mod['full_name']); ?></div>
                                    <div class="text-sm text-gray-500 dark:text-gray-400">@<?php echo htmlspecialchars($mod['username']); ?></div>
                                </div>
                            </div>
                        </td>
                        <td class="py-4 text-sm text-gray-600 dark:text-gray-400"><?php echo htmlspecialchars($mod['email']); ?></td>
                        <td class="py-4">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium 
                                 <?php echo ($mod['status'] ?? 'active') === 'active' ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400' : 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400'; ?>">
                                <i class="fas fa-circle mr-1 text-[6px] <?php echo ($mod['status'] ?? 'active') === 'active' ? 'text-green-500' : 'text-red-500'; ?>"></i>
                                <?php echo ucfirst($mod['status'] ?? 'active'); ?>
                            </span>
                        </td>
                        <td class="py-4 text-sm text-gray-600 dark:text-gray-400">
                            <?php echo date('M d, Y', strtotime($mod['registered_at'])); ?>
                        </td>
                        <td class="py-4">
                            <div class="flex items-center space-x-2">
                                <button onclick="viewUser(<?php echo $mod['id']; ?>)" class="p-2 text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/20 rounded-lg transition-colors">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <button onclick="editUser(<?php echo $mod['id']; ?>)" class="p-2 text-green-600 hover:bg-green-50 dark:hover:bg-green-900/20 rounded-lg transition-colors">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button onclick="changeRole(<?php echo $mod['id']; ?>, 'regular')" class="p-2 text-yellow-600 hover:bg-yellow-50 dark:hover:bg-yellow-900/20 rounded-lg transition-colors" title="Demote to Regular">
                                    <i class="fas fa-arrow-down"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Agents Tab -->
    <div id="tab-agents" class="tab-content p-6 hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead>
                    <tr class="text-left border-b border-gray-200 dark:border-gray-700">
                        <th class="pb-3 text-sm font-semibold text-gray-600 dark:text-gray-400">User</th>
                        <th class="pb-3 text-sm font-semibold text-gray-600 dark:text-gray-400">Balance</th>
                        <th class="pb-3 text-sm font-semibold text-gray-600 dark:text-gray-400">Coins</th>
                        <th class="pb-3 text-sm font-semibold text-gray-600 dark:text-gray-400">Verified</th>
                        <th class="pb-3 text-sm font-semibold text-gray-600 dark:text-gray-400">Status</th>
                        <th class="pb-3 text-sm font-semibold text-gray-600 dark:text-gray-400">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($agents as $ag): ?>
                    <tr class="user-row border-b border-gray-100 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors"
                        data-status="<?php echo $ag['status'] ?? 'active'; ?>"
                        data-search="<?php echo strtolower($ag['username'] . ' ' . $ag['full_name'] . ' ' . $ag['email']); ?>">
                        <td class="py-4">
                            <div class="flex items-center space-x-3">
                                <div class="w-10 h-10 rounded-full bg-gradient-to-br from-amber-400 to-orange-500 flex items-center justify-center text-white font-bold">
                                    <?php echo strtoupper(substr($ag['full_name'], 0, 1)); ?>
                                </div>
                                <div>
                                    <div class="font-medium text-gray-800 dark:text-white"><?php echo htmlspecialchars($ag['full_name']); ?></div>
                                    <div class="text-sm text-gray-500 dark:text-gray-400">@<?php echo htmlspecialchars($ag['username']); ?></div>
                                </div>
                            </div>
                        </td>
                        <td class="py-4 text-sm font-semibold text-gray-800 dark:text-white">৳<?php echo number_format((float)($ag['balance'] ?? 0), 0); ?></td>
                        <td class="py-4 text-sm text-gray-600 dark:text-gray-400"><?php echo (int)($ag['coins'] ?? 0); ?></td>
                        <td class="py-4 text-sm text-gray-600 dark:text-gray-400">
                            <?php echo $ag['agent_verified_at'] ? date('M d, Y', strtotime($ag['agent_verified_at'])) : 'N/A'; ?>
                        </td>
                        <td class="py-4">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium 
                                 <?php echo ($ag['status'] ?? 'active') === 'active' ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400' : 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400'; ?>">
                                <i class="fas fa-circle mr-1 text-[6px] <?php echo ($ag['status'] ?? 'active') === 'active' ? 'text-green-500' : 'text-red-500'; ?>"></i>
                                <?php echo ucfirst($ag['status'] ?? 'active'); ?>
                            </span>
                        </td>
                        <td class="py-4">
                            <div class="flex items-center space-x-2">
                                <button onclick="viewUser(<?php echo $ag['id']; ?>)" class="p-2 text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/20 rounded-lg transition-colors" title="View">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <button onclick="editUser(<?php echo $ag['id']; ?>)" class="p-2 text-green-600 hover:bg-green-50 dark:hover:bg-green-900/20 rounded-lg transition-colors" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button onclick="changeRole(<?php echo $ag['id']; ?>, 'user')" class="p-2 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg transition-colors" title="Revoke Agent">
                                    <i class="fas fa-crown"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($agents)): ?>
                    <tr><td colspan="6" class="py-8 text-center text-gray-500 dark:text-gray-400">No agents found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Merchants Tab -->
    <div id="tab-merchants" class="tab-content p-6 hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead>
                    <tr class="text-left border-b border-gray-200 dark:border-gray-700">
                        <th class="pb-3 text-sm font-semibold text-gray-600 dark:text-gray-400">User</th>
                        <th class="pb-3 text-sm font-semibold text-gray-600 dark:text-gray-400">Email</th>
                        <th class="pb-3 text-sm font-semibold text-gray-600 dark:text-gray-400">Phone</th>
                        <th class="pb-3 text-sm font-semibold text-gray-600 dark:text-gray-400">Status</th>
                        <th class="pb-3 text-sm font-semibold text-gray-600 dark:text-gray-400">Joined</th>
                        <th class="pb-3 text-sm font-semibold text-gray-600 dark:text-gray-400">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($merchants as $m): ?>
                    <tr class="user-row border-b border-gray-100 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors"
                        data-status="<?php echo $m['status'] ?? 'active'; ?>"
                        data-search="<?php echo strtolower($m['username'] . ' ' . $m['full_name'] . ' ' . $m['email']); ?>">
                        <td class="py-4">
                            <div class="flex items-center space-x-3">
                                <div class="w-10 h-10 rounded-full bg-gradient-to-br from-teal-400 to-emerald-500 flex items-center justify-center text-white font-bold">
                                    <?php echo strtoupper(substr($m['full_name'], 0, 1)); ?>
                                </div>
                                <div>
                                    <div class="font-medium text-gray-800 dark:text-white"><?php echo htmlspecialchars($m['full_name']); ?></div>
                                    <div class="text-sm text-gray-500 dark:text-gray-400">@<?php echo htmlspecialchars($m['username']); ?></div>
                                </div>
                            </div>
                        </td>
                        <td class="py-4 text-sm text-gray-600 dark:text-gray-400"><?php echo htmlspecialchars($m['email']); ?></td>
                        <td class="py-4 text-sm text-gray-600 dark:text-gray-400"><?php echo htmlspecialchars($m['phone'] ?? 'N/A'); ?></td>
                        <td class="py-4">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium 
                                 <?php echo ($m['status'] ?? 'active') === 'active' ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400' : 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400'; ?>">
                                <i class="fas fa-circle mr-1 text-[6px] <?php echo ($m['status'] ?? 'active') === 'active' ? 'text-green-500' : 'text-red-500'; ?>"></i>
                                <?php echo ucfirst($m['status'] ?? 'active'); ?>
                            </span>
                        </td>
                        <td class="py-4 text-sm text-gray-600 dark:text-gray-400">
                            <?php echo date('M d, Y', strtotime($m['registered_at'])); ?>
                        </td>
                        <td class="py-4">
                            <div class="flex items-center space-x-2">
                                <button onclick="viewUser(<?php echo $m['id']; ?>)" class="p-2 text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/20 rounded-lg transition-colors" title="View">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <button onclick="editUser(<?php echo $m['id']; ?>)" class="p-2 text-green-600 hover:bg-green-50 dark:hover:bg-green-900/20 rounded-lg transition-colors" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button onclick="changeRole(<?php echo $m['id']; ?>, 'user')" class="p-2 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg transition-colors" title="Revoke Merchant">
                                    <i class="fas fa-store"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($merchants)): ?>
                    <tr><td colspan="6" class="py-8 text-center text-gray-500 dark:text-gray-400">No merchants found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Regular Users Tab -->
    <div id="tab-regular" class="tab-content p-6 hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead>
                    <tr class="text-left border-b border-gray-200 dark:border-gray-700">
                        <th class="pb-3 text-sm font-semibold text-gray-600 dark:text-gray-400">User</th>
                        <th class="pb-3 text-sm font-semibold text-gray-600 dark:text-gray-400">Email</th>
                        <th class="pb-3 text-sm font-semibold text-gray-600 dark:text-gray-400">Phone</th>
                        <th class="pb-3 text-sm font-semibold text-gray-600 dark:text-gray-400">Status</th>
                        <th class="pb-3 text-sm font-semibold text-gray-600 dark:text-gray-400">Joined</th>
                        <th class="pb-3 text-sm font-semibold text-gray-600 dark:text-gray-400">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($regularUsers as $user): ?>
                    <tr class="user-row border-b border-gray-100 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors"
                        data-status="<?php echo $user['status'] ?? 'active'; ?>"
                        data-search="<?php echo strtolower($user['username'] . ' ' . $user['full_name'] . ' ' . $user['email']); ?>">
                        <td class="py-4">
                            <div class="flex items-center space-x-3">
                                <div class="w-10 h-10 rounded-full bg-gradient-to-br from-gray-400 to-gray-600 flex items-center justify-center text-white font-bold">
                                    <?php echo strtoupper(substr($user['full_name'], 0, 1)); ?>
                                </div>
                                <div>
                                    <div class="font-medium text-gray-800 dark:text-white"><?php echo htmlspecialchars($user['full_name']); ?></div>
                                    <div class="text-sm text-gray-500 dark:text-gray-400">@<?php echo htmlspecialchars($user['username']); ?></div>
                                </div>
                            </div>
                        </td>
                        <td class="py-4 text-sm text-gray-600 dark:text-gray-400"><?php echo htmlspecialchars($user['email']); ?></td>
                        <td class="py-4 text-sm text-gray-600 dark:text-gray-400"><?php echo htmlspecialchars($user['phone'] ?? 'N/A'); ?></td>
                        <td class="py-4">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium 
                                 <?php echo ($user['status'] ?? 'active') === 'active' ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400' : 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400'; ?>">
                                <i class="fas fa-circle mr-1 text-[6px] <?php echo ($user['status'] ?? 'active') === 'active' ? 'text-green-500' : 'text-red-500'; ?>"></i>
                                <?php echo ucfirst($user['status'] ?? 'active'); ?>
                            </span>
                        </td>
                        <td class="py-4 text-sm text-gray-600 dark:text-gray-400">
                            <?php echo date('M d, Y', strtotime($user['registered_at'])); ?>
                        </td>
                        <td class="py-4">
                            <div class="flex items-center space-x-2">
                                <button onclick="viewUser(<?php echo $user['id']; ?>)" class="p-2 text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/20 rounded-lg transition-colors">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <button onclick="editUser(<?php echo $user['id']; ?>)" class="p-2 text-green-600 hover:bg-green-50 dark:hover:bg-green-900/20 rounded-lg transition-colors">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button onclick="changeRole(<?php echo $user['id']; ?>, 'merchant')" class="p-2 text-teal-600 hover:bg-teal-50 dark:hover:bg-teal-900/20 rounded-lg transition-colors" title="Promote to Merchant">
                                    <i class="fas fa-store"></i>
                                </button>
                                <button onclick="changeRole(<?php echo $user['id']; ?>, 'moderator')" class="p-2 text-yellow-600 hover:bg-yellow-50 dark:hover:bg-yellow-900/20 rounded-lg transition-colors" title="Promote to Moderator">
                                    <i class="fas fa-user-shield"></i>
                                </button>
                                <button onclick="changeRole(<?php echo $user['id']; ?>, 'agent')" class="p-2 text-amber-600 hover:bg-amber-50 dark:hover:bg-amber-900/20 rounded-lg transition-colors" title="Promote to Agent">
                                    <i class="fas fa-crown"></i>
                                </button>
                                <button onclick="deleteUser(<?php echo $user['id']; ?>, '<?php echo addslashes($user['full_name']); ?>')" class="p-2 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg transition-colors">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (count($regularUsers) >= 50): ?>
        <div class="mt-4 text-center">
            <button onclick="loadMoreUsers()" class="px-4 py-2 text-blue-600 dark:text-blue-400 hover:bg-blue-50 dark:hover:bg-blue-900/20 rounded-lg transition-colors">
                <i class="fas fa-plus mr-2"></i>Load More Users
            </button>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- User Details Modal -->
<div id="userModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl max-w-2xl w-full max-h-[90vh] overflow-y-auto">
        <div class="p-6 border-b border-gray-200 dark:border-gray-700">
            <div class="flex items-center justify-between">
                <h3 class="text-xl font-bold text-gray-800 dark:text-white">User Details</h3>
                <button onclick="closeModal('userModal')" class="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
        </div>
        <div id="userModalContent" class="p-6">
            <!-- Content loaded via AJAX -->
            <div class="text-center py-8">
                <i class="fas fa-spinner fa-spin text-3xl text-blue-500"></i>
                <p class="mt-2 text-gray-500 dark:text-gray-400">Loading...</p>
            </div>
        </div>
    </div>
</div>

<!-- Edit User Modal -->
<div id="editModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl max-w-2xl w-full max-h-[90vh] overflow-y-auto">
        <div class="p-6 border-b border-gray-200 dark:border-gray-700">
            <div class="flex items-center justify-between">
                <h3 class="text-xl font-bold text-gray-800 dark:text-white">Edit User</h3>
                <button onclick="closeModal('editModal')" class="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
        </div>
        <div id="editModalContent" class="p-6">
            <!-- Content loaded via AJAX -->
            <div class="text-center py-8">
                <i class="fas fa-spinner fa-spin text-3xl text-blue-500"></i>
                <p class="mt-2 text-gray-500 dark:text-gray-400">Loading...</p>
            </div>
        </div>
    </div>
</div>

<script>
function switchTab(tab) {
    // Update tab buttons
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.classList.remove('active', 'border-blue-500', 'text-blue-600', 'dark:text-blue-400');
        btn.classList.add('border-transparent', 'text-gray-500', 'dark:text-gray-400');
    });
    event.target.closest('.tab-btn').classList.add('active', 'border-blue-500', 'text-blue-600', 'dark:text-blue-400');
    event.target.closest('.tab-btn').classList.remove('border-transparent', 'text-gray-500', 'dark:text-gray-400');
    
    // Update tab content
    document.querySelectorAll('.tab-content').forEach(content => content.classList.add('hidden'));
    document.getElementById('tab-' + tab).classList.remove('hidden');
}

function filterUsers() {
    const search = document.getElementById('userSearch').value.toLowerCase();
    const status = document.getElementById('statusFilter').value;
    
    document.querySelectorAll('.user-row').forEach(row => {
        const matchesSearch = row.dataset.search.includes(search);
        const matchesStatus = !status || row.dataset.status === status;
        row.style.display = (matchesSearch && matchesStatus) ? '' : 'none';
    });
}

function viewUser(userId) {
    const modal = document.getElementById('userModal');
    const content = document.getElementById('userModalContent');
    modal.classList.remove('hidden');
    
    fetch(`user-manager.php?get_user=${userId}`)
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                const user = data.data.user;
                content.innerHTML = `
                    <div class="space-y-6">
                        <div class="flex items-center space-x-4">
                            <div class="w-20 h-20 rounded-full bg-gradient-to-br from-blue-500 to-purple-600 flex items-center justify-center text-white text-3xl font-bold">
                                ${user.full_name.charAt(0).toUpperCase()}
                            </div>
                            <div>
                                <h4 class="text-2xl font-bold text-gray-800 dark:text-white">${user.full_name}</h4>
                                <p class="text-gray-500 dark:text-gray-400">@${user.username}</p>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div class="bg-gray-50 dark:bg-gray-700 p-4 rounded-lg">
                                <div class="text-sm text-gray-500 dark:text-gray-400">Email</div>
                                <div class="font-medium text-gray-800 dark:text-white">${user.email}</div>
                            </div>
                            <div class="bg-gray-50 dark:bg-gray-700 p-4 rounded-lg">
                                <div class="text-sm text-gray-500 dark:text-gray-400">Phone</div>
                                <div class="font-medium text-gray-800 dark:text-white">${user.phone || 'N/A'}</div>
                            </div>
                            <div class="bg-gray-50 dark:bg-gray-700 p-4 rounded-lg">
                                <div class="text-sm text-gray-500 dark:text-gray-400">Status</div>
                                <div class="font-medium">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-${user.status === 'active' ? 'green' : 'red'}-100 text-${user.status === 'active' ? 'green' : 'red'}-800 dark:bg-${user.status === 'active' ? 'green' : 'red'}-900/30 dark:text-${user.status === 'active' ? 'green' : 'red'}-400">
                                        ${user.status.charAt(0).toUpperCase() + user.status.slice(1)}
                                    </span>
                                </div>
                            </div>
                            <div class="bg-gray-50 dark:bg-gray-700 p-4 rounded-lg">
                                <div class="text-sm text-gray-500 dark:text-gray-400">Joined</div>
                                <div class="font-medium text-gray-800 dark:text-white">${new Date(user.registered_at).toLocaleDateString('en-US', {year: 'numeric', month: 'long', day: 'numeric'})}</div>
                            </div>
                        </div>
                        <div class="flex justify-end space-x-3 pt-4 border-t border-gray-200 dark:border-gray-700">
                            <button onclick="closeModal('userModal'); editUser(${user.id})" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                                <i class="fas fa-edit mr-2"></i>Edit User
                            </button>
                        </div>
                    </div>
                `;
            } else {
                content.innerHTML = `<div class="text-center py-8 text-red-500">${data.message}</div>`;
            }
        })
        .catch(err => {
            content.innerHTML = `<div class="text-center py-8 text-red-500">Error loading user details</div>`;
        });
}

function editUser(userId) {
    const modal = document.getElementById('editModal');
    const content = document.getElementById('editModalContent');
    modal.classList.remove('hidden');
    
    fetch(`user-manager.php?get_user=${userId}`)
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                const user = data.data.user;
                content.innerHTML = `
                    <form id="editUserForm" onsubmit="saveUser(event, ${user.id})" class="space-y-6">
                        <div class="grid grid-cols-2 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Full Name</label>
                                <input type="text" name="full_name" value="${user.full_name}" required
                                    class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-gray-200 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Username</label>
                                <input type="text" name="username" value="${user.username}" required
                                    class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-gray-200 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Email</label>
                                <input type="email" name="email" value="${user.email}" required
                                    class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-gray-200 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Phone</label>
                                <input type="text" name="phone" value="${user.phone || ''}"
                                    class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-gray-200 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Status</label>
                            <select name="status" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-gray-200 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                <option value="active" ${user.status === 'active' ? 'selected' : ''}>Active</option>
                                <option value="inactive" ${user.status === 'inactive' ? 'selected' : ''}>Inactive</option>
                                <option value="suspended" ${user.status === 'suspended' ? 'selected' : ''}>Suspended</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">New Password (leave blank to keep current)</label>
                            <input type="password" name="password" placeholder="Enter new password"
                                class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-gray-200 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Minimum 8 characters</p>
                        </div>
                        <div class="flex justify-end space-x-3 pt-4 border-t border-gray-200 dark:border-gray-700">
                            <button type="button" onclick="closeModal('editModal')" class="px-4 py-2 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                                Cancel
                            </button>
                            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                                <i class="fas fa-save mr-2"></i>Save Changes
                            </button>
                        </div>
                    </form>
                `;
            } else {
                content.innerHTML = `<div class="text-center py-8 text-red-500">${data.message}</div>`;
            }
        })
        .catch(err => {
            content.innerHTML = `<div class="text-center py-8 text-red-500">Error loading user data</div>`;
        });
}

function saveUser(event, userId) {
    event.preventDefault();
    const form = event.target;
    const formData = new FormData(form);
    formData.append('user_id', userId);
    formData.append('save_user', '1');
    
    const btn = form.querySelector('[type="submit"]');
    const orig = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    btn.disabled = true;
    
    fetch('user-manager.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                closeModal('editModal');
                location.reload();
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(err => { alert('Error saving user'); })
        .finally(() => { btn.innerHTML = orig; btn.disabled = false; });
}

function deleteUser(userId, userName) {
    if (confirm(`Are you sure you want to delete ${userName}? This action cannot be undone.`)) {
        alert('Delete user ' + userId + ' - Feature coming soon!');
    }
}

function changeRole(userId, newRole) {
    var label = newRole === 'user' ? 'revoke agent status' : 'change role to ' + newRole;
    if (!confirm('Are you sure you want to ' + label + ' for this user?')) return;
    var fd = new FormData();
    fd.append('change_role', '1');
    fd.append('user_id', userId);
    fd.append('new_role', newRole);
    fetch('user-manager.php', { method: 'POST', body: fd })
        .then(function(r){ return r.json(); })
        .then(function(data){
            if (data.status === 'success') { location.reload(); }
            else { alert('Error: ' + data.message); }
        })
        .catch(function(){ alert('Network error'); });
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.add('hidden');
}

function exportUsers() {
    alert('Export feature coming soon!');
}

function loadMoreUsers() {
    alert('Load more feature coming soon!');
}

// Close modal on outside click
document.getElementById('userModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal('userModal');
});
document.getElementById('editModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal('editModal');
});
</script>

<?php require_once __DIR__ . "/layout/footer.php"; ?>
