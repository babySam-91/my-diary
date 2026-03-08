<?php
// ================================================
// api.php  —  Simple PHP + PostgreSQL backend
// No JWT, no libraries — just PHP sessions
// ================================================

// ── EDIT THESE 5 LINES ──────────────────────────
$DB_HOST = 'localhost';
$DB_PORT = '5432';
$DB_NAME = 'mydiary';
$DB_USER = 'postgres';
$DB_PASS = 'admin';
// ────────────────────────────────────────────────

session_start();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin:  http://localhost');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ── Connect to PostgreSQL ────────────────────────
$conn = pg_connect("host=$DB_HOST port=$DB_PORT dbname=$DB_NAME user=$DB_USER password=$DB_PASS");
if (!$conn) {
    out(500, 'Cannot connect to database. Check your DB settings in api.php');
}

// ── Helpers ──────────────────────────────────────
function out($code, $data) {
    http_response_code($code);
    if (is_string($data)) echo json_encode(['error' => $data]);
    else echo json_encode($data);
    exit;
}

function body() {
    return json_decode(file_get_contents('php://input'), true) ?? [];
}

function qry($conn, $sql, $params = []) {
    if (empty($params)) $r = pg_query($conn, $sql);
    else $r = pg_query_params($conn, $sql, $params);
    if ($r === false) out(500, 'Query error: ' . pg_last_error($conn));
    return $r;
}

function one($conn, $sql, $params = []) {
    return pg_fetch_assoc(qry($conn, $sql, $params)) ?: null;
}

function all($conn, $sql, $params = []) {
    $r = qry($conn, $sql, $params);
    $out = [];
    while ($row = pg_fetch_assoc($r)) $out[] = $row;
    return array_values($out); // always returns a JSON array [], never {}
}

function needAuth() {
    if (empty($_SESSION['user_id'])) out(401, 'Not logged in');
    return $_SESSION['user_id'];
}

// ── Router ───────────────────────────────────────
$uri    = $_SERVER['REQUEST_URI'];
$method = $_SERVER['REQUEST_METHOD'];

// Extract action from ?action=xxx or path
$action = $_GET['action'] ?? '';
$id     = $_GET['id'] ?? null;
$sub    = $_GET['sub'] ?? null;

// ════════════════════════════════════════════════
// AUTH
// ════════════════════════════════════════════════

if ($action === 'register' && $method === 'POST') {
    $b     = body();
    $name  = trim($b['name'] ?? '');
    $email = strtolower(trim($b['email'] ?? ''));
    $pass  = $b['password'] ?? '';

    if (!$name)  out(400, 'Name is required');
    if (!$email) out(400, 'Email is required');
    if (!$pass)  out(400, 'Password is required');
    if (strlen($pass) < 6) out(400, 'Password must be at least 6 characters');

    $exists = one($conn, 'SELECT id FROM users WHERE email=$1', [$email]);
    if ($exists) out(400, 'Email already registered');

    $hash = password_hash($pass, PASSWORD_BCRYPT);
    $user = one($conn, 'INSERT INTO users (name,email,password,app_pin) VALUES ($1,$2,$3,NULL) RETURNING id,name,email,app_pin',
        [$name, $email, $hash]);

    $_SESSION['user_id']   = $user['id'];
    $_SESSION['user_name'] = $user['name'];
    out(200, ['ok' => true, 'user' => $user]);
}

if ($action === 'login' && $method === 'POST') {
    $b     = body();
    $email = strtolower(trim($b['email'] ?? ''));
    $pass  = $b['password'] ?? '';

    if (!$email || !$pass) out(400, 'Email and password required');

    $user = one($conn, 'SELECT * FROM users WHERE email=$1', [$email]);
    if (!$user || !password_verify($pass, $user['password'])) out(401, 'Wrong email or password');

    $_SESSION['user_id']   = $user['id'];
    $_SESSION['user_name'] = $user['name'];
    unset($user['password']);
    out(200, ['ok' => true, 'user' => $user]);
}

if ($action === 'logout') {
    session_destroy();
    out(200, ['ok' => true]);
}

if ($action === 'me') {
    $uid  = needAuth();
    $user = one($conn, 'SELECT id,name,email,app_pin FROM users WHERE id=$1', [$uid]);
    out(200, $user);
}

if ($action === 'setpin' && $method === 'POST') {
    $uid = needAuth();
    $b   = body();
    qry($conn, 'UPDATE users SET app_pin=$1 WHERE id=$2', [$b['pin'] ?? null, $uid]);
    out(200, ['ok' => true]);
}

// ════════════════════════════════════════════════
// BOOKS
// ════════════════════════════════════════════════

