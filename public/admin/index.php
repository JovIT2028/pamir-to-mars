<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/MeasurementWriter.php';

/**
 * Admin panel: places and values. One file, no framework.
 * Security: password_hash login with lock-out, session cookie (HttpOnly, SameSite), CSRF token on every
 * POST, only prepared statements, every output escaped, every change written to audit_logs.
 */
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'");

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function go(string $query = '', string $flash = '', bool $error = false): void
{
    if ($flash !== '') { $_SESSION['flash'] = [$flash, $error]; }
    header('Location: ./' . ($query !== '' ? '?' . $query : ''));
    exit;
}
function num($v, float $min, float $max, string $label): float
{
    if (!is_string($v) || !is_numeric($v) || !is_finite((float)$v) || (float)$v < $min || (float)$v > $max) {
        throw new ValidationException("$label must be a number from $min to $max.");
    }
    return (float)$v;
}
function text($v, int $max, string $label, bool $required = true): ?string
{
    $v = is_string($v) ? trim($v) : '';
    if ($v === '') {
        if ($required) { throw new ValidationException("$label is required."); }
        return null;
    }
    if (mb_strlen($v) > $max) { throw new ValidationException("$label is longer than $max characters."); }
    return $v;
}

Auth::start();
$pdo = Db::pdo();
$ip  = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$uid = Auth::userId();

function audit(PDO $pdo, ?int $uid, string $action, string $target, string $detail, string $ip): void
{
    $pdo->prepare('INSERT INTO audit_logs (user_id, action, target, detail, ip) VALUES (?, ?, ?, ?, ?)')
        ->execute([$uid, $action, mb_substr($target, 0, 120), mb_substr($detail, 0, 500), $ip]);
}

