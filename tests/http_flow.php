<?php
/**
 * End-to-end HTTP flow test for the tournament handler.
 *
 * Boots the built-in PHP server, signs in real QA sessions (native PHP sessions
 * are shared between CLI and the server), then drives the whole tournament /
 * team / club / player-market surface through handlers/tournament_handler.php
 * exactly as the browser would — JSON POST + CSRF token.
 *
 * Run:  php tests/http_flow.php
 * Exits 0 when every check passes.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once __DIR__ . '/../database/config.php';

$ROOT = dirname(__DIR__);
$PORT = 8933;
$BASE = "http://127.0.0.1:$PORT";
$P = 0; $F = 0; $FAILS = [];

function ck(string $label, bool $cond, string $detail = ''): void {
    global $P, $F, $FAILS;
    if ($cond) { $P++; echo "  PASS  $label\n"; }
    else { $F++; $FAILS[] = $label; echo "  FAIL  $label" . ($detail !== '' ? "   << $detail" : '') . "\n"; }
}
function sec(string $t): void { echo "\n== $t ==\n"; }

/** Start the built-in server and wait for the port. */
function startServer(string $root, int $port) {
    $log = sys_get_temp_dir() . '/dream_php_server.log';
    @unlink($log);
    $spec = [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']];
    $proc = proc_open(['php', '-S', "127.0.0.1:$port", '-t', $root], $spec, $pipes, $root);
    if (!is_resource($proc)) { fwrite(STDERR, "could not start php -S\n"); exit(2); }
    for ($i = 0; $i < 80; $i++) {
        $h = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.25);
        if ($h) { fclose($h); return [$proc, $log]; }
        usleep(100000);
    }
    fwrite(STDERR, "server did not come up; log:\n" . (string) @file_get_contents($log) . "\n");
    exit(2);
}

/** Create a native session the server will accept; returns [sid, csrf]. */
function makeSession(array $session): array {
    $sid = 'flow' . bin2hex(random_bytes(8));
    session_id($sid);
    session_start();
    $_SESSION = $session;
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    $token = $_SESSION['csrf_token'];
    session_write_close();
    return [$sid, $token];
}

