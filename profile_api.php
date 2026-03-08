<?php
// =====================================================
// profile_api.php — Profile page API
// Handles: get profile, update profile, avatar,
//          stats, delete account
// =====================================================

// ── EDIT THESE (same as api.php) ──────────────────
$DB_HOST = 'localhost';
$DB_PORT = '5432';
$DB_NAME = 'mydiary';
$DB_USER = 'postgres';
$DB_PASS = 'admin';
// ──────────────────────────────────────────────────

session_start();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// ── DB ────────────────────────────────────────────
$conn = pg_connect("host=$DB_HOST port=$DB_PORT dbname=$DB_NAME user=$DB_USER password=$DB_PASS");
if (!$conn) { out(500, 'Cannot connect to database'); }

// ── Helpers ───────────────────────────────────────
function out($code, $data) {
    http_response_code($code);
    echo is_string($data) ? json_encode(['error' => $data]) : json_encode($data);
    exit;
}
function body() { return json_decode(file_get_contents('php://input'), true) ?? []; }
function qry($conn, $sql, $params = []) {
    $r = empty($params) ? pg_query($conn, $sql) : pg_query_params($conn, $sql, $params);
    if ($r === false) out(500, 'Query error: ' . pg_last_error($conn));
    return $r;
}
function one($conn, $sql, $params = []) { return pg_fetch_assoc(qry($conn, $sql, $params)) ?: null; }
function all($conn, $sql, $params = []) {
    $r = qry($conn, $sql, $params); $out = [];
    while ($row = pg_fetch_assoc($r)) $out[] = $row;
    return array_values($out);
}
function needAuth() {
    if (empty($_SESSION['user_id'])) out(401, 'Not logged in');
    return (int)$_SESSION['user_id'];
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// ════════════════════════════════════════════════
// GET FULL PROFILE + STATS
// ════════════════════════════════════════════════
if ($action === 'profile' && $method === 'GET') {
    $uid = needAuth();

    // Check which optional columns exist
    $cols_res = pg_query($conn, "SELECT column_name FROM information_schema.columns WHERE table_name='users'");
    $existing_cols = [];
    while ($row = pg_fetch_assoc($cols_res)) $existing_cols[] = $row['column_name'];

    $select_cols = 'id, name, email, app_pin';
    if (in_array('bio', $existing_cols))       $select_cols .= ', bio';
    if (in_array('birthday', $existing_cols))  $select_cols .= ', birthday';
    if (in_array('mood', $existing_cols))      $select_cols .= ', mood';
    if (in_array('avatar', $existing_cols))    $select_cols .= ', avatar';
    if (in_array('joined_at', $existing_cols)) $select_cols .= ', joined_at';

    $user = one($conn, "SELECT $select_cols FROM users WHERE id=\$1", [$uid]);
    if (!$user) out(404, 'User not found');

    // Book count
    $bcount = one($conn, 'SELECT COUNT(*)::int AS c FROM books WHERE user_id=$1', [$uid]);

    // Page count
    $pcount = one($conn, 'SELECT COUNT(*)::int AS c FROM pages WHERE user_id=$1', [$uid]);

    // Photo count
    $phcount = one($conn, 'SELECT COUNT(*)::int AS c FROM photos WHERE user_id=$1', [$uid]);

    // Total words written (rough count from content)
    $words = one($conn,
        "SELECT COALESCE(SUM(array_length(regexp_split_to_array(
            regexp_replace(content, '<[^>]+>', '', 'g'), '\s+'), 1)), 0)::int AS c
         FROM pages WHERE user_id=\$1 AND content IS NOT NULL AND content != ''", [$uid]);

    // All books with page count
    $books = all($conn,
        'SELECT b.id, b.title, b.color, b.pin, b.created_at,
                COUNT(p.id)::int AS page_count
         FROM books b
         LEFT JOIN pages p ON p.book_id = b.id
         WHERE b.user_id = $1
         GROUP BY b.id ORDER BY b.created_at DESC', [$uid]);

    // Most recent entry date
    $last = one($conn,
        'SELECT date_label FROM pages WHERE user_id=$1 ORDER BY created_at DESC LIMIT 1', [$uid]);

    // Streak — consecutive days with entries
    $streak = 0;
    $dates = all($conn,
        'SELECT DISTINCT date_iso FROM pages WHERE user_id=$1 ORDER BY date_iso DESC', [$uid]);
    if (!empty($dates)) {
        $streak = 1;
        $prev = new DateTime($dates[0]['date_iso']);
        for ($i = 1; $i < count($dates); $i++) {
            $curr = new DateTime($dates[$i]['date_iso']);
            $diff = $prev->diff($curr)->days;
            if ($diff === 1) { $streak++; $prev = $curr; }
            else break;
        }
    }

    out(200, [
        'user'        => $user,
        'stats'       => [
            'books'      => (int)$bcount['c'],
            'pages'      => (int)$pcount['c'],
            'photos'     => (int)$phcount['c'],
            'words'      => (int)$words['c'],
            'streak'     => $streak,
            'last_entry' => $last ? $last['date_label'] : null,
        ],
        'books'       => $books,
    ]);
}

