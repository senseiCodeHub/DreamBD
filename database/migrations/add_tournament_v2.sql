-- Tournament System v2 — format engine, score flow, check-in, escrow
-- Applied automatically by ensureTournamentFeatureSchema() in database/config.php.
-- Kept here as the canonical reference / manual-apply script.

-- ── tournaments ─────────────────────────────────────────────
ALTER TABLE tournaments ADD COLUMN IF NOT EXISTS rules TEXT NULL AFTER description;
ALTER TABLE tournaments ADD COLUMN IF NOT EXISTS best_of INT NOT NULL DEFAULT 1 AFTER bracket_type;
ALTER TABLE tournaments ADD COLUMN IF NOT EXISTS checkin_minutes INT NOT NULL DEFAULT 30 AFTER starts_at;
ALTER TABLE tournaments ADD COLUMN IF NOT EXISTS checkin_opens_at DATETIME NULL AFTER checkin_minutes;
ALTER TABLE tournaments ADD COLUMN IF NOT EXISTS fee_escrow DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER entry_fee;
ALTER TABLE tournaments ADD COLUMN IF NOT EXISTS prize_escrow DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER fee_escrow;
ALTER TABLE tournaments ADD COLUMN IF NOT EXISTS escrow_released TINYINT(1) NOT NULL DEFAULT 0 AFTER prize_escrow;
ALTER TABLE tournaments ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- ── tournament_participants ────────────────────────────────
ALTER TABLE tournament_participants ADD COLUMN IF NOT EXISTS checked_in TINYINT(1) NOT NULL DEFAULT 0 AFTER fee_paid;
ALTER TABLE tournament_participants ADD COLUMN IF NOT EXISTS checked_in_at DATETIME NULL AFTER checked_in;

-- Dedup before unique guards (prefer confirmed > registered > latest)
DELETE FROM tournament_participants WHERE id NOT IN (
    SELECT id FROM (
        SELECT id, ROW_NUMBER() OVER (
            PARTITION BY tournament_id, user_id
            ORDER BY (status = 'confirmed') DESC, (status = 'registered') DESC, id DESC
        ) AS rn FROM tournament_participants
    ) ranked WHERE rn = 1
);
DELETE FROM tournament_participants WHERE team_id IS NOT NULL AND id NOT IN (
    SELECT id FROM (
        SELECT id, ROW_NUMBER() OVER (
            PARTITION BY tournament_id, team_id
            ORDER BY (status = 'confirmed') DESC, (status = 'registered') DESC, id DESC
        ) AS rn FROM tournament_participants WHERE team_id IS NOT NULL
    ) ranked WHERE rn = 1
);

ALTER TABLE tournament_participants ADD UNIQUE INDEX IF NOT EXISTS uniq_tn_user (tournament_id, user_id);
ALTER TABLE tournament_participants ADD UNIQUE INDEX IF NOT EXISTS uniq_tn_team (tournament_id, team_id);

-- ── tournament_matches ─────────────────────────────────────
ALTER TABLE tournament_matches ADD COLUMN IF NOT EXISTS stage VARCHAR(20) NOT NULL DEFAULT 'single' AFTER tournament_id;
ALTER TABLE tournament_matches ADD COLUMN IF NOT EXISTS match_no INT NULL AFTER round;
ALTER TABLE tournament_matches ADD COLUMN IF NOT EXISTS is_bye TINYINT(1) NOT NULL DEFAULT 0 AFTER status;
ALTER TABLE tournament_matches ADD COLUMN IF NOT EXISTS team1_kind ENUM('team','user') NOT NULL DEFAULT 'user' AFTER team1_id;
ALTER TABLE tournament_matches ADD COLUMN IF NOT EXISTS team2_kind ENUM('team','user') NOT NULL DEFAULT 'user' AFTER team2_id;
ALTER TABLE tournament_matches ADD COLUMN IF NOT EXISTS next_match_id INT UNSIGNED NULL AFTER winner_id;
ALTER TABLE tournament_matches ADD COLUMN IF NOT EXISTS next_slot TINYINT NULL AFTER next_match_id;
ALTER TABLE tournament_matches ADD COLUMN IF NOT EXISTS loser_next_match_id INT UNSIGNED NULL AFTER next_slot;
ALTER TABLE tournament_matches ADD COLUMN IF NOT EXISTS loser_next_slot TINYINT NULL AFTER loser_next_match_id;
ALTER TABLE tournament_matches ADD COLUMN IF NOT EXISTS best_of INT NOT NULL DEFAULT 1 AFTER scheduled_at;
ALTER TABLE tournament_matches ADD COLUMN IF NOT EXISTS report_state ENUM('none','reported','confirmed','disputed') NOT NULL DEFAULT 'none' AFTER best_of;
ALTER TABLE tournament_matches ADD COLUMN IF NOT EXISTS rep1 INT NULL AFTER report_state;
ALTER TABLE tournament_matches ADD COLUMN IF NOT EXISTS rep2 INT NULL AFTER rep1;
ALTER TABLE tournament_matches ADD COLUMN IF NOT EXISTS reported_by INT UNSIGNED NULL AFTER rep2;
ALTER TABLE tournament_matches ADD COLUMN IF NOT EXISTS reported_at DATETIME NULL AFTER reported_by;
ALTER TABLE tournament_matches ADD COLUMN IF NOT EXISTS confirmed_by INT UNSIGNED NULL AFTER reported_at;
ALTER TABLE tournament_matches ADD COLUMN IF NOT EXISTS confirmed_at DATETIME NULL AFTER confirmed_by;
ALTER TABLE tournament_matches ADD COLUMN IF NOT EXISTS dispute_note TEXT NULL AFTER confirmed_at;
ALTER TABLE tournament_matches ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE tournament_matches ADD INDEX IF NOT EXISTS idx_tm_stage (tournament_id, stage);
ALTER TABLE tournament_matches ADD INDEX IF NOT EXISTS idx_tm_report (tournament_id, report_state);

-- Infer team/user kind for pre-existing matches
UPDATE tournament_matches tm SET
    team1_kind = IF(tm.team1_id IS NOT NULL AND EXISTS(SELECT 1 FROM teams t WHERE t.id = tm.team1_id), 'team', 'user'),
    team2_kind = IF(tm.team2_id IS NOT NULL AND EXISTS(SELECT 1 FROM teams t WHERE t.id = tm.team2_id), 'team', 'user')
WHERE tm.team1_kind = 'user' AND tm.team2_kind = 'user' AND (tm.team1_id IS NOT NULL OR tm.team2_id IS NOT NULL);

-- One-time escrow backfill (guarded by site_settings.tournament_escrow_backfilled in code)
UPDATE tournaments t SET
    fee_escrow = t.entry_fee * (
        SELECT COUNT(*) FROM tournament_participants tp
        WHERE tp.tournament_id = t.id AND tp.status = 'confirmed' AND tp.fee_paid = 1
    ),
    prize_escrow = IF(t.prize_escrow = 0, CAST(REPLACE(REPLACE(t.prize_money, ',', ''), '৳', '') AS DECIMAL(10,2)), t.prize_escrow)
WHERE t.status IN ('upcoming', 'live');
