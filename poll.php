<?php
/**
 * Meeting Poll - Doodle-style time slot voting
 * Single-file PHP+SQLite, Porsche Design System aesthetics
 * Features: Primary/Secondary preference voting, calendar layout
 */

// ============== CONFIGURATION ==============
$POLL_TITLE = "Meeting Terminabstimmung";
$POLL_DESCRIPTION = "Wähle deine bevorzugten Zeitslots. Klicke einmal für primär, zweimal für sekundär.";
$ADMIN_SECRET = "";

// Calendar structure
$DAYS = [
    "Mo 10.02." => "Mo",
    "Di 11.02." => "Di",
    "Mi 12.02." => "Mi",
    "Do 13.02." => "Do",
    "Fr 14.02." => "Fr",
];
$TIMES = ["16:30", "17:00", "17:30", "18:00", "18:30"];

// Build TIME_SLOTS from calendar structure
$TIME_SLOTS = [];
foreach ($DAYS as $dayFull => $dayShort) {
    foreach ($TIMES as $time) {
        $TIME_SLOTS[] = "$dayFull $time";
    }
}

// ============== DATABASE SETUP ==============
$db_file = __DIR__ . '/poll_data.sqlite';
$db = new SQLite3($db_file);

// Migration: Add primary_slots and secondary_slots columns
$db->exec("CREATE TABLE IF NOT EXISTS votes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    email TEXT,
    slots TEXT NOT NULL DEFAULT '[]',
    primary_slots TEXT NOT NULL DEFAULT '[]',
    secondary_slots TEXT NOT NULL DEFAULT '[]',
    ip TEXT,
    user_agent TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(name COLLATE NOCASE)
)");

// Check if columns exist, add if missing
$tableInfo = $db->query("PRAGMA table_info(votes)");
$columns = [];
while ($col = $tableInfo->fetchArray(SQLITE3_ASSOC)) {
    $columns[] = $col['name'];
}
if (!in_array('primary_slots', $columns)) {
    $db->exec("ALTER TABLE votes ADD COLUMN primary_slots TEXT NOT NULL DEFAULT '[]'");
}
if (!in_array('secondary_slots', $columns)) {
    $db->exec("ALTER TABLE votes ADD COLUMN secondary_slots TEXT NOT NULL DEFAULT '[]'");
}