// ════════════════════════════════════════════════
// UPDATE PROFILE (name, bio, birthday, mood)
// ════════════════════════════════════════════════
if ($action === 'update' && $method === 'POST') {
    $uid = needAuth();
    $b   = body();

    $name     = trim($b['name'] ?? '');
    $bio      = trim($b['bio'] ?? '');
    $birthday = $b['birthday'] ?? null;
    $mood     = trim($b['mood'] ?? '');

    if (!$name) out(400, 'Name cannot be empty');

    // Validate birthday format
    if ($birthday && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthday)) {
        $birthday = null;
    }

    // Check which columns exist in users table
    $cols_res = pg_query($conn, "SELECT column_name FROM information_schema.columns WHERE table_name='users'");
    $existing_cols = [];
    while ($row = pg_fetch_assoc($cols_res)) $existing_cols[] = $row['column_name'];

    // Always update name (always exists)
    $result = pg_query_params($conn, 'UPDATE users SET name=$1 WHERE id=$2', [$name, $uid]);
    if ($result === false) out(500, 'Failed to update name: ' . pg_last_error($conn));

    // Only update optional columns if they exist
    if (in_array('bio', $existing_cols)) {
        pg_query_params($conn, 'UPDATE users SET bio=$1 WHERE id=$2', [$bio ?: null, $uid]);
    }
    if (in_array('birthday', $existing_cols) && $birthday) {
        pg_query_params($conn, 'UPDATE users SET birthday=$1 WHERE id=$2', [$birthday, $uid]);
    }
    if (in_array('mood', $existing_cols)) {
        pg_query_params($conn, 'UPDATE users SET mood=$1 WHERE id=$2', [$mood ?: null, $uid]);
    }

    // Return updated user — only select columns that exist
    $select_cols = 'id, name, email, app_pin';
    if (in_array('bio', $existing_cols))       $select_cols .= ', bio';
    if (in_array('birthday', $existing_cols))  $select_cols .= ', birthday';
    if (in_array('mood', $existing_cols))      $select_cols .= ', mood';
    if (in_array('avatar', $existing_cols))    $select_cols .= ', avatar';
    if (in_array('joined_at', $existing_cols)) $select_cols .= ', joined_at';

    $user = one($conn, "SELECT $select_cols FROM users WHERE id=\$1", [$uid]);
    out(200, ['ok' => true, 'user' => $user]);
}

// ════════════════════════════════════════════════
// UPDATE AVATAR
// ════════════════════════════════════════════════
if ($action === 'avatar' && $method === 'POST') {
    $uid = needAuth();
    $b   = body();

    $avatar = $b['avatar'] ?? null;
    if (!$avatar) out(400, 'No avatar data provided');

    // Basic size check (~2MB base64 limit)
    if (strlen($avatar) > 2800000) out(400, 'Image too large. Please use a smaller image.');

    qry($conn, 'UPDATE users SET avatar=$1 WHERE id=$2', [$avatar, $uid]);
    out(200, ['ok' => true]);
}

// ════════════════════════════════════════════════
// CHANGE PASSWORD
// ════════════════════════════════════════════════
if ($action === 'password' && $method === 'POST') {
    $uid = needAuth();
    $b   = body();

    $current  = $b['current']      ?? '';
    $new_pass = $b['new'] ?? $b['newpass'] ?? $b['password_new'] ?? '';

    if (!$current || !$new_pass) out(400, 'Both current and new password required');
    if (strlen($new_pass) < 6)   out(400, 'New password must be at least 6 characters');

    $user = one($conn, 'SELECT password FROM users WHERE id=$1', [$uid]);
    if (!$user) out(404, 'User not found');
    if (!password_verify($current, $user['password'])) out(401, 'Current password is incorrect');

    $hash = password_hash($new_pass, PASSWORD_BCRYPT);
    $result = pg_query_params($conn, 'UPDATE users SET password=$1 WHERE id=$2', [$hash, $uid]);
    if ($result === false) out(500, 'Failed to update password: ' . pg_last_error($conn));

    out(200, ['ok' => true]);
}

// ════════════════════════════════════════════════
// DELETE ACCOUNT (sign out permanently)
// ════════════════════════════════════════════════
if ($action === 'delete' && $method === 'POST') {
    $uid = needAuth();
    $b   = body();

    $pass = $b['password'] ?? '';
    if (!$pass) out(400, 'Please confirm your password to delete account');

    $user = one($conn, 'SELECT password FROM users WHERE id=$1', [$uid]);
    if (!$user || !password_verify($pass, $user['password'])) out(401, 'Incorrect password');

    // CASCADE deletes all books, pages, photos automatically
    qry($conn, 'DELETE FROM users WHERE id=$1', [$uid]);
    session_destroy();
    out(200, ['ok' => true, 'message' => 'Account deleted']);
}

// ════════════════════════════════════════════════
// LOGOUT (session only)
// ════════════════════════════════════════════════
// Quick debug — open profile_api.php?action=debug in browser to check DB
if ($action === 'debug') {
    $cols_res = pg_query($conn, "SELECT column_name FROM information_schema.columns WHERE table_name='users' ORDER BY ordinal_position");
    $cols = [];
    while ($row = pg_fetch_assoc($cols_res)) $cols[] = $row['column_name'];
    out(200, ['db' => 'connected', 'users_columns' => $cols, 'session_uid' => $_SESSION['user_id'] ?? null]);
}

if ($action === 'logout') {
    session_destroy();
    out(200, ['ok' => true]);
}

out(404, 'Unknown action');