// ------------------------------------------------------------------ POST actions
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'login') {
            $err = Auth::login($pdo, (string)($_POST['username'] ?? ''), (string)($_POST['password'] ?? ''), $ip);
            if ($err !== null) { go('', $err, true); }
            audit($pdo, Auth::userId(), 'login', '', '', $ip);
            go();
        }
        if ($uid === null) { go('', 'Please log in.', true); }
        if (!Auth::csrfOk($_POST['csrf'] ?? null)) {
            http_response_code(403);
            exit('Invalid or missing security token. Go back, reload the page and try again.');
        }

        if ($action === 'logout') {
            audit($pdo, $uid, 'logout', '', '', $ip);
            Auth::logout();
            go('', 'Logged out.');
        }

        if ($action === 'save_location' || $action === 'new_location') {
            $name = text($_POST['name'] ?? null, 150, 'Name');
            $lat  = num($_POST['latitude'] ?? null, -90, 90, 'Latitude');
            $lon  = num($_POST['longitude'] ?? null, -180, 180, 'Longitude');
            $elevRaw = trim((string)($_POST['elevation_m'] ?? ''));
            $elev = $elevRaw === '' ? null : num($elevRaw, -11000, 9000, 'Elevation');
            $eref = text($_POST['elevation_ref'] ?? null, 80, 'Elevation reference', false);
            $desc = text($_POST['description'] ?? null, 2000, 'Description', false);
            $ver  = isset($_POST['is_verified']) ? 1 : 0;
            if ($action === 'new_location') {
                $slug = Validator::slug($_POST['slug'] ?? null, 'slug');
                $body = Validator::optionalEnum($_POST['body'] ?? null, ['earth', 'mars', 'moon'], 'body');
                $kind = Validator::optionalEnum($_POST['kind'] ?? null, ['site', 'reference', 'known_analog'], 'kind');
                if ($body === null || $kind === null) { throw new ValidationException('Body and kind are required.'); }
                $pdo->prepare('INSERT INTO locations (slug, name, body, kind, latitude, longitude, elevation_m, elevation_ref, is_verified, description)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$slug, $name, $body, $kind, $lat, $lon, $elev, $eref, $ver, $desc]);
                audit($pdo, $uid, 'location_new', $slug, "$name ($body/$kind) $lat,$lon", $ip);
                go('page=edit&slug=' . $slug, "Place '$slug' created. Now add its values.");
            }
            $slug = Validator::slug($_POST['slug'] ?? null, 'slug');
            $st = $pdo->prepare('UPDATE locations SET name=?, latitude=?, longitude=?, elevation_m=?, elevation_ref=?, is_verified=?, description=? WHERE slug=?');
            $st->execute([$name, $lat, $lon, $elev, $eref, $ver, $desc, $slug]);
            audit($pdo, $uid, 'location_edit', $slug, "$name $lat,$lon elev=" . ($elev ?? 'null') . " verified=$ver", $ip);
            go('page=edit&slug=' . $slug, 'Saved.');
        }

        if ($action === 'save_value' || $action === 'delete_value') {
            $slug = Validator::slug($_POST['slug'] ?? null, 'slug');
            $st = $pdo->prepare('SELECT id FROM locations WHERE slug = ?');
            $st->execute([$slug]);
            $locId = $st->fetchColumn();
            if ($locId === false) { throw new ValidationException('Unknown place.'); }

            if ($action === 'delete_value') {
                $id = (string)($_POST['id'] ?? '');
                if (!preg_match('/^\d{1,18}$/', $id)) { throw new ValidationException('Bad id.'); }
                $d = $pdo->prepare('DELETE FROM measurements WHERE id = ? AND location_id = ?');
                $d->execute([$id, $locId]);
                audit($pdo, $uid, 'value_delete', $slug, "measurement id $id", $ip);
                go('page=values&slug=' . $slug, 'Value deleted.');
            }

            $writer = new MeasurementWriter($pdo);
            $param  = (string)($_POST['parameter'] ?? '');
            $source = (string)($_POST['source'] ?? '');
            if (!$writer->hasParameter($param) || !$writer->hasSource($source)) { throw new ValidationException('Unknown parameter or source.'); }
            $monthStr = (string)($_POST['month'] ?? '0');
            if (!preg_match('/^\d{1,2}$/', $monthStr) || (int)$monthStr > 12) { throw new ValidationException('Month must be 0 (annual) to 12.'); }
            $value = num($_POST['value'] ?? null, -1e7, 1e7, 'Value');
            $type  = (string)($_POST['data_type'] ?? '');
            if (!in_array($type, MeasurementWriter::DATA_TYPES, true)) { throw new ValidationException('Unknown data type.'); }
            $period = text($_POST['period'] ?? null, 120, 'Period', false);
            $note   = text($_POST['note'] ?? null, 255, 'Note', false);
            $writer->save((int)$locId, $param, $source, (int)$monthStr, $value, $type, $period, $note);
            audit($pdo, $uid, 'value_save', $slug, "$param=$value $type src=$source month=$monthStr", $ip);
            go('page=values&slug=' . $slug, 'Value saved.');
        }
        throw new ValidationException('Unknown action.');
    } catch (ValidationException $e) {
        $back = (string)($_POST['back'] ?? '');
        go(preg_match('/^[a-z]+(=[a-z0-9_-]+)?(&[a-z]+=[a-z0-9_-]+)?$/', $back) ? $back : '', $e->getMessage(), true);
    } catch (PDOException $e) {
        error_log('[pamir-to-mars admin] ' . $e->getMessage());
        $dup = $e->getCode() === '23000';
        go('', $dup ? 'That slug already exists.' : 'Database error. Nothing was saved.', true);
    } catch (Throwable $e) {
        error_log('[pamir-to-mars admin] ' . $e->getMessage());
        go('', 'Server error. Nothing was saved.', true);
    }
}

