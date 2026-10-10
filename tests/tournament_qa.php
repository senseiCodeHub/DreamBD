<?php
/**
 * Tournament QA harness — exercises the tournament/team/club/market domain
 * end-to-end against the local database.
 *
 * Run:  php tests/tournament_qa.php
 * Exits 0 when every check passes. It seeds '[QA]' fixtures, asserts real DB
 * state and money conservation, then removes everything it created.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';

$db = Database::getInstance()->getConnection();
$P = 0; $F = 0; $FAILS = [];
$GLOBALS['_lastMoney'] = null;

function ck(string $label, bool $cond, string $detail = ''): void {
    global $P, $F, $FAILS;
    if ($cond) { $P++; echo "  PASS  $label\n"; }
    else { $F++; $FAILS[] = $label; echo "  FAIL  $label" . ($detail !== '' ? "   << $detail" : '') . "\n"; }
}
function q(PDO $db, string $sql, array $p = []): PDOStatement { $s = $db->prepare($sql); $s->execute($p); return $s; }
function col(PDO $db, string $sql, array $p = []) { return q($db, $sql, $p)->fetchColumn(); }
function bal(PDO $db, int $id): float { return (float) col($db, "SELECT balance FROM users WHERE id = ?", [$id]); }
function moneyNow(PDO $db): float {
    return (float) col($db, "SELECT COALESCE(SUM(balance),0) FROM users")
         + (float) col($db, "SELECT COALESCE(SUM(prize_escrow + fee_escrow),0) FROM tournaments");
}
function sec(string $t): void {
    global $db, $_lastMoney;
    $now = moneyNow($db);
    $delta = $_lastMoney === null ? 0.0 : $now - $_lastMoney;
    $_lastMoney = $now;
    echo "\n== $t ==\n";
    echo "   [money] total " . number_format($now, 2) . "   delta " . ($delta >= 0 ? '+' : '') . number_format($delta, 2) . "\n";
}
function rj($r): string { return is_array($r) ? json_encode($r, JSON_UNESCAPED_UNICODE) : var_export($r, true); }
function toggle(string $label, $res, bool $want, string $needle = ''): void {
    $ok = is_array($res) && (bool) ($res['success'] ?? false) === $want;
    ck($label, $ok, $ok ? '' : rj($res) . ($needle !== '' ? " | wanted: $needle" : ''));
}

function qaCleanup(PDO $db): void {
    $uids = q($db, "SELECT id FROM users WHERE username LIKE 'qa\\_%'")->fetchAll(PDO::FETCH_COLUMN);
    $tids = q($db, "SELECT id FROM tournaments WHERE title LIKE '[QA]%'")->fetchAll(PDO::FETCH_COLUMN);
    $clubIds = q($db, "SELECT id FROM clubs WHERE tag LIKE 'QA%'")->fetchAll(PDO::FETCH_COLUMN);
    $teamIds = q($db, "SELECT id FROM teams WHERE name LIKE '[QA]%'")->fetchAll(PDO::FETCH_COLUMN);
    $aucIds = $uids ? q($db, "SELECT id FROM player_auctions WHERE seller_id IN (" . implode(',', array_map('intval', $uids)) . ")")->fetchAll(PDO::FETCH_COLUMN) : [];
    foreach ($aucIds as $a) { try { q($db, "DELETE FROM auction_bids WHERE auction_id = ?", [$a]); } catch (Throwable $e) {} }
    foreach ($uids as $u) { try { q($db, "DELETE FROM player_auctions WHERE seller_id = ?", [$u]); } catch (Throwable $e) {} }
    foreach ($uids as $u) { try { q($db, "DELETE FROM auction_bids WHERE bidder_id = ?", [$u]); } catch (Throwable $e) {} }
    foreach ($clubIds as $c) { try { q($db, "UPDATE players SET current_club_id = NULL WHERE current_club_id = ?", [$c]); } catch (Throwable $e) {} }
    foreach ($uids as $u) { try { q($db, "DELETE FROM player_transfers WHERE from_owner_id = ? OR to_owner_id = ?", [$u, $u]); } catch (Throwable $e) {} }
    foreach ($uids as $u) { try { q($db, "DELETE FROM players WHERE owner_id = ? OR user_id = ?", [$u, $u]); } catch (Throwable $e) {} }
    foreach ($tids as $t) {
        foreach (['tournament_matches', 'tournament_results', 'tournament_chat_messages', 'tournament_participants', 'tournament_leaderboard'] as $tbl) {
            try { q($db, "DELETE FROM $tbl WHERE tournament_id = ?", [$t]); } catch (Throwable $e) {}
        }
        try { q($db, "DELETE FROM tournaments WHERE id = ?", [$t]); } catch (Throwable $e) {}
    }
    foreach ($teamIds as $t) { try { q($db, "DELETE FROM team_members WHERE team_id = ?", [$t]); } catch (Throwable $e) {} }
    foreach ($teamIds as $t) { try { q($db, "DELETE FROM teams WHERE id = ?", [$t]); } catch (Throwable $e) {} }
    foreach ($clubIds as $c) { try { q($db, "DELETE FROM club_members WHERE club_id = ?", [$c]); } catch (Throwable $e) {} }
    foreach ($clubIds as $c) { try { q($db, "DELETE FROM clubs WHERE id = ?", [$c]); } catch (Throwable $e) {} }
    foreach ($uids as $u) {
        foreach (['transactions' => 'user_id', 'agent_transactions' => 'agent_id', 'notifications' => 'user_id'] as $tbl => $k) {
            try { q($db, "DELETE FROM $tbl WHERE $k = ?", [$u]); } catch (Throwable $e) {}
        }
    }
    if ($uids) {
        $in = implode(',', array_map('intval', $uids));
        foreach (['team_members' => 'user_id', 'club_members' => 'user_id', 'game_skills' => 'user_id', 'user_experience' => 'user_id', 'player_stats' => 'user_id'] as $tbl => $k) {
            try { q($db, "DELETE FROM $tbl WHERE $k IN ($in)"); } catch (Throwable $e) {}
        }
        q($db, "DELETE FROM users WHERE id IN ($in)");
    }
}
function qaUser(PDO $db, string $username, float $balance): int {
    q($db, "INSERT INTO users (uuid, username, email, password_hash, full_name, role, balance, status, email_verified, registered_at)
            VALUES (?, ?, ?, ?, ?, 'user', ?, 'active', 1, NOW())",
        [bin2hex(random_bytes(16)), $username, $username . '@qa.local', password_hash('Test1234!', PASSWORD_DEFAULT), strtoupper($username), $balance]);
    return (int) $db->lastInsertId();
}
function mkTournament(PDO $db, int $agentId, array $over = []): int {
    $data = array_merge([
        'title' => '[QA] Tournament', 'description' => 'QA', 'rules' => 'Be nice', 'prize_money' => '0',
        'category' => 'eSports', 'max_teams' => 8, 'game_icon' => 'fa-gamepad', 'accent_color' => '#7c3aed',
        'starts_at' => date('Y-m-d H:i:s', strtotime('+2 hours')), 'entry_fee' => '0',
        'bracket_type' => 'single_elimination', 'best_of' => 1, 'checkin_minutes' => 30,
    ], $over);
    $r = createTournamentByAgent($db, $agentId, $data);
    return (int) ($r['tournament_id'] ?? 0);
}
function tid(PDO $db, string $title): int { return (int) col($db, "SELECT id FROM tournaments WHERE title = ? ORDER BY id DESC LIMIT 1", [$title]); }

qaCleanup($db);

sec('CLOCK');
ck('PHP clock matches MySQL clock', abs(strtotime(date('Y-m-d H:i:s')) - strtotime((string) col($db, 'SELECT NOW()'))) <= 5);

sec('FIXTURES (seed ৳3560)');
$agent  = qaUser($db, 'qa_agent1', 1000);
$agent2 = qaUser($db, 'qa_agent2', 1000);
$p1 = qaUser($db, 'qa_p1', 500);
$p2 = qaUser($db, 'qa_p2', 500);
$p3 = qaUser($db, 'qa_p3', 500);
$p4 = qaUser($db, 'qa_p4', 60);
ck('6 QA users created', (int) col($db, "SELECT COUNT(*) FROM users WHERE username LIKE 'qa\\_%'") === 6);
$totalStart = moneyNow($db);

sec('AGENT ACCOUNT (platform fee -5 expected)');
toggle('createAgentAccount(agent1, ৳5)', createAgentAccount($db, $agent, 5.00), true);
ck("agent1 role = 'agent'", col($db, "SELECT role FROM users WHERE id = ?", [$agent]) === 'agent');
ck('agent1 balance 1000→995', abs(bal($db, $agent) - 995) < 0.01, 'bal=' . bal($db, $agent));
toggle('createAgentAccount twice rejected', createAgentAccount($db, $agent, 5.00), false);

sec('CREATE TOURNAMENT (prize 300 → escrow)');
$tid1 = mkTournament($db, $agent, ['title' => '[QA] Single Elim Cup', 'prize_money' => '300', 'entry_fee' => '20', 'best_of' => 3]);
ck('tournament row created', $tid1 > 0);
ck('prize_escrow 300 / fee_escrow 0', col($db, "SELECT CONCAT(prize_escrow,'|',fee_escrow) FROM tournaments WHERE id = ?", [$tid1]) === '300.00|0.00', (string) col($db, "SELECT CONCAT(prize_escrow,'|',fee_escrow) FROM tournaments WHERE id = ?", [$tid1]));
ck('agent debited 300 (695)', abs(bal($db, $agent) - 695) < 0.01, 'bal=' . bal($db, $agent));
toggle('insufficient prize balance rejected', createTournamentByAgent($db, $agent, ['title' => '[QA] Too Rich', 'prize_money' => '999999']), false);

sec('REGISTRATION + ESCROW (4 × ৳20)');
toggle('register p1', registerForTournament($db, $p1, $tid1), true);
ck('p1 500→480, escrow 20', abs(bal($db, $p1) - 480) < 0.01 && (float) col($db, "SELECT fee_escrow FROM tournaments WHERE id = ?", [$tid1]) === 20.0);
toggle('duplicate register rejected', registerForTournament($db, $p1, $tid1), false);
toggle('register p2 (team name)', registerForTournament($db, $p2, $tid1, 'QA Squad'), true);
toggle('register p3', registerForTournament($db, $p3, $tid1), true);
toggle('register p4', registerForTournament($db, $p4, $tid1), true);
ck('escrow 80, p4 60→40', (float) col($db, "SELECT fee_escrow FROM tournaments WHERE id = ?", [$tid1]) === 80.0 && abs(bal($db, $p4) - 40) < 0.01);

sec('CAPACITY + BALANCE GUARDS');
$capId = mkTournament($db, $agent, ['title' => '[QA] One Slot', 'max_teams' => 1]);
toggle('fills the only slot', registerForTournament($db, $p1, $capId), true);
toggle('second register → "full"', registerForTournament($db, $p2, $capId), false);
$priceyId = mkTournament($db, $agent, ['title' => '[QA] Pricey', 'entry_fee' => '999']);
toggle('poor player blocked', registerForTournament($db, $p4, $priceyId), false);
ck('no participant row / no escrow', (int) col($db, "SELECT COUNT(*) FROM tournament_participants WHERE tournament_id = ?", [$priceyId]) === 0 && (float) col($db, "SELECT fee_escrow FROM tournaments WHERE id = ?", [$priceyId]) === 0.0);

sec('UNREGISTER (refund) + REACTIVATE');
toggle('p2 unregisters', unregisterFromTournament($db, $p2, $tid1), true);
ck('p2 refunded to 500, escrow 60', abs(bal($db, $p2) - 500) < 0.01 && (float) col($db, "SELECT fee_escrow FROM tournaments WHERE id = ?", [$tid1]) === 60.0, 'p2=' . bal($db, $p2) . ' escrow=' . col($db, "SELECT fee_escrow FROM tournaments WHERE id = ?", [$tid1]));
toggle('re-register p2', registerForTournament($db, $p2, $tid1), true);
ck('escrow back to 80, p2 480', (float) col($db, "SELECT fee_escrow FROM tournaments WHERE id = ?", [$tid1]) === 80.0 && abs(bal($db, $p2) - 480) < 0.01);

sec('CHECK-IN WINDOW');
$row1 = getTournamentByIdWithCounts($db, $tid1);
$rowOpen = $row1; $rowOpen['starts_at'] = date('Y-m-d H:i:s', strtotime('+20 minutes'));
ck('state "open" inside window', tnCheckinState($rowOpen, date('Y-m-d H:i:s')) === 'open', tnCheckinState($rowOpen, date('Y-m-d H:i:s')));
ck('starts in 2h → still "upcoming"', tnCheckinState($row1, date('Y-m-d H:i:s')) === 'upcoming');
ck('opens_at = starts_at - 30min', tnCheckinOpensAt($row1) === date('Y-m-d H:i:s', strtotime($row1['starts_at']) - 1800));
ck('closes_at = starts_at', tnCheckinClosesAt($row1) === $row1['starts_at']);
$future = $row1; $future['starts_at'] = date('Y-m-d H:i:s', strtotime('+5 hours'));
$past = $row1; $past['starts_at'] = date('Y-m-d H:i:s', strtotime('-1 minute'));
ck('"upcoming" before window', tnCheckinState($future, date('Y-m-d H:i:s')) === 'upcoming');
ck('"closed" after start', tnCheckinState($past, date('Y-m-d H:i:s')) === 'closed');
toggle('tnCheckin(p1)', tnCheckin($db, $tid1, $p1), true);
ck('checked_in persisted', (int) col($db, "SELECT checked_in FROM tournament_participants WHERE tournament_id = ? AND user_id = ?", [$tid1, $p1]) === 1);
toggle('tnCheckin again (idempotent)', tnCheckin($db, $tid1, $p1), true);
toggle('tnCheckin unregistered user', tnCheckin($db, $tid1, $agent), false);

sec('BRACKET BUILD');
toggle('non-owner agent blocked', bracketBuild($db, $tid1, $agent2), false);
toggle('owner builds bracket', bracketBuild($db, $tid1, $agent), true);
$mCount = (int) col($db, "SELECT COUNT(*) FROM tournament_matches WHERE tournament_id = ?", [$tid1]);
ck('4 entrants → ≥3 matches', $mCount >= 3, "matches=$mCount");
ck('status → live', col($db, "SELECT status FROM tournaments WHERE id = ?", [$tid1]) === 'live');
toggle('rebuild rejected', bracketBuild($db, $tid1, $agent), false);
toggle('leave blocked once bracket exists', unregisterFromTournament($db, $p1, $tid1), false);

sec('SCORE REPORT → CONFIRM');
$mm = q($db, "SELECT * FROM tournament_matches WHERE tournament_id = ? AND team1_id IS NOT NULL AND team2_id IS NOT NULL ORDER BY round, match_no LIMIT 1", [$tid1])->fetch();
$mid = (int) $mm['id']; $side1 = (int) $mm['team1_id']; $side2 = (int) $mm['team2_id'];
toggle('outsider cannot report', bracketReportScore($db, $mid, $agent, 2, 0), false);
toggle('draw rejected', bracketReportScore($db, $mid, $side1, 1, 1), false);
toggle('side1 reports 2-0', bracketReportScore($db, $mid, $side1, 2, 0), true);
ck('report_state = reported', col($db, "SELECT report_state FROM tournament_matches WHERE id = ?", [$mid]) === 'reported', (string) col($db, "SELECT report_state FROM tournament_matches WHERE id = ?", [$mid]));
toggle('opponent cannot overwrite the report', bracketReportScore($db, $mid, $side2, 0, 2), false);
toggle('reporter cannot self-confirm', bracketConfirmScore($db, $mid, $side1), false);
toggle('side2 confirms', bracketConfirmScore($db, $mid, $side2), true);
ck('match completed, winner side1', col($db, "SELECT CONCAT(status,'|',winner_id) FROM tournament_matches WHERE id = ?", [$mid]) === "completed|$side1");
ck('winner advanced', (int) col($db, "SELECT COUNT(*) FROM tournament_matches WHERE next_match_id IS NOT NULL AND (team1_id = ? OR team2_id = ?)", [$side1, $side1]) >= 1);

sec('DISPUTE FLOW');
$dm = q($db, "SELECT * FROM tournament_matches WHERE tournament_id = ? AND status = 'scheduled' AND team1_id IS NOT NULL AND team2_id IS NOT NULL ORDER BY round, match_no LIMIT 1", [$tid1])->fetch();
if ($dm) {
    $did = (int) $dm['id']; $d1 = (int) $dm['team1_id']; $d2 = (int) $dm['team2_id'];
    bracketReportScore($db, $did, $d1, 1, 0);
    toggle('dispute by opponent', bracketDisputeScore($db, $did, $d2, 'He did not show up'), true);
    ck('report_state = disputed + note stored', col($db, "SELECT CONCAT(report_state,'|',dispute_note) FROM tournament_matches WHERE id = ?", [$did]) === 'disputed|He did not show up', (string) col($db, "SELECT CONCAT(report_state,'|',dispute_note) FROM tournament_matches WHERE id = ?", [$did]));
    ck('agent notified of dispute', (int) col($db, "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'tournament'", [$agent]) >= 1);
    toggle('agent resolves dispute (other side wins)', bracketAgentResolve($db, $did, $d2, $agent, ['score1' => 0, 'score2' => 1]), true);
    ck('dispute resolved with winner', col($db, "SELECT CONCAT(winner_id,'|',score1,'|',score2,'|',status) FROM tournament_matches WHERE id = ?", [$did]) === "$d2|0|1|completed");
    toggle('resolved match cannot be resolved twice', bracketAgentResolve($db, $did, $d1, $agent), false);
    toggle('non-owner agent cannot resolve', bracketAgentResolve($db, $did, $d1, $agent2), false);
} else {
    ck('a second match existed for the dispute flow', false, 'none scheduled');
}

sec('BRACKET READS');
$sum = bracketSummary($db, $tid1);
ck('bracketSummary has matches + champion field', ($sum['match_count'] ?? 0) >= 3 && array_key_exists('champion', $sum), rj($sum));
ck('bracketMatchesForRender rows', count(bracketMatchesForRender($db, $tid1)) >= 3);
ck('bracketTypeOf', bracketTypeOf($db, $tid1) === 'single_elimination');
ck('round-robin standings empty for single elim (no group stage)', count(bracketStandings($db, $tid1)) === 0);

sec('FINISH BRACKET');
$guard = 0;
while (!bracketIsComplete($db, $tid1) && $guard++ < 20) {
    $row = q($db, "SELECT * FROM tournament_matches WHERE tournament_id = ? AND status = 'scheduled' AND team1_id IS NOT NULL AND team2_id IS NOT NULL ORDER BY round DESC, match_no LIMIT 1", [$tid1])->fetch();
    if (!$row) break;
    $res = bracketAgentResolve($db, (int) $row['id'], (int) $row['team1_id'], $agent, ['score1' => 1, 'score2' => 0]);
    if (!($res['success'] ?? false)) { echo "  !! resolve failed: " . rj($res) . "\n"; break; }
}
ck('bracket complete', bracketIsComplete($db, $tid1), "loops=$guard");
ck('bracketSummary champion set', !empty(bracketSummary($db, $tid1)['champion']), rj(bracketSummary($db, $tid1)));

sec('PRIZE PAYOUT');
$final = q($db, "SELECT * FROM tournament_matches WHERE tournament_id = ? AND winner_id IS NOT NULL ORDER BY round DESC, match_no DESC LIMIT 1", [$tid1])->fetch();
$champion = (int) $final['winner_id'];
$runnerUp = (int) (($final['team1_id'] == $champion) ? $final['team2_id'] : $final['team1_id']);
$beforeChamp = bal($db, $champion); $beforeAgent = bal($db, $agent);
ck('canSubmit true for owner', canSubmitTournamentResults($db, $tid1, $agent) === true);
ck('canSubmit false for other agent', canSubmitTournamentResults($db, $tid1, $agent2) === false);
toggle('outsider cannot submit', saveTournamentResults($db, $tid1, $agent2, [], [['user_id' => $champion, 'placement' => 1, 'prize_amount' => 100]]), false);
$pool = (float) col($db, "SELECT prize_escrow + fee_escrow FROM tournaments WHERE id = ?", [$tid1]);
toggle('over-escrow payout rejected', saveTournamentResults($db, $tid1, $agent, [], [['user_id' => $champion, 'placement' => 1, 'prize_amount' => $pool + 500]]), false);
toggle('empty results rejected', saveTournamentResults($db, $tid1, $agent, [], []), false);
toggle('valid payout (200 + 100)', saveTournamentResults($db, $tid1, $agent, [], [
    ['user_id' => $champion, 'placement' => 1, 'prize_amount' => 200, 'points_earned' => 10, 'result_label' => 'Champion'],
    ['user_id' => $runnerUp, 'placement' => 2, 'prize_amount' => 100, 'points_earned' => 5, 'result_label' => 'Runner-up'],
]), true);
ck('champion +200', abs(bal($db, $champion) - ($beforeChamp + 200)) < 0.01, 'delta=' . (bal($db, $champion) - $beforeChamp));
ck('status completed', col($db, "SELECT status FROM tournaments WHERE id = ?", [$tid1]) === 'completed');
ck('escrow zeroed + released flag', col($db, "SELECT CONCAT(prize_escrow,'|',fee_escrow,'|',escrow_released) FROM tournaments WHERE id = ?", [$tid1]) === '0.00|0.00|1');
ck('leftover entry fees (80) released to agent', abs(bal($db, $agent) - ($beforeAgent + 80)) < 0.01, 'delta=' . (bal($db, $agent) - $beforeAgent));
ck('results bundle 2 players', count(getTournamentResultsBundle($db, $tid1)['players']) === 2);
ck('leaderboard rows', (int) col($db, "SELECT COUNT(*) FROM tournament_leaderboard WHERE tournament_id = ?", [$tid1]) >= 2);
toggle('second submission rejected', saveTournamentResults($db, $tid1, $agent, [], [['user_id' => $champion, 'placement' => 1, 'prize_amount' => 1]]), false);
ck('prize ledger row', (int) col($db, "SELECT COUNT(*) FROM transactions WHERE user_id = ? AND purpose = 'tournament_prize' AND amount = 200", [$champion]) === 1);
ck('getUserTournamentResults', count(getUserTournamentResults($db, $champion, 5)) >= 1);
ck('getUserTournamentRegistrations', count(getUserTournamentRegistrations($db, $p1)) >= 1);
ck('getLeaderboard', count(getLeaderboard($db, $tid1)) >= 1);
ck('room access: participant yes', userCanAccessTournamentRoom($db, $tid1, $p1) === true);
ck('room access: outsider no', userCanAccessTournamentRoom($db, $tid1, $agent2) === false);

sec('ROUND ROBIN (standings + group stage)');
$rrId = mkTournament($db, $agent, ['title' => '[QA] RR League', 'bracket_type' => 'round_robin', 'starts_at' => date('Y-m-d H:i:s', strtotime('+1 hour'))]);
registerForTournament($db, $p1, $rrId); registerForTournament($db, $p2, $rrId); registerForTournament($db, $p3, $rrId);
toggle('round robin bracket builds with 3 entrants', bracketBuild($db, $rrId, $agent), true);
ck('RR match count = 3 (all pairs)', (int) col($db, "SELECT COUNT(*) FROM tournament_matches WHERE tournament_id = ?", [$rrId]) === 3, (string) col($db, "SELECT COUNT(*) FROM tournament_matches WHERE tournament_id = ?", [$rrId]));
$rrGuard = 0;
while (!bracketIsComplete($db, $rrId) && $rrGuard++ < 10) {
    $row = q($db, "SELECT * FROM tournament_matches WHERE tournament_id = ? AND status = 'scheduled' AND team1_id IS NOT NULL AND team2_id IS NOT NULL ORDER BY id LIMIT 1", [$rrId])->fetch();
    if (!$row) break;
    bracketAgentResolve($db, (int) $row['id'], (int) $row['team1_id'], $agent, ['score1' => 2, 'score2' => 1]);
}
$standings = bracketStandings($db, $rrId);
ck('standings has all 3 entrants', count($standings) === 3, 'rows=' . count($standings));
ck('standings ranked 1..3 with points', ($standings[0]['rank'] ?? 0) === 1 && ($standings[0]['points'] ?? 0) >= 3, rj($standings[0]));
ck('RR bracket completes', bracketIsComplete($db, $rrId));

sec('DOUBLE ELIMINATION');
$deId = mkTournament($db, $agent, ['title' => '[QA] Double Elim', 'bracket_type' => 'double_elimination']);
registerForTournament($db, $p1, $deId); registerForTournament($db, $p2, $deId); registerForTournament($db, $p3, $deId); registerForTournament($db, $p4, $deId);
toggle('double elim builds with 4 entrants', bracketBuild($db, $deId, $agent), true);
$stages = q($db, "SELECT stage, COUNT(*) c FROM tournament_matches WHERE tournament_id = ? GROUP BY stage", [$deId])->fetchAll();
ck('has WB + LB stages', count($stages) >= 2, rj($stages));
$planSingle = bracketPlanSingle([['id' => 1], ['id' => 2], ['id' => 3], ['id' => 4], ['id' => 5]]);
ck('bracketPlanSingle(5) produces playable rounds', count($planSingle['matches'] ?? $planSingle) >= 5, rj(array_keys($planSingle)));
ck('bracketSeedOrder(8) = [1,8,4,5,2,7,3,6]', bracketSeedOrder(8) === [1, 8, 4, 5, 2, 7, 3, 6], rj(bracketSeedOrder(8)));
ck('bracketNextPow2(5) = 8', bracketNextPow2(5) === 8);

sec('CHAT / ROOM MESSAGES');
toggle('text message', createTournamentChatMessage($db, $tid1, $p1, 'text', ['message' => 'gg wp']), true);
toggle('empty text rejected', createTournamentChatMessage($db, $tid1, $p1, 'text', ['message' => '   ']), false);
toggle('room card (agent)', createTournamentChatMessage($db, $tid1, $agent, 'room_card', ['room_title' => 'Final room', 'room_code' => 'ABC123', 'room_link' => 'https://x.test/room']), true);
toggle('room card without title/code rejected', createTournamentChatMessage($db, $tid1, $agent, 'room_card', ['room_link' => '']), false);
$msgs = getTournamentRoomMessages($db, $tid1, 50);
ck('room feed has both message kinds', count($msgs) >= 2, 'rows=' . count($msgs));

sec('CANCEL + REFUND');
$cancelId = mkTournament($db, $agent, ['title' => '[QA] Cancel Me', 'prize_money' => '100', 'entry_fee' => '50', 'starts_at' => date('Y-m-d H:i:s', strtotime('+3 hours'))]);
registerForTournament($db, $p1, $cancelId);
registerForTournament($db, $p2, $cancelId);
$p1b = bal($db, $p1); $p2b = bal($db, $p2); $agb = bal($db, $agent);
toggle('non-owner cannot cancel', cancelTournament($db, $cancelId, $agent2), false);
toggle('owner cancels', cancelTournament($db, $cancelId, $agent), true);
ck('p1 refunded +50', abs(bal($db, $p1) - ($p1b + 50)) < 0.01, 'delta=' . (bal($db, $p1) - $p1b));
ck('p2 refunded +50', abs(bal($db, $p2) - ($p2b + 50)) < 0.01, 'delta=' . (bal($db, $p2) - $p2b));
ck('agent got prize escrow 100 back', abs(bal($db, $agent) - ($agb + 100)) < 0.01, 'delta=' . (bal($db, $agent) - $agb));
ck('status cancelled + participants cancelled', col($db, "SELECT status FROM tournaments WHERE id = ?", [$cancelId]) === 'cancelled' && (int) col($db, "SELECT COUNT(*) FROM tournament_participants WHERE tournament_id = ? AND status = 'cancelled'", [$cancelId]) === 2);
ck('escrow zeroed', col($db, "SELECT CONCAT(prize_escrow,'|',fee_escrow,'|',escrow_released) FROM tournaments WHERE id = ?", [$cancelId]) === '0.00|0.00|1');

sec('NO-SHOW AUTO-REFUND (lazy deadline sweep)');
$lateId = mkTournament($db, $agent, ['title' => '[QA] Late Start', 'entry_fee' => '30', 'checkin_minutes' => 5, 'starts_at' => date('Y-m-d H:i:s', strtotime('-1 minute'))]);
registerForTournament($db, $p1, $lateId);
registerForTournament($db, $p3, $lateId);
tnCheckin($db, $lateId, $p3);
$p1b = bal($db, $p1); $p3b = bal($db, $p3);
$sweep = enforceTournamentDeadlines($db, $lateId);
ck('sweep refunded exactly 1', (int) $sweep['refunded'] === 1, rj($sweep));
ck('no-show p1 +30', abs(bal($db, $p1) - ($p1b + 30)) < 0.01, 'delta=' . (bal($db, $p1) - $p1b));
ck('checked-in p3 unchanged', abs(bal($db, $p3) - $p3b) < 0.01);
ck('no-show cancelled / checked-in confirmed', col($db, "SELECT status FROM tournament_participants WHERE tournament_id = ? AND user_id = ?", [$lateId, $p1]) === 'cancelled' && col($db, "SELECT status FROM tournament_participants WHERE tournament_id = ? AND user_id = ?", [$lateId, $p3]) === 'confirmed');
ck('sweep idempotent', (int) enforceTournamentDeadlines($db, $lateId)['refunded'] === 0);

sec('TEAM FLOW');
$team = createTeam($db, $p3, '[QA] Warriors', 'Valorant', 'QA team');
$teamId = (int) ($team['team_id'] ?? 0);
ck('team created + captain member', $teamId > 0 && (int) col($db, "SELECT COUNT(*) FROM team_members WHERE team_id = ? AND user_id = ? AND role = 'captain'", [$teamId, $p3]) === 1, rj($team));
toggle('addTeamMember(p4)', addTeamMember($db, $teamId, $p4), true);
toggle('duplicate member rejected', addTeamMember($db, $teamId, $p4), false);
$teamTour = mkTournament($db, $agent, ['title' => '[QA] Team Cup', 'entry_fee' => '10']);
$p3b = bal($db, $p3);
toggle('captain registers the team', joinTournamentWithTeam($db, $teamId, $teamTour, $p3), true);
ck('participant row linked to team', (int) col($db, "SELECT COUNT(*) FROM tournament_participants WHERE tournament_id = ? AND team_id = ?", [$teamTour, $teamId]) === 1);
ck('entry fee drawn from captain (escrow 10)', abs(bal($db, $p3) - ($p3b - 10)) < 0.01 && (float) col($db, "SELECT fee_escrow FROM tournaments WHERE id = ?", [$teamTour]) === 10.0, 'delta=' . (bal($db, $p3) - $p3b) . ' escrow=' . col($db, "SELECT fee_escrow FROM tournaments WHERE id = ?", [$teamTour]));
toggle('non-captain cannot register a team', joinTournamentWithTeam($db, $teamId, $teamTour, $p4), false);
toggle('duplicate team registration rejected', joinTournamentWithTeam($db, $teamId, $teamTour, $p3), false);
ck('getTournamentTeams lists it', count(getTournamentTeams($db, $teamTour)) >= 1);
toggle('removeTeamMember', removeTeamMember($db, $teamId, $p4), true);
ck('member removed', (int) col($db, "SELECT COUNT(*) FROM team_members WHERE team_id = ?", [$teamId]) === 1);
ck('getUserTeams lists it', count(getUserTeams($db, $p3)) >= 1);
ck('getTeamMembers works', count(getTeamMembers($db, $teamId)) === 1);

sec('CLUB');
$clubRes = createClub($db, $p1, '[QA] Nova', 'QAN', '#38bdf8', 'QA club', 'Dhaka');
$clubId = (int) ($clubRes['club_id'] ?? 0);
ck('club created + owner member', $clubId > 0 && (int) col($db, "SELECT COUNT(*) FROM club_members WHERE club_id = ? AND user_id = ? AND role = 'owner'", [$clubId, $p1]) === 1, rj($clubRes));
toggle('short tag rejected', createClub($db, $p4, 'Bad', 'X'), false);
toggle('joinClub(p2)', joinClub($db, $clubId, $p2), true);
toggle('duplicate join rejected', joinClub($db, $clubId, $p2), false);
toggle('owner updates club', updateClub($db, $clubId, $p1, ['name' => '[QA] Nova Prime', 'region' => 'Sylhet']), true);
ck('club update persisted', col($db, "SELECT CONCAT(name,'|',region) FROM clubs WHERE id = ?", [$clubId]) === '[QA] Nova Prime|Sylhet');
toggle('non-owner cannot update', updateClub($db, $clubId, $p4, ['name' => 'Hacked']), false);
ck('getClubMembers 2', count(getClubMembers($db, $clubId)) === 2);
ck('getClubStandings includes club', count(getClubStandings($db)) >= 1);
ck('owner cannot leave own club', (leaveClub($db, $clubId, $p1)['success'] ?? true) === false);
ck('getUserClubs', count(getUserClubs($db, $p1)) >= 1);

sec('PLAYER MARKET + AUCTION');
q($db, "INSERT INTO players (user_id, owner_id, status, market_value, base_price, rating) VALUES (?, NULL, 'free_agent', 0, 0, 0)
        ON DUPLICATE KEY UPDATE owner_id = NULL, current_club_id = NULL, status = 'free_agent'", [$p4]);
$playerId = (int) col($db, "SELECT id FROM players WHERE user_id = ?", [$p4]);
ck('player profile row exists', $playerId > 0);
ck('free-agent market lists them', count(getMarketPlayers($db, 'free_agent', null, 50)) >= 1);
toggle('free agent cannot be auctioned by themselves', listPlayerForAuction($db, $p4, $playerId, 100, 1), false);
q($db, "UPDATE players SET owner_id = ?, status = 'active', base_price = 100 WHERE id = ?", [$p4, $playerId]);
$p4b = bal($db, $p4);
$listRes = listPlayerForAuction($db, $p4, $playerId, 100, 1);
echo "  listPlayerForAuction: " . rj($listRes) . "\n";
$aucId = (int) col($db, "SELECT id FROM player_auctions WHERE player_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1", [$playerId]);
ck('auction row created', $aucId > 0, rj($listRes));
ck('active auctions feed lists it', count(getActiveAuctions($db, 50)) >= 1);
toggle('bid below base+increment rejected', placeBid($db, $aucId, $p2, 100), false);
$p2b = bal($db, $p2); $agentB = bal($db, $agent);
toggle('valid bid 150', placeBid($db, $aucId, $p2, 150), true);
ck('highest bid = 150', (float) col($db, "SELECT current_price FROM player_auctions WHERE id = ?", [$aucId]) === 150.0);
ck('bidder not charged pre-settlement', abs(bal($db, $p2) - $p2b) < 0.01);
q($db, "UPDATE player_auctions SET end_time = NOW() - INTERVAL 1 MINUTE WHERE id = ?", [$aucId]);
$settled = settleExpiredAuctions($db);
ck('settleExpiredAuctions settled ≥1', $settled >= 1, "settled=$settled");
ck('auction closed as completed with winner', col($db, "SELECT CONCAT(status,'|',IFNULL(winner_id,0),'|',IFNULL(final_price,0)) FROM player_auctions WHERE id = ?", [$aucId]) === "completed|$p2|150.00", (string) col($db, "SELECT CONCAT(status,'|',IFNULL(winner_id,0),'|',IFNULL(final_price,0)) FROM player_auctions WHERE id = ?", [$aucId]));
ck('buyer charged 150', abs(bal($db, $p2) - ($p2b - 150)) < 0.01, 'delta=' . (bal($db, $p2) - $p2b));
ck('seller credited 95% = 142.50', abs(bal($db, $p4) - ($p4b + 142.50)) < 0.01, 'delta=' . (bal($db, $p4) - $p4b));
ck('player owned by buyer', col($db, "SELECT CONCAT(IFNULL(owner_id,0),'|',status) FROM players WHERE id = ?", [$playerId]) === "$p2|active", (string) col($db, "SELECT CONCAT(IFNULL(owner_id,0),'|',status) FROM players WHERE id = ?", [$playerId]));
ck('transfer ledger row', (int) col($db, "SELECT COUNT(*) FROM player_transfers WHERE player_id = ? AND type = 'auction'", [$playerId]) >= 1);
ck('buyer ledger row for auction', (int) col($db, "SELECT COUNT(*) FROM transactions WHERE user_id = ? AND purpose = 'player_auction'", [$p2]) >= 1, 'none — auction money moves without a transactions row');
toggle('owner cannot bid on own auction', placeBid($db, $aucId, $p4, 200), false);

sec('DIRECT BUY + HIRE + RELEASE');
$p5 = qaUser($db, 'qa_p5', 1000);
q($db, "INSERT INTO players (user_id, owner_id, status, market_value, base_price, rating) VALUES (?, NULL, 'free_agent', 200, 200, 4) ON DUPLICATE KEY UPDATE owner_id = NULL, status = 'free_agent'", [$p5]);
$p5Player = (int) col($db, "SELECT id FROM players WHERE user_id = ?", [$p5]);
$p1b = bal($db, $p1);
toggle('buyPlayerDirect(free agent, ৳200)', buyPlayerDirect($db, $p5Player, $p1, 200), true);
ck('buyer charged 200', abs(bal($db, $p1) - ($p1b - 200)) < 0.01, 'delta=' . (bal($db, $p1) - $p1b));
ck('buyer ledger row for purchase', (int) col($db, "SELECT COUNT(*) FROM transactions WHERE user_id = ? AND purpose = 'player_purchase'", [$p1]) >= 1, 'none — purchase money moves without a transactions row');
ck('player owned by buyer', (int) col($db, "SELECT owner_id FROM players WHERE id = ?", [$p5Player]) === $p1);
toggle('non-manager cannot hire to club', hirePlayerToClub($db, $p5Player, $clubId, $p3), false);
toggle('club owner hires player', hirePlayerToClub($db, $p5Player, $clubId, $p1), true);
ck('player attached to club', (int) col($db, "SELECT current_club_id FROM players WHERE id = ?", [$p5Player]) === $clubId);
toggle('hire twice rejected', hirePlayerToClub($db, $p5Player, $clubId, $p1), false);
toggle('non-manager cannot fire', firePlayerFromClub($db, $p5, $clubId, $p3), false);
toggle('club owner fires player', firePlayerFromClub($db, $p5, $clubId, $p1), true);
ck('player detached from club', col($db, "SELECT IFNULL(current_club_id,0) FROM players WHERE id = ?", [$p5Player]) === '0');
toggle('non-owner cannot release', releasePlayer($db, $p5Player, $p2), false);
toggle('owner releases player', releasePlayer($db, $p5Player, $p1), true);
ck('player back to free agent', col($db, "SELECT CONCAT(status,'|',IFNULL(owner_id,0)) FROM players WHERE id = ?", [$p5Player]) === 'free_agent|0');
ck('getPlayerDetail readable', is_array(getPlayerDetail($db, $p5)));
ck('club standings still readable', count(getClubStandings($db)) >= 1);
ck('leaveClub(p2)', (leaveClub($db, $clubId, $p2)['success'] ?? false) === true);

sec('OWNERSHIP VIEW + ASKING PRICE');
// release_player ran above, so re-assert ownership for this section.
q($db, "UPDATE players SET owner_id = ?, status = 'active' WHERE id = ?", [$p1, $p5Player]);
ck('getMyPlayers(p2) shows the auction win', count(array_filter(getMyPlayers($db, $p2), fn($r) => (int) $r['id'] === $playerId)) === 1, 'rows=' . count(getMyPlayers($db, $p2)));
ck('ensurePlayerProfile is idempotent + creates cards', ensurePlayerProfile($db, $agent2) === ensurePlayerProfile($db, $agent2) && ensurePlayerProfile($db, $agent2) > 0);
ck('provisioned user is a free agent', (int) col($db, "SELECT COUNT(*) FROM players WHERE user_id = ? AND status = 'free_agent'", [$agent2]) === 1);
ck('getMyPlayers(p1) shows the direct buy', count(array_filter(getMyPlayers($db, $p1), fn($r) => (int) $r['id'] === $p5Player)) === 1);
toggle('owner sets the asking price', setPlayerMarketValue($db, $p5Player, $p1, 500), true);
ck('asking price persisted', (float) col($db, "SELECT market_value FROM players WHERE id = ?", [$p5Player]) === 500.0);
toggle('stranger cannot price the player', setPlayerMarketValue($db, $p5Player, $p2, 10), false);
toggle('the player can price themself', setPlayerMarketValue($db, $p5Player, $p5, 600), true);
ck('negative price clamped', (setPlayerMarketValue($db, $p5Player, $p5, -50)['success'] ?? false) === true && (float) col($db, "SELECT market_value FROM players WHERE id = ?", [$p5Player]) === 0.0, (string) col($db, "SELECT market_value FROM players WHERE id = ?", [$p5Player]));
toggle('unknown player rejected', setPlayerMarketValue($db, 999999999, $p5, 10), false);

sec('AUCTION — TOP BIDDER CANNOT PAY');
q($db, "UPDATE players SET owner_id = ?, status = 'active' WHERE id = ?", [$p1, $p5Player]);
$auc2 = listPlayerForAuction($db, $p1, $p5Player, 100, 1);
$auc2Id = (int) col($db, "SELECT id FROM player_auctions WHERE player_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1", [$p5Player]);
ck('second auction listed', $auc2Id > 0 && ($auc2['success'] ?? false), rj($auc2));
placeBid($db, $auc2Id, $p2, 150);
$sellerBefore = bal($db, $p1);
$drained = bal($db, $p2);
q($db, "UPDATE users SET balance = 0 WHERE id = ?", [$p2]); // simulate a bidder who spent the money meanwhile
q($db, "UPDATE player_auctions SET end_time = NOW() - INTERVAL 1 MINUTE WHERE id = ?", [$auc2Id]);
settleExpiredAuctions($db);
ck('auction cancelled, not half-settled', col($db, "SELECT status FROM player_auctions WHERE id = ?", [$auc2Id]) === 'cancelled', (string) col($db, "SELECT CONCAT(status,'|',IFNULL(winner_id,0)) FROM player_auctions WHERE id = ?", [$auc2Id]));
ck('player stays with the seller', (int) col($db, "SELECT owner_id FROM players WHERE id = ?", [$p5Player]) === $p1);
ck('seller not credited for an unpaid auction', abs(bal($db, $p1) - $sellerBefore) < 0.01, 'delta=' . (bal($db, $p1) - $sellerBefore));
ck('broke bidder not charged', bal($db, $p2) === 0.0);
ck('auction result logged for the seller', (int) col($db, "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'auction'", [$p1]) >= 1);

sec('AGGREGATES');
ck('getTournamentsWithCounts', count(getTournamentsWithCounts($db, null, 50)) >= 1);
ck('status filter', count(getTournamentsWithCounts($db, 'upcoming', 50)) >= 1);
ck('getTournamentByIdWithCounts', !empty(getTournamentByIdWithCounts($db, $tid1)['title']));
ck('getTournamentParticipants', count(getTournamentParticipants($db, $tid1)) >= 3);
ck('getTournamentPlayerPool', is_array(getTournamentPlayerPool($db, $tid1)));
ck('getTournamentBracket', is_array(getTournamentBracket($db, $tid1)));
ck('getFeaturedTournamentSummary', is_array(getFeaturedTournamentSummary($db)));
ck('getLeaderboard(all)', is_array(getLeaderboard($db)));
ck('getAgentStats', is_array(getAgentStats($db, $agent)));
ck('getAgentTransactions', is_array(getAgentTransactions($db, $agent, 10)));

sec('MONEY CONSERVATION');
$totalEnd = moneyNow($db);
$delta = $totalEnd - $totalStart;
// Everything a user did is conserved, except the documented sinks.
$expected = 1000 /* p5 fixture top-up */ - (5.00 /* agent activation */ + 7.50 /* 5% auction fee */ + 200.00 /* direct buy of an unowned free agent */ + $drained /* fixture drain to make the bidder broke */);
echo "  seeded " . number_format($totalStart, 2) . " (+1000 p5) → end " . number_format($totalEnd, 2) . "   delta " . number_format($delta, 2) . "\n";
echo "  expected sinks: activation 5, auction fee 7.50, direct-buy 200, fixture drain " . number_format($drained, 2) . "\n";
ck('money delta matches the documented sinks exactly', abs($delta - $expected) < 0.01, "delta=$delta expected=$expected");
ck('no tournament money printed or lost', $delta <= $expected + 0.01, 'delta=' . $delta);

sec('CLEANUP');
qaCleanup($db);
ck('QA fixtures removed', (int) col($db, "SELECT COUNT(*) FROM users WHERE username LIKE 'qa\\_%'") === 0 && (int) col($db, "SELECT COUNT(*) FROM tournaments WHERE title LIKE '[QA]%'") === 0);

echo "\n=======================\n PASS: $P   FAIL: $F\n";
if ($FAILS) { echo " failing checks:\n"; foreach ($FAILS as $f) echo "  - $f\n"; }
echo "=======================\n";
exit($F > 0 ? 1 : 0);
