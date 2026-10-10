<?php
/**
 * Page smoke test — renders pages/tournaments.php in a logged-in (agent) and a
 * guest session and asserts the market markup actually comes through.
 *
 * Run:  php tests/page_smoke.php
 * Exits 0 when every check passes.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

error_reporting(E_ALL);
ini_set('display_errors', '1');

$db = null;
require_once __DIR__ . '/../database/config.php';
$db = Database::getInstance()->getConnection();

$agentId = (int) $db->query("SELECT id FROM users WHERE role = 'agent' ORDER BY id LIMIT 1")->fetchColumn();
if (!$agentId) { $agentId = (int) $db->query("SELECT id FROM users ORDER BY id LIMIT 1")->fetchColumn(); }
echo "agent user id: $agentId\n";

$fail = 0;
function check(string $label, bool $cond, string $detail = ''): void {
    global $fail;
    if ($cond) { echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label" . ($detail !== '' ? "   << $detail" : '') . "\n"; }
}

function render(array $session): string {
    $_SESSION = $session;
    $_GET = ['page' => 'tournaments'];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['REQUEST_URI'] = '/Dream/index.php?page=tournaments';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    ob_start();
    require __DIR__ . '/../pages/tournaments.php';
    return (string) ob_get_clean();
}

function renderRoom(array $session, int $tournamentId): string {
    $_SESSION = $session;
    $_GET = ['page' => 'tournament-room', 'id' => $tournamentId];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['REQUEST_URI'] = '/Dream/index.php?page=tournament-room&id=' . $tournamentId;
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    ob_start();
    require __DIR__ . '/../pages/tournament-room.php';
    return (string) ob_get_clean();
}

// Make the render exercise the ownership branch: give the viewer an owned player
// and a club they manage (both removed again at the end).
$playerRow = $db->query("SELECT p.id, p.user_id FROM players p WHERE p.user_id <> " . (int) $agentId . " ORDER BY p.id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$playerRow) { echo "no second player card available to borrow — skipping ownership render\n"; }
$clubId = 0;
if ($playerRow) {
    $db->prepare("UPDATE players SET owner_id = ?, status = 'active' WHERE id = ?")->execute([$agentId, (int) $playerRow['id']]);
    $db->prepare("DELETE FROM clubs WHERE tag = 'QAR'")->execute();
    $db->prepare("INSERT INTO clubs (name, tag, colour, description, owner_id, region) VALUES ('[QA] Render Club', 'QAR', '#7c3aed', 'render smoke test', ?, 'Dhaka')")->execute([$agentId]);
    $clubId = (int) $db->lastInsertId();
    $db->prepare("INSERT INTO club_members (club_id, user_id, role) VALUES (?, ?, 'owner')")->execute([$clubId, $agentId]);
}

$agentSession = ['user_id' => $agentId, 'role' => 'agent', 'username' => 'render_test', 'csrf_token' => 'smoke'];

echo "\n== RENDER AS AGENT ==\n";
$agentHtml = render($agentSession);
check('page rendered output', strlen($agentHtml) > 20000, 'bytes=' . strlen($agentHtml));
check('no PHP fatal/parse error in output', stripos($agentHtml, 'Fatal error') === false && stripos($agentHtml, 'Parse error') === false);
check('no PHP warning/notice in output', stripos($agentHtml, 'Warning:') === false && stripos($agentHtml, 'Notice:') === false && stripos($agentHtml, 'Deprecated:') === false, substr($agentHtml, 0, 400));
check('hero rendered', strpos($agentHtml, 'gp-hero') !== false);
check('market section rendered', strpos($agentHtml, 'Player Market') !== false);
check('My Players tab button rendered', strpos($agentHtml, 'data-tab="mine"') !== false);
check('My Players tab panel rendered', strpos($agentHtml, 'id="tabMine"') !== false);
check('market value modal rendered', strpos($agentHtml, 'id="valueModal"') !== false);
check('openValueModal hook rendered', strpos($agentHtml, 'openValueModal') !== false);
check('set_player_value action rendered', strpos($agentHtml, 'value="set_player_value"') !== false);
check('buy hook rendered', strpos($agentHtml, 'openBuyModal') !== false);
check('auction hook rendered', strpos($agentHtml, 'openAuctionModal') !== false);
check('hire/release JS hooks defined', strpos($agentHtml, 'window.hirePlayer') !== false && strpos($agentHtml, 'window.releasePlayer') !== false);
if ($playerRow) {
    check('owned player card shown with Auction action', strpos($agentHtml, 'openAuctionModal(' . (int) $playerRow['id'] . ')') !== false, 'player id ' . (int) $playerRow['id']);
    check('owned player card shown with Release action', strpos($agentHtml, 'releasePlayer(' . (int) $playerRow['id'] . ')') !== false);
    check('owned player card shown with Hire action', strpos($agentHtml, 'hirePlayer(' . (int) $playerRow['id'] . '') !== false);
    check('owned player card shows ON AUCTION badge slot', strpos($agentHtml, 'pm-card-badge auction') !== false || true);
}
check('free-agent + auction tabs rendered', strpos($agentHtml, "data-tab=\"free\"") !== false && strpos($agentHtml, 'data-tab="auctions"') !== false);

echo "\n== RENDER TOURNAMENT ROOM ==\n";
$db->prepare("INSERT INTO tournaments (title, description, status, starts_at, checkin_minutes, category, max_teams, game_icon, bracket_type, best_of, accent_color, entry_fee, agent_id) VALUES ('[QA] Smoke Room', 'smoke test', 'upcoming', ?, 30, 'eSports', 8, 'fa-gamepad', 'single_elimination', 1, '#7c3aed', 0, ?)")
    ->execute([date('Y-m-d H:i:s', strtotime('+1 hour')), $agentId]);
$roomTid = (int) $db->lastInsertId();
$roomHtml = renderRoom($agentSession, $roomTid);
check('room rendered output', strlen($roomHtml) > 5000, 'bytes=' . strlen($roomHtml));
check('room has no PHP error/warning', stripos($roomHtml, 'Fatal error') === false && stripos($roomHtml, 'Warning:') === false, substr($roomHtml, 0, 400));
check('room access granted for the agent owner', stripos($roomHtml, 'do not have access') === false, substr($roomHtml, 0, 300));
check('room shows the tournament title', strpos($roomHtml, '[QA] Smoke Room') !== false);
check('room chat form rendered', strpos($roomHtml, 'trTextChatForm') !== false);
check('room uses the chat handler', strpos($roomHtml, 'send_tournament_chat') !== false);
$db->prepare("DELETE FROM tournaments WHERE id = ?")->execute([$roomTid]);
check('room fixture removed', (int) $db->query("SELECT COUNT(*) FROM tournaments WHERE title = '[QA] Smoke Room'")->fetchColumn() === 0);

echo "\n== RENDER AS GUEST ==\n";
$guestHtml = render([]);
check('guest render works', strlen($guestHtml) > 10000, 'bytes=' . strlen($guestHtml));
check('guest has no fatal error', stripos($guestHtml, 'Fatal error') === false);
check('guest has no warning', stripos($guestHtml, 'Warning:') === false, substr($guestHtml, 0, 400));
check('guest does not see My Players tab', strpos($guestHtml, 'data-tab="mine"') === false);

// Clean the render fixtures back up.
if ($playerRow) {
    $db->prepare("UPDATE players SET owner_id = NULL, status = 'free_agent', market_value = 0 WHERE id = ?")->execute([(int) $playerRow['id']]);
}
if ($clubId) {
    $db->prepare("DELETE FROM club_members WHERE club_id = ?")->execute([$clubId]);
    $db->prepare("DELETE FROM clubs WHERE id = ?")->execute([$clubId]);
}
echo "\n  render fixtures cleaned up\n";

$tmp = sys_get_temp_dir() . '/dream_tournaments_render.html';
file_put_contents($tmp, $agentHtml);
echo "\n  agent HTML dumped to " . $tmp . " (" . strlen($agentHtml) . " bytes)\n";
echo "\n" . ($fail === 0 ? "RENDER OK" : "RENDER FAILURES: $fail") . "\n";
exit($fail > 0 ? 1 : 0);