// ------------------------------------------------------------------ page shell
function head(string $title): void
{
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">';
    echo '<title>' . h($title) . ' · PAMIR TO MARS admin</title><style>
body{margin:0;background:#0A1222;color:#E8EDF7;font:15px/1.5 system-ui,Segoe UI,Arial,sans-serif}
a{color:#22D3EE} header{display:flex;gap:18px;align-items:center;flex-wrap:wrap;padding:14px 24px;border-bottom:1px solid #22304D;background:#0F1830}
header b{font-size:17px;margin-right:auto} main{max-width:1100px;margin:0 auto;padding:24px}
h1{font-size:24px;margin:0 0 16px} h2{font-size:18px;margin:28px 0 10px}
table{width:100%;border-collapse:collapse;font-size:14px} th,td{text-align:left;padding:8px 10px;border-bottom:1px solid #22304D;vertical-align:top} th{color:#9FB0CC;font-weight:600}
label{display:block;margin:10px 0 4px;color:#9FB0CC;font-size:13px} input,select,textarea{width:100%;box-sizing:border-box;background:#0A1222;color:#E8EDF7;border:1px solid #2A3A5E;border-radius:8px;padding:8px 10px;font:inherit}
input[type=checkbox]{width:auto} textarea{min-height:90px}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:0 16px} .card{background:#0F1830;border:1px solid #22304D;border-radius:14px;padding:18px;margin-bottom:18px}
button,.btn{background:#22D3EE;color:#04202A;border:0;border-radius:8px;padding:8px 16px;font:600 14px system-ui;cursor:pointer;text-decoration:none;display:inline-block} button.sec{background:#2A3A5E;color:#E8EDF7} button.bad{background:#F97316;color:#1A0B02}
.flash{padding:10px 14px;border-radius:8px;margin-bottom:16px;background:#12332A;border:1px solid #34D399} .flash.err{background:#3A1A12;border-color:#F97316}
.muted{color:#9FB0CC} .tag{font-size:12px;padding:2px 8px;border-radius:999px;border:1px solid #2A3A5E}
.ok{color:#34D399} .warn{color:#FBBF24}
</style></head><body>';
}
function flash(): void
{
    if (!empty($_SESSION['flash'])) {
        [$msg, $err] = $_SESSION['flash'];
        unset($_SESSION['flash']);
        echo '<div class="flash' . ($err ? ' err' : '') . '" role="status">' . h($msg) . '</div>';
    }
}
function csrfField(string $back = ''): string
{
    return '<input type="hidden" name="csrf" value="' . h(Auth::csrf()) . '"><input type="hidden" name="back" value="' . h($back) . '">';
}

// ------------------------------------------------------------------ login page
if ($uid === null) {
    head('Log in');
    echo '<main style="max-width:420px"><h1>Admin log in</h1>';
    flash();
    echo '<form method="post" class="card" autocomplete="off"><input type="hidden" name="action" value="login">
      <label for="u">Username</label><input id="u" name="username" required maxlength="40" autocomplete="username">
      <label for="p">Password</label><input id="p" name="password" type="password" required autocomplete="current-password">
      <p><button>Log in</button></p></form>
      <p class="muted">No account yet? Create one in a terminal: <code>php scripts/create_admin.php admin</code></p>
      <p><a href="../">← back to the site</a></p></main></body></html>';
    exit;
}

// ------------------------------------------------------------------ logged in
$page = (string)($_GET['page'] ?? 'places');
$slug = isset($_GET['slug']) && preg_match('/^[a-z0-9_-]{1,60}$/', (string)$_GET['slug']) ? (string)$_GET['slug'] : '';
head(ucfirst($page));
echo '<header><b>PAMIR TO MARS · admin</b>
  <a href="./">Places</a><a href="?page=new">New place</a><a href="?page=logs">Logs</a><a href="../" target="_blank" rel="noopener">Open site</a>
  <form method="post" style="margin:0"><input type="hidden" name="action" value="logout">' . csrfField() . '<button class="sec">Log out</button></form></header><main>';
flash();

$locFields = function (array $l, bool $isNew): void {
    $v = function (string $k) use ($l) { return h($l[$k] ?? ''); };
    echo '<div class="grid">';
    if ($isNew) {
        echo '<div><label>Slug (a-z 0-9 _ -)</label><input name="slug" required pattern="[a-z0-9_-]{1,60}" maxlength="60"></div>';
        echo '<div><label>Body</label><select name="body"><option>earth</option><option>mars</option><option>moon</option></select></div>';
        echo '<div><label>Kind</label><select name="kind"><option>site</option><option>reference</option><option>known_analog</option></select></div>';
    }
    echo '<div><label>Name</label><input name="name" required maxlength="150" value="' . $v('name') . '"></div>
      <div><label>Latitude</label><input name="latitude" required value="' . $v('latitude') . '"></div>
      <div><label>Longitude</label><input name="longitude" required value="' . $v('longitude') . '"></div>
      <div><label>Elevation, m (empty = unknown)</label><input name="elevation_m" value="' . $v('elevation_m') . '"></div>
      <div><label>Elevation reference (e.g. above sea level)</label><input name="elevation_ref" maxlength="80" value="' . $v('elevation_ref') . '"></div></div>
      <label>Description</label><textarea name="description" maxlength="2000">' . $v('description') . '</textarea>
      <label><input type="checkbox" name="is_verified" value="1"' . (!empty($l['is_verified']) ? ' checked' : '') . '> Coordinates and elevation verified against a cited source</label>';
};

if ($page === 'new') {
    echo '<h1>New place</h1><form method="post" class="card"><input type="hidden" name="action" value="new_location">' . csrfField('page=new');
    $locFields([], true);
    echo '<p><button>Create</button></p></form>';

} elseif ($page === 'edit' && $slug !== '') {
    $st = $pdo->prepare('SELECT * FROM locations WHERE slug = ?'); $st->execute([$slug]); $l = $st->fetch();
    if (!$l) { echo '<p class="flash err">Place not found.</p>'; }
    else {
        echo '<h1>' . h($l['name']) . ' <span class="tag">' . h($l['body'] . ' / ' . $l['kind']) . '</span></h1>
              <p><a href="?page=values&slug=' . h($slug) . '">Values of this place →</a></p>
              <form method="post" class="card"><input type="hidden" name="action" value="save_location"><input type="hidden" name="slug" value="' . h($slug) . '">' . csrfField('page=edit&slug=' . $slug);
        $locFields($l, false);
        echo '<p><button>Save</button></p></form>';
    }

} elseif ($page === 'values' && $slug !== '') {
    $st = $pdo->prepare('SELECT id, name, body FROM locations WHERE slug = ?'); $st->execute([$slug]); $l = $st->fetch();
    if (!$l) { echo '<p class="flash err">Place not found.</p>'; }
    else {
        $m = $pdo->prepare('SELECT m.id, p.code AS param, p.unit, s.code AS src, m.month, m.value, m.data_type, m.period, m.note
                            FROM measurements m JOIN parameters p ON p.id=m.parameter_id JOIN data_sources s ON s.id=m.source_id
                            WHERE m.location_id = ? AND m.month = 0 ORDER BY p.sort_order, s.code');
        $m->execute([$l['id']]);
        $rows = $m->fetchAll();
        $monthly = (int)$pdo->query('SELECT COUNT(*) FROM measurements WHERE month > 0 AND location_id = ' . (int)$l['id'])->fetchColumn();
        echo '<h1>Values: ' . h($l['name']) . '</h1><p class="muted">Annual values (month 0). ' . $monthly . ' monthly values from NASA POWER are not listed here.</p>
              <div class="card" style="overflow-x:auto"><table><tr><th>Parameter</th><th>Value</th><th>Type</th><th>Source</th><th>Period / note</th><th></th></tr>';
        foreach ($rows as $r) {
            echo '<tr><td>' . h($r['param']) . ' <span class="muted">' . h($r['unit']) . '</span></td><td>' . h($r['value']) . '</td><td><span class="tag">' . h($r['data_type']) . '</span></td><td>' . h($r['src']) . '</td>
                  <td class="muted">' . h($r['period']) . '<br>' . h($r['note']) . '</td>
                  <td><form method="post" onsubmit="return confirm(\'Delete this value?\')"><input type="hidden" name="action" value="delete_value"><input type="hidden" name="slug" value="' . h($slug) . '"><input type="hidden" name="id" value="' . h($r['id']) . '">' . csrfField('page=values&slug=' . $slug) . '<button class="bad">Delete</button></form></td></tr>';
        }
        if (!$rows) { echo '<tr><td colspan="6" class="muted">No annual values yet.</td></tr>'; }
        echo '</table></div>';

        $params = $pdo->query('SELECT code, name, unit FROM parameters ORDER BY sort_order')->fetchAll();
        $sources = $pdo->query('SELECT code, name FROM data_sources ORDER BY id')->fetchAll();
        echo '<h2>Add or update a value</h2><p class="muted">Use only numbers that come from a cited source. Writing the same parameter, source and month again replaces the old value.</p>
              <form method="post" class="card"><input type="hidden" name="action" value="save_value"><input type="hidden" name="slug" value="' . h($slug) . '">' . csrfField('page=values&slug=' . $slug) . '<div class="grid">
              <div><label>Parameter</label><select name="parameter">';
        foreach ($params as $p) { echo '<option value="' . h($p['code']) . '">' . h($p['name'] . ' (' . $p['unit'] . ')') . '</option>'; }
        echo '</select></div><div><label>Source</label><select name="source">';
        foreach ($sources as $s) { echo '<option value="' . h($s['code']) . '">' . h($s['name']) . '</option>'; }
        echo '</select></div><div><label>Value (a number, in the unit shown)</label><input name="value" required inputmode="decimal"></div>
              <div><label>Month (0 = annual)</label><input name="month" value="0" required pattern="\d{1,2}"></div>
              <div><label>Data type</label><select name="data_type">';
        foreach (MeasurementWriter::DATA_TYPES as $t) { echo '<option>' . h($t) . '</option>'; }
        echo '</select></div><div><label>Period (e.g. Mars Year 34 mean)</label><input name="period" maxlength="120"></div></div>
              <label>Note (citation, caveat)</label><input name="note" maxlength="255"><p><button>Save value</button></p></form>';
    }

} elseif ($page === 'logs') {
    echo '<h1>Logs</h1><h2>Last imports</h2><div class="card" style="overflow-x:auto"><table><tr><th>When</th><th>Source</th><th>Status</th><th>Rows</th><th>Message</th></tr>';
    foreach ($pdo->query('SELECT l.started_at, s.code, l.status, l.rows_saved, l.message FROM import_logs l JOIN data_sources s ON s.id = l.source_id ORDER BY l.id DESC LIMIT 30') as $r) {
        echo '<tr><td>' . h($r['started_at']) . '</td><td>' . h($r['code']) . '</td><td class="' . ($r['status'] === 'ok' ? 'ok' : 'warn') . '">' . h($r['status']) . '</td><td>' . h($r['rows_saved']) . '</td><td class="muted">' . h(mb_substr((string)$r['message'], 0, 160)) . '</td></tr>';
    }
    echo '</table></div><h2>Admin actions (audit log)</h2><div class="card" style="overflow-x:auto"><table><tr><th>When</th><th>User</th><th>Action</th><th>Target</th><th>Detail</th><th>IP</th></tr>';
    foreach ($pdo->query('SELECT a.created_at, u.username, a.action, a.target, a.detail, a.ip FROM audit_logs a LEFT JOIN admin_users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 50') as $r) {
        echo '<tr><td>' . h($r['created_at']) . '</td><td>' . h($r['username']) . '</td><td>' . h($r['action']) . '</td><td>' . h($r['target']) . '</td><td class="muted">' . h($r['detail']) . '</td><td class="muted">' . h($r['ip']) . '</td></tr>';
    }
    echo '</table></div>';

} else {
    echo '<h1>Places</h1><p class="muted">Sites, Mars/Moon references and known analogs. Heatmap grid cells are generated by the importer and are not listed.</p>
          <div class="card" style="overflow-x:auto"><table><tr><th>Name</th><th>Body / kind</th><th>Coordinates</th><th>Elevation</th><th>Verified</th><th>Values</th><th></th></tr>';
    $q = $pdo->query("SELECT l.slug, l.name, l.body, l.kind, l.latitude, l.longitude, l.elevation_m, l.is_verified,
                             (SELECT COUNT(*) FROM measurements m WHERE m.location_id = l.id AND m.value IS NOT NULL) AS n
                      FROM locations l WHERE l.kind <> 'grid' ORDER BY l.kind, l.id");
    foreach ($q as $r) {
        echo '<tr><td><b>' . h($r['name']) . '</b><br><span class="muted">' . h($r['slug']) . '</span></td><td>' . h($r['body'] . ' / ' . $r['kind']) . '</td>
              <td>' . h(round((float)$r['latitude'], 3) . ', ' . round((float)$r['longitude'], 3)) . '</td><td>' . ($r['elevation_m'] === null ? '—' : h(round((float)$r['elevation_m'])) . ' m') . '</td>
              <td>' . ($r['is_verified'] ? '<span class="ok">verified</span>' : '<span class="warn">to verify</span>') . '</td><td>' . h($r['n']) . '</td>
              <td><a href="?page=edit&slug=' . h($r['slug']) . '">Edit</a> · <a href="?page=values&slug=' . h($r['slug']) . '">Values</a></td></tr>';
    }
    echo '</table></div><p class="muted">After changing data, run <code>php scripts/export_static.php</code> to refresh the backup copy used when the server is down.</p>';
}
echo '</main></body></html>';