if ($action === 'books') {
    $uid = needAuth();

    if ($method === 'GET' && !$id) {
        $books = all($conn,
            'SELECT b.*, COUNT(p.id)::int AS page_count
             FROM books b LEFT JOIN pages p ON p.book_id=b.id
             WHERE b.user_id=$1 GROUP BY b.id ORDER BY b.created_at ASC', [$uid]);
        out(200, $books);
    }

    if ($method === 'POST') {
        $b    = body();
        $book = one($conn, 'INSERT INTO books (user_id,title,color) VALUES ($1,$2,$3) RETURNING *',
            [$uid, $b['title'] ?? 'Untitled Book', $b['color'] ?? '#d4a84b']);
        $book['page_count'] = 0;
        out(200, $book);
    }

    if ($method === 'PUT' && $id) {
        $b = body();
        if (isset($b['title']))
            qry($conn, 'UPDATE books SET title=$1 WHERE id=$2 AND user_id=$3', [$b['title'], $id, $uid]);
        if (array_key_exists('pin', $b))
            qry($conn, 'UPDATE books SET pin=$1 WHERE id=$2 AND user_id=$3', [$b['pin'], $id, $uid]);
        out(200, ['ok' => true]);
    }

    if ($method === 'DELETE' && $id) {
        qry($conn, 'DELETE FROM books WHERE id=$1 AND user_id=$2', [$id, $uid]);
        out(200, ['ok' => true]);
    }
}

// ════════════════════════════════════════════════
// PAGES
// ════════════════════════════════════════════════

if ($action === 'pages') {
    $uid = needAuth();

    // List pages for a book: ?action=pages&book_id=X
    $book_id = $_GET['book_id'] ?? null;
    if ($method === 'GET' && $book_id) {
        $pages = all($conn,
            'SELECT id,date_label,date_iso,LEFT(content,80) AS preview,
                    (drawing IS NOT NULL) AS has_drawing,
                    (SELECT COUNT(*)::int FROM photos WHERE page_id=p.id) AS photo_count
             FROM pages p WHERE book_id=$1 AND user_id=$2 ORDER BY created_at ASC',
            [$book_id, $uid]);
        out(200, $pages);
    }

    // Get single full page: ?action=pages&id=X
    if ($method === 'GET' && $id) {
        $page = one($conn, 'SELECT * FROM pages WHERE id=$1 AND user_id=$2', [$id, $uid]);
        if (!$page) out(404, 'Page not found');
        $photos = all($conn, 'SELECT id,data FROM photos WHERE page_id=$1 ORDER BY created_at ASC', [$id]);
        $page['photos'] = $photos ?: []; // always an array
        $page['content'] = $page['content'] ?? '';
        $page['drawing'] = $page['drawing'] ?? null;
        out(200, $page);
    }

    if ($method === 'POST') {
        $b    = body();
        $page = one($conn,
            'INSERT INTO pages (book_id,user_id,date_label,date_iso) VALUES ($1,$2,$3,$4) RETURNING *',
            [$b['book_id'], $uid, $b['date_label'], $b['date_iso']]);
        $page['photos'] = [];
        out(200, $page);
    }

    if ($method === 'PUT' && $id) {
        $b = body();
        if (isset($b['content']))
            qry($conn, 'UPDATE pages SET content=$1 WHERE id=$2 AND user_id=$3', [$b['content'], $id, $uid]);
        if (array_key_exists('drawing', $b))
            qry($conn, 'UPDATE pages SET drawing=$1 WHERE id=$2 AND user_id=$3', [$b['drawing'], $id, $uid]);
        out(200, ['ok' => true]);
    }

    if ($method === 'DELETE' && $id) {
        qry($conn, 'DELETE FROM pages WHERE id=$1 AND user_id=$2', [$id, $uid]);
        out(200, ['ok' => true]);
    }
}

// ════════════════════════════════════════════════
// PHOTOS
// ════════════════════════════════════════════════

if ($action === 'photos') {
    $uid = needAuth();

    if ($method === 'POST') {
        $b   = body();
        $res = one($conn, 'INSERT INTO photos (page_id,user_id,data) VALUES ($1,$2,$3) RETURNING id',
            [$b['page_id'], $uid, $b['data']]);
        out(200, ['id' => $res['id']]);
    }

    if ($method === 'DELETE' && $id) {
        qry($conn, 'DELETE FROM photos WHERE id=$1 AND user_id=$2', [$id, $uid]);
        out(200, ['ok' => true]);
    }
}

// ════════════════════════════════════════════════
// CALENDAR
// ════════════════════════════════════════════════

if ($action === 'calendar') {
    $uid     = needAuth();
    $entries = all($conn, 'SELECT DISTINCT date_iso, book_id FROM pages WHERE user_id=$1', [$uid]);
    out(200, $entries);
}

out(404, 'Unknown action');
