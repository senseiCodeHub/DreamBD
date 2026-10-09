<?php
require_once __DIR__ . '/../includes/session.php';
dream_start_session();
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../includes/functions.php';

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) { header('HTTP/1.1 401 Unauthorized'); exit; }

$tournamentId = (int)($_GET['tournament_id'] ?? 0);
if ($tournamentId <= 0) { header('HTTP/1.1 400 Bad Request'); exit; }

$db = Database::getInstance()->getConnection();

if (!userCanAccessTournamentRoom($db, $tournamentId, $userId)) { header('HTTP/1.1 403 Forbidden'); exit; }

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');
header('Access-Control-Allow-Origin: *');

if (ob_get_level()) ob_end_clean();

$lastMessageId = (int)($_GET['last_id'] ?? 0);
$lastStatus = '';
$lastBracketSig = '';
$tick = 0;

while (true) {
    if (connection_aborted()) break;

    $tournament = getTournamentByIdWithCounts($db, $tournamentId);
    $currentStatus = $tournament['status'] ?? '';

    if ($currentStatus !== $lastStatus) {
        echo "event: status\n";
        echo "data: " . json_encode(['status' => $currentStatus]) . "\n\n";
        $lastStatus = $currentStatus;
        if (flush()) @ob_flush();
    }

    // Bracket change detection: match count + max(updated_at) + pending reports
    $sig = '';
    try {
        $st = $db->query("SELECT COUNT(*) c, COALESCE(MAX(updated_at),'') m, COALESCE(MAX(report_state),'') r FROM tournament_matches WHERE tournament_id = " . (int)$tournamentId);
        $row = $st->fetch();
        $sig = ($row['c'] ?? '') . '|' . ($row['m'] ?? '') . '|' . ($row['r'] ?? '');
    } catch (Throwable $e) { $sig = 'err'; }

    if ($sig !== '' && $sig !== $lastBracketSig) {
        if ($lastBracketSig !== '') {
            // changed after initial load → push fresh bracket
            $bracket = bracketMatchesForRender($db, $tournamentId);
            echo "event: bracket\n";
            echo "data: " . json_encode(['bracket' => $bracket, 'status' => $currentStatus]) . "\n\n";
            if (flush()) @ob_flush();
        }
        $lastBracketSig = $sig;
    }

    $stmt = $db->prepare("SELECT * FROM tournament_chat_messages WHERE tournament_id = ? AND id > ? ORDER BY id ASC");
    $stmt->execute([$tournamentId, $lastMessageId]);
    $messages = $stmt->fetchAll();

    if ($messages) {
        $stmt = $db->prepare("
            SELECT tcm.*, u.full_name, u.username, u.avatar
            FROM tournament_chat_messages tcm
            JOIN users u ON u.id = tcm.sender_id
            WHERE tcm.id = ?
        ");
        $richMessages = [];
        foreach ($messages as $msg) {
            $stmt->execute([$msg['id']]);
            $richMsg = $stmt->fetch();
            if ($richMsg) $richMessages[] = $richMsg;
            $lastMessageId = (int)$msg['id'];
        }

        if ($richMessages) {
            echo "event: messages\n";
            echo "data: " . json_encode($richMessages) . "\n\n";
        }
    }

    // Lazy deadline sweep inside the long-lived loop (ADR-005) + heartbeat
    if ($tick % 15 === 0) {
        try { enforceTournamentDeadlines($db, $tournamentId); } catch (Throwable $e) {}
        echo ": heartbeat " . time() . "\n\n";
    }
    $tick++;

    if (ob_get_level()) @ob_flush();
    flush();

    sleep(2);
}