// ============== HELPERS ==============
function h($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function getVoterByName($db, $name) {
    $stmt = $db->prepare("SELECT * FROM votes WHERE name = :name COLLATE NOCASE");
    $stmt->bindValue(':name', trim($name), SQLITE3_TEXT);
    return $stmt->execute()->fetchArray(SQLITE3_ASSOC);
}

function saveVote($db, $name, $email, $primarySlots, $secondarySlots, $ip, $ua) {
    $existing = getVoterByName($db, $name);
    $allSlots = array_unique(array_merge($primarySlots, $secondarySlots));
    
    if ($existing) {
        $stmt = $db->prepare("UPDATE votes SET email = :email, slots = :slots, primary_slots = :primary, secondary_slots = :secondary, ip = :ip, user_agent = :ua, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
        $stmt->bindValue(':id', $existing['id'], SQLITE3_INTEGER);
    } else {
        $stmt = $db->prepare("INSERT INTO votes (name, email, slots, primary_slots, secondary_slots, ip, user_agent) VALUES (:name, :email, :slots, :primary, :secondary, :ip, :ua)");
        $stmt->bindValue(':name', trim($name), SQLITE3_TEXT);
    }
    $stmt->bindValue(':email', trim($email), SQLITE3_TEXT);
    $stmt->bindValue(':slots', json_encode($allSlots), SQLITE3_TEXT);
    $stmt->bindValue(':primary', json_encode($primarySlots), SQLITE3_TEXT);
    $stmt->bindValue(':secondary', json_encode($secondarySlots), SQLITE3_TEXT);
    $stmt->bindValue(':ip', $ip, SQLITE3_TEXT);
    $stmt->bindValue(':ua', $ua, SQLITE3_TEXT);
    return $stmt->execute();
}

function getAllVotes($db) {
    $results = $db->query("SELECT * FROM votes ORDER BY created_at ASC");
    $votes = [];
    while ($row = $results->fetchArray(SQLITE3_ASSOC)) {
        $row['slots'] = json_decode($row['slots'] ?: '[]', true) ?: [];
        $row['primary_slots'] = json_decode($row['primary_slots'] ?: '[]', true) ?: [];
        $row['secondary_slots'] = json_decode($row['secondary_slots'] ?: '[]', true) ?: [];
        $votes[] = $row;
    }
    return $votes;
}

function getSlotStats($votes, $slots) {
    $stats = [];
    foreach ($slots as $slot) {
        $stats[$slot] = ['primary' => 0, 'secondary' => 0, 'total' => 0, 'score' => 0];
    }
    foreach ($votes as $vote) {
        foreach ($vote['primary_slots'] as $slot) {
            if (isset($stats[$slot])) {
                $stats[$slot]['primary']++;
                $stats[$slot]['total']++;
                $stats[$slot]['score'] += 2; // Primary = 2 points
            }
        }
        foreach ($vote['secondary_slots'] as $slot) {
            if (isset($stats[$slot])) {
                $stats[$slot]['secondary']++;
                $stats[$slot]['total']++;
                $stats[$slot]['score'] += 1; // Secondary = 1 point
            }
        }
    }
    return $stats;
}

// ============== REQUEST HANDLING ==============
$message = "";
$messageType = "";
$voterName = $_COOKIE['poll_voter_name'] ?? '';
$voterData = $voterName ? getVoterByName($db, $voterName) : null;
$isAdmin = isset($_GET['admin']);

if ($isAdmin && $ADMIN_SECRET && ($_GET['admin'] ?? '') !== $ADMIN_SECRET) {
    $isAdmin = false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['name'])) {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $primarySlots = $_POST['primary'] ?? [];
    $secondarySlots = $_POST['secondary'] ?? [];
    
    if (empty($name)) {
        $message = "Bitte gib deinen Namen ein.";
        $messageType = "error";
    } elseif (empty($primarySlots) && empty($secondarySlots)) {
        $message = "Bitte wähle mindestens einen Zeitslot.";
        $messageType = "error";
    } else {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        
        if (saveVote($db, $name, $email, $primarySlots, $secondarySlots, $ip, $ua)) {
            setcookie('poll_voter_name', $name, time() + 86400 * 30, '/');
            $voterName = $name;
            $voterData = getVoterByName($db, $name);
            $message = "Deine Stimme wurde gespeichert.";
            $messageType = "success";
        } else {
            $message = "Fehler beim Speichern.";
            $messageType = "error";
        }
    }
}

$allVotes = getAllVotes($db);
$slotStats = getSlotStats($allVotes, $TIME_SLOTS);
$maxScore = max(array_column($slotStats, 'score')) ?: 1;
$maxTotal = max(array_column($slotStats, 'total')) ?: 1;
$totalVoters = count($allVotes);

// Rank by score (primary=2, secondary=1)
$rankedSlots = $slotStats;
uasort($rankedSlots, fn($a, $b) => $b['score'] <=> $a['score']);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($POLL_TITLE) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --black: #000000;
            --white: #ffffff;
            --gray-50: #fafafa;
            --gray-100: #f5f5f5;
            --gray-200: #e5e5e5;
            --gray-300: #d4d4d4;
            --gray-400: #a3a3a3;
            --gray-500: #737373;
            --gray-600: #525252;
            --gray-700: #404040;
            --gray-800: #262626;
            --gray-900: #171717;
            --primary: #000000;
            --secondary: #737373;
            --success: #018a16;
            --error: #d5001c;
        }
        
        * { box-sizing: border-box; margin: 0; padding: 0; }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--white);
            color: var(--black);
            line-height: 1.5;
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
        }
        
        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 64px 24px;
        }
        
        header {
            margin-bottom: 48px;
            padding-bottom: 32px;
            border-bottom: 1px solid var(--gray-200);
        }
        
        h1 {
            font-size: 32px;
            font-weight: 700;
            letter-spacing: -0.02em;
            margin-bottom: 8px;
        }
        
        .description {
            font-size: 16px;
            color: var(--gray-600);
        }
        
        .meta-bar {
            display: flex;
            gap: 32px;
            margin-bottom: 32px;
            font-size: 14px;
            color: var(--gray-500);
        }
        
        .meta-bar .voted {
            color: var(--success);
            font-weight: 500;
        }
        
        .legend {
            display: flex;
            gap: 24px;
            margin-bottom: 32px;
            font-size: 13px;
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .legend-swatch {
            width: 20px;
            height: 20px;
            border: 1px solid var(--gray-200);
        }
        
        .legend-swatch.primary {
            background: var(--black);
        }
        
        .legend-swatch.secondary {
            background: var(--gray-400);
        }
        
        section {
            margin-bottom: 48px;
        }
        
        h2 {
            font-size: 14px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--gray-500);
            margin-bottom: 24px;
        }
        
        .message {
            padding: 16px 20px;
            margin-bottom: 32px;
            font-size: 14px;
            font-weight: 500;
            border-left: 3px solid;
        }
        
        .message.success {
            background: #f0fdf4;
            border-color: var(--success);
            color: var(--success);
        }
        
        .message.error {
            background: #fef2f2;
            border-color: var(--error);
            color: var(--error);
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-bottom: 32px;
        }
        
        @media (max-width: 540px) {
            .form-row { grid-template-columns: 1fr; gap: 16px; }
        }
        
        .field label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--gray-500);
            margin-bottom: 8px;
        }
        
        .field input {
            width: 100%;
            padding: 14px 16px;
            background: var(--white);
            border: 1px solid var(--gray-300);
            font-size: 16px;
            font-family: inherit;
            transition: border-color 0.15s, box-shadow 0.15s;
        }
        
        .field input:focus {
            outline: none;
            border-color: var(--black);
            box-shadow: 0 0 0 1px var(--black);
        }
        
        .field input::placeholder {
            color: var(--gray-400);
        }
        
        /* Calendar Grid */
        .calendar {
            display: grid;
            grid-template-columns: 60px repeat(5, 1fr);
            gap: 1px;
            background: var(--gray-200);
            border: 1px solid var(--gray-200);
            margin-bottom: 32px;
        }
        
        .calendar-header {
            background: var(--gray-100);
            padding: 16px 8px;
            text-align: center;
            font-weight: 600;
            font-size: 14px;
        }
        
        .calendar-header.corner {
            background: var(--white);
        }
        
        .calendar-time {
            background: var(--gray-50);
            padding: 12px 8px;
            text-align: center;
            font-size: 13px;
            font-weight: 500;
            color: var(--gray-600);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .calendar-cell {
            background: var(--white);
            padding: 0;
            position: relative;
        }
        
        .slot-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            height: 100%;
            min-height: 52px;
            background: var(--white);
            border: none;
            cursor: pointer;
            transition: background 0.15s;
            font-size: 16px;
        }
        
        .slot-btn:hover {
            background: var(--gray-100);
        }
        
        .slot-btn[data-state="primary"] {
            background: var(--black);
            color: var(--white);
        }
        
        .slot-btn[data-state="secondary"] {
            background: var(--gray-400);
            color: var(--white);
        }
        
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 16px 32px;
            background: var(--black);
            color: var(--white);
            border: none;
            font-size: 14px;
            font-weight: 600;
            font-family: inherit;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            cursor: pointer;
            transition: background 0.15s;
        }
        
        .btn:hover {
            background: var(--gray-800);
        }
        
        /* Results Calendar */
        .results-calendar {
            display: grid;
            grid-template-columns: 60px repeat(5, 1fr);
            gap: 1px;
            background: var(--gray-200);
            border: 1px solid var(--gray-200);
        }
        
        .result-cell {
            background: var(--white);
            padding: 0;
            min-height: 52px;
            position: relative;
            overflow: hidden;
        }
        
        .result-bar-stack {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            display: flex;
            flex-direction: column-reverse;
        }
        
        .result-bar-primary {
            background: var(--black);
            transition: height 0.4s ease;
        }
        
        .result-bar-secondary {
            background: var(--gray-400);
            transition: height 0.4s ease;
        }
        
        /* Admin styles */
        .ranking-list {
            display: flex;
            flex-direction: column;
        }
        
        .rank-item {
            display: grid;
            grid-template-columns: 40px 1fr 140px;
            gap: 16px;
            align-items: center;
            padding: 20px 0;
            border-bottom: 1px solid var(--gray-200);
        }
        
        .rank-item:last-child {
            border-bottom: none;
        }
        
        .rank-pos {
            font-size: 20px;
            font-weight: 700;
            color: var(--gray-300);
        }
        
        .rank-pos.top {
            color: var(--black);
        }
        
        .rank-slot {
            font-weight: 500;
        }
        
        .rank-stats {
            font-size: 13px;
            text-align: right;
        }
        
        .rank-stats .score {
            font-weight: 600;
            color: var(--black);
        }
        
        .rank-stats .breakdown {
            color: var(--gray-500);
            font-size: 12px;
        }
        
        .voters-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .voters-table th {
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--gray-500);
            text-align: left;
            padding: 12px 16px;
            border-bottom: 2px solid var(--gray-200);
        }
        
        .voters-table td {
            padding: 16px;
            border-bottom: 1px solid var(--gray-100);
            font-size: 14px;
            vertical-align: top;
        }
        
        .voters-table tr:hover td {
            background: var(--gray-50);
        }
        
        .voter-name {
            font-weight: 500;
        }
        
        .voter-email {
            color: var(--gray-500);
            font-size: 13px;
        }
        
        .slot-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }
        
        .slot-tag {
            font-size: 11px;
            padding: 3px 8px;
            font-weight: 500;
        }
        
        .slot-tag.primary {
            background: var(--black);
            color: var(--white);
        }
        
        .slot-tag.secondary {
            background: var(--gray-300);
            color: var(--gray-700);
        }
        
        .meta-cell {
            font-size: 12px;
            color: var(--gray-400);
        }
        
        footer {
            margin-top: 64px;
            padding-top: 32px;
            border-top: 1px solid var(--gray-200);
            font-size: 13px;
            color: var(--gray-400);
        }
        
        footer a {
            color: var(--gray-500);
            text-decoration: none;
        }
        
        footer a:hover {
            color: var(--black);
        }
        
        @media (max-width: 600px) {
            .calendar, .results-calendar {
                grid-template-columns: 50px repeat(5, 1fr);
            }
            .calendar-header, .calendar-time {
                font-size: 12px;
                padding: 12px 4px;
            }
            .legend {
                flex-direction: column;
                gap: 12px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1><?= h($POLL_TITLE) ?></h1>
            <p class="description"><?= h($POLL_DESCRIPTION) ?></p>
        </header>
        
        <div class="meta-bar">
            <span><?= $totalVoters ?> Teilnehmer</span>
            <span><?= count($TIME_SLOTS) ?> Zeitslots</span>
            <?php if ($voterData): ?>
                <span class="voted">Abgestimmt</span>
            <?php endif; ?>
        </div>
        
        <?php if ($message): ?>
            <div class="message <?= $messageType ?>"><?= h($message) ?></div>
        <?php endif; ?>
        
        <?php if (!$isAdmin): ?>
        
        <div class="legend">
            <div class="legend-item">
                <div class="legend-swatch primary"></div>
                <span>Bevorzugt (1. Wahl)</span>
            </div>
            <div class="legend-item">
                <div class="legend-swatch secondary"></div>
                <span>Möglich (2. Wahl)</span>
            </div>
        </div>
        
        <form method="POST" id="pollForm">
            <section>
                <h2><?= $voterData ? 'Stimme bearbeiten' : 'Verfügbarkeit angeben' ?></h2>
                
                <div class="form-row">
                    <div class="field">
                        <label for="name">Name</label>
                        <input type="text" id="name" name="name" required 
                               value="<?= h($voterData['name'] ?? $voterName) ?>"
                               placeholder="Max Müller">
                    </div>
                    <div class="field">
                        <label for="email">E-Mail (optional)</label>
                        <input type="email" id="email" name="email" 
                               value="<?= h($voterData['email'] ?? '') ?>"
                               placeholder="max@example.com">
                    </div>
                </div>
                
                <div class="calendar">
                    <div class="calendar-header corner"></div>
                    <?php foreach ($DAYS as $dayFull => $dayShort): ?>
                        <div class="calendar-header"><?= h($dayShort) ?></div>
                    <?php endforeach; ?>
                    
                    <?php foreach ($TIMES as $time): ?>
                        <div class="calendar-time"><?= h($time) ?></div>
                        <?php foreach ($DAYS as $dayFull => $dayShort): 
                            $slot = "$dayFull $time";
                            $slotIdx = array_search($slot, $TIME_SLOTS);
                            $state = '';
                            if ($voterData) {
                                if (in_array($slot, $voterData['primary_slots'])) {
                                    $state = 'primary';
                                } elseif (in_array($slot, $voterData['secondary_slots'])) {
                                    $state = 'secondary';
                                }
                            }
                        ?>
                            <div class="calendar-cell">
                                <button type="button" class="slot-btn" 
                                        data-slot="<?= h($slot) ?>" 
                                        data-state="<?= $state ?>"
                                        onclick="cycleSlot(this)"></button>
                            </div>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </div>
                
                <div id="hiddenInputs"></div>
                
                <button type="submit" class="btn">
                    <?= $voterData ? 'Aktualisieren' : 'Abstimmen' ?>
                </button>
            </section>
        </form>
        
        <section>
            <h2>Ergebnisse</h2>
            <div class="results-calendar">
                <div class="calendar-header corner"></div>
                <?php foreach ($DAYS as $dayFull => $dayShort): ?>
                    <div class="calendar-header"><?= h($dayShort) ?></div>
                <?php endforeach; ?>
                
                <?php foreach ($TIMES as $time): ?>
                    <div class="calendar-time"><?= h($time) ?></div>
                    <?php foreach ($DAYS as $dayFull => $dayShort): 
                        $slot = "$dayFull $time";
                        $stats = $slotStats[$slot];
                        $primaryPct = $maxTotal > 0 ? ($stats['primary'] / $maxTotal) * 100 : 0;
                        $secondaryPct = $maxTotal > 0 ? ($stats['secondary'] / $maxTotal) * 100 : 0;
                        $totalHeight = min(100, $primaryPct + $secondaryPct);
                        $primaryHeight = $totalHeight > 0 ? ($primaryPct / ($primaryPct + $secondaryPct)) * $totalHeight : 0;
                        $secondaryHeight = $totalHeight - $primaryHeight;
                    ?>
                        <div class="result-cell">
                            <div class="result-bar-stack">
                                <div class="result-bar-primary" style="height: <?= $primaryHeight ?>%"></div>
                                <div class="result-bar-secondary" style="height: <?= $secondaryHeight ?>%"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </div>
        </section>
        
        <script>
            function cycleSlot(btn) {
                const states = ['', 'primary', 'secondary'];
                const currentState = btn.dataset.state || '';
                const currentIdx = states.indexOf(currentState);
                const nextIdx = (currentIdx + 1) % states.length;
                btn.dataset.state = states[nextIdx];
            }
            
            document.getElementById('pollForm').addEventListener('submit', function(e) {
                const container = document.getElementById('hiddenInputs');
                container.innerHTML = '';
                
                document.querySelectorAll('.slot-btn').forEach(btn => {
                    const slot = btn.dataset.slot;
                    const state = btn.dataset.state;
                    
                    if (state === 'primary') {
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'primary[]';
                        input.value = slot;
                        container.appendChild(input);
                    } else if (state === 'secondary') {
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'secondary[]';
                        input.value = slot;
                        container.appendChild(input);
                    }
                });
            });
        </script>
        
        <?php else: ?>
        
        <div class="legend">
            <div class="legend-item">
                <div class="legend-swatch primary"></div>
                <span>Bevorzugt (2 Punkte)</span>
            </div>
            <div class="legend-item">
                <div class="legend-swatch secondary"></div>
                <span>Möglich (1 Punkt)</span>
            </div>
        </div>
        
        <section>
            <h2>Ranking nach Score</h2>
            <div class="ranking-list">
                <?php $pos = 0; $lastScore = -1; foreach ($rankedSlots as $slot => $stats): 
                    if ($stats['score'] !== $lastScore) { $pos++; $lastScore = $stats['score']; }
                    if ($stats['score'] === 0) continue;
                ?>
                    <div class="rank-item">
                        <div class="rank-pos <?= $pos <= 3 ? 'top' : '' ?>"><?= $pos ?></div>
                        <div class="rank-slot"><?= h($slot) ?></div>
                        <div class="rank-stats">
                            <div class="score"><?= $stats['score'] ?> Punkte</div>
                            <div class="breakdown"><?= $stats['primary'] ?> bevorzugt, <?= $stats['secondary'] ?> möglich</div>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if ($pos === 0): ?>
                    <p style="color: var(--gray-500); padding: 20px 0;">Noch keine Stimmen.</p>
                <?php endif; ?>
            </div>
        </section>
        
        <section>
            <h2>Teilnehmer (<?= $totalVoters ?>)</h2>
            <?php if ($totalVoters > 0): ?>
            <table class="voters-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Bevorzugt</th>
                        <th>Möglich</th>
                        <th>Meta</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($allVotes as $vote): ?>
                    <tr>
                        <td>
                            <div class="voter-name"><?= h($vote['name']) ?></div>
                            <?php if ($vote['email']): ?>
                                <div class="voter-email"><?= h($vote['email']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="slot-tags">
                                <?php foreach ($vote['primary_slots'] as $s): ?>
                                    <span class="slot-tag primary"><?= h(preg_replace('/^\w+ /', '', $s)) ?></span>
                                <?php endforeach; ?>
                                <?php if (empty($vote['primary_slots'])): ?>
                                    <span style="color: var(--gray-400);">—</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <div class="slot-tags">
                                <?php foreach ($vote['secondary_slots'] as $s): ?>
                                    <span class="slot-tag secondary"><?= h(preg_replace('/^\w+ /', '', $s)) ?></span>
                                <?php endforeach; ?>
                                <?php if (empty($vote['secondary_slots'])): ?>
                                    <span style="color: var(--gray-400);">—</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="meta-cell">
                            <?= date('d.m. H:i', strtotime($vote['updated_at'])) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
                <p style="color: var(--gray-500)">Noch keine Stimmen.</p>
            <?php endif; ?>
        </section>
        
        <?php endif; ?>
        
        <footer>
            Meeting Poll
            <?php if ($isAdmin): ?>
                · <a href="?">Zurück zur Abstimmung</a>
            <?php else: ?>
                · <a href="?admin=1">Admin</a>
            <?php endif; ?>
        </footer>
    </div>
</body>
</html>