/** POST JSON to a URL with the session cookie; returns [status, decoded array]. */
function post(string $url, array $data, ?string $sid): array {
    $headers = "Content-Type: application/json\r\nX-Requested-With: XMLHttpRequest\r\n";
    if ($sid) $headers .= "Cookie: PHPSESSID=$sid\r\n";
    $ctx = stream_context_create(['http' => [
        'method' => 'POST', 'header' => $headers,
        'content' => json_encode($data, JSON_UNESCAPED_UNICODE),
        'ignore_errors' => true, 'timeout' => 20,
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    $status = 0;
    foreach (($http_response_header ?? []) as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) { $status = (int) $m[1]; }
    }
    if ($body === false) return [$status ?: 0, ['_transport' => true]];
    $json = json_decode($body, true);
    if (!is_array($json)) return [$status, ['_not_json' => substr((string) $body, 0, 400)]];
    $json['_status'] = $status;
    return [$status, $json];
}

// ─────────────────────────────────────────────────────────────── bootstrap
$db = Database::getInstance()->getConnection();

function qaCleanupHttp(PDO $db): void {
    $uids = $db->query("SELECT id FROM users WHERE username LIKE 'qaf\\_%'")->fetchAll(PDO::FETCH_COLUMN);
    $tids = $db->query("SELECT id FROM tournaments WHERE title LIKE '[QAF]%'")->fetchAll(PDO::FETCH_COLUMN);
    $clubIds = $db->query("SELECT id FROM clubs WHERE tag LIKE 'QAF%'")->fetchAll(PDO::FETCH_COLUMN);
    $teamIds = $db->query("SELECT id FROM teams WHERE name LIKE '[QAF]%'")->fetchAll(PDO::FETCH_COLUMN);
    $aucIds = $uids ? $db->query("SELECT id FROM player_auctions WHERE seller_id IN (" . implode(',', array_map('intval', $uids)) . ")")->fetchAll(PDO::FETCH_COLUMN) : [];
    foreach ($aucIds as $a) { try { $db->prepare("DELETE FROM auction_bids WHERE auction_id = ?")->execute([$a]); } catch (Throwable $e) {} }
    foreach ($uids as $u) { try { $db->prepare("DELETE FROM player_auctions WHERE seller_id = ?")->execute([$u]); } catch (Throwable $e) {} }
    foreach ($uids as $u) { try { $db->prepare("DELETE FROM players WHERE owner_id = ? OR user_id = ?")->execute([$u, $u]); } catch (Throwable $e) {} }
    foreach ($clubIds as $c) { try { $db->prepare("DELETE FROM club_members WHERE club_id = ?")->execute([$c]); } catch (Throwable $e) {}
        try { $db->prepare("DELETE FROM clubs WHERE id = ?")->execute([$c]); } catch (Throwable $e) {} }
    foreach ($teamIds as $t) { try { $db->prepare("DELETE FROM team_members WHERE team_id = ?")->execute([$t]); } catch (Throwable $e) {}
        try { $db->prepare("DELETE FROM teams WHERE id = ?")->execute([$t]); } catch (Throwable $e) {} }
    foreach ($tids as $t) {
        foreach (['tournament_matches', 'tournament_results', 'tournament_chat_messages', 'tournament_participants', 'tournament_leaderboard'] as $tbl) {
            try { $db->prepare("DELETE FROM $tbl WHERE tournament_id = ?")->execute([$t]); } catch (Throwable $e) {}
        }
        try { $db->prepare("DELETE FROM tournaments WHERE id = ?")->execute([$t]); } catch (Throwable $e) {}
    }
    if ($uids) {
        $in = implode(',', array_map('intval', $uids));
        foreach (['transactions' => 'user_id', 'agent_transactions' => 'agent_id', 'notifications' => 'user_id'] as $tbl => $k) {
            try { $db->query("DELETE FROM $tbl WHERE $k IN ($in)"); } catch (Throwable $e) {}
        }
        try { $db->query("DELETE FROM users WHERE id IN ($in)"); } catch (Throwable $e) {}
    }
}
function qaUserHttp(PDO $db, string $username, string $role, float $balance): int {
    $db->prepare("INSERT INTO users (uuid, username, email, password_hash, full_name, role, balance, status, email_verified, registered_at)
                  VALUES (?, ?, ?, ?, ?, ?, ?, 'active', 1, NOW())")
        ->execute([bin2hex(random_bytes(16)), $username, $username . '@qa.local',
                   password_hash('Test1234!', PASSWORD_DEFAULT), strtoupper($username), $role, $balance]);
    return (int) $db->lastInsertId();
}

qaCleanupHttp($db);
$agentId = qaUserHttp($db, 'qaf_agent', 'agent', 5000);
$u1 = qaUserHttp($db, 'qaf_u1', 'user', 1000);
$u2 = qaUserHttp($db, 'qaf_u2', 'user', 1000);
$u3 = qaUserHttp($db, 'qaf_u3', 'user', 1000);

[$agentSid, $agentCsrf] = makeSession(['user_id' => $agentId, 'role' => 'agent', 'username' => 'qaf_agent', 'logged_in' => true]);
[$u1Sid, $u1Csrf]       = makeSession(['user_id' => $u1, 'role' => 'user', 'username' => 'qaf_u1', 'logged_in' => true]);
[$u2Sid, $u2Csrf]       = makeSession(['user_id' => $u2, 'role' => 'user', 'username' => 'qaf_u2', 'logged_in' => true]);
[$u3Sid, $u3Csrf]       = makeSession(['user_id' => $u3, 'role' => 'user', 'username' => 'qaf_u3', 'logged_in' => true]);

sec('SERVER');
list($proc, $log) = startServer($ROOT, $PORT);
ck('built-in server listening', true);

// helper: call an action, assert HTTP 200 + parseable JSON
$last = null;
function call(string $action, array $extra, ?string $sid, string $csrf, string $label = '', ?bool $wantSuccess = null): array {
    global $BASE, $last;
    $data = array_merge(['action' => $action, 'csrf_token' => $csrf], $extra);
    list($status, $res) = post($BASE . '/handlers/tournament_handler.php', $data, $sid);
    $last = $res;
    $okJson = $status === 200 && empty($res['_not_json']) && empty($res['_transport']);
    $noFatal = $okJson && stripos(json_encode($res, JSON_UNESCAPED_UNICODE), 'Fatal error') === false;
    $ok = $okJson && $noFatal && ($wantSuccess === null || (bool) ($res['success'] ?? false) === $wantSuccess);
    ck($label !== '' ? $label : $action, $ok,
        $ok ? '' : "status=$status " . substr(json_encode($res, JSON_UNESCAPED_UNICODE), 0, 300));
    return $res;
}

$tourId = 0; $cancelId = 0; $teamId = 0; $clubId = 0; $playerId = 0; $auctionId = 0;
$secondTournament = 0;

sec('AGENT: account + create tournament');
$res = call('agent_stats', [], $agentSid, $agentCsrf, 'agent_stats', true);
$res = call('get_teams', [], $agentSid, $agentCsrf, 'get_teams');
$res = call('create_team', ['name' => '[QAF] Alpha', 'game' => 'Valorant', 'description' => 'flow test'], $u1Sid, $u1Csrf, 'create_team', true);
$teamId = (int) ($res['team_id'] ?? 0);
ck('team row created', $teamId > 0, json_encode($res));
$res = call('get_team_members', ['team_id' => $teamId], $u1Sid, $u1Csrf, 'get_team_members', true);
$res = call('agent_create_tournament', [
    'title' => '[QAF] Flow Cup', 'description' => 'flow', 'rules' => 'be nice',
    'prize_money' => '300', 'entry_fee' => '20', 'max_teams' => 8,
    'starts_at' => date('Y-m-d H:i:s', strtotime('+40 minutes')),
    'bracket_type' => 'single_elimination', 'best_of' => 1, 'checkin_minutes' => 30,
    'category' => 'eSports',
], $agentSid, $agentCsrf, 'agent_create_tournament', true);
$tourId = (int) ($res['tournament_id'] ?? 0);
ck('tournament created', $tourId > 0, json_encode($res));
$res = call('update_tournament_status', ['tournament_id' => $tourId, 'status' => 'upcoming'], $agentSid, $agentCsrf, 'update_tournament_status (owner)', true);

sec('PARTICIPATION: register / check-in / leave');
call('register', ['tournament_id' => $tourId, 'team_name' => 'Solo One'], $u1Sid, $u1Csrf, 'register u1 (solo)', true);
call('register', ['tournament_id' => $tourId, 'team_name' => 'Solo Two'], $u2Sid, $u2Csrf, 'register u2 (solo)', true);
call('register', ['tournament_id' => $tourId, 'team_id' => $teamId], $u1Sid, $u1Csrf, 'register u1 with team on another tourn — expect fail', false);
call('register', ['tournament_id' => $tourId], $u3Sid, $u3Csrf, 'register u3 (solo)', true);
call('register', ['tournament_id' => $tourId], $u3Sid, $u3Csrf, 'duplicate register rejected', false);
call('get_participants', ['tournament_id' => $tourId], $u1Sid, $u1Csrf, 'get_participants', true);
$res = call('unregister', ['tournament_id' => $tourId], $u3Sid, $u3Csrf, 'unregister u3', true);
call('register', ['tournament_id' => $tourId, 'team_name' => 'Solo Three'], $u3Sid, $u3Csrf, 're-register u3', true);
call('check_in', ['tournament_id' => $tourId], $u1Sid, $u1Csrf, 'check_in u1', true);
call('check_in', ['tournament_id' => $tourId], $u2Sid, $u2Csrf, 'check_in u2', true);
call('check_in', ['tournament_id' => $tourId], $u3Sid, $u3Csrf, 'check_in u3', true);

sec('TEAM JOIN (second tournament)');
call('agent_create_tournament', [
    'title' => '[QAF] Team Cup', 'prize_money' => '0', 'entry_fee' => '10', 'max_teams' => 8,
    'starts_at' => date('Y-m-d H:i:s', strtotime('+3 hours')), 'category' => 'eSports',
], $agentSid, $agentCsrf, 'second tournament', true);
$secondTournament = (int) ($last['tournament_id'] ?? 0);
call('register', ['tournament_id' => $secondTournament, 'team_id' => $teamId], $u1Sid, $u1Csrf, 'join with team', true);
call('register', ['tournament_id' => $secondTournament, 'team_id' => $teamId], $u2Sid, $u2Csrf, 'same team twice rejected', false);
call('add_member', ['team_id' => $teamId, 'member_id' => $u2], $u1Sid, $u1Csrf, 'add_member (captain)', true);
call('add_member', ['team_id' => $teamId, 'member_id' => $u3], $u2Sid, $u2Csrf, 'add_member by non-captain rejected', false);
call('remove_member', ['team_id' => $teamId, 'member_id' => $u3], $u2Sid, $u2Csrf, 'remove_member by non-captain rejected', false);
call('remove_member', ['team_id' => $teamId, 'member_id' => $u2], $u1Sid, $u1Csrf, 'remove_member by captain', true);

sec('BRACKET: build / score / advance / read');
call('generate_bracket', ['tournament_id' => $tourId], $u1Sid, $u1Csrf, 'generate_bracket by non-agent', false);
call('generate_bracket', ['tournament_id' => $tourId], $agentSid, $agentCsrf, 'generate_bracket', true);
call('get_bracket_summary', ['tournament_id' => $tourId], $u1Sid, $u1Csrf, 'get_bracket_summary', true);
$res = call('get_bracket', ['tournament_id' => $tourId], $u1Sid, $u1Csrf, 'get_bracket', true);
call('advance_winner', ['tournament_id' => $tourId, 'match_id' => 0, 'winner_id' => 0], $agentSid, $agentCsrf, 'advance_winner invalid payload rejected', false);

$mk = $db->prepare("SELECT * FROM tournament_matches WHERE tournament_id = ? AND team1_id IS NOT NULL AND team2_id IS NOT NULL ORDER BY round, match_no LIMIT 1");
$mk->execute([$tourId]);
$m = $mk->fetch();
if ($m) {
    $side1 = (int) $m['team1_id']; $side2 = (int) $m['team2_id']; $mid = (int) $m['id'];
    // resolve the bracket so it can finish (score flow where possible, agent otherwise)
    $sidByUser = [$u1 => $u1Sid, $u2 => $u2Sid, $u3 => $u3Sid];
    $csrfByUser = [$u1 => $u1Csrf, $u2 => $u2Csrf, $u3 => $u3Csrf];
    $guard = 0;
    while ($guard++ < 12) {
        $row = $db->prepare("SELECT * FROM tournament_matches WHERE tournament_id = ? AND status = 'scheduled' AND team1_id IS NOT NULL AND team2_id IS NOT NULL ORDER BY round, match_no LIMIT 1");
        $row->execute([$tourId]);
        $row = $row->fetch();
        if (!$row) break;
        $s1 = (int) $row['team1_id']; $s2 = (int) $row['team2_id'];
        if (isset($sidByUser[$s1], $sidByUser[$s2])) {
            call('report_score', ['match_id' => (int) $row['id'], 'score1' => 2, 'score2' => 1], $sidByUser[$s1], $csrfByUser[$s1], 'report_score side1 (match ' . (int) $row['id'] . ')', true);
            call('confirm_score', ['match_id' => (int) $row['id']], $sidByUser[$s2], $csrfByUser[$s2], 'confirm_score side2', true);
        } else {
            call('advance_winner', ['match_id' => (int) $row['id'], 'winner_id' => $s1, 'score1' => 2, 'score2' => 0], $agentSid, $agentCsrf, 'advance_winner (agent)', true);
        }
    }
} else {
    ck('a match with both slots was available', false, 'no matches');
}

sec('ROOM + CHAT');
call('get_tournament_room', ['tournament_id' => $tourId], $u1Sid, $u1Csrf, 'get_tournament_room (participant)', true);
call('get_tournament_chat', ['tournament_id' => $tourId], $u1Sid, $u1Csrf, 'get_tournament_chat (participant)', true);
call('send_tournament_chat', ['tournament_id' => $tourId, 'message_type' => 'text', 'message' => 'gg'], $u1Sid, $u1Csrf, 'send text message', true);
call('send_tournament_chat', ['tournament_id' => $tourId, 'message_type' => 'room_card', 'room_title' => 'Final', 'room_code' => 'QAF1'], $agentSid, $agentCsrf, 'send room card (agent)', true);
call('send_tournament_chat', ['tournament_id' => $tourId, 'message_type' => 'room_card', 'room_title' => 'Final', 'room_code' => 'QAF1'], $u1Sid, $u1Csrf, 'room card by non-agent rejected', false);

sec('RESULTS + LEADERBOARD');
$res = call('submit_tournament_results', [
    'tournament_id' => $tourId,
    'player_results' => [['user_id' => $u1, 'placement' => 1, 'prize_amount' => 200, 'points_earned' => 10],
                          ['user_id' => $u2, 'placement' => 2, 'prize_amount' => 100, 'points_earned' => 5]],
    'team_results' => [],
], $agentSid, $agentCsrf, 'submit_tournament_results (agent)', true);
call('submit_tournament_results', ['tournament_id' => $tourId, 'player_results' => [['user_id' => $u1, 'placement' => 1, 'prize_amount' => 1]], 'team_results' => []], $u1Sid, $u1Csrf, 'results by non-owner rejected', false);
call('get_leaderboard', ['tournament_id' => $tourId], $u1Sid, $u1Csrf, 'get_leaderboard', true);
call('get_club_standings', [], $u1Sid, $u1Csrf, 'get_club_standings', true);

sec('CANCEL + REFUND');
call('agent_create_tournament', ['title' => '[QAF] Cancel Cup', 'prize_money' => '50', 'entry_fee' => '40', 'max_teams' => 8,
    'starts_at' => date('Y-m-d H:i:s', strtotime('+3 hours')), 'category' => 'eSports'], $agentSid, $agentCsrf, 'cancel-cup created', true);
$cancelId = (int) ($last['tournament_id'] ?? 0);
call('register', ['tournament_id' => $cancelId, 'team_name' => 'X'], $u2Sid, $u2Csrf, 'register for cancel-cup', true);
$q = $db->prepare("SELECT balance FROM users WHERE id = ?"); $q->execute([$u2]); $u2Before = (float) $q->fetchColumn();
call('cancel_tournament', ['tournament_id' => $cancelId], $u1Sid, $u1Csrf, 'cancel by non-owner rejected', false);
call('cancel_tournament', ['tournament_id' => $cancelId], $agentSid, $agentCsrf, 'cancel by owner', true);
$q->execute([$u2]); $u2After = (float) $q->fetchColumn();
ck('entry fee refunded on cancel', abs($u2After - ($u2Before + 40)) < 0.01, "before=$u2Before after=$u2After");

sec('CLUB FLOW');
$res = call('create_club', ['name' => '[QAF] Nova', 'tag' => 'QAFN', 'colour' => '#38bdf8', 'description' => 'flow', 'region' => 'Dhaka'], $u1Sid, $u1Csrf, 'create_club', true);
$clubId = (int) ($res['club_id'] ?? 0);
if (!$clubId) { $clubId = (int) $db->query("SELECT id FROM clubs WHERE tag = 'QAFN'")->fetchColumn(); }
call('get_club', ['club_id' => $clubId], $u1Sid, $u1Csrf, 'get_club', true);
call('get_my_clubs', [], $u1Sid, $u1Csrf, 'get_my_clubs', true);
call('join_club', ['club_id' => $clubId], $u2Sid, $u2Csrf, 'join_club', true);
call('join_club', ['club_id' => $clubId], $u2Sid, $u2Csrf, 'duplicate join rejected', false);
call('get_club_members', ['club_id' => $clubId], $u2Sid, $u2Csrf, 'get_club_members', true);
call('update_club', ['club_id' => $clubId, 'name' => '[QAF] Nova Prime', 'region' => 'Sylhet'], $u1Sid, $u1Csrf, 'update_club (owner)', true);
call('update_club', ['club_id' => $clubId, 'name' => 'Hacked'], $u2Sid, $u2Csrf, 'update by non-owner rejected', false);
call('leave_club', ['club_id' => $clubId], $u2Sid, $u2Csrf, 'leave_club', true);

sec('PLAYER MARKET: value → auction → bid → settle');
call('set_player_value', ['player_id' => 99999999, 'market_value' => 100], $u1Sid, $u1Csrf, 'set_player_value unknown player', false);
// give u2 a card and make u1 own it so both market directions are reachable
$db->prepare("INSERT INTO players (user_id, owner_id, status, market_value, base_price, rating) VALUES (?, NULL, 'free_agent', 0, 0, 0)
              ON DUPLICATE KEY UPDATE owner_id = NULL, current_club_id = NULL, status = 'free_agent'")->execute([$u2]);
$q2 = $db->prepare("SELECT id FROM players WHERE user_id = ?"); $q2->execute([$u2]); $playerId = (int) $q2->fetchColumn();
call('set_player_value', ['player_id' => $playerId, 'market_value' => 250], $u2Sid, $u2Csrf, 'set own asking price', true);
call('set_player_value', ['player_id' => $playerId, 'market_value' => 5], $u1Sid, $u1Csrf, 'stranger cannot price a player', false);
call('buy_player', ['player_id' => $playerId, 'price' => 250], $u1Sid, $u1Csrf, 'buy_player', true);
$q3 = $db->prepare("SELECT owner_id FROM players WHERE id = ?"); $q3->execute([$playerId]); $owner = (int) $q3->fetchColumn();
ck('ownership transferred to buyer', $owner === $u1, "owner=$owner");
call('list_player_auction', ['player_id' => $playerId, 'base_price' => 300, 'duration_hours' => 1], $u1Sid, $u1Csrf, 'list_player_auction (owner)', true);
$auctionId = (int) $db->query("SELECT id FROM player_auctions WHERE player_id = $playerId AND status = 'active' ORDER BY id DESC LIMIT 1")->fetchColumn();
ck('auction row created', $auctionId > 0);
call('list_player_auction', ['player_id' => $playerId, 'base_price' => 300, 'duration_hours' => 1], $u1Sid, $u1Csrf, 'duplicate active auction rejected', false);
call('get_active_auctions', [], $u1Sid, $u1Csrf, 'get_active_auctions', true);
call('list_player_auction', ['player_id' => $playerId, 'base_price' => 300, 'duration_hours' => 1], $u2Sid, $u2Csrf, 'non-owner cannot list', false);
call('list_player_auction', ['player_id' => $playerId, 'base_price' => 0, 'duration_hours' => 1], $u1Sid, $u1Csrf, 'zero base price rejected', false);
call('place_bid', ['auction_id' => $auctionId, 'amount' => 100], $u2Sid, $u2Csrf, 'bid below base rejected', false);
call('place_bid', ['auction_id' => $auctionId, 'amount' => 400], $u1Sid, $u1Csrf, 'seller cannot bid on own auction', false);
call('place_bid', ['auction_id' => $auctionId, 'amount' => 400], $u2Sid, $u2Csrf, 'valid bid', true);
call('place_bid', ['auction_id' => $auctionId, 'amount' => 450], $u3Sid, $u3Csrf, 'outbid', true);
call('hire_player', ['player_id' => $playerId, 'club_id' => $clubId], $u2Sid, $u2Csrf, 'hire by non-manager rejected', false);
call('hire_player', ['player_id' => $playerId, 'club_id' => $clubId], $u1Sid, $u1Csrf, 'hire while on auction rejected', false);
call('release_player', ['player_id' => $playerId], $u1Sid, $u1Csrf, 'release by non-owner rejected', false);
call('release_player', ['player_id' => $playerId], $u1Sid, $u1Csrf, 'release while on auction rejected', false);
$db->prepare("UPDATE player_auctions SET end_time = NOW() - INTERVAL 1 MINUTE WHERE id = ?")->execute([$auctionId]);
call('settle_auctions', [], $u1Sid, $u1Csrf, 'settle_auctions', true);
$row = $db->query("SELECT status, winner_id, final_price FROM player_auctions WHERE id = $auctionId")->fetch(PDO::FETCH_ASSOC);
ck('auction settled to u3 at 450', ($row['status'] ?? '') === 'completed' && (int) ($row['winner_id'] ?? 0) === $u3 && abs((float) ($row['final_price'] ?? 0) - 450) < 0.01, json_encode($row));
$q4 = $db->prepare("SELECT owner_id FROM players WHERE id = ?"); $q4->execute([$playerId]); $owner = (int) $q4->fetchColumn();
ck('player owned by the winner', $owner === $u3, "owner=$owner");
$settledLedger = (int) $db->query("SELECT COUNT(*) FROM transactions WHERE purpose = 'player_auction'")->fetchColumn();
ck('auction money is ledgered', $settledLedger >= 2, "rows=$settledLedger");
call('hire_player', ['player_id' => $playerId, 'club_id' => $clubId], $u2Sid, $u2Csrf, 'hire by non-manager rejected (settled)', false);
call('hire_player', ['player_id' => $playerId, 'club_id' => $clubId], $u1Sid, $u1Csrf, 'hire by club owner (settled)', true);
call('get_player_detail', ['user_id' => $u2], $u1Sid, $u1Csrf, 'get_player_detail', true);
call('get_market_players', ['status' => 'free_agent'], $u1Sid, $u1Csrf, 'get_market_players', true);
call('get_my_players', [], $u1Sid, $u1Csrf, 'get_my_players', true);
call('release_player', ['player_id' => $playerId], $u1Sid, $u1Csrf, 'release by non-owner rejected (owner u3)', false);
call('release_player', ['player_id' => $playerId], $u3Sid, $u3Csrf, 'release_player (owner u3)', true);
call('buy_player', ['player_id' => $playerId, 'price' => 100], $u2Sid, $u2Csrf, 'cannot buy your own card', false);
call('set_player_value', ['player_id' => $playerId, 'market_value' => 300], $u3Sid, $u3Csrf, 'released player is no longer priced by the releaser', false);
call('set_player_value', ['player_id' => $playerId, 'market_value' => 300], $u2Sid, $u2Csrf, 'the player prices their own free card', true);

sec('GUEST + PERMISSION GATES');
call('register', ['tournament_id' => $tourId], null, $agentCsrf, 'guest register rejected', false);
call('agent_create_tournament', ['title' => 'x'], $u1Sid, $u1Csrf, 'non-agent cannot create', false);
call('become_agent', ['fee' => 5], $u1Sid, $u1Csrf, 'become_agent', true);

sec('CLEANUP');
qaCleanupHttp($db);
$left = (int) $db->query("SELECT COUNT(*) FROM users WHERE username LIKE 'qaf\\_%'")->fetchColumn();
ck('HTTP flow fixtures removed', $left === 0, "left=$left");

// stop server
proc_terminate($proc);
usleep(300000);
proc_close($proc);

echo "\n=======================\n PASS: $P   FAIL: $F\n";
if ($FAILS) { echo " failing checks:\n"; foreach ($FAILS as $f) echo "  - $f\n"; }
echo "=======================\n";
exit($F > 0 ? 1 : 0);
