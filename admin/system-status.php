<?php
$pageTitle = 'System Status';
$pageHeading = 'System Status';
$currentPage = 'system';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => 'index.php'],
    ['label' => 'System Status']
];

require_once __DIR__ . '/layout/header.php';

$db = Database::getInstance()->getConnection();

// Get system stats
$stats = [
    'php_version' => phpversion(),
    'db_name' => DatabaseConfig::getName(),
    'db_host' => DatabaseConfig::getHost(),
    'server' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
    'memory_usage' => round(memory_get_usage() / 1024 / 1024, 2),
    'execution_time' => round(microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'], 4),
    'user_count' => 0,
    'post_count' => 0,
    'session_count' => 0,
    'db_size' => 0
];

try {
    $stats['user_count'] = $db->query("SELECT COUNT(*) FROM users")->fetch(PDO::FETCH_COLUMN);
    $stats['post_count'] = $db->query("SELECT COUNT(*) FROM posts")->fetch(PDO::FETCH_COLUMN);
    $stats['session_count'] = $db->query("SELECT COUNT(*) FROM user_sessions WHERE expires_at > NOW()")->fetch(PDO::FETCH_COLUMN);

    $stmt = $db->prepare("SELECT SUM(data_length + index_length) / 1024 / 1024 AS size FROM information_schema.tables WHERE table_schema = ?");
    $stmt->execute([DatabaseConfig::getName()]);
    $stats['db_size'] = round($stmt->fetch(PDO::FETCH_COLUMN) ?: 0, 2);
} catch (PDOException $e) {
    // Tables might not exist
}
?>

<!-- Stats Grid -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8 slide-in">
    <div class="stat-card bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700">
        <div class="flex items-center justify-between mb-4">
            <div class="p-3 bg-blue-100 dark:bg-blue-900/30 rounded-xl">
                <i class="fas fa-users text-2xl text-blue-600 dark:text-blue-400"></i>
            </div>
            <span class="text-sm text-green-600 dark:text-green-400 flex items-center">
                <span class="w-2 h-2 bg-green-500 rounded-full mr-2 animate-pulse"></span>Online
            </span>
        </div>
        <h3 class="text-3xl font-bold text-gray-800 dark:text-white"><?php echo number_format($stats['user_count']); ?></h3>
        <p class="text-gray-600 dark:text-gray-400 text-sm mt-1">Total Users</p>
    </div>

    <div class="stat-card bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700">
        <div class="flex items-center justify-between mb-4">
            <div class="p-3 bg-green-100 dark:bg-green-900/30 rounded-xl">
                <i class="fas fa-file-alt text-2xl text-green-600 dark:text-green-400"></i>
            </div>
            <span class="text-sm text-green-600 dark:text-green-400 flex items-center">
                <span class="w-2 h-2 bg-green-500 rounded-full mr-2 animate-pulse"></span>Active
            </span>
        </div>
        <h3 class="text-3xl font-bold text-gray-800 dark:text-white"><?php echo number_format($stats['post_count']); ?></h3>
        <p class="text-gray-600 dark:text-gray-400 text-sm mt-1">Total Posts</p>
    </div>

    <div class="stat-card bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700">
        <div class="flex items-center justify-between mb-4">
            <div class="p-3 bg-purple-100 dark:bg-purple-900/30 rounded-xl">
                <i class="fas fa-wifi text-2xl text-purple-600 dark:text-purple-400"></i>
            </div>
            <span class="text-sm text-blue-600 dark:text-blue-400 flex items-center">
                <i class="fas fa-signal mr-1"></i>Live
            </span>
        </div>
        <h3 class="text-3xl font-bold text-gray-800 dark:text-white"><?php echo number_format($stats['session_count']); ?></h3>
        <p class="text-gray-600 dark:text-gray-400 text-sm mt-1">Active Sessions</p>
    </div>

    <div class="stat-card bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700">
        <div class="flex items-center justify-between mb-4">
            <div class="p-3 bg-yellow-100 dark:bg-yellow-900/30 rounded-xl">
                <i class="fas fa-database text-2xl text-yellow-600 dark:text-yellow-400"></i>
            </div>
            <span class="text-sm text-yellow-600 dark:text-yellow-400 flex items-center">
                <i class="fas fa-hdd mr-1"></i>Storage
            </span>
        </div>
        <h3 class="text-3xl font-bold text-gray-800 dark:text-white"><?php echo $stats['db_size']; ?> MB</h3>
        <p class="text-gray-600 dark:text-gray-400 text-sm mt-1">Database Size</p>
    </div>
</div>

<!-- System Info & Resources -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">

    <!-- System Information -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <h2 class="text-xl font-bold text-gray-800 dark:text-white mb-6">
            <i class="fas fa-info-circle text-blue-500 mr-2"></i>System Information
        </h2>
        <div class="space-y-4">
            <div class="flex justify-between items-center py-3 border-b border-gray-100 dark:border-gray-700">
                <span class="text-gray-600 dark:text-gray-400">PHP Version</span>
                <span class="font-mono font-semibold text-gray-800 dark:text-white"><?php echo $stats['php_version']; ?></span>
            </div>
            <div class="flex justify-between items-center py-3 border-b border-gray-100 dark:border-gray-700">
                <span class="text-gray-600 dark:text-gray-400">Database</span>
                <span class="font-mono font-semibold text-gray-800 dark:text-white"><?php echo $stats['db_name']; ?></span>
            </div>
            <div class="flex justify-between items-center py-3 border-b border-gray-100 dark:border-gray-700">
                <span class="text-gray-600 dark:text-gray-400">Host</span>
                <span class="font-mono font-semibold text-gray-800 dark:text-white"><?php echo $stats['db_host']; ?></span>
            </div>
            <div class="flex justify-between items-center py-3 border-b border-gray-100 dark:border-gray-700">
                <span class="text-gray-600 dark:text-gray-400">Server</span>
                <span class="font-mono font-semibold text-gray-800 dark:text-white text-sm"><?php echo $stats['server']; ?></span>
            </div>
            <div class="flex justify-between items-center py-3 border-b border-gray-100 dark:border-gray-700">
                <span class="text-gray-600 dark:text-gray-400">User Role</span>
                <span class="px-2 py-1 text-xs font-semibold rounded-full <?php echo $userRole === 'super_admin' ? 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400' : 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400'; ?>">
                    <?php echo strtoupper($userRole); ?>
                </span>
            </div>
            <div class="flex justify-between items-center py-3">
                <span class="text-gray-600 dark:text-gray-400">Status</span>
                <span class="px-3 py-1 text-sm font-semibold rounded-full bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400">
                    <i class="fas fa-circle text-xs mr-1 animate-pulse"></i>OPERATIONAL
                </span>
            </div>
        </div>
    </div>

    <!-- Resources -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <h2 class="text-xl font-bold text-gray-800 dark:text-white mb-6">
            <i class="fas fa-microchip text-purple-500 mr-2"></i>Resources
        </h2>
        <div class="space-y-6">
            <div>
                <div class="flex justify-between items-center mb-2">
                    <span class="text-gray-600 dark:text-gray-400 text-sm">Memory Usage</span>
                    <span class="text-gray-800 dark:text-white font-semibold"><?php echo $stats['memory_usage']; ?> MB</span>
                </div>
                <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2">
                    <div class="bg-blue-600 h-2 rounded-full progress-bar" style="width: <?php echo min(($stats['memory_usage'] / 128) * 100, 100); ?>%"></div>
                </div>
            </div>

            <div>
                <div class="flex justify-between items-center mb-2">
                    <span class="text-gray-600 dark:text-gray-400 text-sm">Execution Time</span>
                    <span class="text-gray-800 dark:text-white font-semibold"><?php echo $stats['execution_time']; ?>s</span>
                </div>
                <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2">
                    <div class="bg-green-600 h-2 rounded-full progress-bar" style="width: <?php echo min(($stats['execution_time'] / 2) * 100, 100); ?>%"></div>
                </div>
            </div>

            <div>
                <div class="flex justify-between items-center mb-2">
                    <span class="text-gray-600 dark:text-gray-400 text-sm">Database Size</span>
                    <span class="text-gray-800 dark:text-white font-semibold"><?php echo $stats['db_size']; ?> MB</span>
                </div>
                <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2">
                    <div class="bg-purple-600 h-2 rounded-full progress-bar" style="width: <?php echo min(($stats['db_size'] / 100) * 100, 100); ?>%"></div>
                </div>
            </div>

            <div class="pt-4 border-t border-gray-200 dark:border-gray-700">
                <div class="flex justify-between items-center">
                    <span class="text-gray-600 dark:text-gray-400 text-sm">Session ID</span>
                    <span class="font-mono text-gray-800 dark:text-white text-xs"><?php echo substr(session_id(), 0, 16); ?>...</span>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/layout/footer.php'; ?>
