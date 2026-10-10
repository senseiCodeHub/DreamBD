<?php
// Database helper functions - Clean version

require_once __DIR__ . '/tournament_flow.php';
require_once __DIR__ . '/tournament_bracket.php';

function getProducts(PDO $pdo, $limit = null) {
    $sql = "SELECT * FROM products WHERE status = 'active'";
    if ($limit) {
        $sql .= " LIMIT ?";
    }

    $stmt = $pdo->prepare($sql);
    if ($limit) {
        $stmt->execute([$limit]);
    } else {
        $stmt->execute();
    }

    return $stmt->fetchAll();
}

function ensureSocialTables(PDO $pdo) {
    // Create social tables if they don't exist
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS friendships (
            id INT AUTO_INCREMENT PRIMARY KEY,
            requester_id INT NOT NULL,
            addressee_id INT NOT NULL,
            status ENUM('pending', 'accepted', 'rejected') DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_requester (requester_id),
            INDEX idx_addressee (addressee_id)
        )
    ");
    
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS posts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            content TEXT,
            image_path VARCHAR(255) DEFAULT NULL,
            feeling VARCHAR(100) DEFAULT NULL,
            privacy ENUM('public', 'friends', 'private') DEFAULT 'public',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user (user_id)
        )
    ");
    try { $pdo->exec("ALTER TABLE posts ADD COLUMN feeling VARCHAR(100) DEFAULT NULL"); } catch (Exception $e) {}
    
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS post_likes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            post_id INT NOT NULL,
            user_id INT NOT NULL,
            reaction_type VARCHAR(20) DEFAULT 'like',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY idx_post_user (post_id, user_id)
        )
    ");
    try { $pdo->exec("ALTER TABLE post_likes ADD COLUMN reaction_type VARCHAR(20) DEFAULT 'like'"); } catch (Exception $e) {}
    
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS post_comments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            post_id INT NOT NULL,
            user_id INT NOT NULL,
            comment_text TEXT,
            parent_comment_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_post (post_id)
        )
    ");
    
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS post_shares (
            id INT AUTO_INCREMENT PRIMARY KEY,
            post_id INT NOT NULL,
            user_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_post (post_id)
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS comment_reactions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            comment_id INT NOT NULL,
            user_id INT NOT NULL,
            reaction_type VARCHAR(20) DEFAULT 'like',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY idx_comment_user (comment_id, user_id)
        )
    ");
    try { $pdo->exec("ALTER TABLE comment_reactions ADD COLUMN reaction_type VARCHAR(20) DEFAULT 'like'"); } catch (Exception $e) {}
    
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sender_id INT NOT NULL,
            receiver_id INT NOT NULL,
            body TEXT,
            is_read TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_sender (sender_id),
            INDEX idx_receiver (receiver_id)
        )
    ");
    try { $pdo->exec("ALTER TABLE messages CHANGE message_text body TEXT DEFAULT NULL"); } catch (Exception $e) {}
    
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            type VARCHAR(50),
            message TEXT,
            is_read TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user (user_id)
        )
    ");
    
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_sessions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            session_token VARCHAR(128) NOT NULL,
            payload LONGTEXT,
            user_agent TEXT,
            ip_address VARCHAR(45),
            last_activity INT UNSIGNED NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_token (session_token),
            INDEX idx_user (user_id),
            INDEX idx_activity (last_activity)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    ensureUserSessionsSchema($pdo);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS rate_limits (
            id INT AUTO_INCREMENT PRIMARY KEY,
            type VARCHAR(32) NOT NULL,
            identifier VARCHAR(64) NOT NULL,
            attempts INT DEFAULT 1,
            expires_at TIMESTAMP NULL DEFAULT NULL,
            INDEX idx_type_identifier (type, identifier)
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS security_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT DEFAULT NULL,
            event_type VARCHAR(64) NOT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            user_agent TEXT DEFAULT NULL,
            details TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user (user_id),
            INDEX idx_event (event_type)
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS login_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT DEFAULT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            user_agent TEXT DEFAULT NULL,
            location VARCHAR(128) DEFAULT NULL,
            success TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user (user_id)
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS report_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            reporter_id INT NOT NULL,
            message_id INT NOT NULL,
            reason VARCHAR(255) DEFAULT NULL,
            status ENUM('open','resolved','dismissed') DEFAULT 'open',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_reporter (reporter_id),
            INDEX idx_message (message_id)
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS report_comments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            reporter_id INT NOT NULL,
            comment_id INT NOT NULL,
            reason VARCHAR(255) DEFAULT NULL,
            status ENUM('open','resolved','dismissed') DEFAULT 'open',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_reporter (reporter_id),
            INDEX idx_comment (comment_id)
        )
    ");

    // Ensure users table has registered_at column
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN registered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
    } catch (Exception $e) {}

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pinned_conversations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            other_user_id INT NOT NULL,
            pinned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_user_other (user_id, other_user_id),
            INDEX idx_user (user_id)
        )
    ");

    // Add missing notification columns
    try {
        $pdo->exec("ALTER TABLE notifications ADD COLUMN actor_id INT DEFAULT NULL");
    } catch (PDOException $e) {}
    try {
        $pdo->exec("ALTER TABLE notifications ADD COLUMN entity_id INT DEFAULT NULL");
    } catch (PDOException $e) {}

    // Dismissed suggestions
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS dismissed_suggestions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            dismissed_user_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_user_dismissed (user_id, dismissed_user_id),
            INDEX idx_user (user_id)
        )
    ");

    // Saved posts
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS saved_posts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            post_id INT NOT NULL,
            user_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY idx_save (post_id, user_id)
        )
    ");
    // Post reports
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS post_reports (
            id INT AUTO_INCREMENT PRIMARY KEY,
            post_id INT NOT NULL,
            user_id INT NOT NULL,
            reason VARCHAR(100) DEFAULT NULL,
            status ENUM('pending','resolved','dismissed') DEFAULT 'pending',
            admin_note TEXT DEFAULT NULL,
            resolved_by INT DEFAULT NULL,
            resolved_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_status (status)
        )
    ");
    // Migrate existing tables that may lack newer columns
    try { $pdo->exec("ALTER TABLE post_reports ADD COLUMN status ENUM('pending','resolved','dismissed') DEFAULT 'pending' AFTER reason"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE post_reports ADD COLUMN admin_note TEXT DEFAULT NULL AFTER status"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE post_reports ADD COLUMN resolved_by INT DEFAULT NULL AFTER admin_note"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE post_reports ADD COLUMN resolved_at TIMESTAMP NULL DEFAULT NULL AFTER resolved_by"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE post_reports ADD INDEX idx_status (status)"); } catch (PDOException $e) {}
    ensureTournamentFeatureSchema($pdo);
}

function getFriendshipStatus(PDO $pdo, int $viewerId, int $otherUserId): ?string {
    $stmt = $pdo->prepare("
        SELECT status
        FROM friendships
        WHERE (requester_id = ? AND addressee_id = ?)
           OR (requester_id = ? AND addressee_id = ?)
        LIMIT 1
    ");
    $stmt->execute([$viewerId, $otherUserId, $otherUserId, $viewerId]);
    $friendship = $stmt->fetch();
    
    if (!$friendship) {
        return null;
    }
    
    return $friendship['status'];
}

function getProfileStats(PDO $pdo, int $userId) {
    $stats = [
        'posts' => 0,
        'friends' => 0,
        'photos' => 0
    ];
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE user_id = ?");
    $stmt->execute([$userId]);
    $stats['posts'] = (int) $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM friendships
        WHERE status = 'accepted'
          AND (requester_id = ? OR addressee_id = ?)
    ");
    $stmt->execute([$userId, $userId]);
    $stats['friends'] = (int) $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE user_id = ? AND image_path IS NOT NULL AND image_path != ''");
    $stmt->execute([$userId]);
    $stats['photos'] = (int) $stmt->fetchColumn();
    
    return $stats;
}

function getProfilePosts(PDO $pdo, int $profileUserId, int $viewerId, int $limit = 10) {
    $limit = max(1, $limit);
    $isFriend = getFriendshipStatus($pdo, $viewerId, $profileUserId) === 'friends';
    $isOwner = $viewerId === $profileUserId;
    
    $privacyCondition = $isOwner
        ? "1 = 1"
        : ($isFriend
            ? "(p.privacy IN ('public', 'friends'))"
            : "p.privacy = 'public'");
    
    $sql = "
        SELECT
            p.*,
            u.username,
            u.full_name,
            u.avatar,
            COUNT(DISTINCT pl.id) AS like_count,
            COUNT(DISTINCT pc.id) AS comment_count,
            COUNT(DISTINCT ps.id) AS share_count,
            MAX(CASE WHEN pl.user_id = ? THEN pl.reaction_type ELSE NULL END) AS viewer_reaction
        FROM posts p
        INNER JOIN users u ON u.id = p.user_id
        LEFT JOIN post_likes pl ON pl.post_id = p.id
        LEFT JOIN post_comments pc ON pc.post_id = p.id
        LEFT JOIN post_shares ps ON ps.post_id = p.id
        WHERE p.user_id = ?
          AND {$privacyCondition}
        GROUP BY p.id
        ORDER BY p.created_at DESC
        LIMIT ?
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$viewerId, $profileUserId, $limit]);
    $posts = $stmt->fetchAll();
    
    foreach ($posts as &$post) {
        hydratePostSocial($pdo, $post, $viewerId, 5);
    }
    
    return $posts;
}

function hydratePostSocial(PDO $pdo, array &$post, int $viewerId, int $commentLimit): void {
    $post['comments'] = getPostComments($pdo, (int) $post['id'], $commentLimit, $viewerId, (int) $post['user_id']);
    $post['viewer_reaction'] = $post['viewer_reaction'] ?: null;
    $post['liked_by_viewer'] = !empty($post['viewer_reaction']);
    $raw = getReactionSummary($pdo, (int) $post['id']);
    $summary = [];
    foreach ($raw as $type => $count) {
        $summary[] = ['type' => $type, 'count' => $count, 'meta' => getReactionMeta($type)];
    }
    $post['reaction_summary'] = $summary;
    $post['can_delete'] = ((int) $post['user_id'] === $viewerId);
}

function getFriendsList(PDO $pdo, int $userId, int $limit = 12) {
    $limit = max(1, $limit);
    $sql = "
        SELECT u.id, u.username, u.full_name, u.avatar, u.bio
        FROM friendships f
        INNER JOIN users u
            ON u.id = CASE
                WHEN f.requester_id = ? THEN f.addressee_id
                ELSE f.requester_id
            END
        WHERE (f.requester_id = ? OR f.addressee_id = ?)
          AND f.status = 'accepted'
        ORDER BY COALESCE(f.accepted_at, f.updated_at, f.created_at) DESC
        LIMIT ?
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId, $userId, $userId, $limit]);
    return $stmt->fetchAll();
}

function getFriendRequests(PDO $pdo, int $userId, int $limit = 10) {
    $limit = max(1, $limit);
    $sql = "
        SELECT f.id, f.created_at, u.id AS user_id, u.username, u.full_name, u.avatar, u.bio
        FROM friendships f
        INNER JOIN users u ON u.id = f.requester_id
        WHERE f.addressee_id = ?
          AND f.status = 'pending'
        ORDER BY f.created_at DESC
        LIMIT ?
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId, $limit]);
    return $stmt->fetchAll();
}

function getSentFriendRequests(PDO $pdo, int $userId, int $limit = 10) {
    $limit = max(1, $limit);
    $sql = "
        SELECT f.id, f.created_at, u.id AS user_id, u.username, u.full_name, u.avatar, u.bio
        FROM friendships f
        INNER JOIN users u ON u.id = f.addressee_id
        WHERE f.requester_id = ?
          AND f.status = 'pending'
        ORDER BY f.created_at DESC
        LIMIT ?
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId, $limit]);
    return $stmt->fetchAll();
}

function getSuggestedFriends(PDO $pdo, int $userId, int $limit = 6) {
    $limit = max(1, $limit);
    
    // First try mutual friends approach
    $sql = "
        SELECT
            u.id,
            u.username,
            u.full_name,
            u.avatar,
            u.bio,
            u.location,
            CASE
                WHEN viewer.location IS NOT NULL
                 AND viewer.location <> ''
                 AND u.location = viewer.location THEN 1
                ELSE 0
            END AS same_location,
            (
                SELECT COUNT(DISTINCT viewer_friends.friend_id)
                FROM (
                    SELECT CASE
                         WHEN f1.requester_id = ? THEN f1.addressee_id
                         ELSE f1.requester_id
                     END AS friend_id
                    FROM friendships f1
                    WHERE (f1.requester_id = ? OR f1.addressee_id = ?)
                      AND f1.status = 'accepted'
                ) viewer_friends
                INNER JOIN friendships f2
                    ON f2.status = 'accepted'
                    AND (
                        (f2.requester_id = viewer_friends.friend_id AND f2.addressee_id = u.id)
                     OR (f2.addressee_id = viewer_friends.friend_id AND f2.requester_id = u.id)
                    )
            ) AS mutual_count
        FROM users u
        LEFT JOIN users viewer ON viewer.id = ?
        WHERE u.id != ?
          AND NOT EXISTS (
              SELECT 1
              FROM friendships f
              WHERE (
                    (f.requester_id = ? AND f.addressee_id = u.id)
                 OR (f.requester_id = u.id AND f.addressee_id = ?)
              )
          )
          AND NOT EXISTS (
              SELECT 1 FROM dismissed_suggestions ds
              WHERE ds.user_id = ? AND ds.dismissed_user_id = u.id
          )
        ORDER BY
            mutual_count DESC,
            same_location DESC,
            CASE WHEN COALESCE(u.avatar, '') != '' AND COALESCE(u.avatar, '') != 'default.png' THEN 1 ELSE 0 END DESC,
            COALESCE(NULLIF(u.full_name, ''), u.username) ASC
        LIMIT ?
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId, $userId, $userId, $userId, $userId, $userId, $userId, $userId, $limit]);
    $suggestions = $stmt->fetchAll();
    
    foreach ($suggestions as &$suggestion) {
        $mutualCount = (int) ($suggestion['mutual_count'] ?? 0);
        $sameLocation = !empty($suggestion['same_location']);
        
        if ($mutualCount > 0) {
            $suggestion['suggestion_reason'] = $mutualCount . ' mutual friend' . ($mutualCount === 1 ? '' : 's');
        } elseif ($sameLocation && !empty($suggestion['location'])) {
            $suggestion['suggestion_reason'] = 'Lives in ' . $suggestion['location'];
        } else {
            $suggestion['suggestion_reason'] = 'People you may know';
        }
    }
    unset($suggestion);
    
    if (!$suggestions) {
        // Fallback: just get random users
        $fallbackStmt = $pdo->prepare("
            SELECT
                u.id,
                u.username,
                u.full_name,
                u.avatar,
                u.bio,
                u.location,
                0 AS same_location,
                0 AS mutual_count,
                'People you may know' AS suggestion_reason
            FROM users u
            WHERE u.id != ?
              AND NOT EXISTS (
                    SELECT 1
                    FROM friendships f
                    WHERE f.status = 'accepted'
                      AND (
                            (f.requester_id = ? AND f.addressee_id = u.id)
                         OR (f.requester_id = u.id AND f.addressee_id = ?)
                       )
              )
              AND NOT EXISTS (
                    SELECT 1 FROM dismissed_suggestions ds
                    WHERE ds.user_id = ? AND ds.dismissed_user_id = u.id
              )
            ORDER BY
                CASE WHEN COALESCE(u.avatar, '') != '' AND COALESCE(u.avatar, '') != 'default.png' THEN 1 ELSE 0 END DESC,
                COALESCE(NULLIF(u.full_name, ''), u.username) ASC
            LIMIT ?
        ");
        $fallbackStmt->execute([$userId, $userId, $userId, $userId, $limit]);
        $suggestions = $fallbackStmt->fetchAll() ?: [];
    }
    
    return $suggestions;
}

function getPostComments(PDO $pdo, int $postId, int $limit = 10, int $viewerId = 0, int $postOwnerId = 0, int $offset = 0) {
    $limit = max(1, $limit);
    $offset = max(0, $offset);
    
    // 1. Fetch Root Comments (latest first)
    $stmt = $pdo->prepare("
        SELECT pc.*, u.username, u.full_name, u.avatar
        FROM post_comments pc
        INNER JOIN users u ON u.id = pc.user_id
        WHERE pc.post_id = ? AND (pc.parent_comment_id IS NULL OR pc.parent_comment_id = 0)
        ORDER BY pc.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$postId, $limit, $offset]);
    $rootComments = $stmt->fetchAll() ?: [];
    
    if (empty($rootComments)) return [];

    // Reverse root comments to show chronological order at the bottom
    $rootComments = array_reverse($rootComments);
    $rootIds = array_column($rootComments, 'id');
    
    // 2. Fetch ALL replies for this post (we'll filter in PHP to keep it simple and robust)
    // For very large posts, we might need a more targeted approach, but for now this is best for tree integrity.
    $stmt = $pdo->prepare("
        SELECT pc.*, u.username, u.full_name, u.avatar
        FROM post_comments pc
        INNER JOIN users u ON u.id = pc.user_id
        WHERE pc.post_id = ? AND pc.parent_comment_id > 0
        ORDER BY pc.created_at ASC
    ");
    $stmt->execute([$postId]);
    $allReplies = $stmt->fetchAll() ?: [];

    // 3. Build a map of ALL comments (roots + replies) for enrichment
    $allComments = array_merge($rootComments, $allReplies);
    $commentMap = [];
    foreach ($allComments as &$c) {
        $c['can_delete'] = ((int) $c['user_id'] === $viewerId || $viewerId === $postOwnerId);
        $c['viewer_reaction'] = null;
        if ($viewerId > 0) {
            $stmt2 = $pdo->prepare("SELECT reaction_type FROM comment_reactions WHERE comment_id = ? AND user_id = ?");
            $stmt2->execute([$c['id'], $viewerId]);
            $cr = $stmt2->fetch();
            $c['viewer_reaction'] = $cr ? $cr['reaction_type'] : null;
        }
        $c['reaction_summary'] = getCommentReactionSummary($pdo, (int) $c['id']);
        $c['reaction_count'] = array_sum($c['reaction_summary']);
        $c['created_at_formatted'] = formatTimeAgo($c['created_at'] ?? '');
        $c['replies'] = [];
        $commentMap[$c['id']] = &$c;
    }
    unset($c);

    // 4. Organize into tree
    $tree = [];
    foreach ($rootComments as $rc) {
        $tree[] = &$commentMap[$rc['id']];
    }

    foreach ($allReplies as $reply) {
        $pid = (int) $reply['parent_comment_id'];
        if (isset($commentMap[$pid])) {
            $commentMap[$pid]['replies'][] = &$commentMap[$reply['id']];
        }
    }

    return $tree;
}

