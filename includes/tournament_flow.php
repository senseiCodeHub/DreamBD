<?php
/**
 * Tournament domain flow — escrow money movements, check-in windows,
 * lazy deadline enforcement (no cron).
 *
 * Escrow model (ADR-006):
 *   register  : player.balance -= fee  -> tournaments.fee_escrow  += fee
 *   refund    : tournaments.fee_escrow -= fee -> player.balance    += fee   (always solvent)
 *   complete  : prize payouts draw prize_escrow first, then fee_escrow
 *               leftover (fee_escrow + prize_escrow) -> agent balance + ledger
 *   cancel    : all participant refunds from fee_escrow, prize_escrow -> agent
 */

// ─── ESCROW: collect entry fee (inside caller's transaction) ───

function tnEscrowCollectFee(PDO $pdo, int $tournamentId, float $fee): bool {
    if ($fee <= 0) return true;
    $stmt = $pdo->prepare("UPDATE tournaments SET fee_escrow = fee_escrow + ? WHERE id = ?");
    $stmt->execute([$fee, $tournamentId]);
    return $stmt->rowCount() > 0;
}

/**
 * Refund an entry fee from escrow to a player (inside caller's transaction).
 * Falls back to agent debit only for legacy tournaments with no escrow recorded.
 */
function tnEscrowRefundFee(PDO $pdo, array $tournament, int $userId, float $fee): bool {
    if ($fee <= 0) return true;
    $tournamentId = (int) $tournament['id'];

    $stmt = $pdo->prepare("UPDATE tournaments SET fee_escrow = GREATEST(0, fee_escrow - ?) WHERE id = ? AND fee_escrow >= ?");
    $stmt->execute([$fee, $tournamentId, $fee]);
    if ($stmt->rowCount() > 0) {
        return true;
    }

    // Legacy fallback: escrow empty (pre-v2 data) — debit the agent if possible.
    $agentId = (int) ($tournament['agent_id'] ?? 0);
    if ($agentId > 0) {
        $stmt = $pdo->prepare("UPDATE users SET balance = balance - ? WHERE id = ? AND balance >= ?");
        $stmt->execute([$fee, $agentId, $fee]);
        if ($stmt->rowCount() > 0) {
            $before = (float) $pdo->query("SELECT balance FROM users WHERE id = " . $agentId)->fetchColumn();
            $pdo->prepare("INSERT INTO agent_transactions (agent_id, type, amount, balance_before, balance_after, reference_type, reference_id, description) VALUES (?, 'debit', ?, ?, ?, 'entry_fee_refund', ?, 'Entry refunded to participant (legacy, no escrow)')")
                ->execute([$agentId, $fee, $before + $fee, $before, $tournamentId]);
            return true;
        }
    }
    // Nothing left to refund from — money is lost rather than printed.
    return false;
}

/**
 * Pay a prize amount to a winner from escrow (inside caller's transaction).
 * Draws prize_escrow first, then fee_escrow. Returns false if escrow can't cover it.
 */
function tnEscrowPayPrize(PDO $pdo, int $tournamentId, float $amount): bool {
    if ($amount <= 0) return true;

    $stmt = $pdo->prepare("UPDATE tournaments SET prize_escrow = prize_escrow - ? WHERE id = ? AND prize_escrow >= ?");
    $stmt->execute([$amount, $tournamentId, $amount]);
    if ($stmt->rowCount() > 0) return true;

    $stmt = $pdo->prepare("UPDATE tournaments SET fee_escrow = fee_escrow - ? WHERE id = ? AND fee_escrow >= ?");
    $stmt->execute([$amount, $tournamentId, $amount]);
    return $stmt->rowCount() > 0;
}

/** Total escrow available for prizes (prize pool + collected entry fees). */
function tnEscrowAvailable(PDO $pdo, int $tournamentId): float {
    $stmt = $pdo->prepare("SELECT prize_escrow + fee_escrow FROM tournaments WHERE id = ?");
    $stmt->execute([$tournamentId]);
    return (float) $stmt->fetchColumn();
}

