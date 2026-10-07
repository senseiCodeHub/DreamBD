<?php
$pageTitle = 'Database Manager';
$pageHeading = 'Database Manager';
$currentPage = 'database';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => 'index.php'],
    ['label' => 'Database Manager']
];

require_once __DIR__ . '/layout/header.php';

$db = Database::getInstance()->getConnection();
$queryResult = null;
$queryColumns = [];
$queryRows = [];
$executionTime = 0;

// Handle SQL execution
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sql_query'])) {
    if (!$security->validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token';
    } else {
        $sql = trim($_POST['sql_query']);
        if (!empty($sql)) {
            $startTime = microtime(true);
            try {
                if (stripos($sql, 'SELECT') === 0 || stripos($sql, 'SHOW') === 0 || stripos($sql, 'DESCRIBE') === 0) {
                    $stmt = $db->query($sql);
                    $queryRows = $stmt->fetchAll();
                    if (!empty($queryRows)) {
                        $queryColumns = array_keys($queryRows[0]);
                    }
                    $queryResult = 'success';
                } else {
                    $affected = $db->exec($sql);
                    $queryResult = 'affected';
                    $messages[] = "Query executed successfully. Affected rows: $affected";
                }
                $executionTime = round((microtime(true) - $startTime) * 1000, 2);
            } catch (PDOException $e) {
                $errors[] = 'SQL Error: ' . $e->getMessage();
            }
        }
    }
}

