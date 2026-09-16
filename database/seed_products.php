<?php
/**
 * Seed script to populate products table from static data.
 * Run: php database/seed_products.php
 * Safe to re-run (uses INSERT IGNORE / idempotent checks).
 */

require_once __DIR__ . '/config.php';

$db = Database::getInstance()->getConnection();

echo "Seeding products data...\n";

// ── Author data ──
$authors = [
    ['id' => 1, 'name' => 'Robiul Islam', 'avatar' => 'default.png', 'title' => 'Game Strategy Expert'],
    ['id' => 2, 'name' => 'Shahriar Kabir', 'avatar' => 'default.png', 'title' => 'Esports Coach'],
    ['id' => 3, 'name' => 'Nusrat Jahan', 'avatar' => 'default.png', 'title' => 'Pro Gamer & Guide Creator'],
];

// Ensure author users exist
foreach ($authors as $author) {
    $stmt = $db->prepare("SELECT id FROM users WHERE id = ?");
    $stmt->execute([$author['id']]);
    if (!$stmt->fetch()) {
        // Create the user if not exists
        $stmt = $db->prepare("
            INSERT INTO users (id, username, email, password_hash, full_name, avatar, bio, role, balance)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'user', 10000.00)
        ");
        $stmt->execute([
            $author['id'],
            strtolower(str_replace(' ', '', $author['name'])),
            strtolower(str_replace(' ', '.', $author['name'])) . '@dreambd.test',
            password_hash('password123', PASSWORD_DEFAULT),
            $author['name'],
            $author['avatar'],
            $author['title'],
        ]);
        echo "  Created user: {$author['name']} (ID: {$author['id']})\n";
    } else {
        // Update existing user's bio with title if empty
        $stmt = $db->prepare("UPDATE users SET bio = COALESCE(NULLIF(bio, ''), ?) WHERE id = ?");
        $stmt->execute([$author['title'], $author['id']]);
    }
}