/**
 * Release leftover escrow to the tournament agent — fee pool plus any unspent
 * prize money — and mark the tournament as settled. Idempotent via escrow_released.
 * Must be called inside a transaction.
 */
function tnEscrowReleaseToAgent(PDO $pdo, int $tournamentId): array {
    $stmt = $pdo->prepare("SELECT * FROM tournaments WHERE id = ? FOR UPDATE");
    $stmt->execute([$tournamentId]);
    $t = $stmt->fetch();
    if (!$t) return ['released' => 0.0];

    // Atomic claim — only one caller can flip the flag.
    $stmt = $pdo->prepare("UPDATE tournaments SET escrow_released = 1, fee_escrow = 0, prize_escrow = 0 WHERE id = ? AND escrow_released = 0");
    $stmt->execute([$tournamentId]);
    if ($stmt->rowCount() === 0) return ['released' => 0.0];

    $total = (float) $t['fee_escrow'] + (float) $t['prize_escrow'];
    $agentId = (int) ($t['agent_id'] ?? 0);
    if ($total <= 0 || $agentId <= 0) return ['released' => 0.0];

    $stmt = $pdo->prepare("SELECT balance FROM users WHERE id = ? FOR UPDATE");
    $stmt->execute([$agentId]);
    $before = (float) $stmt->fetchColumn();
    $after = $before + $total;
    $pdo->prepare("UPDATE users SET balance = ? WHERE id = ?")->execute([$after, $agentId]);
    $pdo->prepare("INSERT INTO agent_transactions (agent_id, type, amount, balance_before, balance_after, reference_type, reference_id, description) VALUES (?, 'credit', ?, ?, ?, 'tournament_settlement', ?, 'Tournament escrow released (entry fees + unspent prize)')")
        ->execute([$agentId, $total, $before, $after, $tournamentId]);

    return ['released' => $total];
}

// ─── CHECK-IN WINDOWS ───

/** When the check-in window opens for a tournament (null = no starts_at yet). */
function tnCheckinOpensAt(array $tournament): ?string {
    if (!empty($tournament['checkin_opens_at'])) return $tournament['checkin_opens_at'];
    if (empty($tournament['starts_at'])) return null;
    $minutes = max(0, (int) ($tournament['checkin_minutes'] ?? 30));
    return date('Y-m-d H:i:s', strtotime($tournament['starts_at']) - $minutes * 60);
}

function tnCheckinClosesAt(array $tournament): ?string {
    return !empty($tournament['starts_at']) ? $tournament['starts_at'] : null;
}

function tnCheckinState(array $tournament, string $now): string {
    if (!in_array($tournament['status'] ?? '', ['upcoming', 'live'], true)) return 'closed';
    $opens = tnCheckinOpensAt($tournament);
    $closes = tnCheckinClosesAt($tournament);
    if ($opens === null) return 'closed';
    if ($now < $opens) return 'upcoming';
    if ($closes !== null && $now >= $closes) return 'closed';
    return 'open';
}

/** Mark a participant checked in (idempotent). */
function tnCheckin(PDO $pdo, int $tournamentId, int $userId): array {
    try {
        $stmt = $pdo->prepare("SELECT id, status, checked_in FROM tournament_participants WHERE tournament_id = ? AND user_id = ?");
        $stmt->execute([$tournamentId, $userId]);
        $p = $stmt->fetch();
        if (!$p) return ['success' => false, 'message' => 'You are not registered for this tournament.'];
        if ($p['status'] === 'cancelled') return ['success' => false, 'message' => 'Your registration was cancelled.'];
        if ($p['status'] !== 'confirmed') return ['success' => false, 'message' => 'Registration not confirmed yet.'];

        if ($p['checked_in']) return ['success' => true, 'message' => 'Already checked in.'];

        $stmt = $pdo->prepare("UPDATE tournament_participants SET checked_in = 1, checked_in_at = NOW() WHERE tournament_id = ? AND user_id = ? AND checked_in = 0");
        $stmt->execute([$tournamentId, $userId]);
        return ['success' => true, 'message' => 'Checked in! Good luck.'];
    } catch (Throwable $e) {
        return ['success' => false, 'message' => 'Server error.'];
    }
}