// Handle table creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!$security->validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token';
    } else {
        $action = $_POST['action'];
        try {
            if ($action === 'create_tables') {
                $tables = [
                    "CREATE TABLE IF NOT EXISTS slider_content (
                        id INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        title VARCHAR(255) NOT NULL,
                        badge VARCHAR(100) DEFAULT 'New',
                        description TEXT NOT NULL,
                        bg_gradient VARCHAR(255) DEFAULT 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
                        button1_text VARCHAR(100) DEFAULT 'Learn More',
                        button1_icon VARCHAR(50) DEFAULT 'info-circle',
                        button1_class VARCHAR(50) DEFAULT 'btn-primary',
                        button1_href VARCHAR(255) DEFAULT '#',
                        button2_text VARCHAR(100) DEFAULT 'Explore',
                        button2_icon VARCHAR(50) DEFAULT 'arrow-right',
                        button2_class VARCHAR(50) DEFAULT 'btn-outline',
                        button2_href VARCHAR(255) DEFAULT '#',
                        sort_order INT DEFAULT 0,
                        status ENUM('active', 'inactive') DEFAULT 'active',
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

                    "CREATE TABLE IF NOT EXISTS admin_users (
                        id INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        user_id INT(10) UNSIGNED NOT NULL,
                        role ENUM('super_admin', 'moderator') DEFAULT 'moderator',
                        permissions JSON,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_user_id (user_id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

                    "CREATE TABLE IF NOT EXISTS site_settings (
                        `key` VARCHAR(80) PRIMARY KEY,
                        `value` TEXT,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

                    "INSERT IGNORE INTO site_settings (`key`, `value`) VALUES ('slider_enabled', '1')",

                    "CREATE TABLE IF NOT EXISTS tournament_participants (
                        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        tournament_id INT UNSIGNED NOT NULL,
                        user_id INT UNSIGNED NOT NULL,
                        team_name VARCHAR(100) DEFAULT '',
                        status ENUM('registered','confirmed','cancelled') DEFAULT 'registered',
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_tournament (tournament_id),
                        INDEX idx_user (user_id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

                    "CREATE TABLE IF NOT EXISTS slider_players (
                        id INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        name VARCHAR(100) NOT NULL,
                        rank INT DEFAULT 0,
                        score INT DEFAULT 0,
                        avatar VARCHAR(255) DEFAULT 'default.png',
                        highlight VARCHAR(255) DEFAULT '',
                        is_active TINYINT(1) DEFAULT 1,
                        sort_order INT DEFAULT 0,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

                    "CREATE TABLE IF NOT EXISTS slider_ads (
                        id INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        title VARCHAR(150) NOT NULL,
                        description TEXT,
                        image_path VARCHAR(255) DEFAULT '',
                        link_url VARCHAR(255) DEFAULT '',
                        link_text VARCHAR(100) DEFAULT 'Learn More',
                        bg_color VARCHAR(50) DEFAULT '#1e293b',
                        badge_text VARCHAR(80) DEFAULT 'Sponsored',
                        is_active TINYINT(1) DEFAULT 1,
                        sort_order INT DEFAULT 0,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
                ];

                // Add slider_type column to slider_content if not exists
                try {
                    $db->exec("ALTER TABLE slider_content ADD COLUMN slider_type ENUM('features','tournament','leaderboard','ads') DEFAULT 'features' AFTER badge");
                } catch (PDOException $e) {}
                try {
                    $db->exec("ALTER TABLE slider_content ADD COLUMN image_path VARCHAR(255) DEFAULT NULL AFTER description");
                } catch (PDOException $e) {}
                try {
                    $db->exec("ALTER TABLE slider_content ADD COLUMN link_url VARCHAR(255) DEFAULT NULL AFTER button2_href");
                } catch (PDOException $e) {}
                try {
                    $db->exec("ALTER TABLE slider_content ADD COLUMN link_text VARCHAR(100) DEFAULT NULL AFTER link_url");
                } catch (PDOException $e) {}
                try {
                    $db->exec("ALTER TABLE slider_content ADD COLUMN bg_image VARCHAR(255) DEFAULT NULL AFTER bg_gradient");
                } catch (PDOException $e) {}
                try {
                    $db->exec("ALTER TABLE slider_players ADD COLUMN user_id INT UNSIGNED DEFAULT NULL AFTER name");
                } catch (PDOException $e) {}
                // Fix users table: add AUTO_INCREMENT, PRIMARY KEY, role, etc.
                try {
                    $fixNeeded = false;
                    $stmt = $db->query("SELECT EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '" . DatabaseConfig::getName() . "' AND TABLE_NAME = 'users' AND COLUMN_NAME = 'id'");
                    $row = $stmt->fetch();
                    if (!$row || strpos(strtolower($row['EXTRA'] ?? ''), 'auto_increment') === false) {
                        $fixNeeded = true;
                    }
                    
                    if ($fixNeeded) {
                        // Fix duplicate id=0 rows
                        $stmt = $db->query("SELECT COUNT(*) FROM users WHERE id = 0");
                        if ((int) $stmt->fetchColumn() > 0) {
                            // Drop FK constraints referencing users.id
                            $fkStmt = $db->query("SELECT TABLE_NAME, CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_NAME = 'users' AND REFERENCED_COLUMN_NAME = 'id' AND TABLE_SCHEMA = '" . DatabaseConfig::getName() . "'");
                            $fks = $fkStmt->fetchAll();
                            $dropped = [];
                            foreach ($fks as $fk) {
                                try {
                                    $db->exec("ALTER TABLE `{$fk['TABLE_NAME']}` DROP FOREIGN KEY `{$fk['CONSTRAINT_NAME']}`");
                                    $dropped[] = $fk;
                                } catch (PDOException $e) {}
                            }
                            try { $db->exec("ALTER TABLE users DROP PRIMARY KEY"); } catch (PDOException $e) {}
                            
                            $db->exec("SET @uid = 0");
                            $db->exec("UPDATE users SET id = (@uid := @uid + 1) WHERE id = 0 ORDER BY registered_at ASC");
                            
                            $stmt = $db->query("SELECT COALESCE(MAX(id), 0) + 1 FROM users");
                            $nextId = (int) $stmt->fetchColumn();
                            
                            $db->exec("ALTER TABLE users ADD PRIMARY KEY (id)");
                            $db->exec("ALTER TABLE users MODIFY id INT UNSIGNED NOT NULL AUTO_INCREMENT");
                            $db->exec("ALTER TABLE users AUTO_INCREMENT = {$nextId}");
                            
                            foreach ($dropped as $fk) {
                                try {
                                    $colStmt = $db->query("SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE CONSTRAINT_NAME = '{$fk['CONSTRAINT_NAME']}' AND TABLE_SCHEMA = '" . DatabaseConfig::getName() . "'");
                                    $col = $colStmt->fetchColumn();
                                    if ($col) $db->exec("ALTER TABLE `{$fk['TABLE_NAME']}` ADD CONSTRAINT `{$fk['CONSTRAINT_NAME']}` FOREIGN KEY (`{$col}`) REFERENCES users(id) ON DELETE CASCADE");
                                } catch (PDOException $e) {}
                            }
                        } else {
                            $db->exec("ALTER TABLE users MODIFY id INT UNSIGNED NOT NULL AUTO_INCREMENT");
                        }
                    }
                } catch (PDOException $e) { $errors[] = 'Users fix: ' . $e->getMessage(); }
                
                // Add missing columns
                foreach ([
                    "ALTER TABLE users ADD COLUMN role VARCHAR(30) DEFAULT 'user'",
                    "ALTER TABLE users ADD COLUMN login_attempts INT DEFAULT 0",
                    "ALTER TABLE users ADD COLUMN locked_until DATETIME DEFAULT NULL",
                    "ALTER TABLE users ADD COLUMN last_login DATETIME DEFAULT NULL",
                    "ALTER TABLE users ADD COLUMN last_ip VARCHAR(45) DEFAULT NULL",
                    "ALTER TABLE users ADD COLUMN registered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
                    "ALTER TABLE users ADD COLUMN status VARCHAR(30) DEFAULT 'active'",
                    "ALTER TABLE slider_content ADD COLUMN badge_icon VARCHAR(50) DEFAULT 'fa-star'",
                    "ALTER TABLE slider_content ADD COLUMN accent_color VARCHAR(50) DEFAULT '#3b82f6'",
                    "ALTER TABLE slider_content ADD COLUMN text_color VARCHAR(50) DEFAULT '#ffffff'",
                    "ALTER TABLE slider_content ADD COLUMN overlay_opacity DECIMAL(2,1) DEFAULT 0.6",
                    "ALTER TABLE tournaments ADD COLUMN prize_money VARCHAR(100) DEFAULT ''",
                    "ALTER TABLE tournaments ADD COLUMN category VARCHAR(80) DEFAULT ''",
                    "ALTER TABLE tournaments ADD COLUMN max_teams INT DEFAULT 0",
                    "ALTER TABLE tournaments ADD COLUMN game_icon VARCHAR(50) DEFAULT 'fa-gamepad'",
                    "ALTER TABLE tournaments ADD COLUMN accent_color VARCHAR(50) DEFAULT '#7c3aed'",
                ] as $sql) {
                    try { $db->exec($sql); } catch (PDOException $e) {}
                }

                foreach ($tables as $sql) {
                    $db->exec($sql);
                }

                $messages[] = '[SUCCESS] All tables created successfully with default data';
            }
        } catch (PDOException $e) {
            $errors[] = '[ERROR] ' . $e->getMessage();
        }
    }
}

// Get existing tables
$tables = [];
try {
    $stmt = $db->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    // Ignore
}
?>

<div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700 hover:shadow-md transition-shadow">
        <h3 class="text-lg font-semibold text-gray-800 dark:text-white mb-4">
            <i class="fas fa-cogs text-blue-500 mr-2"></i>Initialize
        </h3>
        <p class="text-gray-600 dark:text-gray-400 text-sm mb-4">Create all required tables</p>
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <input type="hidden" name="action" value="create_tables">
            <button type="submit" class="w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors">
                <i class="fas fa-play mr-2"></i>Create Tables
            </button>
        </form>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700">
        <h3 class="text-lg font-semibold text-gray-800 dark:text-white mb-4">
            <i class="fas fa-table text-purple-500 mr-2"></i>Tables
        </h3>
        <p class="text-gray-600 dark:text-gray-400 text-sm mb-2">Existing: <?php echo count($tables); ?></p>
        <div class="max-h-32 overflow-y-auto">
            <?php foreach ($tables as $table): ?>
            <div class="text-xs text-gray-600 dark:text-gray-400 py-1">
                <i class="fas fa-table mr-2 text-gray-400"></i><?php echo htmlspecialchars($table); ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700">
        <h3 class="text-lg font-semibold text-gray-800 dark:text-white mb-4">
            <i class="fas fa-info-circle text-green-500 mr-2"></i>Quick SQL
        </h3>
        <div class="space-y-2">
            <button onclick="setQuery('SELECT * FROM users LIMIT 10')" class="w-full text-left px-3 py-2 text-xs bg-gray-50 dark:bg-gray-700 rounded hover:bg-blue-50 dark:hover:bg-blue-900/20 transition-colors">
                SELECT * FROM users
            </button>
            <button onclick="setQuery('SHOW TABLES')" class="w-full text-left px-3 py-2 text-xs bg-gray-50 dark:bg-gray-700 rounded hover:bg-blue-50 dark:hover:bg-blue-900/20 transition-colors">
                SHOW TABLES
            </button>
            <button onclick="setQuery('DESCRIBE slider_content')" class="w-full text-left px-3 py-2 text-xs bg-gray-50 dark:bg-gray-700 rounded hover:bg-blue-50 dark:hover:bg-blue-900/20 transition-colors">
                DESCRIBE slider_content
            </button>
        </div>
    </div>
</div>

<!-- SQL Console -->
<div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden mb-8">
    <div class="bg-gray-50 dark:bg-gray-700 px-6 py-4 border-b border-gray-200 dark:border-gray-600">
        <h3 class="text-lg font-semibold text-gray-800 dark:text-white">
            <i class="fas fa-terminal text-green-500 mr-2"></i>SQL Console
        </h3>
    </div>
    <div class="p-6">
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <div class="mb-4">
                <textarea name="sql_query" id="sqlQuery" rows="8" class="w-full px-4 py-3 bg-gray-50 dark:bg-gray-900 border border-gray-300 dark:border-gray-600 rounded-lg font-mono text-sm text-gray-800 dark:text-gray-200 focus:ring-2 focus:ring-blue-500 focus:border-transparent" placeholder="SELECT * FROM users LIMIT 10;"></textarea>
            </div>
            <div class="flex justify-between items-center">
                <div class="text-xs text-gray-500 dark:text-gray-400">
                    <i class="fas fa-info-circle mr-1"></i>Supports SELECT, SHOW, DESCRIBE, INSERT, UPDATE, DELETE
                </div>
                <button type="submit" class="px-6 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg transition-colors">
                    <i class="fas fa-play mr-2"></i>Execute Query
                </button>
            </div>
        </form>

        <!-- Query Result -->
        <?php if ($queryResult === 'success' && !empty($queryRows)): ?>
        <div class="border-t border-gray-200 dark:border-gray-700 mt-6 fade-in">
            <div class="flex justify-between items-center mb-4">
                <h4 class="text-md font-semibold text-gray-800 dark:text-white">
                    <i class="fas fa-table mr-2"></i>Query Results
                </h4>
                <span class="text-xs text-gray-500 dark:text-gray-400">
                    <?php echo count($queryRows); ?> rows | <?php echo $executionTime; ?>ms
                </span>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <?php foreach ($queryColumns as $col): ?>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider"><?php echo htmlspecialchars($col); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                        <?php foreach ($queryRows as $row): ?>
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                            <?php foreach ($queryColumns as $col): ?>
                            <td class="px-4 py-3 text-sm text-gray-800 dark:text-gray-200"><?php echo htmlspecialchars(substr($row[$col] ?? '', 0, 100)); ?></td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php elseif ($queryResult === 'affected'): ?>
        <div class="border-t border-gray-200 dark:border-gray-700 mt-6 fade-in">
            <div class="p-4 bg-green-50 dark:bg-green-900/20 rounded-lg">
                <i class="fas fa-check-circle text-green-500 mr-2"></i>
                <span class="text-green-700 dark:text-green-400">Query executed successfully in <?php echo $executionTime; ?>ms</span>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
    function setQuery(sql) {
        document.getElementById('sqlQuery').value = sql;
    }

    // Auto-resize textarea
    document.getElementById('sqlQuery')?.addEventListener('keydown', function(e) {
        if (e.key === 'Tab') {
            e.preventDefault();
            this.value += '    ';
        }
    });
</script>

<?php require_once __DIR__ . '/layout/footer.php'; ?>