function getReactionSummary(PDO $pdo, int $postId): array {
    $stmt = $pdo->prepare("
        SELECT reaction_type, COUNT(*) as count
        FROM post_likes
        WHERE post_id = ?
        GROUP BY reaction_type
    ");
    $stmt->execute([$postId]);
    $reactions = $stmt->fetchAll();
    
    $summary = [];
    foreach ($reactions as $reaction) {
        $summary[$reaction['reaction_type']] = (int) $reaction['count'];
    }
    
    return $summary;
}

function getCommentReactionSummary(PDO $pdo, int $commentId): array {
    $stmt = $pdo->prepare("
        SELECT reaction_type, COUNT(*) as count
        FROM comment_reactions
        WHERE comment_id = ?
        GROUP BY reaction_type
    ");
    $stmt->execute([$commentId]);
    $reactions = $stmt->fetchAll();
    
    $summary = [];
    foreach ($reactions as $reaction) {
        $summary[$reaction['reaction_type']] = (int) $reaction['count'];
    }
    
    return $summary;
}

function getPostDetails(PDO $pdo, int $postId, int $viewerId): ?array {
    $stmt = $pdo->prepare("
        SELECT
            p.id, p.user_id, p.content, p.image_path, p.privacy, p.created_at, p.updated_at,
            u.username,
            u.full_name,
            u.avatar,
            (SELECT COUNT(DISTINCT pl2.id) FROM post_likes pl2 WHERE pl2.post_id = p.id) AS like_count,
            (SELECT COUNT(DISTINCT pc2.id) FROM post_comments pc2 WHERE pc2.post_id = p.id) AS comment_count,
            (SELECT COUNT(DISTINCT ps2.id) FROM post_shares ps2 WHERE ps2.post_id = p.id) AS share_count,
            (SELECT pl3.reaction_type FROM post_likes pl3 WHERE pl3.post_id = p.id AND pl3.user_id = ? LIMIT 1) AS viewer_reaction
        FROM posts p
        INNER JOIN users u ON u.id = p.user_id
        WHERE p.id = ?
        LIMIT 1
    ");
    $stmt->execute([$viewerId, $postId]);
    $post = $stmt->fetch();
    
    if ($post) {
        hydratePostSocial($pdo, $post, $viewerId, 10);
        $post['created_at_formatted'] = formatTimeAgo($post['created_at']);
    }
    
    return $post ?: null;
}

function getVisibleFeedPosts(PDO $pdo, ?int $viewerId = null, int $limit = 10, string $mode = 'home'): array {
    $limit = max(1, $limit);
    $viewerId = $viewerId ?: 0;

    // Community feed: include the viewer's own posts and order deterministically
    // (newest first within friend/public groups) instead of random shuffle.
    $includeOwn = ($mode === 'community');
    $friendGate = "
            ? > 0
            AND EXISTS (
                SELECT 1
                FROM friendships f
                WHERE f.status = 'accepted'
                  AND (
                        (f.requester_id = ? AND f.addressee_id = p.user_id)
                     OR (f.addressee_id = ? AND f.requester_id = p.user_id)
                    )
                    AND p.privacy = 'friends'
            )";
    $visibility = $includeOwn
        ? "( p.user_id = ? OR p.privacy = 'public' OR ( {$friendGate} ) )"
        : "p.user_id != ? AND ( p.privacy = 'public' OR ( {$friendGate} ) )";
    $tailOrder = $includeOwn ? "p.created_at DESC" : "RAND()";

    $sql = "
        SELECT
            p.*,
            u.username,
            u.full_name,
            u.avatar,
            COUNT(DISTINCT pl.id) AS like_count,
            COUNT(DISTINCT pc.id) AS comment_count,
            COUNT(DISTINCT ps.id) AS share_count,
            MAX(CASE WHEN pl.user_id = ? THEN pl.reaction_type ELSE NULL END) AS viewer_reaction
        FROM posts p
        INNER JOIN users u ON u.id = p.user_id
        LEFT JOIN post_likes pl ON pl.post_id = p.id
        LEFT JOIN post_comments pc ON pc.post_id = p.id
        LEFT JOIN post_shares ps ON ps.post_id = p.id
        WHERE {$visibility}
        GROUP BY p.id
        ORDER BY
          CASE
            WHEN EXISTS (
                SELECT 1
                FROM friendships f2
                WHERE f2.status = 'accepted'
                  AND (
                        (f2.requester_id = ? AND f2.addressee_id = p.user_id)
                     OR (f2.addressee_id = ? AND f2.requester_id = p.user_id)
                    )
            ) THEN 0
            WHEN p.privacy = 'public' THEN 1
            ELSE 2
          END,
          {$tailOrder}
        LIMIT ?
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(1, $viewerId, PDO::PARAM_INT);
    $stmt->bindValue(2, $viewerId, PDO::PARAM_INT);
    $stmt->bindValue(3, $viewerId, PDO::PARAM_INT);
    $stmt->bindValue(4, $viewerId, PDO::PARAM_INT);
    $stmt->bindValue(5, $viewerId, PDO::PARAM_INT);
    $stmt->bindValue(6, $viewerId, PDO::PARAM_INT);
    $stmt->bindValue(7, $viewerId, PDO::PARAM_INT);
    $stmt->bindValue(8, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $posts = $stmt->fetchAll();
    
    foreach ($posts as &$post) {
        hydratePostSocial($pdo, $post, $viewerId, 3);
    }
    
    return $posts;
}

function getTopReachPosts(PDO $pdo, ?int $viewerId = null, int $limit = 5): array {
    $limit = max(1, min($limit, 20));
    $viewerId = $viewerId ?: 0;
    
    $sql = "
        SELECT
            p.*,
            u.username,
            u.full_name,
            u.avatar,
            COUNT(DISTINCT pl.id) AS like_count,
            COUNT(DISTINCT pc.id) AS comment_count,
            COUNT(DISTINCT ps.id) AS share_count,
            MAX(CASE WHEN pl.user_id = ? THEN pl.reaction_type ELSE NULL END) AS viewer_reaction
        FROM posts p
        INNER JOIN users u ON u.id = p.user_id
        LEFT JOIN post_likes pl ON pl.post_id = p.id
        LEFT JOIN post_comments pc ON pc.post_id = p.id
        LEFT JOIN post_shares ps ON ps.post_id = p.id
        WHERE p.privacy = 'public'
          AND p.user_id != ?
        GROUP BY p.id
        ORDER BY (COUNT(DISTINCT pl.id) + COUNT(DISTINCT pc.id) * 2) DESC, RAND()
        LIMIT ?
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(1, $viewerId, PDO::PARAM_INT);
    $stmt->bindValue(2, $viewerId, PDO::PARAM_INT);
    $stmt->bindValue(3, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $posts = $stmt->fetchAll();
    
    foreach ($posts as &$post) {
        hydratePostSocial($pdo, $post, $viewerId, 3);
    }
    
    return $posts;
}

function getHomePeopleSuggestions(PDO $pdo, ?int $viewerId = null, int $limit = 6): array {
    $limit = max(1, $limit);
    
    if ($viewerId) {
        $suggestions = getSuggestedFriends($pdo, (int) $viewerId, $limit);
        if ($suggestions) {
            return $suggestions;
        }
    }
    
    // Fallback: get random active users
    $stmt = $pdo->prepare("
        SELECT u.id, u.username, u.full_name, u.avatar, u.bio, u.location,
                 0 AS same_location, 0 AS mutual_count,
                 'Active in DreamBD' AS suggestion_reason
        FROM users u
        WHERE u.id != ?
          AND NOT EXISTS (
              SELECT 1 FROM dismissed_suggestions ds
              WHERE ds.user_id = ? AND ds.dismissed_user_id = u.id
          )
        ORDER BY u.id DESC
        LIMIT ?
    ");
    $stmt->bindValue(1, $viewerId ?: 0, PDO::PARAM_INT);
    $stmt->bindValue(2, $viewerId ?: 0, PDO::PARAM_INT);
    $stmt->bindValue(3, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll() ?: [];
}

function getHomeSearchResults(PDO $pdo, ?int $viewerId, string $query, int $userLimit = 5, int $postLimit = 4): array {
    $query = trim($query);
    if ($query === '') {
        return ['users' => [], 'posts' => []];
    }
    
    $like = "%{$query}%";
    $prefixLike = $query . '%';
    
    $userStmt = $pdo->prepare("
        SELECT u.id, u.username, u.full_name, u.avatar, u.bio, u.location,
               CASE WHEN u.full_name LIKE ? THEN 1 ELSE 0 END AS name_exact,
               CASE WHEN u.username LIKE ? THEN 1 ELSE 0 END AS username_exact
        FROM users u
        WHERE u.username LIKE ? OR u.full_name LIKE ? OR u.bio LIKE ?
        ORDER BY name_exact DESC, username_exact DESC,
                 CASE WHEN COALESCE(u.avatar, '') != '' AND COALESCE(u.avatar, '') != 'default.png' THEN 1 ELSE 0 END DESC,
                 u.full_name ASC
        LIMIT ?
    ");
    $userStmt->execute([$like, $like, $like, $like, $prefixLike, $userLimit]);
    $users = $userStmt->fetchAll() ?: [];

    $postStmt = $pdo->prepare("
        SELECT p.*, u.username, u.full_name, u.avatar
        FROM posts p
        INNER JOIN users u ON u.id = p.user_id
        WHERE p.content LIKE ?
          AND p.privacy = 'public'
        ORDER BY p.created_at DESC
        LIMIT ?
    ");
    $postStmt->execute(["%{$query}%", $postLimit]);
    $posts = $postStmt->fetchAll() ?: [];
    
    foreach ($posts as &$post) {
        $post['content_excerpt'] = substr(strip_tags($post['content']), 0, 150) . '...';
    }
    
    return ['users' => $users, 'posts' => $posts, 'counts' => ['all' => count($users) + count($posts), 'people' => count($users), 'posts' => count($posts)]];
}

function getSearchResults(PDO $pdo, ?int $viewerId, string $query, string $tab = 'all', int $userLimit = 12, int $postLimit = 12): array {
    $results = getHomeSearchResults($pdo, $viewerId, $query, $userLimit, $postLimit);
    $tab = in_array($tab, ['all', 'people', 'posts'], true) ? $tab : 'all';
    
    if ($tab === 'people') {
        $results['posts'] = [];
    } elseif ($tab === 'posts') {
        $results['users'] = [];
    }
    
    return $results;
}

function getMessageThreads(PDO $pdo, int $userId): array {
    $stmt = $pdo->prepare("
        SELECT
            CASE
                WHEN m.sender_id = ? THEN m.receiver_id
                ELSE m.sender_id
            END AS other_user_id,
            u.username, u.full_name, u.avatar,
            MAX(m.created_at) AS last_message_at,
            SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(m.body, '') ORDER BY m.created_at DESC), ',', 1) AS last_message,
            COUNT(CASE WHEN m.is_read = 0 AND m.receiver_id = ? THEN 1 END) AS unread_count,
            EXISTS(SELECT 1 FROM user_sessions us WHERE us.user_id = u.id AND us.expires_at > NOW() AND us.last_activity >= (UNIX_TIMESTAMP() - 300)) AS is_online,
            EXISTS(SELECT 1 FROM pinned_conversations pc2 WHERE pc2.user_id = ? AND pc2.other_user_id = u.id) AS is_pinned
        FROM messages m
        INNER JOIN users u ON u.id = CASE
                WHEN m.sender_id = ? THEN m.receiver_id
                ELSE m.sender_id
            END
        WHERE m.sender_id = ? OR m.receiver_id = ?
        GROUP BY other_user_id
        ORDER BY is_pinned DESC, last_message_at DESC
    ");
    $stmt->execute([$userId, $userId, $userId, $userId, $userId, $userId]);
    return $stmt->fetchAll();
}

function getMessageContacts(PDO $pdo, int $userId, int $limit = 12): array {
    $limit = max(1, $limit);
    $stmt = $pdo->prepare("
        SELECT
            u.id,
            u.username,
            u.full_name,
            u.avatar,
            u.bio,
            EXISTS(SELECT 1 FROM user_sessions us WHERE us.user_id = u.id AND us.expires_at > NOW() AND us.last_activity >= (UNIX_TIMESTAMP() - 300)) AS is_online,
            (
                SELECT MAX(m.created_at)
                FROM messages m
                WHERE (m.sender_id = ? AND m.receiver_id = u.id)
                   OR (m.receiver_id = ? AND m.sender_id = u.id)
            ) AS last_contact_at
        FROM users u
        WHERE u.id IN (
            SELECT CASE
                     WHEN f.requester_id = ? THEN f.addressee_id
                     ELSE f.requester_id
                 END
            FROM friendships f
            WHERE (f.requester_id = ? OR f.addressee_id = ?)
              AND f.status = 'accepted'
        )
        ORDER BY last_contact_at DESC
        LIMIT ?
    ");
    $stmt->bindValue(1, $userId, PDO::PARAM_INT);
    $stmt->bindValue(2, $userId, PDO::PARAM_INT);
    $stmt->bindValue(3, $userId, PDO::PARAM_INT);
    $stmt->bindValue(4, $userId, PDO::PARAM_INT);
    $stmt->bindValue(5, $userId, PDO::PARAM_INT);
    $stmt->bindValue(6, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function getConversationMessages(PDO $pdo, int $userId, int $otherUserId, int $limit = 100, ?int $beforeMessageId = null, ?int $afterMessageId = null): array {
    $limit = max(1, $limit);
    $extraClause = '';
    $params = [$userId, $userId, $otherUserId, $otherUserId, $userId];

    if ($beforeMessageId && $beforeMessageId > 0) {
        $extraClause = ' AND m.id < ?';
        $params[] = $beforeMessageId;
    } elseif ($afterMessageId && $afterMessageId > 0) {
        $extraClause = ' AND m.id > ?';
        $params[] = $afterMessageId;
    }

    $params[] = $limit;

    $stmt = $pdo->prepare("
        SELECT m.*, u.username, u.full_name, u.avatar,
               r.body AS reply_body, r.image_path AS reply_image_path,
               ru.full_name AS reply_full_name, ru.username AS reply_username,
               EXISTS(SELECT 1 FROM message_pins mp WHERE mp.message_id = m.id AND mp.user_id = ?) AS is_pinned,
               (SELECT reaction_type FROM message_reactions WHERE message_id = m.id AND user_id = ?) AS viewer_reaction
        FROM messages m
        INNER JOIN users u ON u.id = m.sender_id
        LEFT JOIN messages r ON r.id = m.reply_to_message_id
        LEFT JOIN users ru ON ru.id = r.sender_id
        WHERE (
                (m.sender_id = ? AND m.receiver_id = ?)
             OR (m.sender_id = ? AND m.receiver_id = ?)
          )
          {$extraClause}
        ORDER BY m.id DESC
        LIMIT ?
    ");
    array_splice($params, 1, 0, $userId);

    $stmt->execute($params);
    $messages = array_reverse($stmt->fetchAll());    
    $messageIds = array_column($messages, 'id');
    $reactionsByMsg = [];
    if (!empty($messageIds)) {
        $ids = array_map('intval', $messageIds);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        try {
            $stmtR = $pdo->prepare("SELECT message_id, reaction_type, COUNT(*) AS cnt FROM message_reactions WHERE message_id IN ($placeholders) GROUP BY message_id, reaction_type");
            $stmtR->execute($ids);
            while ($row = $stmtR->fetch()) {
                $reactionsByMsg[(int) $row['message_id']][] = ['reaction_type' => $row['reaction_type'], 'count' => (int) $row['cnt']];
            }
        } catch (Throwable $e) {}
    }
    
    foreach ($messages as &$msg) {
        $msg['is_pinned'] = (bool) ($msg['is_pinned'] ?? false);
        $msg['is_read'] = (bool) ($msg['is_read'] ?? false);
        $msg['reaction_summary'] = $reactionsByMsg[(int) $msg['id']] ?? [];
    }
    unset($msg);
    
    try {
        $stmt2 = $pdo->prepare("UPDATE messages SET is_read = 1 WHERE sender_id = ? AND receiver_id = ? AND is_read = 0");
        $stmt2->execute([$otherUserId, $userId]);
    } catch (Throwable $e) {}
    
    return $messages;
}

function getAllFriends(PDO $pdo, int $userId, int $limit = 200): array {
    $limit = max(1, $limit);
    $stmt = $pdo->prepare("
        SELECT u.id, u.username, u.full_name, u.avatar, u.bio,
               EXISTS(SELECT 1 FROM user_sessions us WHERE us.user_id = u.id AND us.expires_at > NOW() AND us.last_activity >= (UNIX_TIMESTAMP() - 300)) AS is_online,
               (SELECT MAX(m.created_at) FROM messages m WHERE (m.sender_id = ? AND m.receiver_id = u.id) OR (m.receiver_id = ? AND m.sender_id = u.id)) AS last_contact_at
        FROM friendships f
        INNER JOIN users u ON u.id = CASE WHEN f.requester_id = ? THEN f.addressee_id ELSE f.requester_id END
        WHERE (f.requester_id = ? OR f.addressee_id = ?) AND f.status = 'accepted'
        ORDER BY u.full_name ASC
        LIMIT ?
    ");
    $stmt->execute([$userId, $userId, $userId, $userId, $userId, $limit]);
    return $stmt->fetchAll();
}

function searchUsers(PDO $pdo, int $userId, string $query, int $limit = 20): array {
    $limit = max(1, $limit);
    $q = '%' . $query . '%';
    $stmt = $pdo->prepare("
        SELECT u.id, u.username, u.full_name, u.avatar,
               CASE WHEN f.id IS NOT NULL THEN 1 ELSE 0 END AS is_friend,
               EXISTS(SELECT 1 FROM user_sessions us WHERE us.user_id = u.id AND us.expires_at > NOW() AND us.last_activity >= (UNIX_TIMESTAMP() - 300)) AS is_online
        FROM users u
        LEFT JOIN friendships f ON (f.requester_id = ? AND f.addressee_id = u.id OR f.requester_id = u.id AND f.addressee_id = ?) AND f.status = 'accepted'
        WHERE u.id != ? AND (u.full_name LIKE ? OR u.username LIKE ?)
        ORDER BY is_friend DESC, u.full_name ASC
        LIMIT ?
    ");
    $stmt->execute([$userId, $userId, $userId, $q, $q, $limit]);
    return $stmt->fetchAll();
}

function getNotificationsList(PDO $pdo, int $userId, int $limit = 50, string $filter = 'all', ?int $beforeId = null): array {
    $limit = max(1, $limit);
    $filterClause = '';
    $params = [$userId];
    
    if ($filter === 'read') {
        $filterClause .= " AND n.is_read = 1";
    } elseif ($filter === 'unread') {
        $filterClause .= " AND n.is_read = 0";
    }
    
    if ($beforeId && $beforeId > 0) {
        $filterClause .= " AND n.id < ?";
        $params[] = $beforeId;
    }
    
    $params[] = $limit;
    
    $stmt = $pdo->prepare("
        SELECT n.*, u.username, u.full_name, u.avatar
        FROM notifications n
        LEFT JOIN users u ON u.id = n.actor_id
        WHERE n.user_id = ?
          {$filterClause}
        ORDER BY n.created_at DESC
        LIMIT ?
    ");
    
    $stmt->execute($params);
    $notifications = $stmt->fetchAll();
    
    // Resolve post_id from comment_id for comment-type notifications
    $commentTypes = ['comment', 'reply', 'mention', 'comment_reaction'];
    foreach ($notifications as &$notification) {
        if (in_array($notification['type'], $commentTypes, true) && !empty($notification['entity_id'])) {
            $cStmt = $pdo->prepare("SELECT post_id FROM post_comments WHERE id = ?");
            $cStmt->execute([(int)$notification['entity_id']]);
            $resolvedPostId = (int)$cStmt->fetchColumn();
            if ($resolvedPostId) {
                $notification['resolved_post_id'] = $resolvedPostId;
            }
        }
    }
    unset($notification);
    
    foreach ($notifications as &$notification) {
        hydrateNotification($notification);
    }
    
    return $notifications;
}

function hydrateNotification(array &$notification): void {
    $notification['time_ago'] = formatTimeAgo($notification['created_at']);
    $notification['created_at_exact'] = date('M j, Y g:i A', strtotime((string) $notification['created_at']));
    
    // Actor info
    $notification['actor_name'] = $notification['full_name'] ?: $notification['username'] ?? 'DreamBD';
    $notification['avatar'] = $notification['avatar'] ?? 'default.png';
    
    // Meta based on type
    $metaMap = [
        'friend_request' => ['icon' => 'user-plus', 'label' => 'Friend Request', 'accent' => 'is-friend', 'color' => '#10b981'],
        'friend_accept' => ['icon' => 'user-check', 'label' => 'Accepted', 'accent' => 'is-friend', 'color' => '#10b981'],
        'like' => ['icon' => 'thumbs-up', 'label' => 'Like', 'accent' => 'is-reaction', 'color' => '#1877f2'],
        'reaction' => ['icon' => 'thumbs-up', 'label' => 'Reaction', 'accent' => 'is-reaction', 'color' => '#1877f2'],
        'comment' => ['icon' => 'comment', 'label' => 'Comment', 'accent' => 'is-comment', 'color' => '#0ea5e9'],
        'share' => ['icon' => 'share', 'label' => 'Share', 'accent' => 'is-system', 'color' => '#64748b'],
        'tournament' => ['icon' => 'trophy', 'label' => 'Tournament', 'accent' => 'is-system', 'color' => '#f59e0b'],
        'message' => ['icon' => 'envelope', 'label' => 'Message', 'accent' => 'is-system', 'color' => '#8b5cf6'],
        'payment_completed' => ['icon' => 'check-circle', 'label' => 'Payment', 'accent' => 'is-system', 'color' => '#059669'],
        'payment_pending' => ['icon' => 'clock', 'label' => 'Pending', 'accent' => 'is-system', 'color' => '#d97706'],
        'payment_cancelled' => ['icon' => 'ban', 'label' => 'Cancelled', 'accent' => 'is-cancelled', 'color' => '#dc2626'],
        'agent_activation' => ['icon' => 'crown', 'label' => 'Agent', 'accent' => 'is-system', 'color' => '#f59e0b'],
        'coin_conversion' => ['icon' => 'arrows-rotate', 'label' => 'Conversion', 'accent' => 'is-system', 'color' => '#8b5cf6'],
        'p2p_order_placed' => ['icon' => 'cart-shopping', 'label' => 'P2P Order', 'accent' => 'is-system', 'color' => '#3b82f6'],
        'p2p_payment_received' => ['icon' => 'money-bill-wave', 'label' => 'P2P Payment', 'accent' => 'is-system', 'color' => '#059669'],
        'p2p_trade_completed' => ['icon' => 'handshake', 'label' => 'P2P Trade', 'accent' => 'is-system', 'color' => '#10b981'],
        'report_resolved' => ['icon' => 'shield-alt', 'label' => 'Report Review', 'accent' => 'is-system', 'color' => '#8b5cf6'],
        'report_received' => ['icon' => 'flag', 'label' => 'Report Received', 'accent' => 'is-system', 'color' => '#f59e0b'],
        'tournament_result' => ['icon' => 'medal', 'label' => 'Results', 'accent' => 'is-system', 'color' => '#10b981'],
        'refund' => ['icon' => 'rotate-left', 'label' => 'Refund', 'accent' => 'is-system', 'color' => '#059669'],
        'transfer' => ['icon' => 'right-left', 'label' => 'Transfer', 'accent' => 'is-system', 'color' => '#0ea5e9'],
        'auction' => ['icon' => 'gavel', 'label' => 'Auction', 'accent' => 'is-system', 'color' => '#f59e0b'],
    ];
    $type = $notification['type'] ?? 'system';
    $notification['meta'] = $metaMap[$type] ?? ['icon' => 'bell', 'label' => 'Update', 'accent' => 'is-system', 'color' => '#64748b'];
    
    // Target URL
    $commentTypes = ['comment', 'reply', 'mention', 'comment_reaction'];
    $isCommentType = in_array($type, $commentTypes, true);
    if ($isCommentType) {
        $postId = $notification['resolved_post_id'] ?? $notification['entity_id'] ?? 0;
        $commentId = (int) ($notification['entity_id'] ?? 0);
        $notification['target_url'] = 'index.php?page=community&post=' . $postId;
        if ($commentId) {
            $notification['target_url'] .= '&comment=' . $commentId;
        }
    } elseif ($type === 'like' || $type === 'reaction') {
        $notification['target_url'] = 'index.php?page=community&post=' . ($notification['entity_id'] ?? 0);
    } elseif ($type === 'friend_request') {
        $notification['target_url'] = 'index.php?page=profile#friends';
    } elseif ($type === 'friend_accept') {
        $notification['target_url'] = 'index.php?page=profile&user=' . ($notification['actor_id'] ?? 0);
    } elseif ($type === 'message') {
        $notification['target_url'] = 'index.php?page=messages&user=' . ($notification['actor_id'] ?? 0);
    } elseif ($type === 'report_resolved') {
        $postId = $notification['entity_id'] ?? 0;
        $reportMsg = rawurlencode(mb_substr($notification['message'] ?? '', 0, 300));
        $notification['target_url'] = 'index.php?page=community&post=' . $postId;
        $notification['target_url'] .= '&report_msg=' . $reportMsg;
    } elseif ($type === 'tournament' || $type === 'tournament_result') {
        $tid = (int) ($notification['entity_id'] ?? 0);
        $notification['target_url'] = $tid > 0 ? 'index.php?page=tournament&id=' . $tid : 'index.php?page=tournaments';
        if ($type === 'tournament_result') $notification['target_url'] .= '#standings';
    } elseif ($type === 'refund') {
        $notification['target_url'] = 'index.php?page=balance';
    } else {
        $urlMap = [
            'share' => 'index.php?page=community',
            'tournament' => 'index.php?page=tournaments',
            'payment_completed' => 'index.php?page=balance',
            'payment_pending' => 'index.php?page=balance',
            'payment_cancelled' => 'index.php?page=balance',
            'agent_activation' => 'index.php?page=tournaments',
            'coin_conversion' => 'index.php?page=balance',
            'p2p_order_placed' => 'index.php?page=p2p',
            'p2p_payment_received' => 'index.php?page=p2p',
            'p2p_trade_completed' => 'index.php?page=p2p',
            'transfer' => 'index.php?page=tournaments#hire',
            'auction' => 'index.php?page=tournaments#hire',
        ];
        $notification['target_url'] = $urlMap[$type] ?? 'index.php?page=notifications';
    }
    
    // Format message text
    if ($type === 'message' && !empty($notification['message'])) {
        $notification['message'] = preg_replace('/^[^:]+:\s*/', '', $notification['message']);
    }
}

function createNotification(PDO $db, int $userId, ?int $actorId, string $type, string $message, ?int $entityId = null): void {
    try {
        $stmt = $db->prepare("INSERT INTO notifications (user_id, actor_id, type, message, entity_id) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$userId, $actorId, $type, $message, $entityId]);
    } catch (Throwable $e) {
        error_log("Notification error: " . $e->getMessage());
    }
}

function getNotificationCounts(PDO $pdo, int $userId): array {
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) AS unread,
            SUM(CASE WHEN is_read = 1 THEN 1 ELSE 0 END) AS read_count
        FROM notifications
        WHERE user_id = ?
    ");
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return [
        'all' => (int) ($row['total'] ?? 0),
        'unread' => (int) ($row['unread'] ?? 0),
        'read' => (int) ($row['read_count'] ?? 0)
    ];
}

function formatTimeAgo($datetime) {
    $time = abs(time() - strtotime((string) $datetime));
    
    if ($time < 60) return 'just now';
    if ($time < 3600) return floor($time / 60) . ' minutes ago';
    if ($time < 86400) return floor($time / 3600) . ' hours ago';
    if ($time < 604800) return floor($time / 86400) . ' days ago';
    return date('M j, Y', strtotime((string) $datetime));
}

function getHeaderSocialCounts(PDO $pdo, ?int $userId): array {
    if (!$userId) {
        return ['messages' => 0, 'notifications' => 0];
    }
    
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0
    ");
    $stmt->execute([$userId]);
    $notifications = (int) $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0
    ");
    $stmt->execute([$userId]);
    $messages = (int) $stmt->fetchColumn();
    
    return ['messages' => $messages, 'notifications' => $notifications];
}


function getCommunityOverview(PDO $pdo, ?int $viewerId = null): array {
    $overview = [
        'members' => 0,
        'online_users' => 0,
        'posts' => 0,
        'public_posts' => 0,
        'friends_posts' => 0
    ];

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users");
    $stmt->execute();
    $overview['members'] = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT user_id) FROM user_sessions WHERE expires_at > NOW()");
    $stmt->execute();
    $overview['online_users'] = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM posts");
    $stmt->execute();
    $overview['posts'] = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE privacy = 'public'");
    $stmt->execute();
    $overview['public_posts'] = (int) $stmt->fetchColumn();

    if ($viewerId) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM posts p
            WHERE p.privacy = 'public'
               OR p.user_id = ?
               OR EXISTS (
                    SELECT 1
                    FROM friendships f
                    WHERE f.status = 'accepted'
                      AND (
                            (f.requester_id = ? AND f.addressee_id = p.user_id)
                         OR (f.addressee_id = ? AND f.requester_id = p.user_id)
                        )
                      AND p.privacy = 'friends'
                )
        ");
        $stmt->execute([$viewerId, $viewerId, $viewerId]);
        $overview['friends_posts'] = (int) $stmt->fetchColumn();
    }

    return $overview;
}

function getOnlineUsersList(PDO $pdo, ?int $excludeUserId = null, int $limit = 8): array {
    $limit = max(1, min(50, $limit));
    $excludeUserId = (int) ($excludeUserId ?? 0);
    $stmt = $pdo->prepare("
        SELECT u.id, u.username, u.full_name, u.avatar
        FROM users u
        WHERE u.id != ?
          AND EXISTS (
              SELECT 1 FROM user_sessions us
              WHERE us.user_id = u.id
                AND us.expires_at > NOW()
                AND us.last_activity >= (UNIX_TIMESTAMP() - 300)
          )
        ORDER BY u.full_name ASC
        LIMIT ?
    ");
    $stmt->bindValue(1, $excludeUserId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function getNavbarSearchResults(PDO $pdo, ?int $viewerId, string $query, int $userLimit = 4, int $postLimit = 3): array {
    $results = getHomeSearchResults($pdo, $viewerId, $query, $userLimit, $postLimit);
    return [
        'users' => $results['users'] ?? [],
        'posts' => $results['posts'] ?? [],
        'counts' => $results['counts'] ?? ['all' => 0, 'people' => 0, 'posts' => 0],
    ];
}

function getHomeTopPlayers(PDO $pdo, int $limit = 3): array {
    $limit = max(1, $limit);

    try {
        $stmt = $pdo->prepare("
            SELECT
                u.id,
                u.username,
                u.full_name,
                u.avatar,
                COUNT(DISTINCT p.id) AS post_count,
                COUNT(DISTINCT pl.id) AS reaction_count,
                COUNT(DISTINCT pc.id) AS comment_count,
                (
                    (COUNT(DISTINCT p.id) * 5) +
                    (COUNT(DISTINCT pl.id) * 3) +
                    (COUNT(DISTINCT pc.id) * 2)
                ) AS leaderboard_score
            FROM users u
            LEFT JOIN posts p ON p.user_id = u.id
            LEFT JOIN post_likes pl ON pl.post_id = p.id
            LEFT JOIN post_comments pc ON pc.post_id = p.id
            GROUP BY u.id
            ORDER BY leaderboard_score DESC, reaction_count DESC, post_count DESC, u.id ASC
            LIMIT ?
        ");
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $players = $stmt->fetchAll() ?: [];

        foreach ($players as $index => &$player) {
            $player['rank'] = $index + 1;
            $player['display_name'] = $player['full_name'] ?: $player['username'];
            $player['score_label'] = number_format((int) ($player['leaderboard_score'] ?? 0)) . ' pts';
        }
        unset($player);

        return $players;
    } catch (Throwable $e) {
        return [];
    }
}

function getFeaturedTournamentSummary(PDO $pdo): array {
    $summary = [
        'title' => 'Dream Cup',
        'status' => 'Upcoming event',
        'starts_at' => null,
        'display_time' => 'Schedule from admin',
    ];

    try {
        $stmt = $pdo->query("
            SELECT title, status, starts_at
            FROM tournaments
            ORDER BY
                CASE WHEN starts_at IS NULL THEN 1 ELSE 0 END,
                starts_at ASC,
                id DESC
            LIMIT 1
        ");
        $tournament = $stmt->fetch();
        if ($tournament) {
            $summary['title'] = $tournament['title'] ?: $summary['title'];
            $summary['status'] = ucfirst((string) ($tournament['status'] ?? 'Upcoming'));
            $summary['starts_at'] = $tournament['starts_at'] ?? null;
            $summary['display_time'] = !empty($tournament['starts_at'])
                ? date('M j, Y g:i A', strtotime((string) $tournament['starts_at']))
                : 'Schedule from admin';
        }
    } catch (Throwable $e) {
        // Leave fallback data.
    }

    return $summary;
}

function getEmoji(string $reaction): string {
    $meta = getReactionMeta($reaction);
    return $meta['emoji'] ?? '👍';
}

function getReactionMeta(string $reaction): array {
    $map = [
        'like' => ['label' => 'Like', 'icon' => 'thumbs-up', 'emoji' => '👍', 'class' => 'reaction-like'],
        'love' => ['label' => 'Love', 'icon' => 'heart', 'emoji' => '❤️', 'class' => 'reaction-love'],
        'care' => ['label' => 'Care', 'icon' => 'face-smile', 'emoji' => '🥰', 'class' => 'reaction-care'],
        'haha' => ['label' => 'Haha', 'icon' => 'face-laugh-squint', 'emoji' => '😆', 'class' => 'reaction-haha'],
        'wow' => ['label' => 'Wow', 'icon' => 'face-surprise', 'emoji' => '😮', 'class' => 'reaction-wow'],
        'sad' => ['label' => 'Sad', 'icon' => 'face-sad-tear', 'emoji' => '😢', 'class' => 'reaction-sad'],
        'angry' => ['label' => 'Angry', 'icon' => 'face-angry', 'emoji' => '😡', 'class' => 'reaction-angry']
    ];

    return $map[$reaction] ?? $map['like'];
}

function renderReactionButtonInner(?string $reaction): string {
    if (!$reaction) {
        return '<i class="fas fa-thumbs-up"></i> Like';
    }

    $meta = getReactionMeta($reaction);
    return '<i class="fas fa-' . htmlspecialchars($meta['icon']) . '"></i> ' . htmlspecialchars($meta['label']);
}

function renderPresenceDot($isOnline, string $extraClass = ''): string {
    $classes = trim('presence-dot' . ($isOnline ? ' is-online' : '') . ($extraClass ? ' ' . $extraClass : ''));
    return '<span class="' . htmlspecialchars($classes) . '" aria-hidden="true"></span>';
}

function formatPresenceStatus($lastActiveAt, $isOnline): string {
    if ($isOnline) {
        return 'Active now';
    }

    if (empty($lastActiveAt)) {
        return 'Offline';
    }

    return 'Active ' . formatTimeAgo($lastActiveAt);
}

function renderReactionSummaryHtml(array $summary, int $totalCount): string {
    $topReactions = array_slice($summary, 0, 2);
    $icons = '';
    $emojiMap = ['like'=>'👍', 'love'=>'❤️', 'care'=>'🥰', 'haha'=>'😆', 'wow'=>'😮', 'sad'=>'😢', 'angry'=>'😡'];

    foreach ($topReactions as $reaction) {
        $type = $reaction['type'] ?? 'like';
        $meta = $reaction['meta'] ?? getReactionMeta($type);
        $emoji = $emojiMap[$type] ?? '👍';
        $icons .= '<span class="reaction-chip ' . htmlspecialchars($meta['class']) . '" title="' . htmlspecialchars($meta['label']) . '">' . $emoji . '</span>';
    }

    if ($icons === '' && $totalCount > 0) {
        $icons = '<span class="reaction-chip reaction-like" title="Like">👍</span>';
    }

    return '<span class="reaction-stack">' . $icons . '</span>' . ($totalCount > 0 ? '<span class="like-count">' . (int) $totalCount . '</span>' : '');
}

function renderCommentReactionButtonInner(?string $reaction): string {
    return renderReactionButtonInner($reaction);
}

function renderCommentReactionSummaryHtml(array $summary, int $totalCount): string {
    return renderReactionSummaryHtml($summary, $totalCount);
}

function renderPostCommentItem(array $comment, bool $isReply = false): string {
    $authorName = htmlspecialchars($comment['full_name'] ?: $comment['username'] ?: 'User');
    $avatar = htmlspecialchars($comment['avatar'] ?? 'default.png');
    $text = nl2br(htmlspecialchars($comment['comment_text'] ?? ''));
    $time = htmlspecialchars($comment['created_at_formatted'] ?? formatTimeAgo($comment['created_at'] ?? ''));
    $timeRaw = htmlspecialchars((string) ($comment['created_at'] ?? ''));
    $timeExact = !empty($comment['created_at']) ? htmlspecialchars(date('M j, Y g:i A', strtotime((string) $comment['created_at']))) : '';
    $reactionSummary = renderCommentReactionSummaryHtml($comment['reaction_summary'] ?? [], (int) ($comment['reaction_count'] ?? 0));
    $reactionButton = renderCommentReactionButtonInner($comment['viewer_reaction'] ?? null);
    $replyButtonLabel = $isReply ? 'Reply again' : 'Reply';
    $menuItems = '';
    if (!empty($comment['can_delete'])) {
        $menuItems .= '<button class="comment-menu-item edit-comment-btn" type="button" data-comment-id="' . (int) $comment['id'] . '"><i class="fas fa-pen"></i> Edit</button>';
        $menuItems .= '<button class="comment-menu-item delete-comment-btn" type="button" data-comment-id="' . (int) $comment['id'] . '"><i class="fas fa-trash"></i> Delete</button>';
    }
    $menuItems .= '<button class="comment-menu-item report-comment-btn" type="button" data-comment-id="' . (int) $comment['id'] . '"><i class="fas fa-flag"></i> Report</button>';

    $html = '
        <div class="social-comment-card' . ($isReply ? ' is-reply' : '') . '" data-comment-id="' . (int) $comment['id'] . '">
            <img src="assets/avatars/' . $avatar . '" alt="" class="social-comment-avatar" onerror="this.src=\'assets/avatars/default.png\'">
            <div class="social-comment-main">
                <div class="social-comment-bubble">
                    <div class="social-comment-topline">
                        <strong>' . $authorName . '</strong>
                        <div class="comment-menu-wrap">
                            <button class="comment-menu-toggle comment-ghost-btn" type="button" data-comment-id="' . (int) $comment['id'] . '" aria-label="Comment options"><i class="fas fa-ellipsis-h"></i></button>
                            <div class="comment-menu-dropdown">' . $menuItems . '</div>
                        </div>
                    </div>
                    <p>' . $text . '</p>
                    <div class="social-comment-reactions is-attached" data-comment-reaction-summary>' . $reactionSummary . '</div>
                </div>
                <div class="social-comment-meta">
                    <span class="social-comment-time js-social-relative-time" data-time="' . $timeRaw . '" title="' . $timeExact . '">' . $time . '</span>
                    <button class="social-comment-action social-comment-react-btn" type="button" data-comment-id="' . (int) $comment['id'] . '" data-reaction="' . htmlspecialchars($comment['viewer_reaction'] ?? '') . '">' . $reactionButton . '</button>
                    <button class="social-comment-action social-comment-reply-btn" type="button" data-comment-id="' . (int) $comment['id'] . '" data-comment-author="' . $authorName . '" data-comment-preview="' . htmlspecialchars(trim((string) ($comment['comment_text'] ?? ''))) . '">' . $replyButtonLabel . '</button>
                </div>
                ';

    if (!empty($comment['replies'])) {
        $html .= '<div class="social-comment-replies">';
        foreach ($comment['replies'] as $reply) {
            $html .= renderPostCommentItem($reply, true);
        }
        $html .= '</div>';
    }

    $html .= '
            </div>
        </div>
    ';

    return $html;
}

// ─── TOURNAMENT HELPERS ───────────────────────────────────────

function getTournamentsWithCounts(PDO $pdo, ?string $statusFilter = null, int $limit = 50): array {
    $sql = "
        SELECT t.*,
               COUNT(tp.id) AS registered_teams
        FROM tournaments t
        LEFT JOIN tournament_participants tp ON tp.tournament_id = t.id AND tp.status = 'confirmed'
    ";
    $params = [];
    if ($statusFilter && $statusFilter !== 'all') {
        $sql .= " WHERE t.status = ?";
        $params[] = $statusFilter;
    }
    $sql .= " GROUP BY t.id ORDER BY COALESCE(t.starts_at, t.created_at) DESC";
    if ($limit > 0) {
        $sql .= " LIMIT ?";
        $params[] = $limit;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getTournamentParticipants(PDO $pdo, int $tournamentId): array {
    $stmt = $pdo->prepare("
        SELECT tp.*, u.full_name, u.username, u.avatar, tm.role AS membership_role
        FROM tournament_participants tp
        LEFT JOIN users u ON u.id = tp.user_id
        LEFT JOIN team_members tm ON tm.team_id = tp.team_id AND tm.user_id = tp.user_id
        WHERE tp.tournament_id = ? AND tp.status = 'confirmed'
        ORDER BY tp.created_at ASC
    ");
    $stmt->execute([$tournamentId]);
    return $stmt->fetchAll();
}

function getTournamentByIdWithCounts(PDO $pdo, int $tournamentId): ?array {
    $stmt = $pdo->prepare("
        SELECT t.*,
               u.full_name AS agent_name,
               u.username AS agent_username,
               (
                   SELECT COUNT(*)
                   FROM tournament_participants tp
                   WHERE tp.tournament_id = t.id AND tp.status = 'confirmed'
               ) AS registered_teams
        FROM tournaments t
        LEFT JOIN users u ON u.id = t.agent_id
        WHERE t.id = ?
        LIMIT 1
    ");
    $stmt->execute([$tournamentId]);
    $tournament = $stmt->fetch();
    return $tournament ?: null;
}

function userCanAccessTournamentRoom(PDO $pdo, int $tournamentId, int $userId): bool {
    $tournament = getTournamentByIdWithCounts($pdo, $tournamentId);
    if (!$tournament || $userId <= 0) {
        return false;
    }

    if ((int) ($tournament['agent_id'] ?? 0) === $userId) {
        return true;
    }

    $directStmt = $pdo->prepare("
        SELECT 1
        FROM tournament_participants
        WHERE tournament_id = ? AND user_id = ? AND status = 'confirmed'
        LIMIT 1
    ");
    $directStmt->execute([$tournamentId, $userId]);
    if ($directStmt->fetchColumn()) {
        return true;
    }

    $teamStmt = $pdo->prepare("
        SELECT 1
        FROM tournament_participants tp
        INNER JOIN team_members tm ON tm.team_id = tp.team_id
        WHERE tp.tournament_id = ?
          AND tp.status = 'confirmed'
          AND tm.user_id = ?
        LIMIT 1
    ");
    $teamStmt->execute([$tournamentId, $userId]);
    return (bool) $teamStmt->fetchColumn();
}

function getTournamentTeams(PDO $pdo, int $tournamentId): array {
    $stmt = $pdo->prepare("
        SELECT tp.id,
               tp.tournament_id,
               tp.user_id,
               tp.team_id,
               tp.team_name,
               tp.status,
               tp.created_at,
               t.name AS linked_team_name,
               captain.full_name AS captain_name,
               captain.username AS captain_username,
               (
                   SELECT COUNT(*)
                   FROM team_members tm_count
                   WHERE tm_count.team_id = tp.team_id
               ) AS member_count
        FROM tournament_participants tp
        LEFT JOIN teams t ON t.id = tp.team_id
        LEFT JOIN users captain ON captain.id = tp.user_id
        WHERE tp.tournament_id = ?
          AND tp.status = 'confirmed'
        ORDER BY tp.created_at ASC
    ");
    $stmt->execute([$tournamentId]);
    return $stmt->fetchAll();
}

function getTournamentPlayerPool(PDO $pdo, int $tournamentId): array {
    $stmt = $pdo->prepare("
        SELECT DISTINCT
               u.id AS user_id,
               u.full_name,
               u.username,
               u.avatar,
               tp.team_id,
               COALESCE(t.name, tp.team_name, 'Solo player') AS team_name,
               COALESCE(tm.role, 'solo') AS membership_role
        FROM tournament_participants tp
        INNER JOIN users u ON u.id = tp.user_id
        LEFT JOIN teams t ON t.id = tp.team_id
        LEFT JOIN team_members tm ON tm.team_id = tp.team_id AND tm.user_id = tp.user_id
        WHERE tp.tournament_id = ?
          AND tp.status = 'confirmed'

        UNION

        SELECT DISTINCT
               u.id AS user_id,
               u.full_name,
               u.username,
               u.avatar,
               tp.team_id,
               COALESCE(t.name, tp.team_name, 'Team member') AS team_name,
               tm.role AS membership_role
        FROM tournament_participants tp
        INNER JOIN team_members tm ON tm.team_id = tp.team_id
        INNER JOIN users u ON u.id = tm.user_id
        LEFT JOIN teams t ON t.id = tp.team_id
        WHERE tp.tournament_id = ?
          AND tp.status = 'confirmed'
          AND tp.team_id IS NOT NULL
        ORDER BY team_name ASC, membership_role DESC, full_name ASC, username ASC
    ");
    $stmt->execute([$tournamentId, $tournamentId]);
    return $stmt->fetchAll();
}

// ─── BRACKET HELPERS ──────────────────────────────────────

function getTournamentBracket(PDO $pdo, int $tournamentId): array {
    $stmt = $pdo->prepare("
        SELECT tm.*,
               COALESCE(t1.name, u1_full.full_name, u1_full.username, 'TBD') AS team1_name,
               COALESCE(t2.name, u2_full.full_name, u2_full.username, 'TBD') AS team2_name,
               COALESCE(w.name, uw_full.full_name, uw_full.username, '') AS winner_name,
               tp1.user_id AS team1_captain_id,
               tp2.user_id AS team2_captain_id,
               u1.full_name AS team1_captain_name,
               u2.full_name AS team2_captain_name
        FROM tournament_matches tm
        LEFT JOIN teams t1 ON t1.id = tm.team1_id
        LEFT JOIN teams t2 ON t2.id = tm.team2_id
        LEFT JOIN teams w ON w.id = tm.winner_id
        LEFT JOIN users u1_full ON u1_full.id = tm.team1_id
        LEFT JOIN users u2_full ON u2_full.id = tm.team2_id
        LEFT JOIN users uw_full ON uw_full.id = tm.winner_id
        LEFT JOIN tournament_participants tp1 ON tp1.tournament_id = tm.tournament_id AND tp1.team_id = tm.team1_id
        LEFT JOIN tournament_participants tp2 ON tp2.tournament_id = tm.tournament_id AND tp2.team_id = tm.team2_id
        LEFT JOIN users u1 ON u1.id = tp1.user_id
        LEFT JOIN users u2 ON u2.id = tp2.user_id
        WHERE tm.tournament_id = ?
        ORDER BY tm.round ASC, tm.id ASC
    ");
    $stmt->execute([$tournamentId]);
    return $stmt->fetchAll();
}

function generateTournamentBracket(PDO $pdo, int $tournamentId, int $agentId): array {
    $tournament = getTournamentByIdWithCounts($pdo, $tournamentId);
    if (!$tournament) return ['success' => false, 'message' => 'Tournament not found.'];
    if ((int)($tournament['agent_id'] ?? 0) !== $agentId) return ['success' => false, 'message' => 'Only the agent can generate brackets.'];

    // Check if bracket already exists
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tournament_matches WHERE tournament_id = ?");
    $stmt->execute([$tournamentId]);
    if ((int)$stmt->fetchColumn() > 0) return ['success' => false, 'message' => 'Bracket already generated.'];

    // Get all registered teams/participants
    $stmt = $pdo->prepare("
        SELECT DISTINCT COALESCE(tp.team_id, tp.user_id) AS entrant_id,
               CASE WHEN tp.team_id IS NOT NULL THEN 'team' ELSE 'player' END AS entrant_type,
               COALESCE(t.name, tp.team_name, u.full_name, u.username) AS entrant_name,
               tp.team_id, tp.user_id
        FROM tournament_participants tp
        LEFT JOIN teams t ON t.id = tp.team_id
        LEFT JOIN users u ON u.id = tp.user_id
        WHERE tp.tournament_id = ? AND tp.status = 'confirmed'
        ORDER BY RAND()
    ");
    $stmt->execute([$tournamentId]);
    $entrants = $stmt->fetchAll();

    $count = count($entrants);
    if ($count < 2) return ['success' => false, 'message' => 'Need at least 2 participants to generate a bracket.'];

    // Find next power of 2 for a balanced bracket
    $roundSize = 1;
    while ($roundSize < $count) $roundSize *= 2;

    // Pad with nulls for byes (entrants that get a free pass to next round)
    while (count($entrants) < $roundSize) {
        $entrants[] = null;
    }

    shuffle($entrants);

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO tournament_matches (tournament_id, round, team1_id, team2_id, status)
            VALUES (?, 1, ?, ?, 'scheduled')
        ");

        $matchesCreated = 0;
        for ($i = 0; $i < $roundSize; $i += 2) {
            $team1 = $entrants[$i];
            $team2 = $entrants[$i + 1] ?? null;

            // Skip if both are null (shouldn't happen for power of 2)
            if (!$team1 && !$team2) continue;

            $team1Id = $team1 ? ($team1['entrant_type'] === 'team' ? $team1['team_id'] : $team1['user_id']) : null;
            $team2Id = $team2 ? ($team2['entrant_type'] === 'team' ? $team2['team_id'] : $team2['user_id']) : null;

            $stmt->execute([$tournamentId, $team1Id, $team2Id]);
            $matchesCreated++;
        }

        $pdo->commit();

        $totalRounds = (int)log($roundSize, 2);
        $bracket = getTournamentBracket($pdo, $tournamentId);

        return [
            'success' => true,
            'message' => "Bracket generated! $matchesCreated matches in round 1, $totalRounds total rounds.",
            'bracket' => $bracket,
            'total_rounds' => $totalRounds
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['success' => false, 'message' => 'Could not generate bracket.'];
    }
}

function advanceTournamentWinner(PDO $pdo, int $matchId, int $winnerTeamId, int $agentId): array {
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT * FROM tournament_matches WHERE id = ? FOR UPDATE");
        $stmt->execute([$matchId]);
        $match = $stmt->fetch();
        if (!$match) { $pdo->rollBack(); return ['success' => false, 'message' => 'Match not found.']; }

        $tournament = getTournamentByIdWithCounts($pdo, (int)$match['tournament_id']);
        if (!$tournament || (int)($tournament['agent_id'] ?? 0) !== $agentId) { $pdo->rollBack(); return ['success' => false, 'message' => 'Only the agent can advance winners.']; }

        $winnerCol = $winnerTeamId == (int)$match['team1_id'] ? 'team1' : 'team2';
        $scoreCol = $winnerCol === 'team1' ? 'score1' : 'score2';

        $stmt = $pdo->prepare("UPDATE tournament_matches SET winner_id = ?, status = 'completed' WHERE id = ?");
        $stmt->execute([$winnerTeamId, $matchId]);

        // Find or create next round match
        $nextRound = (int)$match['round'] + 1;
        $tournamentId = (int)$match['tournament_id'];

        // Find all matches in current round, check if all completed
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM tournament_matches WHERE tournament_id = ? AND round = ? AND status != 'completed'");
        $stmt->execute([$tournamentId, (int)$match['round']]);
        $remaining = (int)$stmt->fetchColumn();

        // Find or create slot in next round
        $stmt = $pdo->prepare("SELECT id, team1_id FROM tournament_matches WHERE tournament_id = ? AND round = ? AND (team1_id IS NULL OR team2_id IS NULL) ORDER BY id ASC LIMIT 1");
        $stmt->execute([$tournamentId, $nextRound]);
        $nextMatch = $stmt->fetch();

        if ($nextMatch) {
            if ((int)$nextMatch['team1_id'] === 0 || !$nextMatch['team1_id']) {
                $stmt = $pdo->prepare("UPDATE tournament_matches SET team1_id = ? WHERE id = ?");
                $stmt->execute([$winnerTeamId, (int)$nextMatch['id']]);
            } else {
                $stmt = $pdo->prepare("UPDATE tournament_matches SET team2_id = ? WHERE id = ?");
                $stmt->execute([$winnerTeamId, (int)$nextMatch['id']]);
            }
        } else {
            // Check if a next round match slot is needed
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM tournament_matches WHERE tournament_id = ? AND round = ?");
            $stmt->execute([$tournamentId, (int)$match['round']]);
            $currentCount = (int)$stmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM tournament_matches WHERE tournament_id = ? AND round = ?");
            $stmt->execute([$tournamentId, $nextRound]);
            $nextCount = (int)$stmt->fetchColumn();

            if ($nextCount < ceil($currentCount / 2)) {
                $stmt = $pdo->prepare("INSERT INTO tournament_matches (tournament_id, round, team1_id, status) VALUES (?, ?, ?, 'scheduled')");
                $stmt->execute([$tournamentId, $nextRound, $winnerTeamId]);
            } else {
                $stmt = $pdo->prepare("UPDATE tournament_matches SET team2_id = ? WHERE tournament_id = ? AND round = ? AND team2_id IS NULL LIMIT 1");
                $stmt->execute([$winnerTeamId, $tournamentId, $nextRound]);
            }
        }

        $pdo->commit();

        $bracket = getTournamentBracket($pdo, $tournamentId);
        return ['success' => true, 'message' => 'Winner advanced to round ' . $nextRound . '.', 'bracket' => $bracket];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['success' => false, 'message' => 'Server error.'];
    }
}

function renderBracketHTML(array $bracket): void {
    if (empty($bracket)) {
        echo '<div class="tr-empty"><i class="fas fa-diagram-project"></i><p>No bracket matches yet.</p></div>';
        return;
    }

    $rounds = [];
    foreach ($bracket as $match) {
        $rounds[(int)$match['round']][] = $match;
    }
    ksort($rounds);

    $totalRounds = count($rounds);
    echo '<div class="tr-bracket-wrapper">';
    foreach ($rounds as $roundNum => $matches) {
        $isLast = ($roundNum === $totalRounds);
        echo '<div class="tr-bracket-round">';
        echo '<div class="tr-bracket-round-header">Round ' . $roundNum . '</div>';
        echo '<div class="tr-bracket-matches">';
        foreach ($matches as $match) {
            $team1 = htmlspecialchars($match['team1_name'] ?? 'TBD');
            $team2 = htmlspecialchars($match['team2_name'] ?? 'TBD');
            $winner = htmlspecialchars($match['winner_name'] ?? '');
            $status = $match['status'] ?? 'scheduled';
            $matchId = (int)$match['id'];
            echo '<div class="tr-bracket-match" data-match-id="' . $matchId . '" data-team1-id="' . ((int)($match['team1_id'] ?? 0)) . '" data-team2-id="' . ((int)($match['team2_id'] ?? 0)) . '">';
            echo '<div class="tr-bracket-team' . ($winner && $winner === $team1 ? ' is-winner' : '') . '">' . $team1 . '</div>';
            echo '<div class="tr-bracket-vs">VS</div>';
            echo '<div class="tr-bracket-team' . ($winner && $winner === $team2 ? ' is-winner' : '') . '">' . $team2 . '</div>';
            if ($status === 'completed' && $winner) {
                echo '<div class="tr-bracket-winner"><i class="fas fa-trophy"></i> ' . $winner . '</div>';
            } elseif ($status === 'live') {
                echo '<div class="tr-bracket-live">Live</div>';
            }
            echo '</div>';
        }
        echo '</div></div>';
    }
    echo '</div>';
}

function getTournamentRoomMessages(PDO $pdo, int $tournamentId, int $limit = 80): array {
    $limit = max(1, min(200, $limit));
    $stmt = $pdo->prepare("
        SELECT m.*,
               u.full_name,
               u.username,
               u.avatar
        FROM tournament_chat_messages m
        INNER JOIN users u ON u.id = m.sender_id
        WHERE m.tournament_id = ?
        ORDER BY m.created_at DESC
        LIMIT {$limit}
    ");
    $stmt->execute([$tournamentId]);
    return array_reverse($stmt->fetchAll());
}

function createTournamentChatMessage(PDO $pdo, int $tournamentId, int $senderId, string $messageType, array $payload): array {
    $messageType = in_array($messageType, ['text', 'room_card'], true) ? $messageType : 'text';
    $body = trim((string) ($payload['message'] ?? ''));
    $meta = [];

    if ($messageType === 'room_card') {
        $meta = [
            'room_title' => trim((string) ($payload['room_title'] ?? '')),
            'room_code' => trim((string) ($payload['room_code'] ?? '')),
            'room_link' => trim((string) ($payload['room_link'] ?? '')),
            'starts_at' => trim((string) ($payload['starts_at'] ?? '')),
            'note' => trim((string) ($payload['note'] ?? '')),
        ];
        if ($meta['room_title'] === '' || ($meta['room_code'] === '' && $meta['room_link'] === '')) {
            return ['success' => false, 'message' => 'Room title and invite details are required.'];
        }
        if ($body === '') {
            $body = 'Room card shared.';
        }
    } elseif ($body === '') {
        return ['success' => false, 'message' => 'Message cannot be empty.'];
    }

    $stmt = $pdo->prepare("
        INSERT INTO tournament_chat_messages (tournament_id, sender_id, message_type, message, metadata_json)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $tournamentId,
        $senderId,
        $messageType,
        $body,
        $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
    ]);

    return ['success' => true, 'message' => 'Message sent.'];
}

function getTournamentResultsBundle(PDO $pdo, int $tournamentId): array {
    $stmt = $pdo->prepare("
        SELECT tr.*,
               u.full_name,
               u.username,
               u.avatar,
               t.name AS linked_team_name
        FROM tournament_results tr
        LEFT JOIN users u ON u.id = tr.user_id
        LEFT JOIN teams t ON t.id = tr.team_id
        WHERE tr.tournament_id = ?
        ORDER BY tr.result_scope ASC, tr.placement ASC, tr.id ASC
    ");
    $stmt->execute([$tournamentId]);
    $rows = $stmt->fetchAll();

    $bundle = ['teams' => [], 'players' => []];
    foreach ($rows as $row) {
        if (($row['result_scope'] ?? '') === 'team') {
            $bundle['teams'][] = $row;
        } else {
            $bundle['players'][] = $row;
        }
    }
    return $bundle;
}

function saveTournamentResults(PDO $pdo, int $tournamentId, int $agentId, array $teamResults, array $playerResults): array {
    $tournament = getTournamentByIdWithCounts($pdo, $tournamentId);
    if (!$tournament) {
        return ['success' => false, 'message' => 'Tournament not found.'];
    }
    if ((int) ($tournament['agent_id'] ?? 0) !== $agentId) {
        return ['success' => false, 'message' => 'Only the tournament agent can submit results.'];
    }

    $cleanTeamResults = [];
    foreach ($teamResults as $row) {
        $teamId = (int) ($row['team_id'] ?? 0);
        $placement = max(1, (int) ($row['placement'] ?? 0));
        if ($teamId <= 0) {
            continue;
        }
        $cleanTeamResults[] = [
            'team_id' => $teamId,
            'placement' => $placement,
            'score' => trim((string) ($row['score'] ?? '')),
            'result_label' => trim((string) ($row['result_label'] ?? '')),
            'notes' => trim((string) ($row['notes'] ?? '')),
            'prize_amount' => (float) ($row['prize_amount'] ?? 0),
            'points_earned' => (int) ($row['points_earned'] ?? 0),
        ];
    }

    $cleanPlayerResults = [];
    foreach ($playerResults as $row) {
        $userId = (int) ($row['user_id'] ?? 0);
        $placement = max(1, (int) ($row['placement'] ?? 0));
        if ($userId <= 0) {
            continue;
        }
        $cleanPlayerResults[] = [
            'user_id' => $userId,
            'team_id' => (int) ($row['team_id'] ?? 0),
            'placement' => $placement,
            'score' => trim((string) ($row['score'] ?? '')),
            'result_label' => trim((string) ($row['result_label'] ?? '')),
            'notes' => trim((string) ($row['notes'] ?? '')),
            'prize_amount' => (float) ($row['prize_amount'] ?? 0),
            'points_earned' => (int) ($row['points_earned'] ?? 0),
        ];
    }

    if (!$cleanTeamResults && !$cleanPlayerResults) {
        return ['success' => false, 'message' => 'Add at least one team or player result.'];
    }

    // ── Prize escrow cap: payouts can never exceed prize pool + collected fees ──
    $requestedPrize = 0.0;
    foreach (array_merge($cleanTeamResults, $cleanPlayerResults) as $row) {
        $requestedPrize += max(0.0, (float) $row['prize_amount']);
    }
    $escrow = tnEscrowAvailable($pdo, $tournamentId);
    if ($requestedPrize > $escrow + 0.0001) {
        return ['success' => false, 'message' => 'Prize total ৳' . number_format($requestedPrize, 0) . ' exceeds available escrow of ৳' . number_format($escrow, 0) . '. Adjust prize amounts or fund the prize pool first.'];
    }

    try {
        $pdo->beginTransaction();

        // One-shot settlement: once escrow is released, results are final.
        $lockStmt = $pdo->prepare("SELECT escrow_released FROM tournaments WHERE id = ? FOR UPDATE");
        $lockStmt->execute([$tournamentId]);
        if ((int) $lockStmt->fetchColumn() === 1) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Results were already published for this tournament.'];
        }

        $pdo->prepare("DELETE FROM tournament_results WHERE tournament_id = ?")->execute([$tournamentId]);

        $insertStmt = $pdo->prepare("
            INSERT INTO tournament_results
                (tournament_id, team_id, user_id, result_scope, placement, points_earned, score, result_label, prize_amount, notes, submitted_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($cleanTeamResults as $teamResult) {
            $insertStmt->execute([
                $tournamentId,
                $teamResult['team_id'],
                null,
                'team',
                $teamResult['placement'],
                $teamResult['points_earned'],
                $teamResult['score'],
                $teamResult['result_label'],
                $teamResult['prize_amount'],
                $teamResult['notes'],
                $agentId,
            ]);
        }

        foreach ($cleanPlayerResults as $playerResult) {
            $insertStmt->execute([
                $tournamentId,
                $playerResult['team_id'] > 0 ? $playerResult['team_id'] : null,
                $playerResult['user_id'],
                'player',
                $playerResult['placement'],
                $playerResult['points_earned'],
                $playerResult['score'],
                $playerResult['result_label'],
                $playerResult['prize_amount'],
                $playerResult['notes'],
                $agentId,
            ]);
        }

        $pdo->prepare("UPDATE tournaments SET status = 'completed' WHERE id = ?")->execute([$tournamentId]);

        // ── Auto-distribute prize money to winners — strictly from escrow ──
        $allResults = array_merge($cleanTeamResults, $cleanPlayerResults);
        $totalPrizeDistributed = 0;
        foreach ($allResults as $result) {
            $prize = (float)($result['prize_amount'] ?? 0);
            if ($prize <= 0) continue;

            $winnerUserId = $result['user_id'] ?? null;
            if (!$winnerUserId && !empty($result['team_id'])) {
                // For team results, credit the team captain
                $stmt = $pdo->prepare("SELECT user_id FROM team_members WHERE team_id = ? AND role = 'captain' LIMIT 1");
                $stmt->execute([(int)$result['team_id']]);
                $captain = $stmt->fetch();
                $winnerUserId = $captain ? (int)$captain['user_id'] : null;
            }
            if (!$winnerUserId) continue;

            if (!tnEscrowPayPrize($pdo, $tournamentId, $prize)) {
                $pdo->rollBack();
                return ['success' => false, 'message' => 'Escrow ran out while paying ৳' . number_format($prize, 0) . ' — no results were saved.'];
            }
            $totalPrizeDistributed += $prize;
            $stmt = $pdo->prepare("SELECT balance FROM users WHERE id = ? FOR UPDATE");
            $stmt->execute([$winnerUserId]);
            $before = (float) $stmt->fetchColumn();
            $pdo->prepare("UPDATE users SET balance = ? WHERE id = ?")->execute([$before + $prize, $winnerUserId]);
            $pdo->prepare("INSERT INTO transactions (user_id, type, amount, balance_before, balance_after, description, reference_id, purpose) VALUES (?, 'prize', ?, ?, ?, ?, ?, 'tournament_prize')")
                ->execute([$winnerUserId, $prize, $before, $before + $prize, 'Prize won in "' . mb_substr($tournament['title'] ?? '', 0, 150) . '"', $tournamentId]);

            createNotification(
                $pdo,
                $winnerUserId,
                $agentId,
                'prize_credited',
                'You won ৳' . number_format($prize, 0) . ' in "' . ($tournament['title'] ?? 'Tournament') . '"! Prize credited to your balance.',
                $tournamentId
            );
        }

        foreach ($cleanPlayerResults as $playerResult) {
            createNotification(
                $pdo,
                $playerResult['user_id'],
                $agentId,
                'tournament_result',
                'Your tournament result for "' . ($tournament['title'] ?? 'Tournament') . '" has been published.',
                $tournamentId
            );
        }

        updateTournamentLeaderboard($pdo, $tournamentId);

        // Release leftover escrow (unspent fees + prize remainder) to the agent
        $settlement = tnEscrowReleaseToAgent($pdo, $tournamentId);
        $released = (float) ($settlement['released'] ?? 0);

        $pdo->commit();
        $msg = 'Tournament results submitted successfully!';
        if ($totalPrizeDistributed > 0) $msg .= ' ৳' . number_format($totalPrizeDistributed, 0) . ' prize distributed.';
        if ($released > 0) $msg .= ' ৳' . number_format($released, 0) . ' escrow released to agent.';
        return ['success' => true, 'message' => $msg];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['success' => false, 'message' => 'Could not save tournament results.'];
    }
}

function canSubmitTournamentResults(PDO $pdo, int $tournamentId, int $agentId): bool {
    $tournament = getTournamentByIdWithCounts($pdo, $tournamentId);
    if (!$tournament) return false;
    if ((int) ($tournament['agent_id'] ?? 0) !== $agentId) return false;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tournament_results WHERE tournament_id = ?");
    $stmt->execute([$tournamentId]);
    return (int) $stmt->fetchColumn() === 0;
}

function updateTournamentLeaderboard(PDO $pdo, int $tournamentId): void {
    try {
        // Delete old leaderboard entries for this tournament to avoid double-counting on re-submission
        $pdo->prepare("DELETE FROM tournament_leaderboard WHERE tournament_id = ?")->execute([$tournamentId]);

        $stmt = $pdo->prepare("
            SELECT user_id, SUM(points_earned) AS total_points, SUM(prize_amount) AS total_prize,
                   COUNT(*) AS tournaments_played, MIN(placement) AS best_rank
            FROM tournament_results
            WHERE tournament_id = ? AND user_id IS NOT NULL
            GROUP BY user_id
        ");
        $stmt->execute([$tournamentId]);
        $players = $stmt->fetchAll();

        $stmt = $pdo->prepare("
            INSERT INTO tournament_leaderboard (tournament_id, user_id, total_points, total_prize, tournaments_played, best_rank)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                total_points = VALUES(total_points),
                total_prize = VALUES(total_prize),
                tournaments_played = VALUES(tournaments_played),
                best_rank = LEAST(IFNULL(best_rank, 999), VALUES(best_rank))
        ");
        foreach ($players as $player) {
            $stmt->execute([
                $tournamentId,
                (int) $player['user_id'],
                (int) ($player['total_points'] ?? 0),
                (float) ($player['total_prize'] ?? 0),
                (int) ($player['tournaments_played'] ?? 1),
                (int) ($player['best_rank'] ?? 999),
            ]);
        }
    } catch (Throwable $e) {
        error_log("Leaderboard update failed: " . $e->getMessage());
    }
}

function getUserTournamentResults(PDO $pdo, int $userId, int $limit = 8): array {
    $limit = max(1, min(50, $limit));
    $stmt = $pdo->prepare("
        SELECT tr.*,
               tour.title AS tournament_title,
               tour.category,
               tour.game_icon,
               tour.accent_color,
               teams.name AS linked_team_name
        FROM tournament_results tr
        INNER JOIN tournaments tour ON tour.id = tr.tournament_id
        LEFT JOIN teams ON teams.id = tr.team_id
        WHERE tr.user_id = ?
        ORDER BY tr.created_at DESC
        LIMIT {$limit}
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function getUserTournamentRegistrations(PDO $pdo, int $userId): array {
    $stmt = $pdo->prepare("
        SELECT tp.*, t.title, t.status AS tournament_status, t.starts_at, t.prize_money, t.game_icon, t.accent_color, t.category, t.entry_fee
        FROM tournament_participants tp
        JOIN tournaments t ON t.id = tp.tournament_id
        WHERE tp.user_id = ? AND tp.status = 'confirmed'
        ORDER BY t.starts_at DESC
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function registerForTournament(PDO $pdo, int $userId, int $tournamentId, string $teamName = ''): array {
    try {
        $pdo->beginTransaction();

        // Lock the tournament row first — closes the capacity race and enables FOR UPDATE below.
        $stmt = $pdo->prepare("SELECT * FROM tournaments WHERE id = ? FOR UPDATE");
        $stmt->execute([$tournamentId]);
        $tournament = $stmt->fetch();
        if (!$tournament) { $pdo->rollBack(); return ['success' => false, 'message' => 'Tournament not found.']; }
        if (!in_array($tournament['status'], ['upcoming', 'live'])) { $pdo->rollBack(); return ['success' => false, 'message' => 'Registration closed.']; }

        $entryFee = (float)$tournament['entry_fee'];

        // Existing registration? Reactivate a cancelled one (charging the fee again),
        // otherwise reject a confirmed duplicate.
        $stmt = $pdo->prepare("SELECT id, status, fee_paid FROM tournament_participants WHERE tournament_id = ? AND user_id = ? FOR UPDATE");
        $stmt->execute([$tournamentId, $userId]);
        $existing = $stmt->fetch();
        $isReactivate = $existing && $existing['status'] === 'cancelled';

        if ($existing && !$isReactivate) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'You are already registered for this tournament.'];
        }

        // Check max teams (locked by the tournament row above)
        if ((int)$tournament['max_teams'] > 0) {
            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM tournament_participants WHERE tournament_id = ? AND status = 'confirmed'");
            $countStmt->execute([$tournamentId]);
            if ((int)$countStmt->fetchColumn() >= (int)$tournament['max_teams']) {
                $pdo->rollBack();
                return ['success' => false, 'message' => 'Tournament is full.'];
            }
        }

        $feeCharged = false;
        if ($entryFee > 0) {
            $stmt = $pdo->prepare("SELECT balance FROM users WHERE id = ? FOR UPDATE");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            $balance = (float)($user['balance'] ?? 0);
            if ($balance < $entryFee) {
                $pdo->rollBack();
                return ['success' => false, 'message' => 'Insufficient balance. Entry fee: ৳' . number_format($entryFee, 0)];
            }
            $newBalance = $balance - $entryFee;
            $pdo->prepare("UPDATE users SET balance = ? WHERE id = ?")->execute([$newBalance, $userId]);
            $pdo->prepare("INSERT INTO transactions (user_id, type, amount, balance_before, balance_after, description, purpose) VALUES (?, 'entry_fee', ?, ?, ?, ?, 'tournament_entry')")
                ->execute([$userId, $entryFee, $balance, $newBalance, 'Entry fee for "' . mb_substr($tournament['title'] ?? '', 0, 150) . '"']);
            // Hold the fee in tournament escrow (released to the agent on completion)
            tnEscrowCollectFee($pdo, $tournamentId, $entryFee);
            $feeCharged = true;
        }

        if ($existing) {
            $pdo->prepare("UPDATE tournament_participants SET status = 'confirmed', team_name = ?, fee_paid = ?, checked_in = 0, checked_in_at = NULL, created_at = NOW() WHERE id = ?")
                ->execute([$teamName, $feeCharged ? 1 : 0, $existing['id']]);
        } else {
            try {
                $pdo->prepare("INSERT INTO tournament_participants (tournament_id, user_id, team_name, fee_paid, status) VALUES (?, ?, ?, ?, 'confirmed')")
                    ->execute([$tournamentId, $userId, $teamName, $feeCharged ? 1 : 0]);
            } catch (PDOException $e) {
                $pdo->rollBack();
                if ((string) $e->getCode() === '23000') return ['success' => false, 'message' => 'You are already registered for this tournament.'];
                throw $e;
            }
        }

        $pdo->commit();
        createNotification($pdo, $userId, (int)($tournament['agent_id'] ?? null) ?: null, 'tournament',
            'Registration confirmed for "' . ($tournament['title'] ?? 'Tournament') . '"' . ($feeCharged ? ' — entry fee ৳' . number_format($entryFee, 0) . ' held in escrow.' : '.'), $tournamentId);
        return ['success' => true, 'message' => $isReactivate ? 'Registration reactivated!' : 'Successfully registered for the tournament!'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        return ['success' => false, 'message' => 'Server error.'];
    }
}

function unregisterFromTournament(PDO $pdo, int $userId, int $tournamentId): array {
    try {
        $pdo->beginTransaction();

        // Get tournament info
        $stmt = $pdo->prepare("SELECT * FROM tournaments WHERE id = ? FOR UPDATE");
        $stmt->execute([$tournamentId]);
        $tournament = $stmt->fetch();
        if (!$tournament) { $pdo->rollBack(); return ['success' => false, 'message' => 'Tournament not found.']; }

        // Get participant record
        $stmt = $pdo->prepare("SELECT id, fee_paid FROM tournament_participants WHERE tournament_id = ? AND user_id = ? AND status = 'confirmed' FOR UPDATE");
        $stmt->execute([$tournamentId, $userId]);
        $participant = $stmt->fetch();
        if (!$participant) { $pdo->rollBack(); return ['success' => false, 'message' => 'No active registration found.']; }

        // Once the bracket exists, rosters are locked — no refunds via leave.
        $bm = $pdo->prepare("SELECT COUNT(*) FROM tournament_matches WHERE tournament_id = ?");
        $bm->execute([$tournamentId]);
        if ((int)$bm->fetchColumn() > 0) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'The bracket has started — leaving is disabled.'];
        }

        // Refund entry fee if paid — always from escrow (never prints money)
        $entryFee = (float)$tournament['entry_fee'];
        $refunded = false;
        if ($entryFee > 0 && ($participant['fee_paid'] ?? 0)) {
            $stmt = $pdo->prepare("SELECT balance FROM users WHERE id = ? FOR UPDATE");
            $stmt->execute([$userId]);
            $userBal = (float)($stmt->fetchColumn() ?: 0);

            if (tnEscrowRefundFee($pdo, $tournament, $userId, $entryFee)) {
                $userAfter = $userBal + $entryFee;
                $pdo->prepare("UPDATE users SET balance = ? WHERE id = ?")->execute([$userAfter, $userId]);
                $pdo->prepare("INSERT INTO transactions (user_id, type, amount, balance_before, balance_after, description, purpose) VALUES (?, 'refund', ?, ?, ?, ?, 'tournament_entry')")
                    ->execute([$userId, $entryFee, $userBal, $userAfter, 'Entry fee refunded — left "' . mb_substr($tournament['title'] ?? '', 0, 150) . '"']);
                $refunded = true;
            }
        }

        // Cancel registration
        $stmt = $pdo->prepare("UPDATE tournament_participants SET status = 'cancelled' WHERE id = ?");
        $stmt->execute([$participant['id']]);

        $pdo->commit();
        $msg = 'Registration cancelled.' . ($refunded ? ' Entry fee of ৳' . number_format($entryFee, 0) . ' refunded.' : '');
        return ['success' => true, 'message' => $msg, 'refund' => $refunded ? $entryFee : 0];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['success' => false, 'message' => 'Server error.'];
    }
}

function cancelTournament(PDO $pdo, int $tournamentId, int $agentId): array {
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT * FROM tournaments WHERE id = ? AND agent_id = ? FOR UPDATE");
        $stmt->execute([$tournamentId, $agentId]);
        $tournament = $stmt->fetch();
        if (!$tournament) { $pdo->rollBack(); return ['success' => false, 'message' => 'Tournament not found or not owned by you.']; }
        if ($tournament['status'] === 'completed') { $pdo->rollBack(); return ['success' => false, 'message' => 'Cannot cancel a completed tournament.']; }
        if ($tournament['status'] === 'cancelled') { $pdo->rollBack(); return ['success' => false, 'message' => 'Tournament already cancelled.']; }

        // Refund entry fees to all confirmed participants — strictly from escrow
        $entryFee = (float)$tournament['entry_fee'];
        if ($entryFee > 0) {
            $stmt = $pdo->prepare("SELECT tp.user_id, tp.fee_paid FROM tournament_participants tp WHERE tp.tournament_id = ? AND tp.status = 'confirmed'");
            $stmt->execute([$tournamentId]);
            $participants = $stmt->fetchAll();
            foreach ($participants as $p) {
                if ($p['fee_paid'] ?? 0) {
                    if (tnEscrowRefundFee($pdo, $tournament, (int)$p['user_id'], $entryFee)) {
                        $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?")->execute([$entryFee, (int)$p['user_id']]);
                        $pdo->prepare("INSERT INTO transactions (user_id, type, amount, description, purpose) VALUES (?, 'refund', ?, ?, 'tournament_cancel')")
                            ->execute([(int)$p['user_id'], $entryFee, 'Entry fee refunded — tournament "' . mb_substr($tournament['title'] ?? '', 0, 150) . '" cancelled']);
                        createNotification($pdo, (int)$p['user_id'], $agentId, 'refund', 'Entry fee ৳' . number_format($entryFee, 0) . ' refunded for cancelled tournament "' . ($tournament['title'] ?? 'Tournament') . '".', $tournamentId);
                    }
                }
            }
        }

        // Return remaining escrow (prize pool + any unclaimed fees) to the agent
        tnEscrowReleaseToAgent($pdo, $tournamentId);

        // Cancel all participant registrations
        $stmt = $pdo->prepare("UPDATE tournament_participants SET status = 'cancelled' WHERE tournament_id = ? AND status = 'confirmed'");
        $stmt->execute([$tournamentId]);

        // Update tournament status
        $stmt = $pdo->prepare("UPDATE tournaments SET status = 'cancelled' WHERE id = ?");
        $stmt->execute([$tournamentId]);

        $pdo->commit();
        return ['success' => true, 'message' => 'Tournament cancelled. All fees refunded.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['success' => false, 'message' => 'Server error.'];
    }
}

// ─── AGENT HELPERS ────────────────────────────────────────

function createAgentAccount(PDO $pdo, int $userId, float $fee): array {
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT role, balance FROM users WHERE id = ? FOR UPDATE");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'User not found.'];
        }
        if ($user['role'] === 'agent') {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Already an agent.'];
        }

        $newBalance = (float)$user['balance'] - $fee;
        if ($newBalance < 0) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Insufficient balance. Please add funds first.'];
        }

        $stmt = $pdo->prepare("UPDATE users SET role = 'agent', balance = ?, agent_verified_at = NOW() WHERE id = ?");
        $stmt->execute([$newBalance, $userId]);

        $stmt = $pdo->prepare("INSERT INTO agent_transactions (agent_id, type, amount, balance_before, balance_after, reference_type, description) VALUES (?, 'debit', ?, ?, ?, 'agent_fee', 'Agent account activation fee')");
        $stmt->execute([$userId, $fee, (float)$user['balance'], $newBalance]);

        $pdo->commit();
        $_SESSION['role'] = 'agent';
        return ['success' => true, 'message' => 'Agent account created!', 'balance' => $newBalance];
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['success' => false, 'message' => 'Server error.'];
    }
}

function addAgentFunds(PDO $pdo, int $userId, float $amount, string $ref = 'manual'): array {
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT balance FROM users WHERE id = ? FOR UPDATE");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user) { 
            $pdo->rollBack(); 
            return ['success' => false, 'message' => 'User not found.']; 
        }

        $before = (float)$user['balance'];
        $after = $before + $amount;
        $stmt = $pdo->prepare("UPDATE users SET balance = ? WHERE id = ?");
        $stmt->execute([$after, $userId]);
        $stmt = $pdo->prepare("INSERT INTO agent_transactions (agent_id, type, amount, balance_before, balance_after, reference_type, description) VALUES (?, 'credit', ?, ?, ?, 'deposit', 'Balance top-up')");
        $stmt->execute([$userId, $amount, $before, $after]);
        $pdo->commit();
        return ['success' => true, 'message' => 'Funds added!', 'balance' => $after];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['success' => false, 'message' => 'Server error.'];
    }
}

function deductPrizeFromAgent(PDO $pdo, int $agentId, float $amount, int $tournamentId): array {
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT balance FROM users WHERE id = ? AND role = 'agent' FOR UPDATE");
        $stmt->execute([$agentId]);
        $user = $stmt->fetch();
        if (!$user) { 
            $pdo->rollBack(); 
            return ['success' => false, 'message' => 'Agent not found.']; 
        }

        $before = (float)$user['balance'];
        if ($before < $amount) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Insufficient balance. Need ৳' . number_format($amount, 0) . ' but have ৳' . number_format($before, 0)];
        }

        $after = $before - $amount;
        $stmt = $pdo->prepare("UPDATE users SET balance = ? WHERE id = ?");
        $stmt->execute([$after, $agentId]);
        $stmt = $pdo->prepare("INSERT INTO agent_transactions (agent_id, type, amount, balance_before, balance_after, reference_type, reference_id, description) VALUES (?, 'debit', ?, ?, ?, 'tournament_prize', ?, 'Prize pool for tournament')");
        $stmt->execute([$agentId, $amount, $before, $after, $tournamentId]);
        $pdo->commit();
        return ['success' => true, 'message' => 'Prize deducted.', 'balance' => $after];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['success' => false, 'message' => 'Server error.'];
    }
}

function getAgentTransactions(PDO $pdo, int $agentId, int $limit = 20): array {
    $stmt = $pdo->prepare("SELECT * FROM agent_transactions WHERE agent_id = ? ORDER BY created_at DESC LIMIT ?");
    $stmt->execute([$agentId, $limit]);
    return $stmt->fetchAll();
}

function getAgentStats(PDO $pdo, int $agentId): array {
    $stats = ['total_tournaments' => 0, 'total_prize_spent' => 0, 'total_participants' => 0, 'total_revenue' => 0];
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM tournaments WHERE agent_id = ?");
        $stmt->execute([$agentId]);
        $stats['total_tournaments'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM agent_transactions WHERE agent_id = ? AND type = 'debit' AND reference_type = 'tournament_prize'");
        $stmt->execute([$agentId]);
        $stats['total_prize_spent'] = (float)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT tp.user_id) FROM tournament_participants tp JOIN tournaments t ON t.id = tp.tournament_id WHERE t.agent_id = ? AND tp.status = 'confirmed'");
        $stmt->execute([$agentId]);
        $stats['total_participants'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COALESCE(SUM(tp_amount.fee_paid), 0) FROM (SELECT tp.id, tp.fee_paid FROM tournament_participants tp JOIN tournaments t ON t.id = tp.tournament_id WHERE t.agent_id = ? AND tp.status = 'confirmed') tp_amount");
        $stmt->execute([$agentId]);
        $total = (float)$stmt->fetchColumn();
        $entryStmt = $pdo->prepare("SELECT COALESCE(SUM(t.entry_fee), 0) FROM tournaments t WHERE t.agent_id = ?");
        $entryStmt->execute([$agentId]);
        $totalEntry = (float)$entryStmt->fetchColumn();
        $stats['total_revenue'] = $totalEntry;
    } catch (Throwable $e) {}
    return $stats;
}

// ─── TEAM HELPERS ────────────────────────────────────────

function createTeam(PDO $pdo, int $captainId, string $name, string $game = '', string $description = ''): array {
    $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($name));
    $slug = trim($slug, '-');
    try {
        $stmt = $pdo->prepare("SELECT id FROM teams WHERE slug = ?");
        $stmt->execute([$slug]);
        if ($stmt->fetch()) {
            $slug .= '-' . substr(uniqid(), -4);
        }
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO teams (name, slug, captain_id, game, description) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$name, $slug, $captainId, $game, $description]);
        $teamId = (int)$pdo->lastInsertId();
        $stmt = $pdo->prepare("INSERT INTO team_members (team_id, user_id, role) VALUES (?, ?, 'captain')");
        $stmt->execute([$teamId, $captainId]);
        $pdo->commit();
        return ['success' => true, 'message' => 'Team created!', 'team_id' => $teamId, 'slug' => $slug];
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['success' => false, 'message' => 'Could not create team.'];
    }
}

function getUserTeams(PDO $pdo, int $userId): array {
    $stmt = $pdo->prepare("SELECT t.*, tm.role AS my_role FROM teams t JOIN team_members tm ON tm.team_id = t.id WHERE tm.user_id = ? ORDER BY t.created_at DESC");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function getTeamMembers(PDO $pdo, int $teamId): array {
    $stmt = $pdo->prepare("SELECT u.id, u.full_name, u.username, u.avatar, tm.role, tm.joined_at FROM team_members tm JOIN users u ON u.id = tm.user_id WHERE tm.team_id = ? ORDER BY FIELD(tm.role, 'captain', 'co-captain', 'member'), tm.joined_at ASC");
    $stmt->execute([$teamId]);
    return $stmt->fetchAll();
}

function addTeamMember(PDO $pdo, int $teamId, int $userId, string $role = 'member'): array {
    try {
        $stmt = $pdo->prepare("INSERT IGNORE INTO team_members (team_id, user_id, role) VALUES (?, ?, ?)");
        $stmt->execute([$teamId, $userId, $role]);
        if ($stmt->rowCount() > 0) return ['success' => true, 'message' => 'Member added!'];
        return ['success' => false, 'message' => 'Already a member.'];
    } catch (Throwable $e) {
        return ['success' => false, 'message' => 'Could not add member.'];
    }
}

function removeTeamMember(PDO $pdo, int $teamId, int $userId): array {
    $stmt = $pdo->prepare("DELETE FROM team_members WHERE team_id = ? AND user_id = ? AND role != 'captain'");
    $stmt->execute([$teamId, $userId]);
    if ($stmt->rowCount() > 0) return ['success' => true, 'message' => 'Member removed.'];
    return ['success' => false, 'message' => 'Cannot remove captain.'];
}

function deleteTeam(PDO $pdo, int $teamId, int $userId): array {
    try {
        // Verify user is captain
        $stmt = $pdo->prepare("SELECT role FROM team_members WHERE team_id = ? AND user_id = ?");
        $stmt->execute([$teamId, $userId]);
        $member = $stmt->fetch();
        if (!$member || $member['role'] !== 'captain') {
            return ['success' => false, 'message' => 'Only the team captain can delete the team.'];
        }
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM team_members WHERE team_id = ?")->execute([$teamId]);
        $pdo->prepare("DELETE FROM tournament_participants WHERE team_id = ?")->execute([$teamId]);
        $pdo->prepare("DELETE FROM tournament_matches WHERE team1_id = ? OR team2_id = ?")->execute([$teamId, $teamId]);
        $pdo->prepare("DELETE FROM tournament_results WHERE team_id = ?")->execute([$teamId]);
        $pdo->prepare("DELETE FROM teams WHERE id = ?")->execute([$teamId]);
        $pdo->commit();
        return ['success' => true, 'message' => 'Team deleted successfully.'];
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['success' => false, 'message' => 'Could not delete team.'];
    }
}

// ─── TEAM TOURNAMENT JOIN ─────────────────────────────────

function joinTournamentWithTeam(PDO $pdo, int $teamId, int $tournamentId, int $userId): array {
    try {
        // Verify user is captain
        $stmt = $pdo->prepare("SELECT role FROM team_members WHERE team_id = ? AND user_id = ?");
        $stmt->execute([$teamId, $userId]);
        $member = $stmt->fetch();
        if (!$member || $member['role'] !== 'captain') return ['success' => false, 'message' => 'Only the team captain can register.'];

        // Check already registered
        $stmt = $pdo->prepare("SELECT id FROM tournament_participants WHERE tournament_id = ? AND team_id = ? AND status = 'confirmed'");
        $stmt->execute([$tournamentId, $teamId]);
        if ($stmt->fetch()) return ['success' => false, 'message' => 'Team already registered.'];

        // Everything below takes row locks and moves money — it must run in a transaction.
        $pdo->beginTransaction();

        // Get tournament (lock row — closes capacity race)
        $stmt = $pdo->prepare("SELECT * FROM tournaments WHERE id = ? FOR UPDATE");
        $stmt->execute([$tournamentId]);
        $tournament = $stmt->fetch();
        if (!$tournament) { $pdo->rollBack(); return ['success' => false, 'message' => 'Tournament not found.']; }
        if (!in_array($tournament['status'], ['upcoming', 'live'])) { $pdo->rollBack(); return ['success' => false, 'message' => 'Registration closed.']; }

        // Check capacity
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM tournament_participants WHERE tournament_id = ? AND status = 'confirmed'");
        $stmt->execute([$tournamentId]);
        $count = (int)$stmt->fetchColumn();
        $max = (int)$tournament['max_teams'];
        if ($max > 0 && $count >= $max) { $pdo->rollBack(); return ['success' => false, 'message' => 'Tournament is full.']; }

        $entryFee = (float)$tournament['entry_fee'];

        if ($entryFee > 0) {
            // Deduct entry fee from captain's balance
            $stmt = $pdo->prepare("SELECT balance FROM users WHERE id = ? FOR UPDATE");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            $balance = (float)($user['balance'] ?? 0);
            if ($balance < $entryFee) {
                $pdo->rollBack();
                return ['success' => false, 'message' => 'Insufficient balance. Entry fee: ৳' . number_format($entryFee, 0)];
            }
            $newBalance = $balance - $entryFee;
            $pdo->prepare("UPDATE users SET balance = ? WHERE id = ?")->execute([$newBalance, $userId]);
            $pdo->prepare("INSERT INTO transactions (user_id, type, amount, balance_before, balance_after, description, purpose) VALUES (?, 'entry_fee', ?, ?, ?, ?, 'tournament_entry')")
                ->execute([$userId, $entryFee, $balance, $newBalance, 'Team entry fee for "' . mb_substr($tournament['title'] ?? '', 0, 150) . '"']);

            // Hold the fee in tournament escrow
            tnEscrowCollectFee($pdo, $tournamentId, $entryFee);
        }

        $teamStmt = $pdo->prepare("SELECT name FROM teams WHERE id = ?");
        $teamStmt->execute([$teamId]);
        $team = $teamStmt->fetch();
        try {
            $stmt = $pdo->prepare("INSERT INTO tournament_participants (tournament_id, user_id, team_id, team_name, fee_paid, status) VALUES (?, ?, ?, ?, ?, 'confirmed')");
            $stmt->execute([$tournamentId, $userId, $teamId, $team['name'] ?? 'Team', $entryFee > 0 ? 1 : 0]);
        } catch (PDOException $e) {
            $pdo->rollBack();
            if ((string) $e->getCode() === '23000') return ['success' => false, 'message' => 'Team already registered.'];
            throw $e;
        }

        $pdo->commit();
        createNotification($pdo, $userId, (int)($tournament['agent_id'] ?? null) ?: null, 'tournament',
            'Team "' . ($team['name'] ?? 'Team') . '" registered for "' . ($tournament['title'] ?? 'Tournament') . '".', $tournamentId);
        return ['success' => true, 'message' => 'Team registered for tournament!'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['success' => false, 'message' => 'Server error.'];
    }
}

// ─── BKASH PAYMENT (DEMO) ─────────────────────────────

function processBkashPayment(PDO $pdo, int $userId, float $amount, string $purpose = 'deposit', ?int $referenceId = null): array {
    try {
        $pdo->beginTransaction();
        
        // Get user balance with FOR UPDATE
        $stmt = $pdo->prepare("SELECT balance, username FROM users WHERE id = ? FOR UPDATE");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if (!$user) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'User not found.'];
        }
        
        $currentBalance = (float)$user['balance'];
        $newBalance = $currentBalance + $amount;
        
        // Update user balance
        $stmt = $pdo->prepare("UPDATE users SET balance = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$newBalance, $userId]);
        
        // Insert transaction record
        $stmt = $pdo->prepare("INSERT INTO transactions (user_id, type, amount, balance_before, balance_after, description, reference_id, purpose) VALUES (?, 'deposit', ?, ?, ?, ?, ?, ?)");
        $description = 'bKash payment of ৳' . $amount;
        $stmt->execute([$userId, $amount, $currentBalance, $newBalance, $description, $referenceId, $purpose]);
        
        // Log the activity using parameterized query (no SQL injection)
        $stmt = $pdo->prepare("SELECT id FROM activity_log WHERE user_id = ? AND action = 'payment_completed' AND reference_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 SECOND)");
        $stmt->execute([$userId, $referenceId]);
        if (!$stmt->fetch()) {
            $stmt = $pdo->prepare("INSERT INTO activity_log (user_id, username, action, details, reference_id, ip_address) VALUES (?, ?, 'payment_completed', ?, ?, ?)");
            $stmt->execute([$userId, $user['username'], 'bKash payment of ৳' . $amount, $referenceId, $_SERVER['REMOTE_ADDR'] ?? 'unknown']);
        }
        
        // Update bKash transaction status if reference ID is provided
        if ($referenceId) {
            $stmt = $pdo->prepare("UPDATE bkash_transactions SET status = 'completed', verified_at = NOW() WHERE id = ?");
            $stmt->execute([$referenceId]);
        }
        
        $pdo->commit();
        
        return [
            'success' => true,
            'message' => 'Payment of ৳' . number_format($amount, 2) . ' processed successfully.',
            'new_balance' => $newBalance
        ];
        
    } catch (Exception $e) {
        $pdo->rollBack();
        return ['success' => false, 'message' => 'Payment processing failed: ' . $e->getMessage()];
    }
}

function createTournamentByAgent(PDO $pdo, int $agentId, array $data): array {
    try {
        $pdo->beginTransaction();
        $prizeMoney = (float)str_replace(',', '', $data['prize_money'] ?? '0');

        // Deduct prize from agent's balance within the same transaction
        $stmt = $pdo->prepare("SELECT balance FROM users WHERE id = ? AND role = 'agent' FOR UPDATE");
        $stmt->execute([$agentId]);
        $user = $stmt->fetch();
        if (!$user) { $pdo->rollBack(); return ['success' => false, 'message' => 'Agent not found.']; }

        $before = (float)$user['balance'];
        if ($before < $prizeMoney) { $pdo->rollBack(); return ['success' => false, 'message' => 'Insufficient balance. Need ৳' . number_format($prizeMoney, 0) . ' but have ৳' . number_format($before, 0)]; }
        $after = $before - $prizeMoney;
        $stmt = $pdo->prepare("UPDATE users SET balance = ? WHERE id = ?");
        $stmt->execute([$after, $agentId]);

        $bracketType = in_array($data['bracket_type'] ?? '', ['single_elimination', 'double_elimination', 'round_robin'], true)
            ? $data['bracket_type'] : 'single_elimination';
        $bestOf = max(1, min(9, (int)($data['best_of'] ?? 1)));
        $checkinMinutes = max(5, min(180, (int)($data['checkin_minutes'] ?? 30)));

        $stmt = $pdo->prepare("INSERT INTO tournaments (title, description, rules, prize_money, category, max_teams, game_icon, accent_color, starts_at, status, entry_fee, agent_id, prize_breakdown, bracket_type, best_of, checkin_minutes, fee_escrow, prize_escrow) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'upcoming', ?, ?, ?, ?, ?, ?, 0, ?)");
        $stmt->execute([
            trim($data['title'] ?? ''),
            trim($data['description'] ?? ''),
            trim($data['rules'] ?? ''),
            $data['prize_money'] ?? '',
            trim($data['category'] ?? ''),
            (int)($data['max_teams'] ?? 0),
            trim($data['game_icon'] ?? 'fa-gamepad'),
            trim($data['accent_color'] ?? '#7c3aed'),
            trim($data['starts_at'] ?: null),
            (float)($data['entry_fee'] ?? 0),
            $agentId,
            $data['prize_breakdown'] ?? null,
            $bracketType,
            $bestOf,
            $checkinMinutes,
            $prizeMoney,
        ]);
        $tournamentId = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare("INSERT INTO agent_transactions (agent_id, type, amount, balance_before, balance_after, reference_type, reference_id, description) VALUES (?, 'debit', ?, ?, ?, 'tournament_prize', ?, 'Prize pool for tournament')");
        $stmt->execute([$agentId, $prizeMoney, $before, $after, $tournamentId]);

        $pdo->commit();
        return ['success' => true, 'message' => 'Tournament created! Prize of ৳' . number_format($prizeMoney, 0) . ' deducted from your balance.', 'tournament_id' => $tournamentId];
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['success' => false, 'message' => 'Could not create tournament.'];
    }
}

/* ================================================
   CLUB SYSTEM FUNCTIONS
   ================================================ */

function createClub(PDO $pdo, int $ownerId, string $name, string $tag, string $colour = '#7c3aed', ?string $description = null, ?string $region = null): array {
    if (strlen($tag) < 2 || strlen($tag) > 10) return ['success' => false, 'message' => 'Tag must be 2-10 characters.'];
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT id FROM clubs WHERE tag = ?");
        $stmt->execute([$tag]);
        if ($stmt->fetch()) { $pdo->rollBack(); return ['success' => false, 'message' => 'Tag already taken.']; }

        $stmt = $pdo->prepare("INSERT INTO clubs (name, tag, colour, description, owner_id, region) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$name, $tag, $colour, $description, $ownerId, $region]);
        $clubId = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare("INSERT INTO club_members (club_id, user_id, role) VALUES (?, ?, 'owner')");
        $stmt->execute([$clubId, $ownerId]);

        $pdo->commit();
        return ['success' => true, 'club_id' => $clubId, 'message' => 'Club created!'];
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['success' => false, 'message' => 'Could not create club.'];
    }
}

function getClub(PDO $pdo, int $clubId): ?array {
    $stmt = $pdo->prepare("SELECT c.*, u.username AS owner_name, u.full_name AS owner_full, u.avatar AS owner_avatar,
        (SELECT COUNT(*) FROM club_members WHERE club_id = c.id) AS member_count
        FROM clubs c JOIN users u ON u.id = c.owner_id WHERE c.id = ?");
    $stmt->execute([$clubId]);
    return $stmt->fetch() ?: null;
}

function getUserClubs(PDO $pdo, int $userId): array {
    $stmt = $pdo->prepare("SELECT c.*, cm.role AS my_role,
        (SELECT COUNT(*) FROM club_members WHERE club_id = c.id) AS member_count
        FROM clubs c JOIN club_members cm ON cm.club_id = c.id AND cm.user_id = ? ORDER BY c.created_at DESC");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function getClubMembers(PDO $pdo, int $clubId): array {
    $stmt = $pdo->prepare("SELECT u.id, u.username, u.full_name, u.avatar, u.nickname, cm.role, cm.joined_at
        FROM club_members cm JOIN users u ON u.id = cm.user_id WHERE cm.club_id = ? ORDER BY FIELD(cm.role,'owner','manager','player','sub'), cm.joined_at");
    $stmt->execute([$clubId]);
    return $stmt->fetchAll();
}

function joinClub(PDO $pdo, int $clubId, int $userId): array {
    $stmt = $pdo->prepare("SELECT id FROM club_members WHERE club_id = ? AND user_id = ?");
    $stmt->execute([$clubId, $userId]);
    if ($stmt->fetch()) return ['success' => false, 'message' => 'Already a member.'];

    try {
        $stmt = $pdo->prepare("INSERT INTO club_members (club_id, user_id, role) VALUES (?, ?, 'player')");
        $stmt->execute([$clubId, $userId]);
        return ['success' => true, 'message' => 'Joined club!'];
    } catch (Throwable $e) {
        return ['success' => false, 'message' => 'Could not join club.'];
    }
}

function leaveClub(PDO $pdo, int $clubId, int $userId): array {
    $stmt = $pdo->prepare("SELECT role FROM club_members WHERE club_id = ? AND user_id = ?");
    $stmt->execute([$clubId, $userId]);
    $member = $stmt->fetch();
    if (!$member) return ['success' => false, 'message' => 'Not a member.'];
    if ($member['role'] === 'owner') return ['success' => false, 'message' => 'Owner cannot leave. Transfer ownership first.'];

    try {
        $stmt = $pdo->prepare("DELETE FROM club_members WHERE club_id = ? AND user_id = ?");
        $stmt->execute([$clubId, $userId]);
        return ['success' => true, 'message' => 'Left club.'];
    } catch (Throwable $e) {
        return ['success' => false, 'message' => 'Could not leave club.'];
    }
}

function updateClub(PDO $pdo, int $clubId, int $userId, array $data): array {
    $stmt = $pdo->prepare("SELECT owner_id FROM clubs WHERE id = ?");
    $stmt->execute([$clubId]);
    $club = $stmt->fetch();
    if (!$club || (int)$club['owner_id'] !== $userId) return ['success' => false, 'message' => 'Only owner can update.'];

    $allowed = ['name','tag','colour','description','region','logo'];
    $sets = []; $params = [];
    foreach ($allowed as $k) {
        if (isset($data[$k])) { $sets[] = "$k = ?"; $params[] = $data[$k]; }
    }
    if (empty($sets)) return ['success' => false, 'message' => 'No data to update.'];
    $params[] = $clubId;

    try {
        $stmt = $pdo->prepare("UPDATE clubs SET " . implode(', ', $sets) . " WHERE id = ?");
        $stmt->execute($params);
        return ['success' => true, 'message' => 'Club updated.'];
    } catch (Throwable $e) {
        return ['success' => false, 'message' => 'Could not update club.'];
    }
}

/* ================================================
   PLAYER AUCTION FUNCTIONS
   ================================================ */

function listPlayerForAuction(PDO $pdo, int $userId, int $playerId, float $basePrice, int $durationHours = 24): array {
    // Check ownership
    $stmt = $pdo->prepare("SELECT id, owner_id, status FROM players WHERE id = ?");
    $stmt->execute([$playerId]);
    $player = $stmt->fetch();
    if (!$player) return ['success' => false, 'message' => 'Player not found.'];
    if ((int)$player['owner_id'] !== $userId) return ['success' => false, 'message' => 'Not your player.'];

    $endTime = date('Y-m-d H:i:s', time() + $durationHours * 3600);
    try {
        $stmt = $pdo->prepare("INSERT INTO player_auctions (player_id, seller_id, base_price, current_price, end_time) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$playerId, $userId, $basePrice, $basePrice, $endTime]);
        $stmt = $pdo->prepare("UPDATE players SET status = 'active', base_price = ? WHERE id = ?");
        $stmt->execute([$basePrice, $playerId]);
        return ['success' => true, 'message' => 'Player listed for auction!'];
    } catch (Throwable $e) {
        return ['success' => false, 'message' => 'Could not list player.'];
    }
}

function placeBid(PDO $pdo, int $auctionId, int $bidderId, float $amount): array {
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT * FROM player_auctions WHERE id = ? FOR UPDATE");
        $stmt->execute([$auctionId]);
        $auction = $stmt->fetch();
        if (!$auction || $auction['status'] !== 'active')
            { $pdo->rollBack(); return ['success' => false, 'message' => 'Auction not active.']; }
        if (time() > strtotime($auction['end_time']))
            { $pdo->rollBack(); return ['success' => false, 'message' => 'Auction ended.']; }
        if ($amount < $auction['current_price'] + $auction['min_increment'])
            { $pdo->rollBack(); return ['success' => false, 'message' => 'Bid too low. Min: ৳' . number_format($auction['current_price'] + $auction['min_increment'], 0) . '']; }

        $balStmt = $pdo->prepare("SELECT balance FROM users WHERE id = ? FOR UPDATE");
        $balStmt->execute([$bidderId]);
        $balance = (float)$balStmt->fetchColumn();
        if ($balance < $amount)
            { $pdo->rollBack(); return ['success' => false, 'message' => 'Insufficient balance.']; }

        $stmt = $pdo->prepare("INSERT INTO auction_bids (auction_id, bidder_id, amount) VALUES (?, ?, ?)");
        $stmt->execute([$auctionId, $bidderId, $amount]);
        $stmt = $pdo->prepare("UPDATE player_auctions SET current_price = ? WHERE id = ?");
        $stmt->execute([$amount, $auctionId]);
        $pdo->commit();
        return ['success' => true, 'current_price' => $amount, 'message' => 'Bid placed!'];
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['success' => false, 'message' => 'Could not place bid.'];
    }
}

function buyPlayerDirect(PDO $pdo, int $playerId, int $buyerId, float $price): array {
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT * FROM players WHERE id = ? FOR UPDATE");
        $stmt->execute([$playerId]);
        $player = $stmt->fetch();
        if (!$player || $player['status'] !== 'free_agent')
            { $pdo->rollBack(); return ['success' => false, 'message' => 'Player not available.']; }

        $balStmt = $pdo->prepare("SELECT balance FROM users WHERE id = ? FOR UPDATE");
        $balStmt->execute([$buyerId]);
        $balance = (float)$balStmt->fetchColumn();
        if ($balance < $price)
            { $pdo->rollBack(); return ['success' => false, 'message' => 'Insufficient balance.']; }

        // Deduct from buyer, credit to seller (if any) — both movements are ledgered.
        $prevOwnerId = !empty($player['owner_id']) ? (int) $player['owner_id'] : null;
        $nameStmt = $pdo->prepare("SELECT COALESCE(full_name, username) FROM users WHERE id = ?");
        $nameStmt->execute([(int) $player['user_id']]);
        $playerName = (string) ($nameStmt->fetchColumn() ?: ('Player #' . $playerId));
        $priceLabel = '৳' . number_format($price, 2);

        $buyerAfter = $balance - $price;
        $pdo->prepare("UPDATE users SET balance = ? WHERE id = ?")->execute([$buyerAfter, $buyerId]);
        $pdo->prepare("INSERT INTO transactions (user_id, type, amount, balance_before, balance_after, description, reference_id, purpose) VALUES (?, 'player_purchase', ?, ?, ?, ?, ?, 'player_purchase')")
            ->execute([$buyerId, $price, $balance, $buyerAfter, 'Signed ' . $playerName . ' for ' . $priceLabel, $playerId]);

        if ($prevOwnerId) {
            $ownerStmt = $pdo->prepare("SELECT balance FROM users WHERE id = ? FOR UPDATE");
            $ownerStmt->execute([$prevOwnerId]);
            $ownerBefore = (float) $ownerStmt->fetchColumn();
            $pdo->prepare("UPDATE users SET balance = ? WHERE id = ?")->execute([$ownerBefore + $price, $prevOwnerId]);
            $pdo->prepare("INSERT INTO transactions (user_id, type, amount, balance_before, balance_after, description, reference_id, purpose) VALUES (?, 'player_sale', ?, ?, ?, ?, ?, 'player_purchase')")
                ->execute([$prevOwnerId, $price, $ownerBefore, $ownerBefore + $price, 'Sold ' . $playerName . ' for ' . $priceLabel, $playerId]);
            createNotification($pdo, $prevOwnerId, $buyerId, 'transfer',
                'Your player ' . $playerName . ' was signed for ' . $priceLabel . '.', $playerId);
        }
        createNotification($pdo, $buyerId, $prevOwnerId ?: null, 'transfer',
            'You signed ' . $playerName . ' for ' . $priceLabel . '.', $playerId);

        $stmt = $pdo->prepare("UPDATE players SET owner_id = ?, current_club_id = NULL, status = 'active' WHERE id = ?");
        $stmt->execute([$buyerId, $playerId]);

        $stmt = $pdo->prepare("INSERT INTO player_transfers (player_id, from_owner_id, to_owner_id, amount, type) VALUES (?, ?, ?, ?, 'direct_sale')");
        $stmt->execute([$playerId, $prevOwnerId, $buyerId, $price]);

        $pdo->commit();
        return ['success' => true, 'message' => 'Player purchased!'];
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['success' => false, 'message' => 'Could not complete purchase.'];
    }
}

function releasePlayer(PDO $pdo, int $playerId, int $ownerId): array {
    $stmt = $pdo->prepare("SELECT id, owner_id, current_club_id FROM players WHERE id = ?");
    $stmt->execute([$playerId]);
    $player = $stmt->fetch();
    if (!$player || (int)$player['owner_id'] !== $ownerId) return ['success' => false, 'message' => 'Not your player.'];

    try {
        $fromClubId = $player['current_club_id'];
        $stmt = $pdo->prepare("UPDATE players SET owner_id = NULL, current_club_id = NULL, status = 'free_agent' WHERE id = ?");
        $stmt->execute([$playerId]);
        $stmt = $pdo->prepare("INSERT INTO player_transfers (player_id, from_club_id, from_owner_id, type) VALUES (?, ?, ?, 'release')");
        $stmt->execute([$playerId, $fromClubId, $ownerId]);
        return ['success' => true, 'message' => 'Player released.'];
    } catch (Throwable $e) {
        return ['success' => false, 'message' => 'Could not release player.'];
    }
}

function hirePlayerToClub(PDO $pdo, int $playerId, int $clubId, int $managerId): array {
    $pdo->beginTransaction();
    try {
        // Verify manager is owner/manager of club
        $stmt = $pdo->prepare("SELECT role FROM club_members WHERE club_id = ? AND user_id = ?");
        $stmt->execute([$clubId, $managerId]);
        $member = $stmt->fetch();
        if (!$member || !in_array($member['role'], ['owner','manager']))
            { $pdo->rollBack(); return ['success' => false, 'message' => 'Not authorized.']; }

        $stmt = $pdo->prepare("SELECT p.* FROM players p JOIN users u ON u.id = p.user_id WHERE p.id = ? FOR UPDATE");
        $stmt->execute([$playerId]);
        $player = $stmt->fetch();
        if (!$player) { $pdo->rollBack(); return ['success' => false, 'message' => 'Player not found.']; }

        // Check if player already in club as member
        $stmt = $pdo->prepare("SELECT id FROM club_members WHERE club_id = ? AND user_id = ?");
        $stmt->execute([$clubId, $player['user_id']]);
        if ($stmt->fetch()) { $pdo->rollBack(); return ['success' => false, 'message' => 'Already in club.']; }

        $stmt = $pdo->prepare("INSERT INTO club_members (club_id, user_id, role) VALUES (?, ?, 'player')");
        $stmt->execute([$clubId, $player['user_id']]);
        $stmt = $pdo->prepare("UPDATE players SET current_club_id = ? WHERE id = ?");
        $stmt->execute([$clubId, $playerId]);
        $stmt = $pdo->prepare("INSERT INTO player_transfers (player_id, to_club_id, to_owner_id, type) VALUES (?, ?, ?, 'hire')");
        $stmt->execute([$playerId, $clubId, $managerId]);

        $pdo->commit();
        return ['success' => true, 'message' => 'Player hired to club!'];
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['success' => false, 'message' => 'Could not hire player.'];
    }
}

function firePlayerFromClub(PDO $pdo, int $playerUserId, int $clubId, int $managerId): array {
    $stmt = $pdo->prepare("SELECT role FROM club_members WHERE club_id = ? AND user_id = ?");
    $stmt->execute([$clubId, $managerId]);
    $member = $stmt->fetch();
    if (!$member || !in_array($member['role'], ['owner','manager']))
        return ['success' => false, 'message' => 'Not authorized.'];

    try {
        $stmt = $pdo->prepare("DELETE FROM club_members WHERE club_id = ? AND user_id = ?");
        $stmt->execute([$clubId, $playerUserId]);
        $stmt = $pdo->prepare("UPDATE players SET current_club_id = NULL WHERE user_id = ? AND current_club_id = ?");
        $stmt->execute([$playerUserId, $clubId]);
        return ['success' => true, 'message' => 'Player removed from club.'];
    } catch (Throwable $e) {
        return ['success' => false, 'message' => 'Could not remove player.'];
    }
}

/* ================================================
   LEADERBOARD & PLAYER STATS
   ================================================ */

function getLeaderboard(PDO $pdo, ?int $tournamentId = null, ?int $clubId = null, int $limit = 50): array {
    $where = []; $params = [];
    if ($tournamentId) { $where[] = "tl.tournament_id = ?"; $params[] = $tournamentId; }
    if ($clubId) { $where[] = "cm.club_id = ?"; $params[] = $clubId; }

    $sql = "SELECT tl.*, u.username, u.full_name, u.avatar, u.nickname,
            COALESCE((SELECT cm.club_id FROM club_members cm WHERE cm.user_id = tl.user_id LIMIT 1), 0) AS club_id,
            COALESCE((SELECT c.name FROM club_members cm JOIN clubs c ON c.id = cm.club_id WHERE cm.user_id = tl.user_id LIMIT 1), '') AS club_name,
            COALESCE((SELECT c.colour FROM club_members cm JOIN clubs c ON c.id = cm.club_id WHERE cm.user_id = tl.user_id LIMIT 1), '#7c3aed') AS club_colour
            FROM tournament_leaderboard tl
            JOIN users u ON u.id = tl.user_id";
    if ($clubId) { $sql .= " JOIN club_members cm ON cm.user_id = tl.user_id"; }
    if (!empty($where)) $sql .= " WHERE " . implode(' AND ', $where);
    $sql .= " ORDER BY tl.total_points DESC, tl.total_prize DESC LIMIT ?";
    $params[] = $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getPlayerDetail(PDO $pdo, int $userId): ?array {
    $stmt = $pdo->prepare("SELECT p.*, u.username, u.full_name, u.avatar, u.nickname, u.registered_at,
        COALESCE((SELECT c.id FROM club_members cm JOIN clubs c ON c.id = cm.club_id WHERE cm.user_id = p.user_id LIMIT 1), NULL) AS club_id,
        COALESCE((SELECT c.name FROM club_members cm JOIN clubs c ON c.id = cm.club_id WHERE cm.user_id = p.user_id LIMIT 1), NULL) AS club_name,
        COALESCE((SELECT c.colour FROM club_members cm JOIN clubs c ON c.id = cm.club_id WHERE cm.user_id = p.user_id LIMIT 1), NULL) AS club_colour,
        COALESCE((SELECT c.logo FROM club_members cm JOIN clubs c ON c.id = cm.club_id WHERE cm.user_id = p.user_id LIMIT 1), NULL) AS club_logo
        FROM players p JOIN users u ON u.id = p.user_id WHERE p.user_id = ?");
    $stmt->execute([$userId]);
    $player = $stmt->fetch();
    if (!$player) return null;

    // Stats
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(goals),0) AS total_goals, COALESCE(SUM(assists),0) AS total_assists, COALESCE(SUM(matches_played),0) AS total_matches, COALESCE(SUM(wins),0) AS total_wins, COALESCE(SUM(kills),0) AS total_kills FROM player_stats WHERE user_id = ?");
    $stmt->execute([$userId]);
    $player['stats'] = $stmt->fetch();

    // Transfer history
    $stmt = $pdo->prepare("SELECT pt.*, fc.name AS from_club_name, tc.name AS to_club_name, fu.username AS from_owner_name, tu.username AS to_owner_name FROM player_transfers pt LEFT JOIN clubs fc ON fc.id = pt.from_club_id LEFT JOIN clubs tc ON tc.id = pt.to_club_id LEFT JOIN users fu ON fu.id = pt.from_owner_id LEFT JOIN users tu ON tu.id = pt.to_owner_id WHERE pt.player_id = ? ORDER BY pt.created_at DESC LIMIT 20");
    $stmt->execute([$playerId = $player['id']]);
    $player['transfers'] = $stmt->fetchAll();

    return $player;
}

function getMarketPlayers(PDO $pdo, string $status = 'free_agent', ?int $clubId = null, int $limit = 50): array {
    $where = ["p.status = ?"]; $params = [$status];
    if ($clubId) { $where[] = "p.current_club_id = ?"; $params[] = $clubId; }

    $sql = "SELECT p.*, u.username, u.full_name, u.avatar, u.nickname,
            COALESCE((SELECT COUNT(*) FROM player_auctions WHERE player_id = p.id AND status = 'active'), 0) AS has_active_auction,
            COALESCE((SELECT current_price FROM player_auctions WHERE player_id = p.id AND status = 'active' ORDER BY start_time DESC LIMIT 1), p.market_value) AS display_price,
            COALESCE((SELECT COUNT(*) FROM auction_bids ab JOIN player_auctions pa ON pa.id = ab.auction_id WHERE pa.player_id = p.id AND pa.status = 'active'), 0) AS bid_count
            FROM players p JOIN users u ON u.id = p.user_id
            WHERE " . implode(' AND ', $where) . " ORDER BY p.market_value DESC LIMIT ?";
    $params[] = $limit;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Ensure the user has a player card in the market. Everybody is a free agent
 * until someone signs them — without a row a user can never be traded.
 */
function ensurePlayerProfile(PDO $pdo, int $userId): int {
    if ($userId <= 0) return 0;
    try {
        $stmt = $pdo->prepare("SELECT id FROM players WHERE user_id = ?");
        $stmt->execute([$userId]);
        $existing = (int) $stmt->fetchColumn();
        if ($existing > 0) return $existing;

        $pdo->prepare("INSERT INTO players (user_id, owner_id, status, market_value, base_price, rating) VALUES (?, NULL, 'free_agent', 0, 0, 0)")
            ->execute([$userId]);
        return (int) $pdo->lastInsertId();
    } catch (Throwable $e) {
        return 0;
    }
}

/** Players the user currently owns (bought at auction or direct) — the squad they manage. */
function getMyPlayers(PDO $pdo, int $ownerId, int $limit = 50): array {
    $stmt = $pdo->prepare("SELECT p.*, u.username, u.full_name, u.avatar, u.nickname, c.name AS club_name, c.tag AS club_tag, c.colour AS club_colour,
        COALESCE((SELECT COUNT(*) FROM player_auctions WHERE player_id = p.id AND status = 'active'), 0) AS has_active_auction,
        COALESCE((SELECT current_price FROM player_auctions WHERE player_id = p.id AND status = 'active' ORDER BY start_time DESC LIMIT 1), p.market_value) AS display_price
        FROM players p
        JOIN users u ON u.id = p.user_id
        LEFT JOIN clubs c ON c.id = p.current_club_id
        WHERE p.owner_id = ? ORDER BY p.market_value DESC, p.id DESC LIMIT ?");
    $stmt->execute([$ownerId, $limit]);
    return $stmt->fetchAll();
}

/**
 * Set the asking price shown in the free-agent market. The player themself or
 * their current owner can set it — this is what buyers pay via buy_player.
 */
function setPlayerMarketValue(PDO $pdo, int $playerId, int $userId, float $value): array {
    $value = max(0.0, min(10000000.0, round($value, 2)));

    $stmt = $pdo->prepare("SELECT user_id, owner_id FROM players WHERE id = ?");
    $stmt->execute([$playerId]);
    $player = $stmt->fetch();
    if (!$player) return ['success' => false, 'message' => 'Player not found.'];

    $isSelf = (int) $player['user_id'] === $userId;
    $isOwner = !empty($player['owner_id']) && (int) $player['owner_id'] === $userId;
    if (!$isSelf && !$isOwner) return ['success' => false, 'message' => 'You cannot price this player.'];

    try {
        $pdo->prepare("UPDATE players SET market_value = ? WHERE id = ?")->execute([$value, $playerId]);
        return ['success' => true, 'message' => 'Market value set to ৳' . number_format($value, 0) . '.'];
    } catch (Throwable $e) {
        return ['success' => false, 'message' => 'Could not update the market value.'];
    }
}

function getClubStandings(PDO $pdo): array {
    $stmt = $pdo->prepare("SELECT c.*,
        (SELECT COUNT(*) FROM club_members WHERE club_id = c.id) AS member_count,
        (SELECT COALESCE(SUM(tl.total_points),0) FROM tournament_leaderboard tl JOIN club_members cm ON cm.user_id = tl.user_id WHERE cm.club_id = c.id) AS total_club_points
        FROM clubs c ORDER BY total_club_points DESC, c.trophies DESC");
    $stmt->execute();
    return $stmt->fetchAll();
}

function getActiveAuctions(PDO $pdo, int $limit = 30): array {
    $stmt = $pdo->prepare("SELECT pa.*, p.user_id AS player_user_id, u.username AS seller_name, p2.username AS player_name, p2.full_name AS player_full, p2.avatar AS player_avatar,
        (SELECT COUNT(*) FROM auction_bids WHERE auction_id = pa.id) AS total_bids,
        COALESCE((SELECT MAX(amount) FROM auction_bids WHERE auction_id = pa.id), pa.base_price) AS highest_bid
        FROM player_auctions pa
        JOIN players p ON p.id = pa.player_id
        JOIN users u ON u.id = pa.seller_id
        JOIN users p2 ON p2.id = p.user_id
        WHERE pa.status = 'active' AND pa.end_time > NOW()
        ORDER BY pa.end_time ASC LIMIT ?");
    $stmt->execute([$limit]);
    return $stmt->fetchAll();
}

function settleExpiredAuctions(PDO $pdo): int {
    $count = 0;
    try {
        $stmt = $pdo->query("SELECT * FROM player_auctions WHERE status = 'active' AND end_time <= NOW()");
        $expired = $stmt->fetchAll();
        foreach ($expired as $auction) {
            $pdo->beginTransaction();
            try {
                // Get highest bidder
                $bidStmt = $pdo->prepare("SELECT * FROM auction_bids WHERE auction_id = ? ORDER BY amount DESC LIMIT 1");
                $bidStmt->execute([$auction['id']]);
                $topBid = $bidStmt->fetch();

                if ($topBid) {
                    $amount = (float) $topBid['amount'];
                    $winnerId = (int) $topBid['bidder_id'];
                    $sellerId = (int) $auction['seller_id'];
                    $playerRefId = (int) $auction['player_id'];

                    $nameStmt = $pdo->prepare("SELECT COALESCE(u.full_name, u.username) FROM players p JOIN users u ON u.id = p.user_id WHERE p.id = ?");
                    $nameStmt->execute([$playerRefId]);
                    $playerName = (string) ($nameStmt->fetchColumn() ?: ('Player #' . $playerRefId));
                    $amountLabel = '৳' . number_format($amount, 2);

                    // The winning bidder must still be able to pay — verify before moving money.
                    $balStmt = $pdo->prepare("SELECT balance FROM users WHERE id = ? FOR UPDATE");
                    $balStmt->execute([$winnerId]);
                    $winnerBefore = (float) $balStmt->fetchColumn();

                    if ($winnerBefore < $amount) {
                        $stmt = $pdo->prepare("UPDATE player_auctions SET status = 'cancelled' WHERE id = ? AND status = 'active'");
                        $stmt->execute([$auction['id']]);
                        createNotification($pdo, $sellerId, $winnerId, 'auction',
                            'Auction for ' . $playerName . ' was cancelled — the top bidder could not pay.', $playerRefId);
                    } else {
                        // Claim the auction atomically so a concurrent sweep cannot settle it twice.
                        $claim = $pdo->prepare("UPDATE player_auctions SET status = 'completed', winner_id = ?, final_price = ? WHERE id = ? AND status = 'active'");
                        $claim->execute([$winnerId, $amount, $auction['id']]);
                        if ($claim->rowCount() === 0) { $pdo->rollBack(); continue; }

                        $sellerCut = round($amount * 0.95, 2); // 5% platform fee
                        $platformFee = round($amount - $sellerCut, 2);

                        $pdo->prepare("UPDATE users SET balance = ? WHERE id = ?")->execute([$winnerBefore - $amount, $winnerId]);
                        $pdo->prepare("INSERT INTO transactions (user_id, type, amount, balance_before, balance_after, description, reference_id, purpose) VALUES (?, 'player_purchase', ?, ?, ?, ?, ?, 'player_auction')")
                            ->execute([$winnerId, $amount, $winnerBefore, $winnerBefore - $amount,
                                'Won auction for ' . $playerName . ' at ' . $amountLabel, $playerRefId]);

                        $sellerStmt = $pdo->prepare("SELECT balance FROM users WHERE id = ? FOR UPDATE");
                        $sellerStmt->execute([$sellerId]);
                        $sellerBefore = (float) $sellerStmt->fetchColumn();
                        $pdo->prepare("UPDATE users SET balance = ? WHERE id = ?")->execute([$sellerBefore + $sellerCut, $sellerId]);
                        $pdo->prepare("INSERT INTO transactions (user_id, type, amount, balance_before, balance_after, description, reference_id, purpose) VALUES (?, 'player_sale', ?, ?, ?, ?, ?, 'player_auction')")
                            ->execute([$sellerId, $sellerCut, $sellerBefore, $sellerBefore + $sellerCut,
                                'Sold ' . $playerName . ' at auction for ' . $amountLabel . ' (platform fee ৳' . number_format($platformFee, 2) . ')', $playerRefId]);

                        // Update player ownership
                        $stmt = $pdo->prepare("UPDATE players SET owner_id = ?, status = 'active', base_price = 0 WHERE id = ?");
                        $stmt->execute([$winnerId, $playerRefId]);

                        $stmt = $pdo->prepare("INSERT INTO player_transfers (player_id, from_owner_id, to_owner_id, amount, type) VALUES (?, ?, ?, ?, 'auction')");
                        $stmt->execute([$playerRefId, $sellerId, $winnerId, $amount]);

                        createNotification($pdo, $winnerId, $sellerId, 'auction',
                            'You won the auction for ' . $playerName . ' at ' . $amountLabel . '.', $playerRefId);
                        createNotification($pdo, $sellerId, $winnerId, 'auction',
                            $playerName . ' sold at auction for ' . $amountLabel . ' — ৳' . number_format($sellerCut, 2) . ' credited.', $playerRefId);
                    }
                } else {
                    $stmt = $pdo->prepare("UPDATE player_auctions SET status = 'cancelled' WHERE id = ?");
                    $stmt->execute([$auction['id']]);
                }
                $pdo->commit();
                $count++;
            } catch (Throwable $e) {
                $pdo->rollBack();
            }
        }
    } catch (Throwable $e) {}
    return $count;
}

