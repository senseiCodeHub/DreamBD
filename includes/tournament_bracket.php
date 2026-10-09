<?php
// ─── TOURNAMENT BRACKET ENGINE (Phase 2, ADR-002) ───
// 3 formats: single_elimination, double_elimination, round_robin
// Explicit match graph: next_match_id/next_slot (winner path),
// loser_next_match_id/loser_next_slot (loser path), stage, team1/2_kind.
// Static plan resolves byes/walkovers at build time; only real-play
// matches carry runtime links. Sentinels: team_id NULL = pending, 0 = bye-dead.

const TN_STAGE_SINGLE  = 'single';
const TN_STAGE_WINNERS = 'winners';
const TN_STAGE_LOSERS  = 'losers';
const TN_STAGE_GF      = 'grandfinal';
const TN_STAGE_GROUP   = 'group';

const TN_BRACKET_SINGLE = 'single_elimination';
const TN_BRACKET_DOUBLE = 'double_elimination';
const TN_BRACKET_RR     = 'round_robin';

// ─── PUBLIC API ───

/** Build the bracket for a tournament (agent-gated). Idempotent. */
function bracketBuild(PDO $pdo, int $tournamentId, int $agentId): array {
    $t = getTournamentByIdWithCounts($pdo, $tournamentId);
    if (!$t) return ['success' => false, 'message' => 'Tournament not found.'];
    if ((int)($t['agent_id'] ?? 0) !== $agentId) return ['success' => false, 'message' => 'Only the agent can generate brackets.'];

    $exists = $pdo->prepare("SELECT COUNT(*) FROM tournament_matches WHERE tournament_id = ?");
    $exists->execute([$tournamentId]);
    if ((int)$exists->fetchColumn() > 0) return ['success' => false, 'message' => 'Bracket already generated.'];

    $entrants = bracketFetchEntrants($pdo, $tournamentId);
    $count = count($entrants);
    $type = $t['bracket_type'] ?? TN_BRACKET_SINGLE;

    if ($type === TN_BRACKET_RR) {
        if ($count < 3) return ['success' => false, 'message' => 'Round robin needs at least 3 participants.'];
    } elseif ($type === TN_BRACKET_DOUBLE) {
        if ($count < 4) return ['success' => false, 'message' => 'Double elimination needs at least 4 participants.'];
    } elseif ($count < 2) {
        return ['success' => false, 'message' => 'Need at least 2 participants to generate a bracket.'];
    }

    try {
        $pdo->beginTransaction();

        $plan = match ($type) {
            TN_BRACKET_DOUBLE => bracketPlanDouble($entrants),
            TN_BRACKET_RR     => bracketPlanRoundRobin($entrants),
            default           => bracketPlanSingle($entrants),
        };
        bracketMaterialize($pdo, $tournamentId, $t, $plan);

        $pdo->prepare("UPDATE tournaments SET status = 'live' WHERE id = ? AND status = 'upcoming'")
            ->execute([$tournamentId]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('bracketBuild: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Could not generate bracket.'];
    }

    $summary = bracketSummary($pdo, $tournamentId);
    return [
        'success' => true,
        'message' => "Bracket generated: {$summary['match_count']} matches (" . bracketTypeLabel($type) . ").",
        'summary' => $summary,
        'bracket' => bracketMatchesForRender($pdo, $tournamentId),
    ];
}

/**
 * Complete a match with a winner. Cascades winner/loser slots, resolves
 * forced walkovers, and finalizes the tournament when the bracket is done.
 * $source: 'agent' (manual advance), 'confirm' (score flow), 'walkover'.
 */
function bracketAdvance(
    PDO $pdo, int $matchId, int $winnerId, string $winnerKind,
    int $score1, int $score2, string $source = 'agent', ?int $actorId = null
): array {
    $ownTx = !$pdo->inTransaction();
    try {
        if ($ownTx) $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT * FROM tournament_matches WHERE id = ? FOR UPDATE");
        $stmt->execute([$matchId]);
        $m = $stmt->fetch();
        if (!$m) { if ($ownTx) $pdo->rollBack(); return ['success' => false, 'message' => 'Match not found.']; }
        if (in_array($m['status'], ['completed', 'walkover'])) {
            if ($ownTx) $pdo->rollBack();
            return ['success' => false, 'message' => 'Match already completed.'];
        }
        $tid = (int)$m['tournament_id'];

        $tStmt = $pdo->prepare("SELECT * FROM tournaments WHERE id = ? FOR UPDATE");
        $tStmt->execute([$tid]);
        $tournament = $tStmt->fetch();

        $side = bracketWinnerSide($m, $winnerId, $winnerKind);
        if ($side === null) {
            if ($ownTx) $pdo->rollBack();
            return ['success' => false, 'message' => 'Winner is not part of this match.'];
        }
        $loserId   = $side === 1 ? (int)($m['team2_id'] ?? 0) : (int)($m['team1_id'] ?? 0);
        $loserKind = $side === 1 ? $m['team2_kind'] : $m['team1_kind'];

        $upd = $pdo->prepare("UPDATE tournament_matches SET score1 = ?, score2 = ?, winner_id = ?, status = 'completed',
                              report_state = 'confirmed', confirmed_by = COALESCE(?, confirmed_by), confirmed_at = NOW(), updated_at = NOW()
                              WHERE id = ?");
        $upd->execute([$score1, $score2, $winnerId, $actorId, $matchId]);

        bracketFillSlot($pdo, (int)$m['next_match_id'], (int)$m['next_slot'], $winnerId, $winnerKind);
        bracketFillSlot($pdo, (int)$m['loser_next_match_id'], (int)$m['loser_next_slot'], $loserId, $loserKind);

        // Grand-final reset: if the LB champ wins GF1, the WB champ gets a second life.
        if ($m['stage'] === TN_STAGE_GF && $winnerId !== (int)$m['team1_id'] && $winnerId > 0) {
            $gfCount = $pdo->prepare("SELECT COUNT(*) FROM tournament_matches WHERE tournament_id = ? AND stage = ?");
            $gfCount->execute([$tid, TN_STAGE_GF]);
            if ((int)$gfCount->fetchColumn() === 1) {
                $pdo->prepare("INSERT INTO tournament_matches
                    (tournament_id, stage, round, match_no, team1_id, team1_kind, team2_id, team2_kind, status, best_of)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'scheduled', ?)")
                    ->execute([$tid, TN_STAGE_GF, (int)$m['round'] + 1, (int)$m['match_no'] + 1,
                        (int)$m['team1_id'], $m['team1_kind'], (int)$m['team2_id'], $m['team2_kind'], $bestOf = max(1, (int)$m['best_of'])]);
            }
        }

        bracketResolveWalkovers($pdo, $tid);

        $finalized = bracketFinalize($pdo, $tid, $tournament);

        if ($ownTx) $pdo->commit();
        return [
            'success' => true,
            'message' => 'Match completed.',
            'finalized' => $finalized['complete'] ?? false,
            'summary' => bracketSummary($pdo, $tid),
            'bracket' => bracketMatchesForRender($pdo, $tid),
        ];
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
        error_log('bracketAdvance: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Server error.'];
    }
}

/**
 * Agent manual resolve (also settles disputes). Infers winner kind from the
 * match row; scores come from $req or default to 1-0 / reported values.
 */
function bracketAgentResolve(PDO $pdo, int $matchId, int $winnerId, int $agentId, array $req = []): array {
    $m = bracketLockMatch($pdo, $matchId);
    if (!$m) return ['success' => false, 'message' => 'Match not found.'];
    $t = bracketLockTournament($pdo, (int)$m['tournament_id'], false);
    if (!$t || (int)($t['agent_id'] ?? 0) !== $agentId) return ['success' => false, 'message' => 'Only the tournament agent can advance winners.'];

    $kind = ((int)$m['team1_id'] === $winnerId) ? $m['team1_kind'] : $m['team2_kind'];
    $winnerIsTeam1 = ((int)$m['team1_id'] === $winnerId);
    $s1 = array_key_exists('score1', $req) ? (int)$req['score1'] : null;
    $s2 = array_key_exists('score2', $req) ? (int)$req['score2'] : null;
    if ($s1 === null || $s2 === null) {
        if ($m['report_state'] === 'reported' || $m['report_state'] === 'disputed') {
            $s1 = (int)$m['rep1']; $s2 = (int)$m['rep2'];
        } else {
            $s1 = $winnerIsTeam1 ? 1 : 0;
            $s2 = $winnerIsTeam1 ? 0 : 1;
        }
    }
    // Agent override must be consistent with the chosen winner.
    if (($s1 > $s2) !== $winnerIsTeam1) {
        if ($winnerIsTeam1) { $s1 = max(1, max($s1, $s2)); $s2 = 0; }
        else { $s2 = max(1, max($s1, $s2)); $s1 = 0; }
    }
    return bracketAdvance($pdo, $matchId, $winnerId, $kind, $s1, $s2, 'agent', $agentId);
}

/** Player score report: reporter claims both scores. */
function bracketReportScore(PDO $pdo, int $matchId, int $userId, int $score1, int $score2): array {
    $ownTx = !$pdo->inTransaction();
    try {
        if ($ownTx) $pdo->beginTransaction();
        $m = bracketLockMatch($pdo, $matchId);
        if (!$m) { if ($ownTx) $pdo->rollBack(); return ['success' => false, 'message' => 'Match not found.']; }
        if (in_array($m['status'], ['completed', 'walkover'])) {
            if ($ownTx) $pdo->rollBack();
            return ['success' => false, 'message' => 'Match already completed.'];
        }
        if ($m['report_state'] === 'confirmed') {
            if ($ownTx) $pdo->rollBack();
            return ['success' => false, 'message' => 'Score already confirmed.'];
        }
        if (!bracketUserInMatch($m, $userId)) {
            if ($ownTx) $pdo->rollBack();
            return ['success' => false, 'message' => 'Only participants can report scores.'];
        }
        if ($m['report_state'] === 'reported' && (int)$m['reported_by'] !== $userId) {
            if ($ownTx) $pdo->rollBack();
            return ['success' => false, 'message' => 'Score already reported — confirm or dispute it instead.'];
        }
        if ($score1 === $score2) {
            if ($ownTx) $pdo->rollBack();
            return ['success' => false, 'message' => 'A match cannot end in a draw — report the real winner.'];
        }
        bracketLockTournament($pdo, (int)$m['tournament_id']);

        $pdo->prepare("UPDATE tournament_matches SET rep1 = ?, rep2 = ?, report_state = 'reported',
                        reported_by = ?, reported_at = NOW(), dispute_note = NULL, updated_at = NOW() WHERE id = ?")
            ->execute([$score1, $score2, $userId, $matchId]);

        $tid = (int)$m['tournament_id'];
        $opponent = bracketOpponentOf($m, $userId);
        if ($opponent > 0) {
            createNotification($pdo, $opponent, $userId, 'tournament',
                'Score reported for a match in your tournament — please confirm or dispute.', $tid);
        }
        if ($ownTx) $pdo->commit();
        return ['success' => true, 'message' => 'Score reported. Waiting for opponent confirmation.'];
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
        error_log('bracketReportScore: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Server error.'];
    }
}

/** Opponent confirms the reported score → match auto-resolves. */
function bracketConfirmScore(PDO $pdo, int $matchId, int $userId): array {
    $ownTx = !$pdo->inTransaction();
    try {
        if ($ownTx) $pdo->beginTransaction();
        $m = bracketLockMatch($pdo, $matchId);
        if (!$m) { if ($ownTx) $pdo->rollBack(); return ['success' => false, 'message' => 'Match not found.']; }
        if ($m['report_state'] !== 'reported') {
            if ($ownTx) $pdo->rollBack();
            return ['success' => false, 'message' => 'No reported score to confirm.'];
        }
        if ((int)$m['reported_by'] === $userId) {
            if ($ownTx) $pdo->rollBack();
            return ['success' => false, 'message' => 'Your own report needs opponent confirmation.'];
        }
        if (!bracketUserInMatch($m, $userId)) {
            if ($ownTx) $pdo->rollBack();
            return ['success' => false, 'message' => 'Only the opponent can confirm.'];
        }
        bracketLockTournament($pdo, (int)$m['tournament_id']);

        $s1 = (int)$m['rep1']; $s2 = (int)$m['rep2'];
        if ($ownTx) $pdo->commit(); // commit state, then resolve in its own transaction
        return bracketAdvance($pdo, $matchId, $s1 > $s2 ? (int)$m['team1_id'] : (int)$m['team2_id'],
            $s1 > $s2 ? $m['team1_kind'] : $m['team2_kind'], $s1, $s2, 'confirm', $userId);
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
        error_log('bracketConfirmScore: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Server error.'];
    }
}

/** Opponent disputes the reported score → agent decides. */function bracketDisputeScore(PDO $pdo, int $matchId, int $userId, string $note): array {
    $ownTx = !$pdo->inTransaction();
    try {
        if ($ownTx) $pdo->beginTransaction();
        $m = bracketLockMatch($pdo, $matchId);
        if (!$m) { if ($ownTx) $pdo->rollBack(); return ['success' => false, 'message' => 'Match not found.']; }
        if ($m['report_state'] !== 'reported') {
            if ($ownTx) $pdo->rollBack();
            return ['success' => false, 'message' => 'No reported score to dispute.'];
        }
        if (!bracketUserInMatch($m, $userId)) {
            if ($ownTx) $pdo->rollBack();
            return ['success' => false, 'message' => 'Only participants can dispute.'];
        }
        bracketLockTournament($pdo, (int)$m['tournament_id']);

        $pdo->prepare("UPDATE tournament_matches SET report_state = 'disputed', dispute_note = ?, updated_at = NOW() WHERE id = ?")
            ->execute([mb_substr(trim($note), 0, 2000) ?: 'No reason given.', $matchId]);

        $tid = (int)$m['tournament_id'];
        $t = bracketLockTournament($pdo, $tid);
        if (!empty($t['agent_id'])) {
            createNotification($pdo, (int)$t['agent_id'], $userId, 'tournament',
                'Score dispute on a match in "' . ($t['title'] ?? 'your tournament') . '" — please review and set the winner.', $tid);
        }
        if ($ownTx) $pdo->commit();
        return ['success' => true, 'message' => 'Dispute filed. The agent will decide the winner.'];
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
        error_log('bracketDisputeScore: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Server error.'];
    }
}

/** Round-robin standings (points 3/1/0, then score diff, wins). */
function bracketStandings(PDO $pdo, int $tournamentId): array {
    $mStmt = $pdo->prepare("SELECT id, team1_id, team1_kind, team2_id, team2_kind, score1, score2, winner_id
                            FROM tournament_matches WHERE tournament_id = ? AND stage = ? AND status IN ('completed','walkover')");
    $mStmt->execute([$tournamentId, TN_STAGE_GROUP]);
    $rows = $mStmt->fetchAll();

    $table = [];
    $touch = function (string $kind, int $id) use (&$table) {
        $key = $kind . ':' . $id;
        if (!isset($table[$key])) $table[$key] = ['kind' => $kind, 'id' => $id, 'played' => 0, 'wins' => 0, 'draws' => 0, 'losses' => 0, 'score_for' => 0, 'score_against' => 0, 'points' => 0];
        return $key;
    };
    $name = function (string $kind, int $id) use ($pdo): string {
        if ($kind === 'team') {
            $s = $pdo->prepare("SELECT name FROM teams WHERE id = ?"); $s->execute([$id]);
            return (string)($s->fetchColumn() ?: ('Team #' . $id));
        }
        $s = $pdo->prepare("SELECT COALESCE(full_name, username) FROM users WHERE id = ?"); $s->execute([$id]);
        return (string)($s->fetchColumn() ?: ('Player #' . $id));
    };

    foreach ($rows as $r) {
        if (!(int)$r['team1_id'] || !(int)$r['team2_id']) continue;
        $k1 = $touch($r['team1_kind'], (int)$r['team1_id']);
        $k2 = $touch($r['team2_kind'], (int)$r['team2_id']);
        $table[$k1]['played']++; $table[$k2]['played']++;
        $table[$k1]['score_for'] += (int)$r['score1']; $table[$k1]['score_against'] += (int)$r['score2'];
        $table[$k2]['score_for'] += (int)$r['score2']; $table[$k2]['score_against'] += (int)$r['score1'];
        $winner = (int)($r['winner_id'] ?? 0);
        if ($winner === 0) {
            $table[$k1]['draws']++; $table[$k2]['draws']++;
            $table[$k1]['points']++; $table[$k2]['points']++;
        } elseif ($winner === (int)$r['team1_id']) {
            $table[$k1]['wins']++; $table[$k1]['points'] += 3; $table[$k2]['losses']++;
        } else {
            $table[$k2]['wins']++; $table[$k2]['points'] += 3; $table[$k1]['losses']++;
        }
    }

    $out = array_values($table);
    foreach ($out as &$row) { $row['name'] = $name($row['kind'], $row['id']); $row['diff'] = $row['score_for'] - $row['score_against']; }
    unset($row);
    usort($out, fn($a, $b) => [$b['points'], $b['diff'], $b['wins'], $b['name']] <=> [$a['points'], $a['diff'], $a['wins'], $a['name']]);
    foreach ($out as $i => &$row) $row['rank'] = $i + 1;
    unset($row);
    return $out;
}

/** UI summary: type, stage counts, completion, champion. */
function bracketSummary(PDO $pdo, int $tournamentId): array {
    $rows = bracketRawMatches($pdo, $tournamentId);
    $type = bracketTypeOf($pdo, $tournamentId);
    $byStage = [];
    $complete = true;
    foreach ($rows as $r) {
        $stage = $r['stage'] ?: TN_STAGE_SINGLE;
        if (!isset($byStage[$stage])) $byStage[$stage] = ['total' => 0, 'completed' => 0, 'max_round' => 0];
        $byStage[$stage]['total']++;
        $byStage[$stage]['max_round'] = max($byStage[$stage]['max_round'], (int)$r['round']);
        if (in_array($r['status'], ['completed', 'walkover'])) $byStage[$stage]['completed']++;
        else $complete = false;
    }

    $champion = null;
    $isComplete = $complete && count($rows) > 0;
    if ($isComplete) {
        if ($type === TN_BRACKET_RR) {
            $stand = bracketStandings($pdo, $tournamentId);
            if ($stand) $champion = ['kind' => $stand[0]['kind'], 'id' => (int)$stand[0]['id'], 'name' => $stand[0]['name']];
        } else {
            // Winner of the highest-round championship match (GF2 > GF1 > final)
            $final = null;
            foreach ($rows as $r) {
                if (!$r['winner_id']) continue;
                if ($type === TN_BRACKET_DOUBLE) {
                    if ($r['stage'] !== TN_STAGE_GF) continue;
                } elseif ($r['stage'] !== TN_STAGE_SINGLE) continue;
                if (!$final || (int)$r['round'] > (int)$final['round']) $final = $r;
            }
            if ($final && $final['winner_id']) {
                $wKind = ((int)$final['winner_id'] === (int)$final['team1_id']) ? $final['team1_kind'] : $final['team2_kind'];
                $champion = ['kind' => $wKind, 'id' => (int)$final['winner_id'], 'name' => bracketSlotName($wKind, (int)$final['winner_id'])];
            }
        }
    }
    $tournament = bracketLockTournament($pdo, $tournamentId, false);
    return [
        'match_count' => count($rows),
        'pending' => $isComplete ? 0 : 1,
        'complete' => $isComplete,
        'type' => $type,
        'type_label' => bracketTypeLabel($type),
        'stages' => $byStage,
        'champion' => $champion,
        'status' => $tournament['status'] ?? '',
    ];
}

/** Kind-aware match rows for rendering (grouped data for UI). */
function bracketMatchesForRender(PDO $pdo, int $tournamentId): array {
    $rows = bracketRawMatches($pdo, $tournamentId);
    foreach ($rows as &$r) {
        $r['stage'] = $r['stage'] ?: TN_STAGE_SINGLE;
        $r['team1_name'] = bracketSlotName($r['team1_kind'], (int)($r['team1_id'] ?? 0));
        $r['team2_name'] = bracketSlotName($r['team2_kind'], (int)($r['team2_id'] ?? 0));
        $r['winner_name'] = $r['winner_id'] ? bracketSlotName($r['winner_kind'] ?? null, (int)$r['winner_id']) : null;
    }
    unset($r);
    return $rows;
}

function bracketTypeLabel(string $type): string {
    return match ($type) {
        TN_BRACKET_DOUBLE => 'Double Elimination',
        TN_BRACKET_RR     => 'Round Robin',
        default           => 'Single Elimination',
    };
}

function bracketTypeOf(PDO $pdo, int $tournamentId): string {
    $s = $pdo->prepare("SELECT bracket_type FROM tournaments WHERE id = ?");
    $s->execute([$tournamentId]);
    return (string)($s->fetchColumn() ?: TN_BRACKET_SINGLE);
}

// ─── DEADLINE / COMPLETION ───

function bracketIsComplete(PDO $pdo, int $tournamentId): bool {
    $rows = bracketRawMatches($pdo, $tournamentId);
    if (!$rows) return false;
    foreach ($rows as $r) {
        if (!in_array($r['status'], ['completed', 'walkover'])) return false;
    }
    // Double elim: GF must be settled (or WB champ took GF1 outright)
    $type = bracketTypeOf($pdo, $tournamentId);
    if ($type === TN_BRACKET_DOUBLE) {
        $gf1 = null; $gf2Done = false;
        foreach ($rows as $r) {
            if ($r['stage'] !== TN_STAGE_GF) continue;
            if (!$gf1) { $gf1 = $r; continue; }
            if ($r['winner_id']) $gf2Done = true;
        }
        if (!$gf1 || !$gf1['winner_id']) return false;
        if ((int)$gf1['winner_id'] !== (int)$gf1['team1_id'] && !$gf2Done) return false;
    }
    return true;
}

/**
 * Called after every match resolution — idempotent (ADR-005).
 * Money-free tournaments auto-complete; otherwise the agent is nudged
 * to submit results (saveTournamentResults performs the escrow settlement).
 */
function bracketFinalize(PDO $pdo, int $tournamentId, ?array $tournament = null): array {
    if (!bracketIsComplete($pdo, $tournamentId)) return ['complete' => false];

    $ownTx = !$pdo->inTransaction();
    try {
        if ($ownTx) $pdo->beginTransaction();
        $t = $tournament ?? bracketLockTournament($pdo, $tournamentId);
        if (!$t) { if ($ownTx) $pdo->rollBack(); return ['complete' => false]; }

        $fee = (float)($t['entry_fee'] ?? 0);
        $prizeEscrow = (float)($t['prize_escrow'] ?? 0);
        $feeEscrow = (float)($t['fee_escrow'] ?? 0);
        $hasResults = bracketHasResults($pdo, $tournamentId);

        if ($hasResults) {
            if ($t['status'] !== 'completed') {
                $pdo->prepare("UPDATE tournaments SET status = 'completed' WHERE id = ?")->execute([$tournamentId]);
            }
            if ((int)($t['escrow_released'] ?? 0) === 0 && $fee <= 0 && $prizeEscrow <= 0 && $feeEscrow <= 0) {
                tnEscrowReleaseToAgent($pdo, $tournamentId);
            }
        } elseif (($prizeEscrow <= 0 && $feeEscrow <= 0 && $fee <= 0)) {
            // Money-free tournament: complete immediately, nothing to settle.
            $pdo->prepare("UPDATE tournaments SET status = 'completed' WHERE id = ? AND status != 'completed'")->execute([$tournamentId]);
            $rows = bracketRawMatches($pdo, $tournamentId);
            foreach ($rows as $r) {
                if ($r['winner_id'] && $r['status'] === 'completed' && $r['stage'] !== TN_STAGE_GROUP) {
                    // no results submitted — nothing to credit; participants informed below
                    break;
                }
            }
            $s = $pdo->prepare("SELECT user_id FROM tournament_participants WHERE tournament_id = ? AND status = 'confirmed'");
            $s->execute([$tournamentId]);
            foreach ($s->fetchAll() as $p) {
                createNotification($pdo, (int)$p['user_id'], (int)($t['agent_id'] ?? 0) ?: null, 'tournament_result',
                    'Bracket finished for "' . ($t['title'] ?? 'Tournament') . '". Final standings are out.', $tournamentId);
            }
        } else {
            // Money pending: nudge the agent exactly once to submit results.
            $agentId = (int)($t['agent_id'] ?? 0);
            if ($agentId > 0) {
                $n = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND entity_id = ? AND type = 'tournament' AND message LIKE 'Bracket complete%'");
                $n->execute([$agentId, $tournamentId]);
                if ((int)$n->fetchColumn() === 0) {
                    createNotification($pdo, $agentId, null, 'tournament',
                        'Bracket complete for "' . ($t['title'] ?? 'Tournament') . '" — submit results to settle prizes.', $tournamentId);
                }
            }
        }

        if ($ownTx) $pdo->commit();
        return ['complete' => true, 'status' => $hasResults ? 'completed' : 'awaiting_results'];
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
        error_log('bracketFinalize: ' . $e->getMessage());
        return ['complete' => false];
    }
}

// ─── PLAN BUILDERS (pure) ───

/** Standard balanced seed order for power-of-2 size: [1,8,4,5,2,7,3,6]. */
function bracketSeedOrder(int $p): array {
    if ($p <= 1) return [1];
    $half = bracketSeedOrder($p >> 1);
    $out = [];
    foreach ($half as $h) { $out[] = $h; $out[] = $p + 1 - $h; }
    return $out;
}

function bracketNextPow2(int $n): int {
    $p = 1;
    while ($p < $n) $p <<= 1;
    return $p;
}

/**
 * Token kinds: ['E', id, kind] entrant | ['W', key] winner of match |
 * ['L', key] loser of match | ['N'] null/bye.
 * Plan match: key, stage, round, slot_no, s1, s2, walkover/void flag, winner.
 */
function bracketPlanSingle(array $entrants): array {
    $count = count($entrants);
    $p = bracketNextPow2($count);
    $order = bracketSeedOrder($p);
    $slots = [];
    foreach ($order as $seed) $slots[] = $seed <= $count ? ['E', (int)$entrants[$seed - 1]['id'], $entrants[$seed - 1]['kind']] : ['N'];

    $rounds = (int)log($p, 2);
    $plan = [];
    $prevKeys = [];
    for ($r = 1; $r <= $rounds; $r++) {
        $mCount = $p / (1 << $r);
        $keys = [];
        for ($i = 0; $i < $mCount; $i++) {
            $key = "r{$r}m{$i}";
            if ($r === 1) {
                $s1 = $slots[$i * 2];
                $s2 = $slots[$i * 2 + 1];
            } else {
                $s1 = ['W', $prevKeys[$i * 2]];
                $s2 = ['W', $prevKeys[$i * 2 + 1]];
            }
            $plan[$key] = [
                'key' => $key, 'stage' => TN_STAGE_SINGLE, 'round' => $r, 'slot_no' => $i,
                's1' => $s1, 's2' => $s2, 'loser_dest' => null,
            ];
            $keys[] = $key;
        }
        // Wire winner destinations inside the plan
        if ($r > 1) {
            foreach ($prevKeys as $pi => $pk) {
                $plan[$pk]['winner_dest'] = ['r' . $r . 'm' . intdiv($pi, 2), ($pi % 2 === 0) ? 1 : 2];
            }
        }
        $prevKeys = $keys;
    }
    foreach ($plan as &$pm) if (!isset($pm['winner_dest'])) $pm['winner_dest'] = null;
    unset($pm);
    return $plan;
}

function bracketPlanDouble(array $entrants): array {
    $count = count($entrants);
    $p = bracketNextPow2($count);
    $k = (int)log($p, 2);
    $plan = bracketPlanSingle($entrants);

    // Re-key: winner-bracket matches become stage 'winners'
    foreach ($plan as $key => &$pm) {
        $pm['stage'] = TN_STAGE_WINNERS;
        $pm['loser_dest'] = null;
    }
    unset($pm);

    // Double elim with p=2 degenerates to single (no losers bracket)
    if ($p <= 2) {
        $plan[$plan[array_key_last($plan)]]['stage'] = TN_STAGE_GF;
        return $plan;
    }

    // Losers bracket rounds: 2(k-1) rounds; odd=pairing, even=vs WB dropouts
    $lbRounds = 2 * ($k - 1);
    $lbKeysByRound = []; // round j (1-based) => [keys]

    // WB round1 keys in order (losers feed LB round 1)
    $wbR1 = [];
    foreach ($plan as $key => $pm) if ($pm['stage'] === TN_STAGE_WINNERS && $pm['round'] === 1) $wbR1[] = $key;

    for ($j = 1; $j <= $lbRounds; $j++) {
        $i = intdiv($j + 1, 2); // pair index: j=1→1, j=2→1, j=3→2, j=4→2...
        $countJ = 1 << max(0, $k - 1 - $i); // 2^(k-1-i)
        $keys = [];
        for ($m = 0; $m < $countJ; $m++) {
            $key = "l{$j}m{$m}";
            $stageRound = $k + $j;
            if ($j === 1) {
                $s1 = ['L', $wbR1[$m * 2] ?? null];
                $s2 = ['L', $wbR1[$m * 2 + 1] ?? null];
                if ($s1[1] === null) $s1 = ['N'];
                if ($s2[1] === null) $s2 = ['N'];
            } elseif ($j % 2 === 0) {
                // even: W of previous LB round (same index) + L of WB round (i+1)
                $prevKey = $lbKeysByRound[$j - 1][$m] ?? null;
                $s1 = $prevKey ? ['W', $prevKey] : ['N'];
                $wbRoundKeys = [];
                foreach ($plan as $key2 => $pm2) if ($pm2['stage'] === TN_STAGE_WINNERS && $pm2['round'] === $i + 1) $wbRoundKeys[] = $key2;
                $s2 = isset($wbRoundKeys[$m]) ? ['L', $wbRoundKeys[$m]] : ['N'];
            } else {
                // odd (>1): pair winners of previous LB round
                $s1 = isset($lbKeysByRound[$j - 1][$m * 2]) ? ['W', $lbKeysByRound[$j - 1][$m * 2]] : ['N'];
                $s2 = isset($lbKeysByRound[$j - 1][$m * 2 + 1]) ? ['W', $lbKeysByRound[$j - 1][$m * 2 + 1]] : ['N'];
            }
            $plan[$key] = [
                'key' => $key, 'stage' => TN_STAGE_LOSERS, 'round' => $stageRound, 'slot_no' => $m,
                's1' => $s1, 's2' => $s2, 'winner_dest' => null, 'loser_dest' => null,
            ];
            $keys[] = $key;
        }
        $lbKeysByRound[$j] = $keys;
    }

    // LB losers are eliminated — no loser_dest. Wire LB winner destinations:
    // odd round winners → next even round same index; even (non-final) → next odd pairing;
    // final LB round winner → GF slot 2.
    foreach ($lbKeysByRound as $j => $keys) {
        foreach ($keys as $m => $key) {
            if ($j === $lbRounds) {
                $plan[$key]['winner_dest'] = ['gf1', 2];
            } elseif ($j % 2 === 1) {
                // pairing round → next even round, same index m
                if (isset($lbKeysByRound[$j + 1][$m])) $plan[$key]['winner_dest'] = [$lbKeysByRound[$j + 1][$m], 1];
            } else {
                // vs-WB round → next odd round pairs (2m,2m+1)
                $nextOdd = $j + 1;
                $target = intdiv($m, 2);
                if (isset($lbKeysByRound[$nextOdd][$target])) {
                    $plan[$key]['winner_dest'] = [$lbKeysByRound[$nextOdd][$target], ($m % 2 === 0) ? 1 : 2];
                }
            }
        }
    }

    // WB losers → LB destinations (loser_dest on WB matches).
    // LB round1 = pair of WB round1 losers; even LB round 2(r-1)
    // receives WB round r losers (r≥2) at slot 2.
    for ($r = 1; $r <= $k; $r++) {
        $wbRoundKeys = [];
        foreach ($plan as $key => $pm) if ($pm['stage'] === TN_STAGE_WINNERS && $pm['round'] === $r) $wbRoundKeys[] = $key;
        foreach ($wbRoundKeys as $m => $key) {
            if ($r === 1) {
                // WB round-1 losers pair up: matches 2m/2m+1 → LB round-1 match m
                $lbTarget = $lbKeysByRound[1][intdiv($m, 2)] ?? null;
                $slot = ($m % 2 === 0) ? 1 : 2;
                $plan[$key]['loser_dest'] = $lbTarget ? [$lbTarget, $slot] : null;
            } else {
                $evenRound = 2 * ($r - 1);
                $lbTarget = $lbKeysByRound[$evenRound][$m] ?? null;
                $plan[$key]['loser_dest'] = $lbTarget ? [$lbTarget, 2] : null;
            }
        }
    }

    // Grand final: WB final winner → GF slot 1.
    // (WB final loser already routed to LB final slot 2 by the loop above.)
    $wbFinal = null;
    foreach ($plan as $key => $pm) if ($pm['stage'] === TN_STAGE_WINNERS && $pm['round'] === $k) $wbFinal = $key;
    if ($wbFinal !== null) {
        $plan[$wbFinal]['winner_dest'] = ['gf1', 1];
    }

    // GF match itself (GF2 created lazily at runtime if LB champ wins GF1)
    $plan['gf1'] = [
        'key' => 'gf1', 'stage' => TN_STAGE_GF, 'round' => $k + $lbRounds + 1, 'slot_no' => 0,
        's1' => $wbFinal ? ['W', $wbFinal] : ['N'],
        's2' => ($lbKeysByRound[$lbRounds][0] ?? null) ? ['W', $lbKeysByRound[$lbRounds][0]] : ['N'],
        'winner_dest' => null, 'loser_dest' => null, 'is_gf' => true,
    ];

    return $plan;
}

function bracketPlanRoundRobin(array $entrants): array {
    $plan = [];
    $n = count($entrants);
    $m = 0;
    for ($i = 0; $i < $n; $i++) {
        for ($j = $i + 1; $j < $n; $j++) {
            $key = "g{$m}";
            $plan[$key] = [
                'key' => $key, 'stage' => TN_STAGE_GROUP, 'round' => intdiv($m, max(1, intdiv($n * ($n - 1) / 2, max(1, (int)ceil($n / 2))))) + 1,
                'slot_no' => $m,
                's1' => ['E', (int)$entrants[$i]['id'], $entrants[$i]['kind']],
                's2' => ['E', (int)$entrants[$j]['id'], $entrants[$j]['kind']],
                'winner_dest' => null, 'loser_dest' => null,
            ];
            $m++;
        }
    }
    // Round-robin round assignment: simple wave — match index grouped by "round" of i
    $r = 1; $idx = 0;
    for ($i = 0; $i < $n; $i++) {
        for ($j = $i + 1; $j < $n; $j++) {
            $key = "g{$idx}";
            if (isset($plan[$key])) $plan[$key]['round'] = $r;
            $idx++;
        }
        $r++;
        if ($idx >= count($plan)) break;
    }
    return $plan;
}

// ─── MATERIALIZATION ───

/**
 * Insert plan matches in two passes:
 * (1) per-slot static resolution — each token resolves independently
 *     (E→concrete, N→bye-dead 0, unresolved W/L→dynamic);
 * (2) insert with concrete/dead/pending slots + runtime links for dynamics.
 * Walkover/void matches are decided statically and cascade their winners.
 */
function bracketMaterialize(PDO $pdo, int $tournamentId, array $tournament, array $plan): int {
    $bestOf = max(1, (int)($tournament['best_of'] ?? 1));

    // Pass 0: decide static outcomes with a fixpoint (walkovers cascade).
    $outcome = [];   // key => ['winner' => ['id','kind']|null] when statically decided
    $slots = [];     // key => [slot1, slot2] where each = ['C', id, kind] | ['D', srcKey, 'W'|'L']
    $changed = true; $guard = 0;
    while ($changed && $guard++ < 60) {
        $changed = false;
        foreach ($plan as $key => $pm) {
            if (!isset($slots[$key])) {
                $slots[$key] = [bracketMarker($pm['s1'], $plan, $outcome), bracketMarker($pm['s2'], $plan, $outcome)];
            }
            if (isset($outcome[$key])) continue;
            [$m1, $m2] = $slots[$key];
            if ($m1[0] === 'D' || $m2[0] === 'D') {
                // Dynamic side may still force a walkover if the other is C-dead(0)
                // and the dynamic source is statically decided (won't happen: D implies undecided).
                continue;
            }
            $id1 = (int)$m1[1]; $id2 = (int)$m2[1];
            if ($id1 > 0 && $id2 > 0) {
                $outcome[$key] = ['winner' => null]; // real play — no static outcome
                $changed = true;
            } elseif ($id1 > 0 || $id2 > 0) {
                $winner = $id1 > 0 ? ['id' => $id1, 'kind' => $m1[2]] : ['id' => $id2, 'kind' => $m2[2]];
                $outcome[$key] = ['winner' => $winner, 'walkover' => true];
                $changed = true;
                // Cascade: its W/L markers become concrete for consumers
                foreach ($plan as $k2 => $pm2) {
                    if (isset($slots[$k2])) {
                        for ($i = 0; $i < 2; $i++) {
                            if ($slots[$k2][$i][0] === 'D' && $slots[$k2][$i][1] === $key) {
                                $slots[$k2][$i] = bracketMarker([($slots[$k2][$i][2] === 'W' ? 'W' : 'L'), $key], $plan, $outcome);
                            }
                        }
                    }
                }
            } else {
                $outcome[$key] = ['winner' => null, 'void' => true];
                $changed = true;
                foreach ($plan as $k2 => $pm2) {
                    if (isset($slots[$k2])) {
                        for ($i = 0; $i < 2; $i++) {
                            if ($slots[$k2][$i][0] === 'D' && $slots[$k2][$i][1] === $key) {
                                $slots[$k2][$i] = bracketMarker([($slots[$k2][$i][2] === 'W' ? 'W' : 'L'), $key], $plan, $outcome);
                            }
                        }
                    }
                }
            }
        }
        // Also try to (re)build markers for slots created later
        foreach ($plan as $key => $pm) {
            if (!isset($slots[$key])) continue;
            for ($i = 0; $i < 2; $i++) {
                if ($slots[$key][$i][0] === 'D') {
                    $src = $slots[$key][$i][1];
                    $tok = [$slots[$key][$i][2], $src];
                    $new = bracketMarker($tok, $plan, $outcome);
                    if ($new[0] !== 'D') { $slots[$key][$i] = $new; $changed = true; }
                }
            }
        }
    }

    // Pass 1: insert
    $ids = [];
    $ins = $pdo->prepare("INSERT INTO tournament_matches
        (tournament_id, stage, round, match_no, team1_id, team1_kind, team2_id, team2_kind, status, is_bye, winner_id, best_of)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $matchNo = 0;
    foreach ($plan as $key => $pm) {
        $m1 = $slots[$key][0]; $m2 = $slots[$key][1];
        $t1 = $m1[0] === 'C' ? (int)$m1[1] : ($m1[0] === 'D' && bracketMarkerIsDead($m1, $slots) ? 0 : null);
        $t2 = $m2[0] === 'C' ? (int)$m2[1] : ($m2[0] === 'D' && bracketMarkerIsDead($m2, $slots) ? 0 : null);
        $k1 = $m1[0] === 'C' ? $m1[2] : 'user';
        $k2 = $m2[0] === 'C' ? $m2[2] : 'user';
        $decided = $outcome[$key] ?? null;
        $isWalkover = !empty($decided['walkover']) || !empty($decided['void']);
        $status = $isWalkover ? 'walkover' : 'scheduled';
        $winnerId = $decided['winner']['id'] ?? null;
        $matchNo++;
        $ins->execute([
            $tournamentId, $pm['stage'], $pm['round'], $matchNo,
            $t1, $k1, $t2, $k2, $status, $isWalkover ? 1 : 0, $winnerId, $bestOf,
        ]);
        $ids[$key] = (int)$pdo->lastInsertId();
    }

    // Pass 2: runtime links — only for slots that are still pending (NULL)
    $upd = $pdo->prepare("UPDATE tournament_matches SET next_match_id = ?, next_slot = ? WHERE id = ?");
    $updL = $pdo->prepare("UPDATE tournament_matches SET loser_next_match_id = ?, loser_next_slot = ? WHERE id = ?");
    foreach ($plan as $key => $pm) {
        if (empty($ids[$key])) continue;
        if (!empty($pm['winner_dest'])) {
            [$destKey, $slot] = $pm['winner_dest'];
            if (isset($ids[$destKey])) {
                $dm = $slots[$destKey][$slot - 1];
                if ($dm[0] === 'D') $upd->execute([$ids[$destKey], $slot, $ids[$key]]);
            }
        }
        if (!empty($pm['loser_dest'])) {
            [$destKey, $slot] = $pm['loser_dest'];
            if (isset($ids[$destKey])) {
                $dm = $slots[$destKey][$slot - 1];
                if ($dm[0] === 'D') $updL->execute([$ids[$destKey], $slot, $ids[$key]]);
            }
        }
    }

    // Runtime walkover cascade for any remaining dead/pending combos
    bracketResolveWalkovers($pdo, $tournamentId);

    return count($ids);
}

/** Build a per-slot marker: ['C', id, kind] concrete | ['D', src, 'W'|'L'] dynamic. */
function bracketMarker(array $token, array $plan, array $outcome): array {
    if ($token[0] === 'E') return ['C', (int)$token[1], $token[2]];
    if ($token[0] === 'N') return ['C', 0, 'user'];
    // W/L of src
    $src = $token[1];
    if ($src === null || !isset($outcome[$src])) return ['D', $src, $token[0]];
    $o = $outcome[$src];
    if ($token[0] === 'W') {
        if (isset($o['winner']) && $o['winner']) return ['C', (int)$o['winner']['id'], $o['winner']['kind']];
        // Void match has no winner → consumer slot is bye-dead
        if (!empty($o['void'])) return ['C', 0, 'user'];
        return ['D', $src, 'W'];
    }
    // Loser of statically decided match
    if (!empty($o['walkover']) || !empty($o['void'])) {
        // Loser = the side that isn't the winner (or dead)
        // We need both slots of src — recovered from plan tokens via marker? Use plan:
        $sp = $plan[$src];
        // If winner known, loser is the other side only if both sides were concrete at decision time.
        // For walkover the loser is dead (N) or the unchosen concrete side of a later runtime match.
        if (!empty($o['void'])) return ['C', 0, 'user'];
        // walkover: loser is the bye side (id 0) — WB r1 byes. Any real-vs-real walkover
        // only happens at runtime, not statically. So loser = dead.
        return ['C', 0, 'user'];
    }
    return ['D', $src, 'L'];
}

function bracketMarkerIsDead(array $marker, array $slots): bool {
    return false; // D markers are pending unless explicitly concrete-dead
}

// ─── RUNTIME HELPERS ───

function bracketFetchEntrants(PDO $pdo, int $tournamentId): array {
    $stmt = $pdo->prepare("
        SELECT DISTINCT COALESCE(tp.team_id, tp.user_id) AS entrant_id,
               CASE WHEN tp.team_id IS NOT NULL THEN 'team' ELSE 'user' END AS entrant_type,
               COALESCE(t.name, tp.team_name, u.full_name, u.username) AS entrant_name,
               tp.team_id, tp.user_id
        FROM tournament_participants tp
        LEFT JOIN teams t ON t.id = tp.team_id
        LEFT JOIN users u ON u.id = tp.user_id
        WHERE tp.tournament_id = ? AND tp.status = 'confirmed'
        ORDER BY tp.id ASC
    ");
    $stmt->execute([$tournamentId]);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $id = (int)($row['entrant_id'] ?: 0);
        if ($id <= 0) continue;
        $out[] = ['id' => $id, 'kind' => $row['entrant_type'] === 'team' ? 'team' : 'user', 'name' => (string)$row['entrant_name']];
    }
    return $out;
}

function bracketRawMatches(PDO $pdo, int $tournamentId): array {
    $stmt = $pdo->prepare("SELECT * FROM tournament_matches WHERE tournament_id = ? ORDER BY round ASC, match_no ASC, id ASC");
    $stmt->execute([$tournamentId]);
    return $stmt->fetchAll();
}

function bracketSlotName(?string $kind, int $id): ?string {
    if ($id <= 0) return null;
    if ($kind === 'team') {
        $pdo = Database::getInstance()->getConnection();
        $s = $pdo->prepare("SELECT name FROM teams WHERE id = ?"); $s->execute([$id]);
        return (string)($s->fetchColumn() ?: ('Team #' . $id));
    }
    $pdo = Database::getInstance()->getConnection();
    $s = $pdo->prepare("SELECT COALESCE(full_name, username) FROM users WHERE id = ?"); $s->execute([$id]);
    return (string)($s->fetchColumn() ?: ('Player #' . $id));
}

function bracketLockTournament(PDO $pdo, int $tournamentId, bool $lock = true): ?array {
    $sql = "SELECT * FROM tournaments WHERE id = ?" . ($lock ? " FOR UPDATE" : "");
    $s = $pdo->prepare($sql); $s->execute([$tournamentId]);
    return $s->fetch() ?: null;
}

function bracketLockMatch(PDO $pdo, int $matchId): ?array {
    $inTx = $pdo->inTransaction();
    $s = $pdo->prepare("SELECT * FROM tournament_matches WHERE id = ?" . ($inTx ? " FOR UPDATE" : ""));
    $s->execute([$matchId]);
    return $s->fetch() ?: null;
}

function bracketWinnerSide(array $match, int $winnerId, string $winnerKind): ?int {
    if ($winnerId > 0 && (int)$match['team1_id'] === $winnerId && ($match['team1_kind'] ?? 'user') === $winnerKind) return 1;
    if ($winnerId > 0 && (int)$match['team2_id'] === $winnerId && ($match['team2_kind'] ?? 'user') === $winnerKind) return 2;
    // Tolerate kind mismatch (legacy rows) — match by id only
    if ($winnerId > 0 && (int)$match['team1_id'] === $winnerId) return 1;
    if ($winnerId > 0 && (int)$match['team2_id'] === $winnerId) return 2;
    return null;
}

function bracketUserInMatch(array $match, int $userId): bool {
    if (($match['team1_kind'] ?? 'user') === 'user' && (int)$match['team1_id'] === $userId) return true;
    if (($match['team2_kind'] ?? 'user') === 'user' && (int)$match['team2_id'] === $userId) return true;
    // Team members may act for their team
    foreach ([1, 2] as $side) {
        if (($match["team{$side}_kind"] ?? 'user') === 'team') {
            $pdo = Database::getInstance()->getConnection();
            $s = $pdo->prepare("SELECT 1 FROM team_members WHERE team_id = ? AND user_id = ? LIMIT 1");
            $s->execute([(int)$match["team{$side}_id"], $userId]);
            if ($s->fetchColumn()) return true;
        }
    }
    return false;
}

function bracketOpponentOf(array $match, int $userId): int {
    if (($match['team1_kind'] ?? 'user') === 'user' && (int)$match['team1_id'] === $userId) {
        return ($match['team2_kind'] ?? 'user') === 'user' ? (int)($match['team2_id'] ?? 0) : 0;
    }
    if (($match['team2_kind'] ?? 'user') === 'user' && (int)$match['team2_id'] === $userId) {
        return ($match['team1_kind'] ?? 'user') === 'user' ? (int)($match['team1_id'] ?? 0) : 0;
    }
    return 0;
}

/** Fill a pending (NULL) or bye-dead (0) slot; returns true if filled/overwritten-as-real. */
function bracketFillSlot(PDO $pdo, int $matchId, int $slot, int $teamId, string $kind): bool {
    if ($matchId <= 0 || $slot < 1 || $slot > 2 || $teamId <= 0) return false;
    $s = $pdo->prepare("SELECT team1_id, team2_id FROM tournament_matches WHERE id = ?");
    $s->execute([$matchId]);
    $row = $s->fetch();
    if (!$row) return false;
    $col = $slot === 1 ? 'team1_id' : 'team2_id';
    $kindCol = $slot === 1 ? 'team1_kind' : 'team2_kind';
    $current = (int)($slot === 1 ? $row['team1_id'] : $row['team2_id']);
    if ($current > 0 && $current !== $teamId) return false; // already has a real team
    $pdo->prepare("UPDATE tournament_matches SET {$col} = ?, {$kindCol} = ?, updated_at = NOW() WHERE id = ?")
        ->execute([$teamId, $kind, $matchId]);
    return true;
}

/** After any slot fill: if one side real and other bye-dead(0), walkover it. */
function bracketResolveWalkovers(PDO $pdo, int $tournamentId): void {
    $rows = bracketRawMatches($pdo, $tournamentId);
    foreach ($rows as $r) {
        if (in_array($r['status'], ['completed', 'walkover'])) continue;
        $t1 = $r['team1_id'] === null ? null : (int)$r['team1_id'];
        $t2 = $r['team2_id'] === null ? null : (int)$r['team2_id'];
        if ($t1 === null || $t2 === null) continue;
        $winner = null;
        if ($t1 > 0 && $t2 === 0) $winner = ['id' => $t1, 'kind' => $r['team1_kind']];
        elseif ($t2 > 0 && $t1 === 0) $winner = ['id' => $t2, 'kind' => $r['team2_kind']];
        elseif ($t1 === 0 && $t2 === 0) $winner = null; // void
        else continue;

        $pdo->prepare("UPDATE tournament_matches SET status = 'walkover', is_bye = 1, winner_id = ?, report_state = 'confirmed', updated_at = NOW() WHERE id = ?")
            ->execute([$winner ? $winner['id'] : null, (int)$r['id']]);
        if ($winner) {
            bracketFillSlot($pdo, (int)$r['next_match_id'], (int)$r['next_slot'], $winner['id'], $winner['kind']);
            bracketFillSlot($pdo, (int)$r['loser_next_match_id'], (int)$r['loser_next_slot'], (int)($winner['id'] === (int)$r['team1_id'] ? ($r['team2_id'] ?? 0) : ($r['team1_id'] ?? 0)), $winner['id'] === (int)$r['team1_id'] ? $r['team2_kind'] : $r['team1_kind']);
        }
    }
}

function bracketHasResults(PDO $pdo, int $tournamentId): bool {
    $s = $pdo->prepare("SELECT COUNT(*) FROM tournament_results WHERE tournament_id = ?");
    $s->execute([$tournamentId]);
    return (int)$s->fetchColumn() > 0;
}

/**
 * Stage-aware bracket HTML (namespaced .tfd-br-*). Groups by stage then round,
 * shows BYE/winner/live states, per-stage headers.
 */
function bracketRenderStages(array $rows): string {
    if (!$rows) {
        return '<div class="tfd-br-empty"><i class="fas fa-diagram-project"></i><p>No bracket yet — it appears once the agent generates it.</p></div>';
    }
    $order = [TN_STAGE_WINNERS => 1, TN_STAGE_LOSERS => 2, TN_STAGE_SINGLE => 3, TN_STAGE_GF => 4, TN_STAGE_GROUP => 5];
    $stageTitles = [
        TN_STAGE_WINNERS => 'Winners Bracket',
        TN_STAGE_LOSERS  => 'Losers Bracket',
        TN_STAGE_SINGLE  => 'Bracket',
        TN_STAGE_GF      => 'Grand Final',
        TN_STAGE_GROUP   => 'Group Stage',
    ];
    $stages = [];
    foreach ($rows as $r) $stages[$r['stage'] ?: TN_STAGE_SINGLE][] = $r;
    uksort($stages, fn($a, $b) => ($order[$a] ?? 9) <=> ($order[$b] ?? 9));

    $html = '<div class="tfd-br">';
    foreach ($stages as $stage => $sRows) {
        $rounds = [];
        foreach ($sRows as $r) $rounds[(int)$r['round']][] = $r;
        ksort($rounds);
        $html .= '<div class="tfd-br-stage">';
        $stageIcon = match ($stage) {
            TN_STAGE_WINNERS => 'fa-trophy',
            TN_STAGE_LOSERS  => 'fa-shield-halved',
            TN_STAGE_GF      => 'fa-crown',
            TN_STAGE_GROUP   => 'fa-arrows-rotate',
            default          => 'fa-diagram-project',
        };
        $html .= '<div class="tfd-br-stage-title"><i class="fas ' . $stageIcon .
            '"></i> ' . htmlspecialchars($stageTitles[$stage] ?? ucfirst($stage)) . '</div>';
        $html .= '<div class="tfd-br-rounds">';
        foreach ($rounds as $rn => $rRows) {
            $label = $stage === TN_STAGE_GROUP ? 'Matchday ' . $rn : ($stage === TN_STAGE_GF ? (count($rRows) > 1 || $rn > 1 ? 'Grand Final (Reset)' : 'Grand Final') : 'Round ' . $rn);
            $html .= '<div class="tfd-br-round"><div class="tfd-br-round-h">' . htmlspecialchars($label) . '</div>';
            foreach ($rRows as $m) {
                $status = $m['status'] ?? 'scheduled';
                $t1 = $m['team1_name'] ?? null; $t2 = $m['team2_name'] ?? null;
                $w = $m['winner_id'] ? (int)$m['winner_id'] : 0;
                $w1 = $w > 0 && $w === (int)($m['team1_id'] ?? 0);
                $w2 = $w > 0 && $w === (int)($m['team2_id'] ?? 0);
                $cls = 'tfd-br-m' . ($status === 'live' ? ' is-live' : '') . (!empty($m['is_bye']) ? ' is-bye' : '');
                $html .= '<div class="' . $cls . '" data-match="' . (int)$m['id'] . '">';
                $html .= '<div class="tfd-br-slot' . ($w1 ? ' is-win' : '') . '">' .
                    ($t1 !== null ? htmlspecialchars($t1) : '<span class="tbd">TBD</span>') .
                    ($w1 ? '<i class="fas fa-check"></i>' : '') . '</div>';
                $html .= '<div class="tfd-br-vs">' . (!empty($m['is_bye']) ? 'BYE' : 'vs') . '</div>';
                $html .= '<div class="tfd-br-slot' . ($w2 ? ' is-win' : '') . '">' .
                    ($t2 !== null ? htmlspecialchars($t2) : '<span class="tbd">TBD</span>') .
                    ($w2 ? '<i class="fas fa-check"></i>' : '') . '</div>';
                if ($status === 'completed' && isset($m['score1'], $m['score2']) && $m['score1'] !== null) {
                    $html .= '<div class="tfd-br-score">' . (int)$m['score1'] . ' – ' . (int)$m['score2'] . '</div>';
                }
                $html .= '</div>';
            }
            $html .= '</div>';
        }
        $html .= '</div></div>';
    }
    $html .= '</div>';
    return $html;
}
