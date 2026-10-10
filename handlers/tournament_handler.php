<?php
require_once __DIR__ . '/../includes/session.php';
dream_start_session();
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');
$response = ['success' => false, 'message' => ''];
$security = new Security();
$raw = file_get_contents('php://input');
$json = json_decode($raw, true);
$req = is_array($json) ? $json : $_POST;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$security->validateCSRFToken($req['csrf_token'] ?? '')) {
    $response['message'] = 'Invalid security token';
    echo json_encode($response); exit;
}

$userId = $_SESSION['user_id'] ?? null;
$action = $req['action'] ?? ($_GET['action'] ?? '');

try {
    $db = Database::getInstance()->getConnection();
    enforceTournamentDeadlines($db);
    // Lazy settle for expired player auctions — no cron (same pattern as deadlines).
    try { settleExpiredAuctions($db); } catch (Throwable $e) {}

    switch ($action) {
        // ─── AGENT ACTIONS ───
        case 'become_agent':
            if (!$userId) { $response['message'] = 'Please log in.'; break; }
            $fee = (float)($req['fee'] ?? 5.00);
            $result = createAgentAccount($db, $userId, $fee);
            $response = array_merge($response, $result);
            if ($response['success']) {
                $_SESSION['balance'] = $result['balance'];
            }
            break;

        case 'add_funds':
            if (!$userId) { $response['message'] = 'Please log in.'; break; }
            if ($_SESSION['role'] !== 'agent') { $response['message'] = 'Only agents can add funds.'; break; }
            $amount = abs((float)($req['amount'] ?? 0));
            if ($amount < 10) { $response['message'] = 'Minimum ৳10.'; break; }
            $result = addAgentFunds($db, $userId, $amount);
            $response = array_merge($response, $result);
            if ($response['success']) {
                $_SESSION['balance'] = $response['balance'];
            }
            break;

        case 'bkash_send_otp':
            // Demo: simulate sending OTP via bKash
            if (!$userId) { $response['message'] = 'Please log in.'; break; }
            if ($_SESSION['role'] !== 'agent') { $response['message'] = 'Only agents can add funds via bKash.'; break; }
            $phone = trim($req['bkash_phone'] ?? '');
            if (!preg_match('/^01[3-9]\d{8}$/', $phone)) {
                $response['message'] = 'Please enter a valid Bangladeshi phone number (01XXXXXXXXX).';
                break;
            }
            $otp = strval(rand(100000, 999999));
            $_SESSION['bkash_otp_hash'] = hash('sha256', $otp);
            $_SESSION['bkash_phone'] = $phone;
            $_SESSION['bkash_amount'] = abs((float)($req['bkash_amount'] ?? $req['amount'] ?? 0));
            $response = ['success' => true, 'message' => 'OTP sent to ' . substr($phone, 0, 5) . '***** (Demo: ' . $otp . ')', 'demo_otp' => $otp];
            break;

        case 'bkash_verify_otp':
            if (!$userId) { $response['message'] = 'Please log in.'; break; }
            if ($_SESSION['role'] !== 'agent') { $response['message'] = 'Only agents can add funds.'; break; }
            $inputOtp = trim($req['bkash_otp'] ?? '');
            $storedHash = $_SESSION['bkash_otp_hash'] ?? '';
            if (!$storedHash || hash('sha256', $inputOtp) !== $storedHash) {
                $response['message'] = 'Invalid OTP. Please try again.';
                break;
            }
            $amount = abs((float)($_SESSION['bkash_amount'] ?? 0));
            if ($amount < 10) { $response['message'] = 'Minimum ৳10.'; break; }
            // Clear OTP session
            unset($_SESSION['bkash_otp_hash'], $_SESSION['bkash_otp_plain'], $_SESSION['bkash_phone'], $_SESSION['bkash_amount']);
            // Record the bKash transaction
            try {
                $stmt = $db->prepare("INSERT INTO agent_transactions (agent_id, type, amount, reference_type, description, balance_before, balance_after) VALUES (?, 'credit', ?, 'bkash', 'bKash payment', 0, 0)");
                $stmt->execute([$userId, $amount]);
            } catch (Throwable $e) {}
            // Credit balance
            $result = addAgentFunds($db, $userId, $amount);
            $response = array_merge($response, $result);
            if ($response['success']) {
                $_SESSION['balance'] = $response['balance'];
                $response['message'] = 'bKash payment successful! ৳' . number_format($amount, 0) . ' added to your balance.';
            }
            break;

        case 'bkash_become_agent_send_otp':
            if (!$userId) { $response['message'] = 'Please log in.'; break; }
            $phone = trim($req['bkash_phone'] ?? '');
            if (!preg_match('/^01[3-9]\d{8}$/', $phone)) {
                $response['message'] = 'Please enter a valid Bangladeshi phone number (01XXXXXXXXX).';
                break;
            }
            $otp = strval(rand(100000, 999999));
            $_SESSION['bkash_otp_hash'] = hash('sha256', $otp);
            $_SESSION['bkash_phone'] = $phone;
            $_SESSION['bkash_amount'] = 5.00;
            $_SESSION['bkash_agent_activation'] = true;
            $response = ['success' => true, 'message' => 'OTP sent to ' . substr($phone, 0, 5) . '***** (Demo: ' . $otp . ')', 'demo_otp' => $otp];
            break;

        case 'bkash_become_agent_verify':
            if (!$userId) { $response['message'] = 'Please log in.'; break; }
            if (!($_SESSION['bkash_agent_activation'] ?? false)) {
                $response['message'] = 'No activation request found. Please start over.';
                break;
            }
            $inputOtp = trim($req['bkash_otp'] ?? '');
            $storedHash = $_SESSION['bkash_otp_hash'] ?? '';
            if (!$storedHash || hash('sha256', $inputOtp) !== $storedHash) {
                $response['message'] = 'Invalid OTP. Please try again.';
                break;
            }
            // Clear OTP session
            unset($_SESSION['bkash_otp_hash'], $_SESSION['bkash_otp_plain'], $_SESSION['bkash_phone'], $_SESSION['bkash_amount'], $_SESSION['bkash_agent_activation']);
            // Activate agent — $5 fee paid via bKash, so credit balance + set role
            try {
                $db->beginTransaction();
                $stmt = $db->prepare("SELECT balance, role FROM users WHERE id = ? FOR UPDATE");
                $stmt->execute([$userId]);
                $user = $stmt->fetch();
                if (!$user) { if ($db->inTransaction()) $db->rollBack(); $response['message'] = 'User not found.'; break; }
                if ($user['role'] === 'agent') { if ($db->inTransaction()) $db->rollBack(); $response['message'] = 'Already an agent.'; break; }
                $before = (float)$user['balance'];
                $after = $before + 5.00;
                $stmt = $db->prepare("UPDATE users SET role = 'agent', balance = ?, agent_verified_at = NOW() WHERE id = ?");
                $stmt->execute([$after, $userId]);
                $stmt = $db->prepare("INSERT INTO agent_transactions (agent_id, type, amount, balance_before, balance_after, reference_type, description) VALUES (?, 'credit', 5.00, ?, ?, 'bkash_agent_fee', 'Agent activation via bKash')");
                $stmt->execute([$userId, $before, $after]);
                $db->commit();
                $_SESSION['role'] = 'agent';
                $_SESSION['balance'] = $after;
                $response = ['success' => true, 'message' => 'bKash payment successful! You are now an agent. Welcome!', 'balance' => $after];
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                $response['message'] = 'Server error.';
            }
            break;

        case 'agent_create_tournament':
            if (!$userId || $_SESSION['role'] !== 'agent') { $response['message'] = 'Only agents can create tournaments.'; break; }
            $result = createTournamentByAgent($db, $userId, $req);
            $response = array_merge($response, $result);
            break;

        case 'agent_stats':
            if (!$userId || $_SESSION['role'] !== 'agent') { $response['message'] = 'Unauthorized.'; break; }
            $stats = getAgentStats($db, $userId);
            $txns = getAgentTransactions($db, $userId);
            $stmt = $db->prepare("SELECT balance FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            $response = ['success' => true, 'balance' => (float)($user['balance'] ?? 0), 'stats' => $stats, 'transactions' => $txns];
            break;

        // ─── TEAM ACTIONS ───
        case 'create_team':
            if (!$userId) { $response['message'] = 'Please log in.'; break; }
            $name = trim($req['name'] ?? '');
            if (strlen($name) < 2) { $response['message'] = 'Team name must be at least 2 characters.'; break; }
            $result = createTeam($db, $userId, $name, trim($req['game'] ?? ''), trim($req['description'] ?? ''));
            $response = array_merge($response, $result);
            break;

        case 'get_teams':
            if (!$userId) { $response['message'] = 'Please log in.'; break; }
            $teams = getUserTeams($db, $userId);
            $response = ['success' => true, 'teams' => $teams];
            break;

        case 'get_team_members':
            $teamId = (int)($req['team_id'] ?? 0);
            if (!$teamId) { $response['message'] = 'Invalid team.'; break; }
            $members = getTeamMembers($db, $teamId);
            $response = ['success' => true, 'members' => $members];
            break;

        case 'add_member':
            if (!$userId) { $response['message'] = 'Please log in.'; break; }
            $teamId = (int)($req['team_id'] ?? 0);
            $memberId = (int)($req['member_id'] ?? 0);
            if (!$teamId || !$memberId) { $response['message'] = 'Invalid request.'; break; }
            $roleStmt = $db->prepare("SELECT role FROM team_members WHERE team_id = ? AND user_id = ?");
            $roleStmt->execute([$teamId, $userId]);
            if ($roleStmt->fetchColumn() !== 'captain') { $response['message'] = 'Only the team captain can add members.'; break; }
            $result = addTeamMember($db, $teamId, $memberId);
            $response = array_merge($response, $result);
            break;

        case 'remove_member':
            if (!$userId) { $response['message'] = 'Please log in.'; break; }
            $teamId = (int)($req['team_id'] ?? 0);
            $memberId = (int)($req['member_id'] ?? 0);
            if (!$teamId || !$memberId) { $response['message'] = 'Invalid request.'; break; }
            $roleStmt = $db->prepare("SELECT role FROM team_members WHERE team_id = ? AND user_id = ?");
            $roleStmt->execute([$teamId, $userId]);
            if (!in_array($roleStmt->fetchColumn(), ['captain', 'co-captain'], true)) { $response['message'] = 'Only team leaders can remove members.'; break; }
            $result = removeTeamMember($db, $teamId, $memberId);
            $response = array_merge($response, $result);
            break;

        case 'delete_team':
            if (!$userId) { $response['message'] = 'Please log in.'; break; }
            $teamId = (int)($req['team_id'] ?? 0);
            if (!$teamId) { $response['message'] = 'Invalid team.'; break; }
            $result = deleteTeam($db, $teamId, $userId);
            $response = array_merge($response, $result);
            break;

        // ─── TOURNAMENT PARTICIPATION ───
        case 'register':
            if (!$userId) { $response['message'] = 'Please log in.'; break; }
            $tournamentId = (int)($req['tournament_id'] ?? 0);
            $teamId = (int)($req['team_id'] ?? 0);
            if (!$tournamentId) { $response['message'] = 'Invalid tournament.'; break; }
            if ($teamId > 0) {
                $result = joinTournamentWithTeam($db, $teamId, $tournamentId, $userId);
            } else {
                $teamName = trim($req['team_name'] ?? '');
                $result = registerForTournament($db, $userId, $tournamentId, $teamName);
            }
            $response = array_merge($response, $result);
            break;

        case 'unregister':
            if (!$userId) { $response['message'] = 'Please log in.'; break; }
            $tournamentId = (int)($req['tournament_id'] ?? 0);
            $result = unregisterFromTournament($db, $userId, $tournamentId);
            $response = array_merge($response, $result);
            break;

        case 'get_participants':
            $tournamentId = (int)($req['tournament_id'] ?? 0);
            if (!$tournamentId) { $response['message'] = 'Invalid.'; break; }
            $participants = getTournamentParticipants($db, $tournamentId);
            $response = ['success' => true, 'participants' => $participants];
            break;

        // ─── SUBMIT PAYMENT (admin approval) ───
        case 'update_tournament_status':
            if (!$userId) { $response['message'] = 'Please log in.'; break; }
            $tournamentId = (int)($req['tournament_id'] ?? 0);
            $status = trim((string)($req['status'] ?? ''));
            if (!in_array($status, ['upcoming', 'live', 'completed', 'cancelled'], true)) { $response['message'] = 'Invalid status.'; break; }
            $tournament = getTournamentByIdWithCounts($db, $tournamentId);
            if (!$tournament) { $response['message'] = 'Tournament not found.'; break; }
            if ((int)($tournament['agent_id'] ?? 0) !== (int)$userId || ($_SESSION['role'] ?? '') !== 'agent') { $response['message'] = 'Only the tournament agent can update status.'; break; }
            $stmt = $db->prepare("UPDATE tournaments SET status = ? WHERE id = ?");
            $stmt->execute([$status, $tournamentId]);
            $response = ['success' => true, 'message' => 'Tournament status updated.', 'status' => $status];
            break;

        case 'generate_bracket':
            if (!$userId || ($_SESSION['role'] ?? '') !== 'agent') { $response['message'] = 'Only agents can generate brackets.'; break; }
            $tournamentId = (int)($req['tournament_id'] ?? 0);
            if (!$tournamentId) { $response['message'] = 'Invalid tournament.'; break; }
            $result = bracketBuild($db, $tournamentId, (int)$userId);
            $response = array_merge($response, $result);
            break;

        case 'advance_winner':
            if (!$userId || ($_SESSION['role'] ?? '') !== 'agent') { $response['message'] = 'Only agents can advance winners.'; break; }
            $matchId = (int)($req['match_id'] ?? 0);
            $winnerTeamId = (int)($req['winner_team_id'] ?? 0);
            if (!$matchId || !$winnerTeamId) { $response['message'] = 'Invalid match or winner.'; break; }
            $result = bracketAgentResolve($db, $matchId, $winnerTeamId, (int)$userId, $req);
            $response = array_merge($response, $result);
            break;

        case 'report_score':
            if (!$userId) { $response['message'] = 'Please log in.'; break; }
            $matchId = (int)($req['match_id'] ?? 0);
            if (!$matchId) { $response['message'] = 'Invalid match.'; break; }
            $response = array_merge($response, bracketReportScore($db, $matchId, (int)$userId, (int)($req['score1'] ?? -1), (int)($req['score2'] ?? -1)));
            break;

        case 'confirm_score':
            if (!$userId) { $response['message'] = 'Please log in.'; break; }
            $matchId = (int)($req['match_id'] ?? 0);
            if (!$matchId) { $response['message'] = 'Invalid match.'; break; }
            $response = array_merge($response, bracketConfirmScore($db, $matchId, (int)$userId));
            break;

        case 'dispute_score':
            if (!$userId) { $response['message'] = 'Please log in.'; break; }
            $matchId = (int)($req['match_id'] ?? 0);
            if (!$matchId) { $response['message'] = 'Invalid match.'; break; }
            $response = array_merge($response, bracketDisputeScore($db, $matchId, (int)$userId, (string)($req['note'] ?? '')));
            break;

        case 'check_in':
            if (!$userId) { $response['message'] = 'Please log in.'; break; }
            $tournamentId = (int)($req['tournament_id'] ?? 0);
            if (!$tournamentId) { $response['message'] = 'Invalid tournament.'; break; }
            $response = array_merge($response, tnCheckin($db, $tournamentId, (int)$userId));
            break;

        case 'get_bracket_summary':
            $tournamentId = (int)($req['tournament_id'] ?? 0);
            if (!$tournamentId) { $response['message'] = 'Invalid tournament.'; break; }
            enforceTournamentDeadlines($db, $tournamentId);
            $response = ['success' => true, 'summary' => bracketSummary($db, $tournamentId), 'bracket' => bracketMatchesForRender($db, $tournamentId)];
            break;

        case 'get_bracket':
            $tournamentId = (int)($req['tournament_id'] ?? 0);
            if (!$tournamentId) { $response['message'] = 'Invalid tournament.'; break; }
            $response = ['success' => true, 'bracket' => bracketMatchesForRender($db, $tournamentId), 'summary' => bracketSummary($db, $tournamentId)];
            break;

        case 'get_tournament_room':
            $tournamentId = (int)($req['tournament_id'] ?? 0);
            if (!$userId) { $response['message'] = 'Please log in.'; break; }
            if (!$tournamentId || !userCanAccessTournamentRoom($db, $tournamentId, (int)$userId)) { $response['message'] = 'You do not have access to this tournament room.'; break; }
            enforceTournamentDeadlines($db, $tournamentId);
            $roomTournament = getTournamentByIdWithCounts($db, $tournamentId);
            $response = [
                'success' => true,
                'tournament' => $roomTournament,
                'checkin' => $roomTournament ? tnCheckinState($roomTournament, date('Y-m-d H:i:s')) : 'closed',
                'participants' => getTournamentParticipants($db, $tournamentId),
                'teams' => getTournamentTeams($db, $tournamentId),
                'player_pool' => getTournamentPlayerPool($db, $tournamentId),
                'messages' => getTournamentRoomMessages($db, $tournamentId),
                'results' => getTournamentResultsBundle($db, $tournamentId),
                'bracket' => bracketMatchesForRender($db, $tournamentId),
                'bracket_summary' => bracketSummary($db, $tournamentId),
            ];
            break;

        case 'get_tournament_chat':
            $tournamentId = (int)($req['tournament_id'] ?? 0);
            if (!$userId) { $response['message'] = 'Please log in.'; break; }
            if (!$tournamentId || !userCanAccessTournamentRoom($db, $tournamentId, (int)$userId)) { $response['message'] = 'You do not have access to this tournament room.'; break; }
            $response = ['success' => true, 'messages' => getTournamentRoomMessages($db, $tournamentId)];
            break;

        case 'send_tournament_chat':
            $tournamentId = (int)($req['tournament_id'] ?? 0);
            if (!$userId) { $response['message'] = 'Please log in.'; break; }
            $tournament = getTournamentByIdWithCounts($db, $tournamentId);
            if (!$tournament || !userCanAccessTournamentRoom($db, $tournamentId, (int)$userId)) { $response['message'] = 'You do not have access to this tournament room.'; break; }
            $messageType = trim((string)($req['message_type'] ?? 'text'));
            $isAgentOwner = (int)($tournament['agent_id'] ?? 0) === (int)$userId && ($_SESSION['role'] ?? '') === 'agent';
            if ($messageType === 'room_card' && !$isAgentOwner) { $response['message'] = 'Only the tournament agent can share room cards.'; break; }
            $result = createTournamentChatMessage($db, $tournamentId, (int)$userId, $messageType, $req);
            $response = array_merge($response, $result);
            if ($response['success']) {
                $response['messages'] = getTournamentRoomMessages($db, $tournamentId);
            }
            break;

        case 'cancel_tournament':
            if (!$userId || ($_SESSION['role'] ?? '') !== 'agent') { $response['message'] = 'Only agents can cancel tournaments.'; break; }
            $tournamentId = (int)($req['tournament_id'] ?? 0);
            if (!$tournamentId) { $response['message'] = 'Invalid tournament.'; break; }
            $result = cancelTournament($db, $tournamentId, (int)$userId);
            $response = array_merge($response, $result);
            break;

        case 'submit_tournament_results':
            $tournamentId = (int)($req['tournament_id'] ?? 0);
            if (!$userId || ($_SESSION['role'] ?? '') !== 'agent') { $response['message'] = 'Only agents can submit results.'; break; }
            $teamResults = $req['team_results'] ?? [];
            $playerResults = $req['player_results'] ?? [];
            if (is_string($teamResults)) {
                $decoded = json_decode($teamResults, true);
                $teamResults = is_array($decoded) ? $decoded : [];
            }
            if (is_string($playerResults)) {
                $decoded = json_decode($playerResults, true);
                $playerResults = is_array($decoded) ? $decoded : [];
            }
            $result = saveTournamentResults($db, $tournamentId, (int)$userId, is_array($teamResults) ? $teamResults : [], is_array($playerResults) ? $playerResults : []);
            $response = array_merge($response, $result);
            if ($response['success']) {
                $response['results'] = getTournamentResultsBundle($db, $tournamentId);
            }
            break;

        case 'submit_payment':
            if (!$userId) { $response['message'] = 'Please log in.'; break; }
            $method = trim($req['method'] ?? 'bkash');
            if (!in_array($method, ['bkash','nagad','rocket','other'])) { $response['message'] = 'Invalid payment method.'; break; }
            $senderPhone = trim($req['sender_phone'] ?? '');
            if (!preg_match('/^01[3-9]\d{8}$/', $senderPhone)) {
                $response['message'] = 'Please enter a valid Bangladeshi phone number (01XXXXXXXXX).';
                break;
            }
            $txid = strtoupper(trim($req['transaction_id'] ?? ''));
            if (strlen($txid) < 4) { $response['message'] = 'Please enter a valid Transaction ID.'; break; }
            $amount = abs((float)($req['amount'] ?? 0));
            if ($amount < 10) { $response['message'] = 'Minimum amount ৳10.'; break; }
            if ($amount > 3200) { $response['message'] = 'Maximum amount ৳3200.'; break; }
            try {
                $db->beginTransaction();
                $stmt = $db->prepare("SELECT id FROM payment_requests WHERE transaction_id = ? FOR UPDATE");
                $stmt->execute([$txid]);
                if ($stmt->fetch()) { if ($db->inTransaction()) $db->rollBack(); $response['message'] = 'This Transaction ID has already been used.'; break; }
                $purpose = trim($req['purpose'] ?? 'add_money');
                if (!in_array($purpose, ['add_money','agent_activation'])) $purpose = 'add_money';
                $stmt = $db->prepare("INSERT INTO payment_requests (user_id, method, sender_phone, transaction_id, amount, status, purpose) VALUES (?, ?, ?, ?, ?, 'pending', ?)");
                $stmt->execute([$userId, $method, $senderPhone, $txid, $amount, $purpose]);
                $db->commit();
                $msg = $purpose === 'agent_activation' ? 'Agent activation request submitted! Admin will verify and activate your account shortly.' : 'Payment request submitted! Admin will verify and approve shortly.';
                $ntype = $purpose === 'agent_activation' ? 'agent_activation' : 'payment_pending';
                createNotification($db, $userId, $userId, $ntype, $purpose === 'agent_activation' ? 'Agent activation request submitted. Waiting for admin approval.' : 'Payment of ৳' . number_format($amount, 0) . ' submitted. Waiting for admin approval.');
                if ($purpose === 'agent_activation') {
                    try {
                        $stmt = $db->prepare("SELECT email, username FROM users WHERE id = ?");
                        $stmt->execute([$userId]);
                        $u = $stmt->fetch();
                        if ($u && !empty($u['email'])) {
                            require_once __DIR__ . '/../includes/mail_templates.php';
                            require_once __DIR__ . '/../includes/mailer.php';
                            $body = MailTemplates::agentRequestSubmitted($u['username']);
                            Mailer::getInstance()->send($u['email'], 'Agent Activation Request Received', $body);
                        }
                    } catch (Throwable $em) { /* skip email error */ }
                }
                $response = ['success' => true, 'message' => $msg];
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                $response['message'] = 'Server error. Please try again.';
            }
            break;

        // ─── VERIFY PAYMENT (instant credit) ───
        case 'verify_payment':
            if (!$userId) { $response['message'] = 'Please log in.'; break; }
            $method = trim($req['method'] ?? 'bkash');
            if (!in_array($method, ['bkash','nagad','rocket'])) { $response['message'] = 'Invalid payment method.'; break; }
            $txid = strtoupper(trim($req['transaction_id'] ?? ''));
            if (strlen($txid) < 4) { $response['message'] = 'Please enter a valid Transaction ID.'; break; }
            $amount = abs((float)($req['amount'] ?? 0));
            if ($amount < 10) { $response['message'] = 'Minimum amount ৳10.'; break; }
            if ($amount > 3200) { $response['message'] = 'Maximum amount ৳3200.'; break; }
            try {
                $db->beginTransaction();
                $stmt = $db->prepare("SELECT id FROM payment_requests WHERE transaction_id = ? FOR UPDATE");
                $stmt->execute([$txid]);
                if ($stmt->fetch()) { if ($db->inTransaction()) $db->rollBack(); $response['message'] = 'This Transaction ID has already been used.'; break; }
                $stmt = $db->prepare("SELECT balance FROM users WHERE id = ? FOR UPDATE");
                $stmt->execute([$userId]);
                $user = $stmt->fetch();
                if (!$user) { $db->rollBack(); $response['message'] = 'User not found.'; break; }
                $before = (float)$user['balance'];
                $after = $before + $amount;
                $stmt = $db->prepare("UPDATE users SET balance = ? WHERE id = ?");
                $stmt->execute([$after, $userId]);
                $stmt = $db->prepare("INSERT INTO payment_requests (user_id, method, sender_phone, transaction_id, amount, status, admin_id) VALUES (?, ?, ?, ?, ?, 'completed', ?)");
                $stmt->execute([$userId, $method, '01700000000', $txid, $amount, $userId]);
                $pid = (int)$db->lastInsertId();
                $stmt = $db->prepare("INSERT INTO agent_transactions (agent_id, type, amount, balance_before, balance_after, reference_type, reference_id, description) VALUES (?, 'credit', ?, ?, ?, 'payment_request', ?, ?)");
                $stmt->execute([$userId, $amount, $before, $after, $pid, 'Payment verified: ' . $method . ' ' . $txid]);
                $db->commit();
                $_SESSION['balance'] = $after;
                createNotification($db, $userId, $userId, 'payment_completed', 'Payment verified! ৳' . number_format($amount, 0) . ' added to your balance.');
                $response = ['success' => true, 'message' => 'Payment verified! ৳' . number_format($amount, 0) . ' added to your balance.', 'balance' => $after];
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                $response['message'] = 'Server error. Please try again.';
            }
            break;

        // ─── BUY COINS (deduct from balance) ───
        case 'buy_coins':
            if (!$userId) { $response['message'] = 'Please log in.'; break; }
            $amount = (int)($req['amount'] ?? 0);
            if ($amount < 10) { $response['message'] = 'Minimum 10 bronze coins.'; break; }
            try {
                $db->beginTransaction();
                $stmt = $db->prepare("SELECT balance, bronze_coins FROM users WHERE id = ? FOR UPDATE");
                $stmt->execute([$userId]);
                $u = $stmt->fetch();
                if (!$u) { $db->rollBack(); $response['message'] = 'User not found.'; break; }
                $bal = (float)$u['balance'];
                if ($bal < $amount) { $db->rollBack(); $response['message'] = 'Insufficient balance. You need ৳' . number_format($amount, 0) . ' but have ৳' . number_format($bal, 0) . '.'; break; }
                $stmt = $db->prepare("UPDATE users SET balance = balance - ?, bronze_coins = bronze_coins + ? WHERE id = ?");
                $stmt->execute([$amount, $amount, $userId]);
                $stmt = $db->prepare("INSERT INTO coin_transactions (from_user_id, to_user_id, type, coin_type, amount, description) VALUES (NULL, ?, 'purchase', 'bronze', ?, 'Bronze coin purchase from balance')");
                $stmt->execute([$userId, $amount]);
                $db->commit();
                $_SESSION['balance'] = $bal - $amount;
                createNotification($db, $userId, $userId, 'payment_completed', number_format($amount, 0) . ' bronze coins purchased from balance.');
                $response = ['success' => true, 'message' => number_format($amount, 0) . ' bronze coins purchased!'];
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                $response['message'] = 'Server error.';
            }
            break;

        case 'search_users':
            if (!$userId) { $response['users'] = []; break; }
            $query = trim($req['query'] ?? '');
            if (strlen($query) < 2) { $response['users'] = []; break; }
            try {
                $stmt = $db->prepare("SELECT id, username, full_name FROM users WHERE (username LIKE ? OR full_name LIKE ?) AND id != ? LIMIT 8");
                $like = '%' . $query . '%';
                $stmt->execute([$like, $like, $userId]);
                $response['users'] = $stmt->fetchAll();
            } catch (Throwable $e) {
                $response['users'] = [];
            }
            break;

        case 'transfer_coins':
            if (!$userId) { $response['message'] = 'Please log in.'; break; }
            $receiver = trim($req['receiver'] ?? '');
            $amount = (int)($req['amount'] ?? 0);
            if ($amount < 1) { $response['message'] = 'Minimum 1 bronze coin.'; break; }
            if (empty($receiver)) { $response['message'] = 'Please enter a username or email.'; break; }
            // 24h limit: check last transfer_sent
            try {
                $stmt = $db->prepare("SELECT created_at FROM coin_transactions WHERE from_user_id = ? AND type = 'transfer_sent' AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR) LIMIT 1");
                $stmt->execute([$userId]);
                if ($stmt->fetch()) { $response['message'] = 'You can only send coins once every 24 hours. Please wait.'; break; }
            } catch (Throwable $e) {}
            try {
                $db->beginTransaction();
                $stmt = $db->prepare("SELECT id, username, full_name, bronze_coins FROM users WHERE (username = ? OR email = ?) AND id != ? LIMIT 1");
                $stmt->execute([$receiver, $receiver, $userId]);
                $target = $stmt->fetch();
                if (!$target) { $db->rollBack(); $response['message'] = 'User not found.'; break; }
                $stmt = $db->prepare("SELECT bronze_coins FROM users WHERE id = ? FOR UPDATE");
                $stmt->execute([$userId]);
                $sender = $stmt->fetch();
                if (!$sender || (int)$sender['bronze_coins'] < $amount) { $db->rollBack(); $response['message'] = 'Insufficient bronze coins. You have ' . (int)($sender['bronze_coins']??0) . '.'; break; }
                $stmt = $db->prepare("UPDATE users SET bronze_coins = bronze_coins - ? WHERE id = ?");
                $stmt->execute([$amount, $userId]);
                $stmt = $db->prepare("UPDATE users SET bronze_coins = bronze_coins + ? WHERE id = ?");
                $stmt->execute([$amount, (int)$target['id']]);
                $stmt = $db->prepare("INSERT INTO coin_transactions (from_user_id, to_user_id, type, coin_type, amount, description) VALUES (?, ?, 'transfer_sent', 'bronze', ?, ?)");
                $stmt->execute([$userId, (int)$target['id'], $amount, 'Sent to ' . ($target['full_name'] ?: $target['username'])]);
                $stmt = $db->prepare("INSERT INTO coin_transactions (from_user_id, to_user_id, type, coin_type, amount, description) VALUES (?, ?, 'transfer_received', 'bronze', ?, ?)");
                $stmt->execute([$userId, (int)$target['id'], $amount, 'Received from ' . ($_SESSION['full_name'] ?: $_SESSION['username'])]);
                $db->commit();
                $response = ['success' => true, 'message' => number_format($amount) . ' bronze coins sent to ' . htmlspecialchars($target['full_name'] ?: $target['username']) . '!'];
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                $response['message'] = 'Server error. Please try again.';
            }
            break;



        // ─── P2P: Execute Trade (buy from sell offer) ───
        case 'p2p_buy':
            if (!$userId) { $response['message'] = 'Please log in.'; break; }
            $offerId = (int)($req['offer_id'] ?? 0);
            $qty = abs((int)($req['quantity'] ?? 0));
            if ($qty < 1) { $response['message'] = 'Quantity must be at least 1.'; break; }
            try {
                $db->beginTransaction();
                $stmt = $db->prepare("SELECT * FROM p2p_offers WHERE id = ? AND type = 'sell' AND status = 'active' FOR UPDATE");
                $stmt->execute([$offerId]);
                $offer = $stmt->fetch();
                if (!$offer) { $db->rollBack(); $response['message'] = 'Offer not available.'; break; }
                if ($qty > (int)$offer['remaining']) { $db->rollBack(); $response['message'] = 'Only ' . $offer['remaining'] . ' coins remaining.'; break; }
                $max = (int)$offer['max_amount'];
                $min = (int)$offer['min_amount'];
                if ($qty < $min) { $db->rollBack(); $response['message'] = 'Minimum ' . $min . ' coins.'; break; }
                if ($max > 0 && $qty > $max) { $db->rollBack(); $response['message'] = 'Maximum ' . $max . ' coins.'; break; }
                $totalPrice = $qty * (float)$offer['price_per_coin'];
                $coinCol = $offer['coin_type'] . '_coins';
                // Check buyer balance (in BDT)
                $stmt = $db->prepare("SELECT balance FROM users WHERE id = ? FOR UPDATE");
                $stmt->execute([$userId]);
                $buyer = $stmt->fetch();
                if (!$buyer || (float)$buyer['balance'] < $totalPrice) { $db->rollBack(); $response['message'] = 'Insufficient balance. Need ৳' . number_format($totalPrice, 0) . '.'; break; }
                // Deduct BDT from buyer, add coins to buyer
                $stmt = $db->prepare("UPDATE users SET balance = balance - ? WHERE id = ?");
                $stmt->execute([$totalPrice, $userId]);
                $stmt = $db->prepare("UPDATE users SET $coinCol = $coinCol + ? WHERE id = ?");
                $stmt->execute([$qty, $userId]);
                // Add BDT to seller (agent)
                $stmt = $db->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
                $stmt->execute([$totalPrice, (int)$offer['agent_id']]);
                // Deduct coins from seller
                $stmt = $db->prepare("UPDATE users SET $coinCol = $coinCol - ? WHERE id = ?");
                $stmt->execute([$qty, (int)$offer['agent_id']]);
                // Update remaining
                $newRemaining = (int)$offer['remaining'] - $qty;
                $newStatus = $newRemaining < 1 ? 'completed' : 'active';
                $stmt = $db->prepare("UPDATE p2p_offers SET remaining = ?, status = ? WHERE id = ?");
                $stmt->execute([$newRemaining, $newStatus, $offerId]);
                // Create trade record
                $stmt = $db->prepare("INSERT INTO p2p_trades (offer_id, seller_id, buyer_id, coin_type, quantity, total_price, status, payment_method) VALUES (?, ?, ?, ?, ?, ?, 'completed', 'balance')");
                $stmt->execute([$offerId, (int)$offer['agent_id'], $userId, $offer['coin_type'], $qty, $totalPrice]);
                $tradeId = $db->lastInsertId();
                // Log coin transactions
                $stmt = $db->prepare("INSERT INTO coin_transactions (from_user_id, to_user_id, type, coin_type, amount, price, description, ref_id) VALUES (?, ?, 'p2p_sell', ?, ?, ?, ?, ?)");
                $stmt->execute([(int)$offer['agent_id'], $userId, $offer['coin_type'], $qty, $totalPrice, 'P2P buy from offer #' . $offerId, $tradeId]);
                $stmt = $db->prepare("INSERT INTO coin_transactions (from_user_id, to_user_id, type, coin_type, amount, price, description, ref_id) VALUES (?, ?, 'p2p_buy', ?, ?, ?, ?, ?)");
                $stmt->execute([(int)$offer['agent_id'], $userId, $offer['coin_type'], $qty, $totalPrice, 'P2P sell to offer #' . $offerId, $tradeId]);
                $db->commit();
                $response = ['success' => true, 'message' => $qty . ' ' . $offer['coin_type'] . ' coins purchased for ৳' . number_format($totalPrice, 0) . '!'];
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                $response['message'] = 'Server error. Please try again.';
            }
            break;

        case 'p2p_sell':
        // ─── P2P: Create Offer (merchants only) ───
            if (!$userId) { $response['message'] = 'Please log in.'; break; }
            $offerId = (int)($req['offer_id'] ?? 0);
            $qty = abs((int)($req['quantity'] ?? 0));
            if ($qty < 1) { $response['message'] = 'Quantity must be at least 1.'; break; }
            try {
                $db->beginTransaction();
                $stmt = $db->prepare("SELECT * FROM p2p_offers WHERE id = ? AND type = 'buy' AND status = 'active' FOR UPDATE");
                $stmt->execute([$offerId]);
                $offer = $stmt->fetch();
                if (!$offer) { $db->rollBack(); $response['message'] = 'Offer not available.'; break; }
                if ($qty > (int)$offer['remaining']) { $db->rollBack(); $response['message'] = 'Only ' . $offer['remaining'] . ' coins remaining.'; break; }
                $max = (int)$offer['max_amount'];
                $min = (int)$offer['min_amount'];
                if ($qty < $min) { $db->rollBack(); $response['message'] = 'Minimum ' . $min . ' coins.'; break; }
                if ($max > 0 && $qty > $max) { $db->rollBack(); $response['message'] = 'Maximum ' . $max . ' coins.'; break; }
                $totalPrice = $qty * (float)$offer['price_per_coin'];
                $coinCol = $offer['coin_type'] . '_coins';
                // Check seller has enough coins
                $stmt = $db->prepare("SELECT $coinCol, balance FROM users WHERE id = ? FOR UPDATE");
                $stmt->execute([$userId]);
                $seller = $stmt->fetch();
                if (!$seller || (int)$seller[$coinCol] < $qty) { $db->rollBack(); $response['message'] = 'Insufficient ' . $offer['coin_type'] . ' coins.'; break; }
                // Deduct coins from seller, add BDT
                $stmt = $db->prepare("UPDATE users SET $coinCol = $coinCol - ?, balance = balance + ? WHERE id = ?");
                $stmt->execute([$qty, $totalPrice, $userId]);
                // Deduct BDT from agent (buyer), add coins
                $stmt = $db->prepare("UPDATE users SET balance = balance - ?, $coinCol = $coinCol + ? WHERE id = ?");
                $stmt->execute([$totalPrice, $qty, (int)$offer['agent_id']]);
                $newRemaining = (int)$offer['remaining'] - $qty;
                $newStatus = $newRemaining < 1 ? 'completed' : 'active';
                $stmt = $db->prepare("UPDATE p2p_offers SET remaining = ?, status = ? WHERE id = ?");
                $stmt->execute([$newRemaining, $newStatus, $offerId]);
                $stmt = $db->prepare("INSERT INTO p2p_trades (offer_id, seller_id, buyer_id, coin_type, quantity, total_price, status, payment_method) VALUES (?, ?, ?, ?, ?, ?, 'completed', 'balance')");
                $stmt->execute([$offerId, $userId, (int)$offer['agent_id'], $offer['coin_type'], $qty, $totalPrice]);
                $tradeId = $db->lastInsertId();
                $stmt = $db->prepare("INSERT INTO coin_transactions (from_user_id, to_user_id, type, coin_type, amount, price, description, ref_id) VALUES (?, ?, 'p2p_sell', ?, ?, ?, ?, ?)");
                $stmt->execute([$userId, (int)$offer['agent_id'], $offer['coin_type'], $qty, $totalPrice, 'P2P sell to offer #' . $offerId, $tradeId]);
                $stmt = $db->prepare("INSERT INTO coin_transactions (from_user_id, to_user_id, type, coin_type, amount, price, description, ref_id) VALUES (?, ?, 'p2p_buy', ?, ?, ?, ?, ?)");
                $stmt->execute([$userId, (int)$offer['agent_id'], $offer['coin_type'], $qty, $totalPrice, 'P2P buy from offer #' . $offerId, $tradeId]);
                $db->commit();
                $response = ['success' => true, 'message' => $qty . ' ' . $offer['coin_type'] . ' coins sold for ৳' . number_format($totalPrice, 0) . '!'];
            } catch (Throwable $e) {
                $db->rollBack();
                $response['message'] = 'Server error.';
            }
            break;

        case 'update_profile':
            if (!$userId) { $response['message'] = 'Please log in.'; break; }
            $nickname = trim($req['nickname'] ?? $_SESSION['username'] ?? '');
            $skillLevel = trim($req['skill_level'] ?? '');
            $favoriteGame = trim($req['favorite_game'] ?? '');
            $bio = trim($req['bio'] ?? '');
            $discord = trim($req['discord'] ?? '');
            $facebook = trim($req['facebook'] ?? '');
            $instagram = trim($req['instagram'] ?? '');
            $youtube = trim($req['youtube'] ?? '');

            $_SESSION['nickname'] = $nickname;
            $_SESSION['skill_level'] = $skillLevel;
            $_SESSION['favorite_game'] = $favoriteGame;
            $_SESSION['bio'] = $bio;
            $_SESSION['discord'] = $discord;
            $_SESSION['facebook'] = $facebook;
            $_SESSION['instagram'] = $instagram;
            $_SESSION['youtube'] = $youtube;

            try {
                $stmt = $db->prepare("UPDATE users SET nickname = ?, skill_level = ?, favorite_game = ?, bio = ?, discord = ?, facebook = ?, instagram = ?, youtube = ? WHERE id = ?");
                $stmt->execute([$nickname, $skillLevel, $favoriteGame, $bio, $discord, $facebook, $instagram, $youtube, $userId]);
            } catch (Throwable $e) {
                error_log("Profile update error: " . $e->getMessage());
                $response = ['success' => true, 'message' => 'Profile updated (session only).'];
                break;
            }
            $response = ['success' => true, 'message' => 'Profile updated!'];
            break;

        // ─── GAME SKILLS ───
        case 'save_game_skill':
            if (!$userId) { $response['message'] = 'Please log in.'; break; }
            $game = trim($req['game'] ?? '');
            $skillLevel = trim($req['skill_level'] ?? '');
            if (!$game) { $response['message'] = 'Game required.'; break; }
            try {
                if ($skillLevel === '') {
                    $stmt = $db->prepare("DELETE FROM game_skills WHERE user_id = ? AND game = ?");
                    $stmt->execute([$userId, $game]);
                    $response = ['success' => true, 'message' => 'Skill removed!'];
                } else {
                    $stmt = $db->prepare("INSERT INTO game_skills (user_id, game, skill_level) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE skill_level = VALUES(skill_level)");
                    $stmt->execute([$userId, $game, $skillLevel]);
                    $response = ['success' => true, 'message' => 'Skill saved!'];
                }
            } catch (Throwable $e) {
                error_log("save_game_skill error: " . $e->getMessage());
                $response = ['success' => false, 'message' => 'Failed to process skill.'];
            }
            break;

        // ─── CLUB ACTIONS ───
        case 'create_club':
            if (!$userId) { $response['message'] = 'Please log in.'; break; }
            $name = trim($req['name'] ?? '');
            $tag = trim($req['tag'] ?? '');
            $colour = trim($req['colour'] ?? '#7c3aed');
            $description = trim($req['description'] ?? '');
            $region = trim($req['region'] ?? '');
            if (!$name || !$tag) { $response['message'] = 'Name and tag required.'; break; }
            $response = createClub($db, $userId, $name, $tag, $colour, $description, $region);
            break;

        case 'get_club':
            $clubId = (int)($req['club_id'] ?? 0);
            if (!$clubId) { $response['message'] = 'Club ID required.'; break; }
            $club = getClub($db, $clubId);
            if ($club) { $response = ['success' => true, 'club' => $club]; }
            else { $response['message'] = 'Club not found.'; }
            break;

        case 'get_my_clubs':
            if (!$userId) { $response['message'] = 'Please log in.'; break; }
            $response = ['success' => true, 'clubs' => getUserClubs($db, $userId)];
            break;

        case 'get_club_members':
            $clubId = (int)($req['club_id'] ?? 0);
            if (!$clubId) { $response['message'] = 'Club ID required.'; break; }
            $response = ['success' => true, 'members' => getClubMembers($db, $clubId)];
            break;

        case 'join_club':
            if (!$userId) { $response['message'] = 'Please log in.'; break; }
            $clubId = (int)($req['club_id'] ?? 0);
            if (!$clubId) { $response['message'] = 'Club ID required.'; break; }
            $response = joinClub($db, $clubId, $userId);
            break;

        case 'leave_club':
            if (!$userId) { $response['message'] = 'Please log in.'; break; }
            $clubId = (int)($req['club_id'] ?? 0);
            if (!$clubId) { $response['message'] = 'Club ID required.'; break; }
            $response = leaveClub($db, $clubId, $userId);
            break;

        case 'update_club':
            if (!$userId) { $response['message'] = 'Please log in.'; break; }
            $clubId = (int)($req['club_id'] ?? 0);
            if (!$clubId) { $response['message'] = 'Club ID required.'; break; }
            $data = [];
            foreach (['name','tag','colour','description','region','logo'] as $k) {
                if (isset($req[$k])) $data[$k] = $req[$k];
            }
            $response = updateClub($db, $clubId, $userId, $data);
            break;

        // ─── PLAYER MARKET / AUCTION ───
        case 'set_player_value':
            if (!$userId) { $response['message'] = 'Please log in.'; break; }
            $playerId = (int)($req['player_id'] ?? 0);
            $value = (float)($req['market_value'] ?? 0);
            if (!$playerId || $value < 0) { $response['message'] = 'Invalid parameters.'; break; }
            $response = setPlayerMarketValue($db, $playerId, (int)$userId, $value);
            break;

        case 'list_player_auction':
            if (!$userId) { $response['message'] = 'Please log in.'; break; }
            $playerId = (int)($req['player_id'] ?? 0);
            $basePrice = (float)($req['base_price'] ?? 0);
            $duration = (int)($req['duration_hours'] ?? 24);
            if (!$playerId || $basePrice <= 0) { $response['message'] = 'Invalid parameters.'; break; }
            $response = listPlayerForAuction($db, $userId, $playerId, $basePrice, $duration);
            break;

        case 'place_bid':
            if (!$userId) { $response['message'] = 'Please log in.'; break; }
            $auctionId = (int)($req['auction_id'] ?? 0);
            $amount = (float)($req['amount'] ?? 0);
            if (!$auctionId || $amount <= 0) { $response['message'] = 'Invalid parameters.'; break; }
            $response = placeBid($db, $auctionId, $userId, $amount);
            break;

        case 'buy_player':
            if (!$userId) { $response['message'] = 'Please log in.'; break; }
            $playerId = (int)($req['player_id'] ?? 0);
            $price = (float)($req['price'] ?? 0);
            if (!$playerId || $price <= 0) { $response['message'] = 'Invalid parameters.'; break; }
            $response = buyPlayerDirect($db, $playerId, $userId, $price);
            break;

        case 'release_player':
            if (!$userId) { $response['message'] = 'Please log in.'; break; }
            $playerId = (int)($req['player_id'] ?? 0);
            if (!$playerId) { $response['message'] = 'Player ID required.'; break; }
            $response = releasePlayer($db, $playerId, $userId);
            break;

        case 'hire_player':
            if (!$userId) { $response['message'] = 'Please log in.'; break; }
            $playerId = (int)($req['player_id'] ?? 0);
            $clubId = (int)($req['club_id'] ?? 0);
            if (!$playerId || !$clubId) { $response['message'] = 'Player and Club required.'; break; }
            $response = hirePlayerToClub($db, $playerId, $clubId, $userId);
            break;

        case 'fire_player':
            if (!$userId) { $response['message'] = 'Please log in.'; break; }
            $playerUserId = (int)($req['player_user_id'] ?? 0);
            $clubId = (int)($req['club_id'] ?? 0);
            if (!$playerUserId || !$clubId) { $response['message'] = 'Player and Club required.'; break; }
            $response = firePlayerFromClub($db, $playerUserId, $clubId, $userId);
            break;

        // ─── DATA FETCH ───
        case 'get_leaderboard':
            $tournamentId = isset($req['tournament_id']) ? (int)$req['tournament_id'] : null;
            $clubId = isset($req['club_id']) ? (int)$req['club_id'] : null;
            $limit = (int)($req['limit'] ?? 50);
            $response = ['success' => true, 'leaderboard' => getLeaderboard($db, $tournamentId, $clubId, $limit)];
            break;

        case 'get_my_players':
            if (!$userId) { $response['message'] = 'Please log in.'; break; }
            ensurePlayerProfile($db, (int) $userId);
            $response = ['success' => true, 'players' => getMyPlayers($db, (int) $userId, 50)];
            break;

        case 'get_market_players':
            $status = $req['status'] ?? 'free_agent';
            $clubId = isset($req['club_id']) ? (int)$req['club_id'] : null;
            $limit = (int)($req['limit'] ?? 50);
            $response = ['success' => true, 'players' => getMarketPlayers($db, $status, $clubId, $limit)];
            break;

        case 'get_player_detail':
            $playerUserId = (int)($req['user_id'] ?? 0);
            if (!$playerUserId) { $response['message'] = 'User ID required.'; break; }
            $detail = getPlayerDetail($db, $playerUserId);
            if ($detail) { $response = ['success' => true, 'player' => $detail]; }
            else { $response['message'] = 'Player not found.'; }
            break;

        case 'get_active_auctions':
            $limit = (int)($req['limit'] ?? 30);
            $response = ['success' => true, 'auctions' => getActiveAuctions($db, $limit)];
            break;

        case 'get_club_standings':
            $response = ['success' => true, 'standings' => getClubStandings($db)];
            break;

        case 'settle_auctions':
            if (!$userId) { $response['message'] = 'Please log in.'; break; }
            $count = settleExpiredAuctions($db);
            $response = ['success' => true, 'settled' => $count, 'message' => "$count auctions settled."];
            break;

        default:
            $response['message'] = 'Unknown action.';
    }
} catch (Throwable $e) {
    $response['message'] = 'Server error.';
}

echo json_encode($response);
exit;
