<?php
// Shared .tfd-br-* bracket styles. Expects $accent (raw hex string).
$accentCss = htmlspecialchars($accent ?? '#8b5cf6', ENT_QUOTES);
?>
.tfd-br-stage{margin-bottom:22px}
.tfd-br-stage-title{font-size:.82rem;font-weight:900;text-transform:uppercase;letter-spacing:.08em;color:var(--tfd-br-title,#fff);display:flex;gap:8px;align-items:center;margin-bottom:10px}
.tfd-br-stage-title i{color:<?php echo $accentCss; ?>}
.tfd-br-rounds{display:flex;gap:14px;overflow-x:auto;padding-bottom:8px}
.tfd-br-round{min-width:190px;flex:0 0 auto}
.tfd-br-round-h{font-size:.68rem;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:var(--tfd-br-muted,rgba(255,255,255,.45));margin-bottom:8px;text-align:center}
.tfd-br-m{background:var(--tfd-br-card,rgba(255,255,255,.04));border:1px solid var(--tfd-br-border,rgba(255,255,255,.09));border-radius:10px;padding:8px 10px;margin-bottom:10px}
.tfd-br-m.is-live{border-color:rgba(16,185,129,.6);box-shadow:0 0 0 1px rgba(16,185,129,.3)}
.tfd-br-m.is-bye{opacity:.55}
.tfd-br-slot{display:flex;justify-content:space-between;align-items:center;font-size:.82rem;font-weight:600;color:var(--tfd-br-slot,rgba(255,255,255,.8));padding:4px 2px}
.tfd-br-slot.is-win{color:#059669;font-weight:900}
.dark .tfd-br-slot.is-win,[data-theme="dark"] .tfd-br-slot.is-win{color:#6ee7b7}
.tfd-br-slot i{font-size:.7rem}
.tfd-br-slot .tbd{color:var(--tfd-br-muted,rgba(255,255,255,.3));font-style:italic;font-weight:500}
.tfd-br-vs{text-align:center;font-size:.62rem;font-weight:900;color:var(--tfd-br-muted,rgba(255,255,255,.35));letter-spacing:.1em;padding:2px 0}
.tfd-br-score{text-align:center;font-size:.72rem;font-weight:800;color:#d97706;margin-top:3px}
.dark .tfd-br-score,[data-theme="dark"] .tfd-br-score{color:#f59e0b}
.tfd-br-empty{text-align:center;padding:40px 16px;color:var(--tfd-br-muted,rgba(255,255,255,.45))}
.tfd-br-empty i{font-size:2rem;margin-bottom:10px;display:block;color:<?php echo $accentCss; ?>}
.tfd-br-empty p{font-size:.9rem}