// ── Product data ──
$products = [
    [
        'id' => 101, 'author_id' => 1,
        'name' => 'Free Fire Master Guide 2026',
        'description' => 'Ultimate strategy guide with tips, tricks, and advanced gameplay techniques for Free Fire. Covers all weapons, maps, characters, and ranked strategies.',
        'short_desc' => 'Complete Free Fire strategy guide',
        'price' => 299, 'image' => 'https://picsum.photos/seed/ff-guide/400/300',
        'badge' => 'Bestseller', 'badge_color' => '#f59e0b',
        'rating' => 4.8, 'sales' => 1240, 'pages' => 156,
        'category' => 'pdf', 'preview_text' => 'This comprehensive Free Fire guide covers everything from beginner basics to advanced pro strategies. You will learn about weapon stats, recoil patterns, movement techniques, map rotations, character abilities, and team coordination. Each chapter includes detailed explanations with screenshots and real-game examples from top-ranked players.',
        'content_long' => null,
    ],
    [
        'id' => 102, 'author_id' => 1,
        'name' => 'PUBG Mobile Pro Handbook',
        'description' => 'Complete PUBG Mobile guide covering maps, weapons, strategies, and battle royale tactics from pro players.',
        'short_desc' => 'Pro-level PUBG Mobile tactics',
        'price' => 249, 'image' => 'https://picsum.photos/seed/pubg-handbook/400/300',
        'badge' => 'Popular', 'badge_color' => '#3b82f6',
        'rating' => 4.6, 'sales' => 890, 'pages' => 134,
        'category' => 'pdf',
        'preview_text' => 'Master PUBG Mobile with this comprehensive pro handbook. Learn drop strategies for every map, weapon mastery guides, vehicle tactics, squad communication frameworks, and advanced rotation patterns.',
        'content_long' => null,
    ],
    [
        'id' => 103, 'author_id' => 2,
        'name' => 'MLBB Hero Tier List 2026',
        'description' => 'Updated Mobile Legends tier list with best builds, emblems, and counter picks for every hero in the current meta.',
        'short_desc' => 'Up-to-date MLBB meta guide',
        'price' => 199, 'image' => 'https://picsum.photos/seed/mlbb-guide/400/300',
        'badge' => '', 'badge_color' => '',
        'rating' => 4.5, 'sales' => 670, 'pages' => 98,
        'category' => 'pdf',
        'preview_text' => 'Stay ahead of the Mobile Legends meta with this regularly updated tier list guide. Every hero is ranked with detailed analysis of their strengths, weaknesses, optimal builds, emblem setups, and counter picks.',
        'content_long' => null,
    ],
    [
        'id' => 104, 'author_id' => 3,
        'name' => 'Valorant Aim Training Guide',
        'description' => 'Professional aim training routines, crosshair placements, and practice maps for Valorant.',
        'short_desc' => 'Aim like a pro in Valorant',
        'price' => 349, 'image' => 'https://picsum.photos/seed/valorant-aim/400/300',
        'badge' => 'New', 'badge_color' => '#10b981',
        'rating' => 4.9, 'sales' => 430, 'pages' => 112,
        'category' => 'pdf',
        'preview_text' => 'Transform your aim with this professional Valorant training guide. Covers fundamental aiming mechanics including crosshair placement, flick shots, tracking, peeking techniques, and spray control.',
        'content_long' => null,
    ],
    [
        'id' => 105, 'author_id' => 2,
        'name' => 'COD Mobile Championship Guide',
        'description' => 'Competitive COD Mobile guide featuring pro loadouts, map strategies, and tournament-winning tactics.',
        'short_desc' => 'Compete like a champion',
        'price' => 279, 'image' => 'https://picsum.photos/seed/codm-guide/400/300',
        'badge' => 'Trending', 'badge_color' => '#8b5cf6',
        'rating' => 4.7, 'sales' => 560, 'pages' => 128,
        'category' => 'pdf',
        'preview_text' => 'Dominate COD Mobile with this championship-level guide. Features pro player loadouts for every game mode, map-specific strategies, spawn control techniques, and team coordination frameworks.',
        'content_long' => null,
    ],
];

$inserted = 0;
foreach ($products as $p) {
    $stmt = $db->prepare("SELECT id FROM products WHERE id = ?");
    $stmt->execute([$p['id']]);
    if ($stmt->fetch()) {
        // Update existing
        $stmt = $db->prepare("
            UPDATE products SET
                name = ?, description = ?, short_desc = ?, price = ?, image = ?,
                badge = ?, badge_color = ?, rating = ?, sales = ?, pages = ?,
                category = ?, preview_text = ?, content_long = ?,
                author_id = ?, status = 'active'
            WHERE id = ?
        ");
        $stmt->execute([
            $p['name'], $p['description'], $p['short_desc'], $p['price'], $p['image'],
            $p['badge'] ?: null, $p['badge_color'] ?: null, $p['rating'], $p['sales'], $p['pages'],
            $p['category'], $p['preview_text'], $p['content_long'],
            $p['author_id'], $p['id'],
        ]);
        echo "  Updated product: {$p['name']}\n";
    } else {
        // Insert new
        $stmt = $db->prepare("
            INSERT INTO products (id, name, description, short_desc, price, image,
                badge, badge_color, rating, sales, pages,
                category, preview_text, content_long, author_id, stock, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 'active')
        ");
        $stmt->execute([
            $p['id'], $p['name'], $p['description'], $p['short_desc'], $p['price'], $p['image'],
            $p['badge'] ?: null, $p['badge_color'] ?: null, $p['rating'], $p['sales'], $p['pages'],
            $p['category'], $p['preview_text'], $p['content_long'],
            $p['author_id'],
        ]);
        $inserted++;
        echo "  Inserted product: {$p['name']}\n";
    }
}

echo "\nDone! $inserted new products inserted, " . (count($products) - $inserted) . " updated.\n";