// ─── LAZY DEADLINE ENFORCEMENT (no cron — ADR-005) ───

/**
 * Idempotent sweep run from page loads, handler entries and the SSE loop:
 *  - closes check-in windows (marks state only — bracket generation consumes it)
 *  - auto-refunds confirmed participants who never checked in (before bracket exists)
 * Safe to call frequently; every step is guarded by state transitions.
 */
function enforceTournamentDeadlines(PDO $pdo, ?int $tournamentId = null): array {
    $actions = ['refunded' => 0, 'cancelled' => []];
    try {
        $now = date('Y-m-d H:i:s');

        // Candidate tournaments whose check-in window just closed.
        $sql = "SELECT * FROM tournaments
                WHERE status IN ('upcoming','live')
                  AND starts_at IS NOT NULL
                  AND starts_at <= ?";
        $params = [$now];
        if ($tournamentId !== null) { $sql .= " AND id = ?"; $params[] = $tournamentId; }
        $sql .= " LIMIT 25";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $due = $stmt->fetchAll();
        if (!$due) return $actions;

        foreach ($due as $t) {
            $tid = (int) $t['id'];
            $hasBracket = $pdo->prepare("SELECT COUNT(*) FROM tournament_matches WHERE tournament_id = ?");
            $hasBracket->execute([$tid]);
            if ((int) $hasBracket->fetchColumn() > 0) continue; // bracket locked in — no auto-refund

            // No-shows: confirmed but never checked in -> cancel + refund.
            $stmt = $pdo->prepare("SELECT id, user_id FROM tournament_participants WHERE tournament_id = ? AND status = 'confirmed' AND checked_in = 0");
            $stmt->execute([$tid]);
            $noShows = $stmt->fetchAll();
            if (!$noShows) continue;

            $fee = (float) $t['entry_fee'];
            foreach ($noShows as $ns) {
                $pdo->beginTransaction();
                try {
                    $stmt = $pdo->prepare("SELECT id, fee_paid FROM tournament_participants WHERE id = ? AND status = 'confirmed' AND checked_in = 0 FOR UPDATE");
                    $stmt->execute([(int) $ns['id']]);
                    $row = $stmt->fetch();
                    if (!$row) { $pdo->commit(); continue; }

                    // Refund strictly from escrow, and only credit the player + write the
                    // ledger row once the escrow actually returned the money.
                    $refunded = false;
                    if ($fee > 0 && $row['fee_paid']) {
                        $title = mb_substr($t['title'] ?? '', 0, 150);
                        if (tnEscrowRefundFee($pdo, $t, (int) $ns['user_id'], $fee)) {
                            $balanceStmt = $pdo->prepare("SELECT balance FROM users WHERE id = ? FOR UPDATE");
                            $balanceStmt->execute([(int) $ns['user_id']]);
                            $before = (float) $balanceStmt->fetchColumn();
                            $pdo->prepare("UPDATE users SET balance = ? WHERE id = ?")
                                ->execute([$before + $fee, (int) $ns['user_id']]);
                            $pdo->prepare("INSERT INTO transactions (user_id, type, amount, balance_before, balance_after, description, purpose) VALUES (?, 'refund', ?, ?, ?, ?, 'tournament_checkin')")
                                ->execute([(int) $ns['user_id'], $fee, $before, $before + $fee, 'Check-in missed — entry fee refunded for "' . $title . '"']);
                            $refunded = true;
                        }
                    }
                    $pdo->prepare("UPDATE tournament_participants SET status = 'cancelled' WHERE id = ?")->execute([(int) $ns['id']]);
                    createNotification($pdo, (int) $ns['user_id'], (int) ($t['agent_id'] ?? null) ?: null, 'refund',
                        'You missed check-in for "' . ($t['title'] ?? 'Tournament') . '"' . ($refunded ? ' — entry fee refunded.' : '.'), $tid);
                    $pdo->commit();
                    $actions['refunded']++;
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                }
            }
            $actions['cancelled'][] = $tid;
        }
    } catch (Throwable $e) {
        error_log("enforceTournamentDeadlines: " . $e->getMessage());
    }
    return $actions;
}
