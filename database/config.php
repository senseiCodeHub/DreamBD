<?php
date_default_timezone_set('Asia/Dhaka');

/**
 * Simple .env loader
 */
function loadEnv($path) {
    if (!file_exists($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
            putenv(sprintf('%s=%s', $name, $value));
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

// Load environment variables if .env exists
loadEnv(__DIR__ . '/../.env');

/**
 * Robust env() — checks getenv(), $_ENV, $_SERVER in order.
 * Works even if putenv()/getenv() are restricted on some hosts.
 */
if (!function_exists('env')) {
    function env(string $key, $default = null) {
        $val = getenv($key);
        if ($val !== false && $val !== '') return $val;
        return $_ENV[$key] ?? $_SERVER[$key] ?? $default;
    }
}

if (!function_exists('dream_asset')) {
    function dream_asset(string $path): string {
        $normalized = ltrim($path, '/');
        $file = __DIR__ . '/../' . $normalized;
        $version = is_file($file) ? (string) filemtime($file) : '1';
        return htmlspecialchars($normalized . '?v=' . $version, ENT_QUOTES, 'UTF-8');
    }
}

class DatabaseConfig {
    // Database Configuration
    public static function getHost() { return env('DB_HOST', 'localhost'); }
    public static function getName() { return env('DB_NAME', 'dream'); }
    public static function getUser() { return env('DB_USER', 'root'); }
    public static function getPass() { return env('DB_PASS', ''); }
    const DB_CHARSET = 'utf8mb4';
    
    // Security Configuration
    const MAX_LOGIN_ATTEMPTS = 5;
    const LOCKOUT_TIME = 900; // 15 minutes
    const SESSION_TIMEOUT = 86400; // 24 hours
    
    // JWT Configuration
    public static function getJwtSecret() { 
        return env('JWT_SECRET', '7535d3f26d41e280fd659f0793e2d77fee6b165b7001f505a62e67c93864bd5b'); 
    }
    
    // Encryption Configuration
    public static function getEncryptionKey() { 
        return env('ENCRYPTION_KEY', 'R1B5KpP+F9k8eZ9rP2sA7m7Y0Z1E2M9F0KJXc3nq8y4='); 
    }
    
    // SMTP Configuration (Brevo)
    public static function getSmtpHost() { return env('SMTP_HOST', 'smtp-relay.brevo.com'); }
    public static function getSmtpPort() { return env('SMTP_PORT', 587); }
    public static function getSmtpUser() { return env('SMTP_USER', 'ad8803001@smtp-brevo.com'); }
    public static function getSmtpPass() { return env('SMTP_PASS', ''); }

    // reCAPTCHA Configuration
    const RECAPTCHA_SITE_KEY = '6LewAA0tAAAAAHYT_EzWeqK2p6rZ8Rl07XqJVkXu';
    const RECAPTCHA_SECRET_KEY = '6LewAA0tAAAAAIFjaFow-sAzq7OfNVucnVwzHGSm';
}

class Database {
    private static $instance = null;
    private $connection;
    
    private function __construct() {
        $dsn = "mysql:host=" . DatabaseConfig::getHost() . 
               ";dbname=" . DatabaseConfig::getName() . 
               ";charset=" . DatabaseConfig::DB_CHARSET;
        
        try {
            $this->connection = new PDO($dsn, DatabaseConfig::getUser(), DatabaseConfig::getPass(), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]);
            $this->connection->exec("SET time_zone = '+06:00'");
        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            throw new Exception("Database connection error.");
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }
    
    public function getConnection() { return $this->connection; }
}

function dream_column_exists(PDO $db, string $table, string $column): bool {
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*)
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
        ");
        $stmt->execute([$table, $column]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function dream_column_type(PDO $db, string $table, string $column): ?string {
    try {
        $stmt = $db->prepare("
            SELECT COLUMN_TYPE
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
            LIMIT 1
        ");
        $stmt->execute([$table, $column]);
        $value = $stmt->fetchColumn();
        return $value !== false ? strtolower((string) $value) : null;
    } catch (Throwable $e) {
        return null;
    }
}

function ensureUserSessionsSchema(PDO $db): void {
    $hasSessionToken = dream_column_exists($db, 'user_sessions', 'session_token');
    $idType = dream_column_type($db, 'user_sessions', 'id');

    if (!$hasSessionToken || ($idType !== null && strpos($idType, 'int') !== 0)) {
        $legacyTable = 'user_sessions_legacy_' . date('YmdHis');
        $sourceToken = $hasSessionToken ? 'session_token' : 'id';

        $db->exec("
            CREATE TABLE IF NOT EXISTS user_sessions_migrated (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                session_token VARCHAR(128) NOT NULL,
                payload LONGTEXT NULL,
                user_agent TEXT NULL,
                ip_address VARCHAR(45) NULL,
                last_activity INT UNSIGNED NOT NULL,
                expires_at DATETIME NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_token (session_token),
                INDEX idx_user (user_id),
                INDEX idx_activity (last_activity),
                INDEX idx_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->exec("
            INSERT IGNORE INTO user_sessions_migrated
                (user_id, session_token, payload, user_agent, ip_address, last_activity, expires_at, created_at)
            SELECT
                user_id,
                CAST({$sourceToken} AS CHAR(128)),
                payload,
                user_agent,
                ip_address,
                last_activity,
                expires_at,
                COALESCE(created_at, CURRENT_TIMESTAMP)
            FROM user_sessions
        ");

        $db->exec("RENAME TABLE user_sessions TO {$legacyTable}, user_sessions_migrated TO user_sessions");
    }

    $migrations = [
        "ALTER TABLE user_sessions MODIFY id INT UNSIGNED NOT NULL AUTO_INCREMENT",
        "ALTER TABLE user_sessions MODIFY user_id INT UNSIGNED NOT NULL",
        "ALTER TABLE user_sessions MODIFY session_token VARCHAR(128) NOT NULL",
        "ALTER TABLE user_sessions MODIFY last_activity INT UNSIGNED NOT NULL",
        "ALTER TABLE user_sessions MODIFY expires_at DATETIME NOT NULL",
        "ALTER TABLE user_sessions ADD COLUMN IF NOT EXISTS payload LONGTEXT NULL AFTER session_token",
        "ALTER TABLE user_sessions ADD COLUMN IF NOT EXISTS user_agent TEXT NULL AFTER payload",
        "ALTER TABLE user_sessions ADD COLUMN IF NOT EXISTS ip_address VARCHAR(45) NULL AFTER user_agent",
        "ALTER TABLE user_sessions ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER expires_at",
        "ALTER TABLE user_sessions ADD UNIQUE INDEX IF NOT EXISTS uniq_token (session_token)",
        "ALTER TABLE user_sessions ADD INDEX IF NOT EXISTS idx_user (user_id)",
        "ALTER TABLE user_sessions ADD INDEX IF NOT EXISTS idx_activity (last_activity)",
        "ALTER TABLE user_sessions ADD INDEX IF NOT EXISTS idx_expires (expires_at)"
    ];

    foreach ($migrations as $sql) {
        try {
            $db->exec($sql);
        } catch (Throwable $e) {
        }
    }
}

function ensureTournamentFeatureSchema(PDO $db): void {
    $queries = [
        "ALTER TABLE tournament_participants ADD COLUMN IF NOT EXISTS team_id INT UNSIGNED NULL AFTER user_id",
        "ALTER TABLE tournament_participants ADD COLUMN IF NOT EXISTS fee_paid TINYINT(1) NOT NULL DEFAULT 0 AFTER team_name",
        "ALTER TABLE tournament_participants MODIFY team_name VARCHAR(120) NULL",
        "CREATE TABLE IF NOT EXISTS tournament_chat_messages (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            tournament_id INT UNSIGNED NOT NULL,
            sender_id INT UNSIGNED NOT NULL,
            message_type ENUM('text','room_card','system') NOT NULL DEFAULT 'text',
            message TEXT NULL,
            room_code VARCHAR(120) NULL,
            room_password VARCHAR(120) NULL,
            room_link VARCHAR(255) NULL,
            invite_note TEXT NULL,
            metadata_json TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_tournament_chat (tournament_id, created_at),
            INDEX idx_sender_chat (sender_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS tournament_results (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            tournament_id INT UNSIGNED NOT NULL,
            team_id INT UNSIGNED NULL,
            user_id INT UNSIGNED NULL,
            result_scope ENUM('team','player') NOT NULL DEFAULT 'player',
            placement INT NULL,
            points INT NULL,
            points_earned INT DEFAULT 0,
            kills INT NULL,
            score DECIMAL(10,2) NULL,
            result_label VARCHAR(255) NULL,
            prize_amount DECIMAL(10,2) DEFAULT 0.00,
            notes TEXT NULL,
            result_note TEXT NULL,
            submitted_by INT UNSIGNED NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_tournament_team (tournament_id, team_id),
            UNIQUE KEY uniq_tournament_player (tournament_id, user_id),
            INDEX idx_tournament_result (tournament_id),
            INDEX idx_user_result (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];

    foreach ($queries as $sql) {
        try {
            $db->exec($sql);
        } catch (Throwable $e) {
        }
    }

    // Migrate existing tournament_results table with missing columns
    $tableMigrations = [
        "ALTER TABLE tournament_chat_messages ADD COLUMN IF NOT EXISTS metadata_json TEXT NULL AFTER invite_note",
        "ALTER TABLE tournament_results ADD COLUMN IF NOT EXISTS result_scope ENUM('team','player') NOT NULL DEFAULT 'player' AFTER user_id",
        "ALTER TABLE tournament_results ADD COLUMN IF NOT EXISTS result_label VARCHAR(255) NULL AFTER score",
        "ALTER TABLE tournament_results ADD COLUMN IF NOT EXISTS prize_amount DECIMAL(10,2) DEFAULT 0.00 AFTER result_label",
        "ALTER TABLE tournament_results ADD COLUMN IF NOT EXISTS points_earned INT DEFAULT 0 AFTER points",
        "ALTER TABLE tournament_results ADD COLUMN IF NOT EXISTS notes TEXT NULL AFTER prize_amount",
        "ALTER TABLE tournament_results ADD INDEX IF NOT EXISTS idx_result_tournament_scope (tournament_id, result_scope)",
        "ALTER TABLE tournament_results ADD INDEX IF NOT EXISTS idx_result_user_tournament (user_id, tournament_id)",
        "CREATE TABLE IF NOT EXISTS tournament_leaderboard (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            tournament_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            total_points INT DEFAULT 0,
            total_prize DECIMAL(10,2) DEFAULT 0.00,
            tournaments_played INT DEFAULT 0,
            best_rank INT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_player_leaderboard (tournament_id, user_id),
            INDEX idx_leaderboard_points (tournament_id, total_points DESC),
            INDEX idx_user_leaderboard (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];
    foreach ($tableMigrations as $sql) {
        try { $db->exec($sql); } catch (Throwable $e) {}
    }

    // ── Tournament v2: format engine, score flow, check-in, escrow ──
    $v2Migrations = [
        // tournaments: rules, best-of, check-in window, escrow accounting
        "ALTER TABLE tournaments ADD COLUMN IF NOT EXISTS rules TEXT NULL AFTER description",
        "ALTER TABLE tournaments ADD COLUMN IF NOT EXISTS best_of INT NOT NULL DEFAULT 1 AFTER bracket_type",
        "ALTER TABLE tournaments ADD COLUMN IF NOT EXISTS checkin_minutes INT NOT NULL DEFAULT 30 AFTER starts_at",
        "ALTER TABLE tournaments ADD COLUMN IF NOT EXISTS checkin_opens_at DATETIME NULL AFTER checkin_minutes",
        "ALTER TABLE tournaments ADD COLUMN IF NOT EXISTS fee_escrow DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER entry_fee",
        "ALTER TABLE tournaments ADD COLUMN IF NOT EXISTS prize_escrow DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER fee_escrow",
        "ALTER TABLE tournaments ADD COLUMN IF NOT EXISTS escrow_released TINYINT(1) NOT NULL DEFAULT 0 AFTER prize_escrow",
        "ALTER TABLE tournaments ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",

        // participants: check-in state + duplicate-registration guards
        "ALTER TABLE tournament_participants ADD COLUMN IF NOT EXISTS checked_in TINYINT(1) NOT NULL DEFAULT 0 AFTER fee_paid",
        "ALTER TABLE tournament_participants ADD COLUMN IF NOT EXISTS checked_in_at DATETIME NULL AFTER checked_in",

        // matches: explicit advancement graph + score report state machine
        "ALTER TABLE tournament_matches ADD COLUMN IF NOT EXISTS stage VARCHAR(20) NOT NULL DEFAULT 'single' AFTER tournament_id",
        "ALTER TABLE tournament_matches ADD COLUMN IF NOT EXISTS match_no INT NULL AFTER round",
        "ALTER TABLE tournament_matches ADD COLUMN IF NOT EXISTS is_bye TINYINT(1) NOT NULL DEFAULT 0 AFTER status",
        "ALTER TABLE tournament_matches ADD COLUMN IF NOT EXISTS team1_kind ENUM('team','user') NOT NULL DEFAULT 'user' AFTER team1_id",
        "ALTER TABLE tournament_matches ADD COLUMN IF NOT EXISTS team2_kind ENUM('team','user') NOT NULL DEFAULT 'user' AFTER team2_id",
        "ALTER TABLE tournament_matches ADD COLUMN IF NOT EXISTS next_match_id INT UNSIGNED NULL AFTER winner_id",
        "ALTER TABLE tournament_matches ADD COLUMN IF NOT EXISTS next_slot TINYINT NULL AFTER next_match_id",
        "ALTER TABLE tournament_matches ADD COLUMN IF NOT EXISTS loser_next_match_id INT UNSIGNED NULL AFTER next_slot",
        "ALTER TABLE tournament_matches ADD COLUMN IF NOT EXISTS loser_next_slot TINYINT NULL AFTER loser_next_match_id",
        "ALTER TABLE tournament_matches ADD COLUMN IF NOT EXISTS best_of INT NOT NULL DEFAULT 1 AFTER scheduled_at",
        "ALTER TABLE tournament_matches ADD COLUMN IF NOT EXISTS report_state ENUM('none','reported','confirmed','disputed') NOT NULL DEFAULT 'none' AFTER best_of",
        "ALTER TABLE tournament_matches ADD COLUMN IF NOT EXISTS rep1 INT NULL AFTER report_state",
        "ALTER TABLE tournament_matches ADD COLUMN IF NOT EXISTS rep2 INT NULL AFTER rep1",
        "ALTER TABLE tournament_matches ADD COLUMN IF NOT EXISTS reported_by INT UNSIGNED NULL AFTER rep2",
        "ALTER TABLE tournament_matches ADD COLUMN IF NOT EXISTS reported_at DATETIME NULL AFTER reported_by",
        "ALTER TABLE tournament_matches ADD COLUMN IF NOT EXISTS confirmed_by INT UNSIGNED NULL AFTER reported_at",
        "ALTER TABLE tournament_matches ADD COLUMN IF NOT EXISTS confirmed_at DATETIME NULL AFTER confirmed_by",
        "ALTER TABLE tournament_matches ADD COLUMN IF NOT EXISTS dispute_note TEXT NULL AFTER confirmed_at",
        "ALTER TABLE tournament_matches ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
        "ALTER TABLE tournament_matches ADD INDEX IF NOT EXISTS idx_tm_stage (tournament_id, stage)",
        "ALTER TABLE tournament_matches ADD INDEX IF NOT EXISTS idx_tm_report (tournament_id, report_state)",
    ];
    foreach ($v2Migrations as $sql) {
        try { $db->exec($sql); } catch (Throwable $e) {}
    }

    // Dedup before unique guards (prefer confirmed > registered > latest)
    $v2Cleanup = [
        "DELETE FROM tournament_participants WHERE id NOT IN (
            SELECT id FROM (
                SELECT id, ROW_NUMBER() OVER (
                    PARTITION BY tournament_id, user_id
                    ORDER BY (status = 'confirmed') DESC, (status = 'registered') DESC, id DESC
                ) AS rn FROM tournament_participants
            ) ranked WHERE rn = 1
        )",
        "DELETE FROM tournament_participants WHERE team_id IS NOT NULL AND id NOT IN (
            SELECT id FROM (
                SELECT id, ROW_NUMBER() OVER (
                    PARTITION BY tournament_id, team_id
                    ORDER BY (status = 'confirmed') DESC, (status = 'registered') DESC, id DESC
                ) AS rn FROM tournament_participants WHERE team_id IS NOT NULL
            ) ranked WHERE rn = 1
        )",
    ];
    foreach ($v2Cleanup as $sql) {
        try { $db->exec($sql); } catch (Throwable $e) {}
    }

    $v2Indexes = [
        "ALTER TABLE tournament_participants ADD UNIQUE INDEX IF NOT EXISTS uniq_tn_user (tournament_id, user_id)",
        "ALTER TABLE tournament_participants ADD UNIQUE INDEX IF NOT EXISTS uniq_tn_team (tournament_id, team_id)",
    ];
    foreach ($v2Indexes as $sql) {
        try { $db->exec($sql); } catch (Throwable $e) {}
    }

    // Infer team/user kind for pre-existing matches
    try {
        $db->exec("UPDATE tournament_matches tm SET
            team1_kind = IF(tm.team1_id IS NOT NULL AND EXISTS(SELECT 1 FROM teams t WHERE t.id = tm.team1_id), 'team', 'user'),
            team2_kind = IF(tm.team2_id IS NOT NULL AND EXISTS(SELECT 1 FROM teams t WHERE t.id = tm.team2_id), 'team', 'user')
            WHERE tm.team1_kind = 'user' AND tm.team2_kind = 'user' AND (tm.team1_id IS NOT NULL OR tm.team2_id IS NOT NULL)");
    } catch (Throwable $e) {}

    // One-time escrow backfill for legacy (pre-escrow) tournaments
    try {
        $stmt = $db->query("SELECT COUNT(*) FROM site_settings WHERE `key` = 'tournament_escrow_backfilled'");
        if ((int)$stmt->fetchColumn() === 0) {
            $db->exec("UPDATE tournaments t SET
                fee_escrow = t.entry_fee * (
                    SELECT COUNT(*) FROM tournament_participants tp
                    WHERE tp.tournament_id = t.id AND tp.status = 'confirmed' AND tp.fee_paid = 1
                ),
                prize_escrow = IF(t.prize_escrow = 0, CAST(REPLACE(REPLACE(t.prize_money, ',', ''), '৳', '') AS DECIMAL(10,2)), t.prize_escrow)
                WHERE t.status IN ('upcoming', 'live')");
            $db->exec("INSERT IGNORE INTO site_settings (`key`, `value`) VALUES ('tournament_escrow_backfilled', '1')");
        }
    } catch (Throwable $e) {}
}

function ensureP2PSchema(PDO $db): void {
    $queries = [
        "CREATE TABLE IF NOT EXISTS p2p_offers (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            agent_id INT UNSIGNED NOT NULL,
            type ENUM('buy','sell') NOT NULL DEFAULT 'sell',
            coin_type ENUM('bronze','silver','gold') NOT NULL DEFAULT 'bronze',
            price_per_coin DECIMAL(10,2) NOT NULL,
            quantity INT UNSIGNED NOT NULL,
            remaining INT UNSIGNED NOT NULL,
            min_amount INT UNSIGNED NOT NULL DEFAULT 1,
            max_amount INT UNSIGNED NOT NULL DEFAULT 0,
            status ENUM('active','completed','cancelled') NOT NULL DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_agent (agent_id),
            INDEX idx_type_status (type, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS p2p_payment_settings (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            method VARCHAR(30) NOT NULL,
            number VARCHAR(50) NOT NULL,
            instruction VARCHAR(30) DEFAULT 'send_money',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_user_method (user_id, method)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS p2p_chat_messages (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            trade_id INT UNSIGNED NOT NULL,
            sender_id INT UNSIGNED NOT NULL,
            message TEXT,
            image_path VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_trade (trade_id),
            INDEX idx_sender (sender_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS p2p_reports (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            trade_id INT UNSIGNED NOT NULL,
            reporter_id INT UNSIGNED NOT NULL,
            reported_id INT UNSIGNED NOT NULL,
            reason VARCHAR(100) NOT NULL,
            details TEXT,
            status ENUM('open','resolved','dismissed') DEFAULT 'open',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_trade (trade_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS p2p_reviews (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            trade_id INT UNSIGNED NOT NULL,
            reviewer_id INT UNSIGNED NOT NULL,
            merchant_id INT UNSIGNED NOT NULL,
            rating TINYINT UNSIGNED NOT NULL CHECK (rating >= 1 AND rating <= 5),
            comment VARCHAR(500) DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_reviewer_merchant (reviewer_id, merchant_id),
            INDEX idx_merchant (merchant_id),
            INDEX idx_reviewer (reviewer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS coin_transactions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            from_user_id INT UNSIGNED,
            to_user_id INT UNSIGNED,
            type VARCHAR(30) NOT NULL,
            coin_type ENUM('bronze','silver','gold') NOT NULL,
            amount INT UNSIGNED NOT NULL,
            price DECIMAL(10,2),
            description VARCHAR(255),
            ref_id INT UNSIGNED,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_from (from_user_id),
            INDEX idx_to (to_user_id),
            INDEX idx_type (type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];
    foreach ($queries as $sql) {
        try { $db->exec($sql); } catch (Throwable $e) {}
    }

    // Add missing columns to p2p_trades
    $tradeMigrations = [
        "ALTER TABLE p2p_trades ADD COLUMN IF NOT EXISTS offer_id INT UNSIGNED NOT NULL AFTER id",
        "ALTER TABLE p2p_trades ADD COLUMN IF NOT EXISTS coin_type ENUM('bronze','silver','gold') NOT NULL DEFAULT 'bronze' AFTER buyer_id",
        "ALTER TABLE p2p_trades ADD COLUMN IF NOT EXISTS total_price DECIMAL(12,2) NOT NULL AFTER quantity",
        "ALTER TABLE p2p_trades ADD COLUMN IF NOT EXISTS payment_method VARCHAR(30) AFTER status",
        "ALTER TABLE p2p_trades ADD COLUMN IF NOT EXISTS sender_phone VARCHAR(30) AFTER payment_method",
        "ALTER TABLE p2p_trades ADD COLUMN IF NOT EXISTS txid VARCHAR(100) AFTER sender_phone",
        "ALTER TABLE p2p_trades ADD COLUMN IF NOT EXISTS completed_at DATETIME AFTER txid",
        "ALTER TABLE p2p_trades MODIFY status ENUM('pending','paid','completed','cancelled','disputed') NOT NULL DEFAULT 'pending'"
    ];
    foreach ($tradeMigrations as $sql) {
        try { $db->exec($sql); } catch (Throwable $e) {}
    }

    try {
        if (!dream_column_exists($db, 'p2p_chat_messages', 'image_path')) {
            $db->exec("ALTER TABLE p2p_chat_messages ADD COLUMN image_path VARCHAR(255) DEFAULT NULL AFTER message");
        }
    } catch (Throwable $e) {}

    // Add missing columns to p2p_reports
    $reportMigrations = [
        "ALTER TABLE p2p_reports ADD COLUMN IF NOT EXISTS resolved_at DATETIME AFTER status",
        "ALTER TABLE p2p_reports ADD COLUMN IF NOT EXISTS resolved_by INT UNSIGNED AFTER resolved_at",
        "ALTER TABLE p2p_reports ADD COLUMN IF NOT EXISTS admin_note VARCHAR(500) AFTER resolved_by"
    ];
    foreach ($reportMigrations as $sql) {
        try { $db->exec($sql); } catch (Throwable $e) {}
    }

    // Migrate p2p_reviews unique key to per-user-per-merchant
    try {
        $db->exec("ALTER TABLE p2p_reviews DROP INDEX IF EXISTS uniq_trade_review");
    } catch (Throwable $e) {}
    try {
        $db->exec("ALTER TABLE p2p_reviews ADD UNIQUE KEY IF NOT EXISTS uniq_reviewer_merchant (reviewer_id, merchant_id)");
    } catch (Throwable $e) {}
}

function ensureClubFeatureSchema(PDO $db): void {
    $queries = [
        // Players table (extends users for market tracking)
        "CREATE TABLE IF NOT EXISTS players (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL UNIQUE,
            current_club_id INT UNSIGNED NULL,
            owner_id INT UNSIGNED NULL,
            status ENUM('active','free_agent','retired','banned') NOT NULL DEFAULT 'free_agent',
            market_value DECIMAL(10,2) DEFAULT 0.00,
            base_price DECIMAL(10,2) DEFAULT 0.00,
            rating DECIMAL(3,1) DEFAULT 0.0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_player_club (current_club_id),
            INDEX idx_player_owner (owner_id),
            INDEX idx_player_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Clubs
        "CREATE TABLE IF NOT EXISTS clubs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            tag VARCHAR(10) NOT NULL UNIQUE,
            logo VARCHAR(255) DEFAULT 'default-club.png',
            colour VARCHAR(20) DEFAULT '#7c3aed',
            description TEXT NULL,
            owner_id INT UNSIGNED NOT NULL,
            region VARCHAR(80) NULL,
            trophies INT DEFAULT 0,
            total_points INT DEFAULT 0,
            rank INT DEFAULT 0,
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (owner_id) REFERENCES users(id),
            INDEX idx_club_owner (owner_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Club members
        "CREATE TABLE IF NOT EXISTS club_members (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            club_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            role ENUM('owner','manager','player','sub') NOT NULL DEFAULT 'player',
            joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_club_user (club_id, user_id),
            FOREIGN KEY (club_id) REFERENCES clubs(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_club_member_club (club_id),
            INDEX idx_club_member_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Player stats
        "CREATE TABLE IF NOT EXISTS player_stats (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            tournament_id INT UNSIGNED NULL,
            goals INT DEFAULT 0,
            assists INT DEFAULT 0,
            matches_played INT DEFAULT 0,
            wins INT DEFAULT 0,
            kills INT DEFAULT 0,
            score DECIMAL(10,2) DEFAULT 0.00,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE SET NULL,
            INDEX idx_stats_user (user_id),
            INDEX idx_stats_tournament (tournament_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Game skills per user per game
        "CREATE TABLE IF NOT EXISTS game_skills (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            game VARCHAR(80) NOT NULL,
            skill_level VARCHAR(30) DEFAULT '',
            game_icon VARCHAR(40) DEFAULT 'fa-gamepad',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE KEY uk_user_game (user_id, game)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Player auctions
        "CREATE TABLE IF NOT EXISTS player_auctions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            player_id INT UNSIGNED NOT NULL,
            seller_id INT UNSIGNED NOT NULL,
            base_price DECIMAL(10,2) NOT NULL,
            current_price DECIMAL(10,2) NOT NULL,
            min_increment DECIMAL(10,2) DEFAULT 50.00,
            start_time DATETIME DEFAULT CURRENT_TIMESTAMP,
            end_time DATETIME NOT NULL,
            status ENUM('active','completed','cancelled') NOT NULL DEFAULT 'active',
            winner_id INT UNSIGNED NULL,
            final_price DECIMAL(10,2) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
            FOREIGN KEY (seller_id) REFERENCES users(id),
            FOREIGN KEY (winner_id) REFERENCES users(id) ON DELETE SET NULL,
            INDEX idx_auction_player (player_id),
            INDEX idx_auction_seller (seller_id),
            INDEX idx_auction_status (status),
            INDEX idx_auction_end (end_time)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Auction bids
        "CREATE TABLE IF NOT EXISTS auction_bids (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            auction_id INT UNSIGNED NOT NULL,
            bidder_id INT UNSIGNED NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (auction_id) REFERENCES player_auctions(id) ON DELETE CASCADE,
            FOREIGN KEY (bidder_id) REFERENCES users(id),
            INDEX idx_bid_auction (auction_id),
            INDEX idx_bid_bidder (bidder_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Player transfers history
        "CREATE TABLE IF NOT EXISTS player_transfers (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            player_id INT UNSIGNED NOT NULL,
            from_club_id INT UNSIGNED NULL,
            to_club_id INT UNSIGNED NULL,
            from_owner_id INT UNSIGNED NULL,
            to_owner_id INT UNSIGNED NULL,
            amount DECIMAL(10,2) DEFAULT 0.00,
            type ENUM('auction','direct_sale','release','hire','free_agent') NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
            FOREIGN KEY (from_club_id) REFERENCES clubs(id) ON DELETE SET NULL,
            FOREIGN KEY (to_club_id) REFERENCES clubs(id) ON DELETE SET NULL,
            INDEX idx_transfer_player (player_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];

    foreach ($queries as $sql) {
        try { $db->exec($sql); } catch (PDOException $e) { error_log("Club Schema: " . $e->getMessage()); }
    }

    // Always-run column additions
    $colQueries = [
        "ALTER TABLE tournaments ADD COLUMN IF NOT EXISTS restricted_club_id INT UNSIGNED NULL AFTER max_teams",
        "ALTER TABLE tournaments ADD COLUMN IF NOT EXISTS bracket_type VARCHAR(40) DEFAULT 'single_elimination' AFTER game_icon",
        "ALTER TABLE tournament_participants ADD COLUMN IF NOT EXISTS club_id INT UNSIGNED NULL AFTER team_id",
    ];
    foreach ($colQueries as $sql) {
        try { $db->exec($sql); } catch (Throwable $e) {}
    }

    // Ensure default player records for existing users.
    // One-time backfill: every active account gets a player card, otherwise the
    // market/auction/hire flows have nothing to sell. New accounts are covered
    // lazily by ensurePlayerProfile().
    try {
        $seeded = false;
        $stmt = $db->query("SELECT `value` FROM site_settings WHERE `key` = 'player_market_seeded'");
        if ($stmt && $stmt->fetch()) $seeded = true;

        if (!$seeded) {
            $db->exec("INSERT IGNORE INTO players (user_id, status) SELECT id, 'free_agent' FROM users WHERE status = 'active'");
            // A player with an owner is never on the free-agent list.
            $db->exec("UPDATE players SET status = 'active' WHERE owner_id IS NOT NULL AND status = 'free_agent'");
            $db->exec("INSERT IGNORE INTO site_settings (`key`, `value`) VALUES ('player_market_seeded', '1')");
        }
    } catch (Throwable $e) {}
}

function ensureProductSchema(PDO $db): void {
    $queries = [
        "CREATE TABLE IF NOT EXISTS products (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            description TEXT NULL,
            short_desc VARCHAR(255) DEFAULT NULL,
            price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            image VARCHAR(255) DEFAULT NULL,
            file_path VARCHAR(255) DEFAULT NULL,
            file_type VARCHAR(20) DEFAULT NULL,
            badge VARCHAR(50) DEFAULT NULL,
            badge_color VARCHAR(20) DEFAULT NULL,
            rating DECIMAL(2,1) DEFAULT 0.0,
            sales INT UNSIGNED DEFAULT 0,
            pages INT UNSIGNED DEFAULT 0,
            category VARCHAR(80) DEFAULT NULL,
            stock INT NOT NULL DEFAULT 0,
            status ENUM('active','inactive','pending') NOT NULL DEFAULT 'active',
            author_id INT UNSIGNED DEFAULT NULL,
            preview_text TEXT DEFAULT NULL,
            content_long TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE SET NULL,
            INDEX idx_product_category (category),
            INDEX idx_product_status (status),
            INDEX idx_product_author (author_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS product_purchases (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            product_id INT UNSIGNED NOT NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            status ENUM('completed','refunded') NOT NULL DEFAULT 'completed',
            author_earnings DECIMAL(10,2) DEFAULT 0.00,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
            UNIQUE KEY uniq_user_product (user_id, product_id),
            INDEX idx_purchase_user (user_id),
            INDEX idx_purchase_product (product_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];

    foreach ($queries as $sql) {
        try { $db->exec($sql); } catch (PDOException $e) { error_log("Product Schema: " . $e->getMessage()); }
    }

    $colQueries = [
        "ALTER TABLE products ADD COLUMN IF NOT EXISTS author_id INT UNSIGNED DEFAULT NULL AFTER status",
        "ALTER TABLE products ADD COLUMN IF NOT EXISTS file_path VARCHAR(255) DEFAULT NULL AFTER author_id",
        "ALTER TABLE products ADD COLUMN IF NOT EXISTS file_type VARCHAR(20) DEFAULT NULL AFTER file_path",
        "ALTER TABLE products ADD COLUMN IF NOT EXISTS preview_text TEXT DEFAULT NULL AFTER file_type",
        "ALTER TABLE products ADD COLUMN IF NOT EXISTS content_long TEXT DEFAULT NULL AFTER preview_text",
        "ALTER TABLE products ADD COLUMN IF NOT EXISTS short_desc VARCHAR(255) DEFAULT NULL AFTER description",
        "ALTER TABLE products ADD COLUMN IF NOT EXISTS badge VARCHAR(50) DEFAULT NULL AFTER image",
        "ALTER TABLE products ADD COLUMN IF NOT EXISTS badge_color VARCHAR(20) DEFAULT NULL AFTER badge",
        "ALTER TABLE products ADD COLUMN IF NOT EXISTS rating DECIMAL(2,1) DEFAULT 0.0 AFTER badge_color",
        "ALTER TABLE products ADD COLUMN IF NOT EXISTS sales INT UNSIGNED DEFAULT 0 AFTER rating",
        "ALTER TABLE products ADD COLUMN IF NOT EXISTS pages INT UNSIGNED DEFAULT 0 AFTER sales",
        "ALTER TABLE products MODIFY status ENUM('active','inactive','pending') NOT NULL DEFAULT 'active'",
    ];
    foreach ($colQueries as $sql) {
        try { $db->exec($sql); } catch (Throwable $e) {}
    }
}

function ensureTopupSchema(PDO $db): void {
    $queries = [
        "CREATE TABLE IF NOT EXISTS game_topup_games (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(80) NOT NULL UNIQUE,
            name VARCHAR(120) NOT NULL,
            icon VARCHAR(60) DEFAULT 'fa-gamepad',
            logo VARCHAR(255) DEFAULT NULL,
            gradient VARCHAR(255) DEFAULT '',
            shadow_color VARCHAR(50) DEFAULT '',
            description VARCHAR(255) DEFAULT '',
            sort_order INT DEFAULT 0,
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_topup_game_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS game_topup_packages (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            game_id INT UNSIGNED NOT NULL,
            name VARCHAR(120) NOT NULL,
            price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            badge VARCHAR(50) DEFAULT '',
            badge_color VARCHAR(20) DEFAULT '',
            sort_order INT DEFAULT 0,
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_pkg_game (game_id),
            INDEX idx_pkg_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS topup_orders (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            game_id INT UNSIGNED NULL,
            package_id INT UNSIGNED NULL,
            game_name VARCHAR(120) DEFAULT '',
            package_name VARCHAR(120) DEFAULT '',
            amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            player_uid VARCHAR(120) NOT NULL DEFAULT '',
            player_zone VARCHAR(60) DEFAULT '',
            contact VARCHAR(120) DEFAULT '',
            status ENUM('pending','processing','completed','cancelled') NOT NULL DEFAULT 'pending',
            note TEXT DEFAULT NULL,
            handled_by INT UNSIGNED DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_topup_user (user_id),
            INDEX idx_topup_status (status),
            INDEX idx_topup_package (package_id),
            INDEX idx_topup_game (game_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];

    foreach ($queries as $sql) {
        try { $db->exec($sql); } catch (Throwable $e) {}
    }

    // Products table gets free-preview support columns; games get a logo column
    $colQueries = [
        "ALTER TABLE products ADD COLUMN IF NOT EXISTS preview_file VARCHAR(255) DEFAULT NULL AFTER preview_text",
        "ALTER TABLE products ADD COLUMN IF NOT EXISTS preview_pages INT UNSIGNED DEFAULT 0 AFTER preview_file",
        "ALTER TABLE game_topup_games ADD COLUMN IF NOT EXISTS logo VARCHAR(255) DEFAULT NULL AFTER icon",
    ];
    foreach ($colQueries as $sql) {
        try { $db->exec($sql); } catch (Throwable $e) {}
    }

    // Seed default games/packages only when the games table is empty
    try {
        $count = (int) $db->query("SELECT COUNT(*) FROM game_topup_games")->fetchColumn();
        if ($count === 0) {
            $defaultGames = [
                ['slug' => 'freefire', 'name' => 'Free Fire', 'icon' => 'fa-crosshairs', 'gradient' => 'linear-gradient(135deg, #ff6b35, #f7931e)', 'shadow' => 'rgba(255,107,53,0.3)', 'description' => 'Diamond top-up. Instant delivery.',
                 'packages' => [['name' => '100 Diamonds', 'price' => 120], ['name' => '310 Diamonds', 'price' => 350, 'badge' => 'Popular'], ['name' => '520 Diamonds', 'price' => 590, 'badge' => 'Best Value'], ['name' => '1060 Diamonds', 'price' => 1180], ['name' => '2180 Diamonds', 'price' => 2350, 'badge' => 'Premium']]],
                ['slug' => 'pubg', 'name' => 'PUBG Mobile', 'icon' => 'fa-gun', 'gradient' => 'linear-gradient(135deg, #e53935, #b71c1c)', 'shadow' => 'rgba(229,57,53,0.3)', 'description' => 'UC top-up. Fast & secure.',
                 'packages' => [['name' => '60 UC', 'price' => 150], ['name' => '180 UC', 'price' => 420], ['name' => '325 UC', 'price' => 750, 'badge' => 'Popular'], ['name' => '660 UC', 'price' => 1480, 'badge' => 'Best Value'], ['name' => '1800 UC', 'price' => 3950, 'badge' => 'Premium']]],
                ['slug' => 'mlbb', 'name' => 'Mobile Legends', 'icon' => 'fa-chess-king', 'gradient' => 'linear-gradient(135deg, #7b1fa2, #4a148c)', 'shadow' => 'rgba(123,31,162,0.3)', 'description' => 'Diamonds for MLBB.',
                 'packages' => [['name' => '86 Diamonds', 'price' => 110], ['name' => '172 Diamonds', 'price' => 220], ['name' => '350 Diamonds', 'price' => 430, 'badge' => 'Popular'], ['name' => '706 Diamonds', 'price' => 860, 'badge' => 'Best Value'], ['name' => '2010 Diamonds', 'price' => 2400, 'badge' => 'Premium']]],
                ['slug' => 'codm', 'name' => 'COD Mobile', 'icon' => 'fa-skull', 'gradient' => 'linear-gradient(135deg, #1e88e5, #0d47a1)', 'shadow' => 'rgba(30,136,229,0.3)', 'description' => 'CP top-up for COD.',
                 'packages' => [['name' => '80 CP', 'price' => 130], ['name' => '420 CP', 'price' => 650, 'badge' => 'Popular'], ['name' => '880 CP', 'price' => 1320], ['name' => '2400 CP', 'price' => 3500, 'badge' => 'Best Value']]],
                ['slug' => 'valorant', 'name' => 'Valorant', 'icon' => 'fa-bolt', 'gradient' => 'linear-gradient(135deg, #ff4655, #bd2935)', 'shadow' => 'rgba(255,70,85,0.3)', 'description' => 'VP top-up.',
                 'packages' => [['name' => '475 VP', 'price' => 650], ['name' => '1000 VP', 'price' => 1280, 'badge' => 'Popular'], ['name' => '2050 VP', 'price' => 2550, 'badge' => 'Best Value']]],
                ['slug' => 'coc', 'name' => 'Clash of Clans', 'icon' => 'fa-shield-halved', 'gradient' => 'linear-gradient(135deg, #e53935, #ff6f00)', 'shadow' => 'rgba(229,57,53,0.3)', 'description' => 'Gems for Clash.',
                 'packages' => [['name' => '500 Gems', 'price' => 180], ['name' => '1200 Gems', 'price' => 420, 'badge' => 'Popular'], ['name' => '2500 Gems', 'price' => 850], ['name' => '6500 Gems', 'price' => 2100, 'badge' => 'Best Value'], ['name' => '14000 Gems', 'price' => 4200, 'badge' => 'Premium']]],
            ];

            foreach ($defaultGames as $gi => $game) {
                $stmt = $db->prepare("INSERT INTO game_topup_games (slug, name, icon, gradient, shadow_color, description, sort_order, status) VALUES (?,?,?,?,?,?,?,'active')");
                $stmt->execute([$game['slug'], $game['name'], $game['icon'], $game['gradient'], $game['shadow'], $game['description'], $gi]);
                $gameId = (int) $db->lastInsertId();
                $stmt = $db->prepare("INSERT INTO game_topup_packages (game_id, name, price, badge, badge_color, sort_order, status) VALUES (?,?,?,?,?,?,'active')");
                foreach ($game['packages'] as $pi => $pkg) {
                    $badge = $pkg['badge'] ?? '';
                    $badgeColor = $badge ? ($badge === 'Popular' ? '#3b82f6' : ($badge === 'Best Value' ? '#10b981' : '#ef4444')) : '';
                    $stmt->execute([$gameId, $pkg['name'], $pkg['price'], $badge, $badgeColor, $pi]);
                }
            }
        }
    } catch (Throwable $e) {}
}

// Composer autoload
$composerAutoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
}

// Global connection testing and auto-schema
try {
    $db = Database::getInstance()->getConnection();
    
    // Robust Schema Check (only run if site_settings doesn't exist or flag is missing)
    $runSchema = false;
    try {
        $stmt = $db->query("SELECT value FROM site_settings WHERE `key` = 'schema_initialized'");
        if (!$stmt->fetch()) $runSchema = true;
    } catch (PDOException $e) {
        $runSchema = true;
    }

    if ($runSchema) {
        $queries = [
            // Core Tables
            "CREATE TABLE IF NOT EXISTS site_settings (`key` VARCHAR(80) PRIMARY KEY, `value` TEXT, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS users (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, username VARCHAR(50) UNIQUE NOT NULL, email VARCHAR(120) UNIQUE NOT NULL, password_hash VARCHAR(255) NOT NULL, full_name VARCHAR(120), role VARCHAR(30) DEFAULT 'user', balance DECIMAL(12,2) DEFAULT 0.00, bronze_coins INT UNSIGNED DEFAULT 0, silver_coins INT UNSIGNED DEFAULT 0, gold_coins INT UNSIGNED DEFAULT 0, avatar VARCHAR(255) DEFAULT 'default.png', status VARCHAR(30) DEFAULT 'active', registered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS user_sessions (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, session_token VARCHAR(128) NOT NULL, payload LONGTEXT, user_agent TEXT, ip_address VARCHAR(45), last_activity INT UNSIGNED NOT NULL, expires_at DATETIME NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uniq_token (session_token), INDEX idx_user (user_id), INDEX idx_activity (last_activity)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS posts (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, content TEXT NOT NULL, image_path VARCHAR(255), privacy ENUM('public','friends','private') DEFAULT 'public', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_user (user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS post_comments (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, post_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL, comment_text TEXT NOT NULL, parent_comment_id INT UNSIGNED DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_post (post_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS post_likes (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, post_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL, reaction_type VARCHAR(20) DEFAULT 'like', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uniq_like (post_id, user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS notifications (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, actor_id INT UNSIGNED, type VARCHAR(30) NOT NULL, entity_id INT UNSIGNED, message VARCHAR(255), is_read TINYINT(1) DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_user (user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS messages (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, sender_id INT UNSIGNED NOT NULL, receiver_id INT UNSIGNED NOT NULL, body TEXT NOT NULL, is_read TINYINT(1) DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_thread (sender_id, receiver_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS slider_content (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255) NOT NULL, badge VARCHAR(100) DEFAULT 'New', description TEXT NOT NULL, status ENUM('active', 'inactive') DEFAULT 'active', sort_order INT DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS tournaments (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, title VARCHAR(150) NOT NULL, description TEXT, status VARCHAR(40) DEFAULT 'upcoming', entry_fee DECIMAL(10,2) DEFAULT 0.00, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS tournament_participants (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tournament_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL, status ENUM('registered','confirmed','cancelled') DEFAULT 'registered', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_tournament (tournament_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS p2p_trades (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, seller_id INT UNSIGNED NOT NULL, buyer_id INT UNSIGNED NOT NULL, quantity INT UNSIGNED NOT NULL, status ENUM('pending','paid','completed','cancelled') DEFAULT 'pending', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "INSERT IGNORE INTO site_settings (`key`, `value`) VALUES ('schema_initialized', '1')",
            "INSERT IGNORE INTO site_settings (`key`, `value`) VALUES ('slider_enabled', '1')"
        ];

        foreach ($queries as $sql) {
            try { $db->exec($sql); } catch (PDOException $e) { error_log("Initial Schema: " . $e->getMessage()); }
        }

    }

    // Always-run column migrations for existing databases
    $alwaysRun = [
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS uuid CHAR(36) AFTER id",
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS phone VARCHAR(30) AFTER full_name",
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS bio TEXT AFTER phone",
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS location VARCHAR(150) AFTER bio",
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS website VARCHAR(255) AFTER location",
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS cover_image VARCHAR(255) DEFAULT 'default.jpg' AFTER avatar",
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS preferences LONGTEXT AFTER website",
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS login_attempts INT DEFAULT 0 AFTER role",
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS locked_until DATETIME AFTER login_attempts",
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS last_login DATETIME AFTER status",
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS last_ip VARCHAR(45) AFTER last_login",
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS email_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER status",
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS email_verification_token VARCHAR(64) DEFAULT NULL AFTER email_verified",
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS email_verification_expires DATETIME DEFAULT NULL AFTER email_verification_token",
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS reset_token VARCHAR(64) DEFAULT NULL AFTER email_verification_expires",
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS reset_token_expires DATETIME DEFAULT NULL AFTER reset_token",
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS agent_verified_at DATETIME AFTER balance",
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS coins INT UNSIGNED DEFAULT 0 AFTER agent_verified_at",
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS nickname VARCHAR(50) AFTER coins",
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS skill_level VARCHAR(30) AFTER nickname",
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS favorite_game VARCHAR(80) AFTER skill_level",
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS discord VARCHAR(60) AFTER favorite_game",
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS facebook VARCHAR(255) AFTER discord",
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS instagram VARCHAR(255) AFTER facebook",
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS youtube VARCHAR(255) AFTER instagram",
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS gold_coins INT UNSIGNED DEFAULT 0 AFTER youtube",
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS silver_coins INT UNSIGNED DEFAULT 0 AFTER gold_coins",
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS bronze_coins INT UNSIGNED DEFAULT 0 AFTER silver_coins",
        "ALTER TABLE slider_content ADD COLUMN IF NOT EXISTS slider_type ENUM('features','tournament','leaderboard','ads') DEFAULT 'features' AFTER badge",
        "CREATE TABLE IF NOT EXISTS payment_requests (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, method ENUM('bkash','nagad','rocket','other') NOT NULL DEFAULT 'bkash', sender_phone VARCHAR(20) NOT NULL, transaction_id VARCHAR(100) NOT NULL, amount DECIMAL(12,2) NOT NULL, status ENUM('pending','completed','cancelled') DEFAULT 'pending', purpose VARCHAR(30) DEFAULT 'add_money', admin_id INT UNSIGNED DEFAULT NULL, admin_note TEXT DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uniq_txid (transaction_id), KEY idx_user (user_id), KEY idx_status (status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "ALTER TABLE payment_requests ADD COLUMN IF NOT EXISTS purpose VARCHAR(30) DEFAULT 'add_money' AFTER status",
        "ALTER TABLE payment_requests ADD COLUMN IF NOT EXISTS admin_id INT UNSIGNED DEFAULT NULL AFTER purpose",
        "ALTER TABLE payment_requests ADD COLUMN IF NOT EXISTS admin_note TEXT DEFAULT NULL AFTER admin_id",
    ];
    foreach ($alwaysRun as $sql) {
        try { $db->exec($sql); } catch (Throwable $e) {}
    }

    ensureP2PSchema($db);
    ensureUserSessionsSchema($db);
    ensureTournamentFeatureSchema($db);
    ensureClubFeatureSchema($db);
    ensureProductSchema($db);
    ensureTopupSchema($db);
} catch (Exception $e) {
    error_log("Config Error: " . $e->getMessage());
}
