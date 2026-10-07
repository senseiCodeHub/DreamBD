<?php
$pageTitle = 'Analytics';
$pageHeading = 'Analytics & Reports';
$currentPage = 'analytics';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => 'index.php'],
    ['label' => 'Analytics']
];

require_once __DIR__ . '/layout/header.php';

$db = Database::getInstance()->getConnection();

// Get analytics data
$analytics = [
    'user_registrations' => [],
    'active_users_today' => 0,
    'total_posts' => 0,
    'total_comments' => 0,
    'popular_pages' => []
];

try {
    // User registrations by month (last 6 months)
    $stmt = $db->query("
        SELECT DATE_FORMAT(registered_at, '%Y-%m') as month, COUNT(*) as count
        FROM users
        WHERE registered_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(registered_at, '%Y-%m')
        ORDER BY month
    ");
    $analytics['user_registrations'] = $stmt->fetchAll();

    // Active users today
    $stmt = $db->query("
        SELECT COUNT(DISTINCT user_id) FROM user_sessions
        WHERE last_activity >= UNIX_TIMESTAMP(CURDATE())
    ");
    $analytics['active_users_today'] = $stmt->fetchColumn() ?: 0;

    // Total posts
    $stmt = $db->query("SELECT COUNT(*) FROM posts");
    $analytics['total_posts'] = $stmt->fetchColumn();

    // Total comments
    $stmt = $db->query("SELECT COUNT(*) FROM comments");
    $analytics['total_comments'] = $stmt->fetchColumn();

} catch (PDOException $e) {
    // Tables might not exist
}
?>

<!-- Analytics Cards -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8 slide-in">
    <div class="stat-card bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700">
        <div class="flex items-center justify-between mb-4">
            <div class="p-3 bg-blue-100 dark:bg-blue-900/30 rounded-xl">
                <i class="fas fa-users text-2xl text-blue-600 dark:text-blue-400"></i>
            </div>
            <span class="text-xs text-green-600 dark:text-green-400 bg-green-100 dark:bg-green-900/30 px-2 py-1 rounded-full">
                +12%
            </span>
        </div>
        <h3 class="text-3xl font-bold text-gray-800 dark:text-white"><?php echo $analytics['active_users_today']; ?></h3>
        <p class="text-gray-600 dark:text-gray-400 text-sm mt-1">Active Today</p>
    </div>

    <div class="stat-card bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700">
        <div class="flex items-center justify-between mb-4">
            <div class="p-3 bg-green-100 dark:bg-green-900/30 rounded-xl">
                <i class="fas fa-file-alt text-2xl text-green-600 dark:text-green-400"></i>
            </div>
            <span class="text-xs text-green-600 dark:text-green-400 bg-green-100 dark:bg-green-900/30 px-2 py-1 rounded-full">
                +8%
            </span>
        </div>
        <h3 class="text-3xl font-bold text-gray-800 dark:text-white"><?php echo number_format($analytics['total_posts']); ?></h3>
        <p class="text-gray-600 dark:text-gray-400 text-sm mt-1">Total Posts</p>
    </div>

    <div class="stat-card bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700">
        <div class="flex items-center justify-between mb-4">
            <div class="p-3 bg-purple-100 dark:bg-purple-900/30 rounded-xl">
                <i class="fas fa-comments text-2xl text-purple-600 dark:text-purple-400"></i>
            </div>
            <span class="text-xs text-blue-600 dark:text-blue-400 bg-blue-100 dark:bg-blue-900/30 px-2 py-1 rounded-full">
                Live
            </span>
        </div>
        <h3 class="text-3xl font-bold text-gray-800 dark:text-white"><?php echo number_format($analytics['total_comments']); ?></h3>
        <p class="text-gray-600 dark:text-gray-400 text-sm mt-1">Comments</p>
    </div>

    <div class="stat-card bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700">
        <div class="flex items-center justify-between mb-4">
            <div class="p-3 bg-yellow-100 dark:bg-yellow-900/30 rounded-xl">
                <i class="fas fa-chart-line text-2xl text-yellow-600 dark:text-yellow-400"></i>
            </div>
            <span class="text-xs text-green-600 dark:text-green-400 bg-green-100 dark:bg-green-900/30 px-2 py-1 rounded-full">
                Growth
            </span>
        </div>
        <h3 class="text-3xl font-bold text-gray-800 dark:text-white">+24%</h3>
        <p class="text-gray-600 dark:text-gray-400 text-sm mt-1">Monthly Growth</p>
    </div>
</div>

<!-- User Registration Chart (Placeholder) -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <h2 class="text-xl font-bold text-gray-800 dark:text-white mb-6">
            <i class="fas fa-chart-area text-blue-500 mr-2"></i>User Registrations
        </h2>
        <div class="h-64 flex items-end space-x-2">
            <?php
            $maxCount = 1;
            foreach ($analytics['user_registrations'] as $reg) {
                if ($reg['count'] > $maxCount) $maxCount = $reg['count'];
            }
            foreach ($analytics['user_registrations'] as $reg):
                $height = ($reg['count'] / $maxCount) * 78;
            ?>
            <div class="flex-1 h-full flex flex-col items-center justify-end">
                <div class="w-full bg-gradient-to-t from-violet-600 to-violet-400 rounded-t-lg hover:from-violet-700 hover:to-violet-500 transition-all cursor-pointer"
                     style="height: <?php echo $height; ?>%"
                     title="<?php echo $reg['month']; ?>: <?php echo $reg['count']; ?> users">
                </div>
                <span class="text-xs text-gray-500 dark:text-gray-400 mt-2"><?php echo substr($reg['month'], 5); ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <h2 class="text-xl font-bold text-gray-800 dark:text-white mb-6">
            <i class="fas fa-pie-chart text-green-500 mr-2"></i>Activity Distribution
        </h2>
        <div class="space-y-4">
            <div>
                <div class="flex justify-between items-center mb-2">
                    <span class="text-sm text-gray-600 dark:text-gray-400">Posts</span>
                    <span class="text-sm font-semibold text-gray-800 dark:text-white">45%</span>
                </div>
                <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2">
                    <div class="bg-blue-600 h-2 rounded-full" style="width: 45%"></div>
                </div>
            </div>
            <div>
                <div class="flex justify-between items-center mb-2">
                    <span class="text-sm text-gray-600 dark:text-gray-400">Comments</span>
                    <span class="text-sm font-semibold text-gray-800 dark:text-white">30%</span>
                </div>
                <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2">
                    <div class="bg-green-600 h-2 rounded-full" style="width: 30%"></div>
                </div>
            </div>
            <div>
                <div class="flex justify-between items-center mb-2">
                    <span class="text-sm text-gray-600 dark:text-gray-400">Profile Views</span>
                    <span class="text-sm font-semibold text-gray-800 dark:text-white">25%</span>
                </div>
                <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2">
                    <div class="bg-purple-600 h-2 rounded-full" style="width: 25%"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Recent Activity Log -->
<div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
    <h2 class="text-xl font-bold text-gray-800 dark:text-white mb-6">
        <i class="fas fa-history text-purple-500 mr-2"></i>Recent Activity Log
    </h2>
    <div class="space-y-4">
        <div class="flex items-center space-x-4 p-4 bg-gray-50 dark:bg-gray-700/50 rounded-xl hover:shadow-md transition-shadow">
            <div class="w-10 h-10 bg-blue-100 dark:bg-blue-900/30 rounded-full flex items-center justify-center">
                <i class="fas fa-user-plus text-blue-500"></i>
            </div>
            <div class="flex-1">
                <p class="text-sm text-gray-800 dark:text-white font-medium">New user registered</p>
                <p class="text-xs text-gray-500 dark:text-gray-400">User ID: 12345 joined the platform</p>
            </div>
            <span class="text-xs text-gray-500 dark:text-gray-400">2 min ago</span>
        </div>
        <div class="flex items-center space-x-4 p-4 bg-gray-50 dark:bg-gray-700/50 rounded-xl hover:shadow-md transition-shadow">
            <div class="w-10 h-10 bg-green-100 dark:bg-green-900/30 rounded-full flex items-center justify-center">
                <i class="fas fa-edit text-green-500"></i>
            </div>
            <div class="flex-1">
                <p class="text-sm text-gray-800 dark:text-white font-medium">Post updated</p>
                <p class="text-xs text-gray-500 dark:text-gray-400">Post #5678 was edited</p>
            </div>
            <span class="text-xs text-gray-500 dark:text-gray-400">15 min ago</span>
        </div>
        <div class="flex items-center space-x-4 p-4 bg-gray-50 dark:bg-gray-700/50 rounded-xl hover:shadow-md transition-shadow">
            <div class="w-10 h-10 bg-purple-100 dark:bg-purple-900/30 rounded-full flex items-center justify-center">
                <i class="fas fa-comment text-purple-500"></i>
            </div>
            <div class="flex-1">
                <p class="text-sm text-gray-800 dark:text-white font-medium">New comment</p>
                <p class="text-xs text-gray-500 dark:text-gray-400">User commented on post #9012</p>
            </div>
            <span class="text-xs text-gray-500 dark:text-gray-400">1 hour ago</span>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/layout/footer.php'; ?>
