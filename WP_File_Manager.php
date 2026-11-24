<?php
declare(strict_types=1);

/**
 * Application: Filemanager
 * Pure native PHP file management script (single-file deployment).
 * Compatible with standard PHP 7.4+ / 8.x shared and cloud hosting environments.
 */

session_start();

// ============================================================================
// CONFIGURATION
// ============================================================================
const FM_APP_NAME = 'Filemanager';
const FM_DISPLAY_TITLE = 'WP File Manager';
const FM_CSRF_KEY = 'filemanager_csrf_token';

$base_real = realpath(__DIR__);
if ($base_real === false) {
    $base_real = __DIR__;
}
define('FM_HOME_PATH', rtrim($base_real, DIRECTORY_SEPARATOR));

// ============================================================================
// HELPERS
// ============================================================================
function fm_csrf_token(): string
{
    if (empty($_SESSION[FM_CSRF_KEY]) || !is_string($_SESSION[FM_CSRF_KEY])) {
        $_SESSION[FM_CSRF_KEY] = bin2hex(random_bytes(32));
    }
    return $_SESSION[FM_CSRF_KEY];
}

function fm_verify_csrf(string $token): bool
{
    $stored = $_SESSION[FM_CSRF_KEY] ?? '';
    return is_string($stored) && $stored !== '' && hash_equals($stored, $token);
}

function fm_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function fm_flash(string $type, string $message): void
{
    $_SESSION['fm_flash'][] = ['type' => $type, 'message' => $message];
}

function fm_pull_flash(): array
{
    $items = $_SESSION['fm_flash'] ?? [];
    unset($_SESSION['fm_flash']);
    return is_array($items) ? $items : [];
}

/**
 * Resolves a server path (absolute or relative to FM_HOME_PATH) to its real path.
 */
function fm_resolve_server_dir(string $raw_path): ?string
{
    $cleaned = str_replace("\0", '', trim($raw_path));
    if ($cleaned === '' || $cleaned === '.') {
        return FM_HOME_PATH;
    }

    $normalized = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $cleaned);

    $is_unix_abs = (strncmp($normalized, DIRECTORY_SEPARATOR, 1) === 0);
    $is_win_abs = (bool)preg_match('/^[A-Za-z]:(\\\\|\/|$)/', $normalized);

    if ($is_unix_abs || $is_win_abs) {
        if ($is_win_abs && preg_match('/^[A-Za-z]:$/', $normalized)) {
            $normalized .= DIRECTORY_SEPARATOR;
        }
        $candidate = $normalized;
    } else {
        $candidate = FM_HOME_PATH . DIRECTORY_SEPARATOR . ltrim($normalized, DIRECTORY_SEPARATOR);
    }

    $real = @realpath($candidate);
    if ($real === false || !@is_dir($real)) {
        return null;
    }

    if (strlen($real) > 1 && substr($real, -1) === DIRECTORY_SEPARATOR && !preg_match('/^[A-Za-z]:\\\\$/', $real)) {
        $real = rtrim($real, DIRECTORY_SEPARATOR);
    }

    return $real;
}

function fm_format_size(int $bytes): string
{
    if ($bytes < 0) {
        return '0 B';
    }
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $index = 0;
    $size = (float)$bytes;
    while ($size >= 1024.0 && $index < count($units) - 1) {
        $size /= 1024.0;
        $index++;
    }
    return ($index === 0 ? (string)$bytes : number_format($size, 2)) . ' ' . $units[$index];
}

function fm_format_perms(string $path): string
{
    $perms = @fileperms($path);
    if ($perms === false) {
        return '----';
    }
    return substr(sprintf('%o', $perms), -4);
}

function fm_is_valid_name(string $name): bool
{
    if ($name === '' || $name === '.' || $name === '..') {
        return false;
    }
    if (strpbrk($name, "/\\\0") !== false) {
        return false;
    }
    return true;
}

function fm_delete_recursive(string $target): bool
{
    if (is_link($target) || is_file($target)) {
        return @unlink($target);
    }
    if (!is_dir($target)) {
        return false;
    }
    $items = @scandir($target);
    if ($items === false) {
        return false;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $child = $target . DIRECTORY_SEPARATOR . $item;
        if (!fm_delete_recursive($child)) {
            return false;
        }
    }
    return @rmdir($target);
}

function fm_is_editable_file(string $path): bool
{
    if (!is_file($path)) {
        return false;
    }
    $size = @filesize($path);
    if ($size !== false && $size > 2 * 1024 * 1024) {
        return false;
    }
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $editable_exts = [
        'txt', 'md', 'csv', 'json', 'xml', 'yml', 'yaml', 'ini', 'conf', 'log',
        'php', 'phtml', 'html', 'htm', 'css', 'scss', 'js', 'ts', 'sql', 'sh', 'htaccess'
    ];
    return in_array($ext, $editable_exts, true) || $ext === '';
}

function fm_redirect_dir(string $abs_dir): void
{
    $query = $abs_dir !== '' ? '?dir=' . rawurlencode($abs_dir) : '';
    header('Location: ' . basename($_SERVER['PHP_SELF'] ?? 'index.php') . $query);
    exit;
}

// ============================================================================
// CURRENT DIRECTORY RESOLUTION
// ============================================================================
$requested_dir = (string)($_GET['dir'] ?? $_POST['current_dir'] ?? '');
$current_abs = fm_resolve_server_dir($requested_dir);
if ($current_abs === null) {
    if ($requested_dir !== '') {
        fm_flash('error', 'Directory not found or not accessible: ' . $requested_dir);
    }
    $current_abs = FM_HOME_PATH;
}

// ============================================================================
// FILE DOWNLOAD HANDLER
// ============================================================================
if (isset($_GET['download'])) {
    $dl_path = str_replace("\0", '', (string)$_GET['download']);
    $dl_abs = @realpath($dl_path);
    if ($dl_abs === false || !@is_file($dl_abs) || !@is_readable($dl_abs)) {
        fm_flash('error', 'File cannot be downloaded.');
        fm_redirect_dir($current_abs);
    }

    $filename = basename($dl_abs);
    $filesize = @filesize($dl_abs);

    if (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
    header('X-Content-Type-Options: nosniff');
    if ($filesize !== false && $filesize >= 0) {
        header('Content-Length: ' . $filesize);
    }
    readfile($dl_abs);
    exit;
}

// ============================================================================
// POST ACTION HANDLERS (JUMP, UPLOAD WITH OVERWRITE, CREATE, RENAME, CHMOD, SAVE, DELETE)
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!fm_verify_csrf((string)($_POST['csrf_token'] ?? ''))) {
        fm_flash('error', 'Security token mismatch. Action aborted.');
        fm_redirect_dir($current_abs);
    }

    $action = (string)$_POST['action'];

    if ($action === 'jump_dir') {
        $jump_target = (string)($_POST['jump_path'] ?? '');
        $resolved_jump = fm_resolve_server_dir($jump_target);
        if ($resolved_jump === null) {
            fm_flash('error', 'Invalid or inaccessible directory path.');
            fm_redirect_dir($current_abs);
        }
        fm_redirect_dir($resolved_jump);
    }

    if ($action === 'upload') {
        if (!isset($_FILES['upload_file']) || !is_array($_FILES['upload_file'])) {
            fm_flash('error', 'No file selected for upload.');
            fm_redirect_dir($current_abs);
        }

        $err = (int)($_FILES['upload_file']['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            $upload_errors = [
                UPLOAD_ERR_INI_SIZE => 'Uploaded file exceeds server upload_max_filesize.',
                UPLOAD_ERR_FORM_SIZE => 'Uploaded file exceeds allowed form size.',
                UPLOAD_ERR_PARTIAL => 'File was only partially uploaded.',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder on server.',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
                UPLOAD_ERR_EXTENSION => 'File upload stopped by server extension.',
            ];
            fm_flash('error', $upload_errors[$err] ?? 'Upload failed.');
            fm_redirect_dir($current_abs);
        }

        $orig_name = basename((string)($_FILES['upload_file']['name'] ?? ''));
        $tmp_name = (string)($_FILES['upload_file']['tmp_name'] ?? '');

        if (!fm_is_valid_name($orig_name) || !is_uploaded_file($tmp_name)) {
            fm_flash('error', 'Invalid uploaded filename.');
            fm_redirect_dir($current_abs);
        }

        $dest_path = rtrim($current_abs, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $orig_name;
        if (is_dir($dest_path)) {
            fm_flash('error', 'A directory with the name "' . $orig_name . '" already exists.');
            fm_redirect_dir($current_abs);
        }

        if (!is_writable($current_abs)) {
            fm_flash('error', 'Current directory is not writable.');
            fm_redirect_dir($current_abs);
        }

        $overwrote = file_exists($dest_path);
        if (@move_uploaded_file($tmp_name, $dest_path)) {
            @chmod($dest_path, 0644);
            fm_flash(
                'success',
                $overwrote
                    ? 'Overwrote existing file "' . $orig_name . '".'
                    : 'Uploaded "' . $orig_name . '".'
            );
        } else {
            fm_flash('error', 'Could not move uploaded file.');
        }
        fm_redirect_dir($current_abs);
    }

    if ($action === 'create_item') {
        $kind = (string)($_POST['item_type'] ?? 'file');
        $name = trim((string)($_POST['item_name'] ?? ''));

        if (!fm_is_valid_name($name)) {
            fm_flash('error', 'Invalid name.');
            fm_redirect_dir($current_abs);
        }

        $target = rtrim($current_abs, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;
        if (file_exists($target)) {
            fm_flash('error', 'Target already exists.');
            fm_redirect_dir($current_abs);
        }

        if (!is_writable($current_abs)) {
            fm_flash('error', 'Directory is not writable.');
            fm_redirect_dir($current_abs);
        }

        if ($kind === 'dir') {
            if (@mkdir($target, 0755, false)) {
                fm_flash('success', 'Created folder "' . $name . '".');
            } else {
                fm_flash('error', 'Failed to create folder.');
            }
        } else {
            if (@file_put_contents($target, '', LOCK_EX) !== false) {
                @chmod($target, 0644);
                fm_flash('success', 'Created file "' . $name . '".');
            } else {
                fm_flash('error', 'Failed to create file.');
            }
        }
        fm_redirect_dir($current_abs);
    }

    if ($action === 'rename') {
        $old_raw = (string)($_POST['target_path'] ?? '');
        $new_name = trim((string)($_POST['new_name'] ?? ''));
        $old_abs = @realpath(str_replace("\0", '', $old_raw));

        if ($old_abs === false || !file_exists($old_abs)) {
            fm_flash('error', 'Invalid target for rename.');
            fm_redirect_dir($current_abs);
        }
        if (!fm_is_valid_name($new_name)) {
            fm_flash('error', 'Invalid new name.');
            fm_redirect_dir($current_abs);
        }

        $new_abs = dirname($old_abs) . DIRECTORY_SEPARATOR . $new_name;
        if (file_exists($new_abs)) {
            fm_flash('error', 'Destination name already exists.');
            fm_redirect_dir($current_abs);
        }

        if (@rename($old_abs, $new_abs)) {
            fm_flash('success', 'Renamed to "' . $new_name . '".');
        } else {
            fm_flash('error', 'Rename failed. Check permissions.');
        }
        fm_redirect_dir($current_abs);
    }

    if ($action === 'chmod') {
        $target_raw = (string)($_POST['target_path'] ?? '');
        $mode_raw = trim((string)($_POST['chmod_mode'] ?? ''));
        $target_abs = @realpath(str_replace("\0", '', $target_raw));

        if ($target_abs === false || !file_exists($target_abs)) {
            fm_flash('error', 'Invalid target for permissions change.');
            fm_redirect_dir($current_abs);
        }

        if (!preg_match('/^[0-7]{3,4}$/', $mode_raw)) {
            fm_flash('error', 'Enter a valid octal mode (e.g. 0644 or 0755).');
            fm_redirect_dir($current_abs);
        }

        $mode_oct = octdec($mode_raw);
        if (@chmod($target_abs, $mode_oct)) {
            fm_flash('success', 'Updated permissions for "' . basename($target_abs) . '" to ' . substr(sprintf('%o', $mode_oct), -4) . '.');
        } else {
            fm_flash('error', 'Failed to update permissions.');
        }
        fm_redirect_dir($current_abs);
    }

    if ($action === 'save_file') {
        $target_raw = (string)($_POST['target_path'] ?? '');
        $content = (string)($_POST['file_content'] ?? '');
        $target_abs = @realpath(str_replace("\0", '', $target_raw));

        if ($target_abs === false || !is_file($target_abs)) {
            fm_flash('error', 'File not found.');
            fm_redirect_dir($current_abs);
        }

        if (!is_writable($target_abs)) {
            fm_flash('error', 'File is not writable.');
            fm_redirect_dir($current_abs);
        }

        $normalized = str_replace("\r\n", "\n", $content);
        if (@file_put_contents($target_abs, $normalized, LOCK_EX) !== false) {
            fm_flash('success', 'Saved "' . basename($target_abs) . '".');
        } else {
            fm_flash('error', 'Unable to save file changes.');
        }
        fm_redirect_dir($current_abs);
    }

    if ($action === 'delete') {
        $target_raw = (string)($_POST['target_path'] ?? '');
        $target_abs = @realpath(str_replace("\0", '', $target_raw));

        if ($target_abs === false || !file_exists($target_abs)) {
            fm_flash('error', 'Target not found.');
            fm_redirect_dir($current_abs);
        }

        if (fm_delete_recursive($target_abs)) {
            fm_flash('success', 'Deleted "' . basename($target_abs) . '".');
        } else {
            fm_flash('error', 'Failed to delete "' . basename($target_abs) . '".');
        }
        fm_redirect_dir($current_abs);
    }
}

// ============================================================================
// EDIT VIEW DATA (IF REQUESTED VIA GET ?edit=...)
// ============================================================================
$editing_file_abs = null;
$editing_file_content = '';
if (isset($_GET['edit'])) {
    $candidate_edit = @realpath(str_replace("\0", '', (string)$_GET['edit']));
    if ($candidate_edit !== false && is_file($candidate_edit)) {
        if (!fm_is_editable_file($candidate_edit)) {
            fm_flash('error', 'Selected file is either too large or not a supported text format.');
        } elseif (!is_readable($candidate_edit)) {
            fm_flash('error', 'Selected file is not readable.');
        } else {
            $raw = @file_get_contents($candidate_edit);
            if ($raw !== false) {
                $editing_file_abs = $candidate_edit;
                $editing_file_content = $raw;
            } else {
                fm_flash('error', 'Could not read file contents.');
            }
        }
    } else {
        fm_flash('error', 'File not found.');
    }
}

// ============================================================================
// DIRECTORY LISTING
// ============================================================================
$entries = [];
$raw_items = @scandir($current_abs);
if ($raw_items !== false) {
    $dirs = [];
    $files = [];
    $prefix = rtrim($current_abs, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    foreach ($raw_items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $full = $prefix . $item;
        $is_dir = is_dir($full) && !is_link($full);
        $mtime = @filemtime($full);
        $size_val = $is_dir ? 0 : (int)(@filesize($full) ?: 0);
        $entry = [
            'name' => $item,
            'full_path' => $full,
            'is_dir' => $is_dir,
            'type' => $is_dir ? 'Folder' : (strtoupper((string)(pathinfo($item, PATHINFO_EXTENSION) ?: 'File'))),
            'size_text' => $is_dir ? '—' : fm_format_size($size_val),
            'modified' => $mtime ? date('Y-m-d H:i:s', $mtime) : '—',
            'perms' => fm_format_perms($full),
            'editable' => !$is_dir && fm_is_editable_file($full),
        ];
        if ($is_dir) {
            $dirs[] = $entry;
        } else {
            $files[] = $entry;
        }
    }
    usort($dirs, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
    usort($files, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
    $entries = array_merge($dirs, $files);
}

// Build clickable segments across the entire server path
$full_path_slash = str_replace('\\', '/', $current_abs);
$path_segments = [];

if (preg_match('/^([A-Za-z]:)(.*)$/', $full_path_slash, $m)) {
    $drive = $m[1];
    $rest = ltrim($m[2], '/');
    $path_segments[] = [
        'label' => $drive,
        'target' => $drive . '/',
        'leading_slash' => false,
    ];
    if ($rest !== '') {
        $acc = $drive;
        foreach (explode('/', $rest) as $seg) {
            $acc .= '/' . $seg;
            $path_segments[] = [
                'label' => $seg,
                'target' => $acc,
                'leading_slash' => true,
            ];
        }
    }
} else {
    $path_segments[] = [
        'label' => '/',
        'target' => '/',
        'leading_slash' => false,
    ];
    $trimmed = ltrim($full_path_slash, '/');
    if ($trimmed !== '') {
        $acc = '';
        foreach (explode('/', $trimmed) as $seg) {
            $acc .= '/' . $seg;
            $path_segments[] = [
                'label' => $seg,
                'target' => $acc,
                'leading_slash' => false,
            ];
        }
    }
}

$flashes = fm_pull_flash();
$csrf = fm_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="application-name" content="<?= fm_h(FM_APP_NAME) ?>">
    <title><?= fm_h(FM_DISPLAY_TITLE) ?></title>
    <style>
        :root {
            --ground: #0c1015;
            --surface: #131922;
            --surface-raise: #19222f;
            --ink: #e8eef5;
            --muted: #8b9bb0;
            --hairline: #232f3e;
            --accent: #5b8def;
            --accent-hover: #4779dc;
            --accent-tint: rgba(91, 141, 239, 0.14);
            --danger: #f06d7d;
            --danger-tint: rgba(240, 109, 125, 0.14);
            --ok: #3ec99a;
            --ok-tint: rgba(62, 201, 154, 0.14);
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: "Inter", ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            background: var(--ground);
            color: var(--ink);
            line-height: 1.5;
        }
        .studio {
            max-width: 1360px;
            margin: 0 auto;
            padding: 28px 24px 48px;
            display: grid;
            grid-template-columns: 330px minmax(0, 1fr);
            gap: 28px;
            align-items: start;
        }
        @media (max-width: 960px) {
            .studio {
                grid-template-columns: 1fr;
                padding: 18px 16px 36px;
                gap: 20px;
            }
        }

        /* Left Rail */
        .rail {
            background: var(--surface);
            border: 1px solid var(--hairline);
            border-radius: 14px;
            padding: 22px;
            position: sticky;
            top: 24px;
        }
        @media (max-width: 960px) {
            .rail { position: static; }
        }
        .rail-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 18px;
            padding-bottom: 14px;
            border-bottom: 1px solid var(--hairline);
        }
        .brand {
            margin: 0;
            font-size: 22px;
            font-weight: 700;
            letter-spacing: -0.025em;
            color: var(--ink);
        }
        .path-box {
            background: var(--ground);
            border: 1px solid var(--hairline);
            border-radius: 10px;
            padding: 12px;
            margin-bottom: 20px;
        }
        .path-caption {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--muted);
            margin-bottom: 8px;
        }
        .clickable-full-path {
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 12px;
            line-height: 1.7;
            word-break: break-all;
            color: var(--muted);
            margin-bottom: 10px;
        }
        .path-seg {
            color: var(--accent);
            text-decoration: none;
            font-weight: 500;
            padding: 1px 3px;
            border-radius: 4px;
        }
        .path-seg:hover {
            background: var(--accent-tint);
            color: #93b8ff;
            text-decoration: underline;
        }

        .tool-block {
            padding-top: 16px;
            margin-top: 16px;
            border-top: 1px solid var(--hairline);
        }
        .tool-heading {
            margin: 0 0 10px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--muted);
        }
        .stack {
            display: flex;
            flex-direction: column;
            gap: 9px;
        }
        .row {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        /* Main Workspace */
        .workspace {
            min-width: 0;
        }
        .flash {
            padding: 12px 16px;
            border-radius: 10px;
            margin-bottom: 16px;
            font-size: 13px;
            font-weight: 500;
            border: 1px solid transparent;
        }
        .flash.success {
            background: var(--ok-tint);
            color: var(--ok);
            border-color: rgba(62, 201, 154, 0.35);
        }
        .flash.error {
            background: var(--danger-tint);
            color: var(--danger);
            border-color: rgba(240, 109, 125, 0.35);
        }

        .panel {
            background: var(--surface);
            border: 1px solid var(--hairline);
            border-radius: 14px;
            overflow: hidden;
        }
        .editor-panel {
            padding: 22px;
        }
        .editor-title {
            margin: 0 0 14px;
            font-size: 16px;
            font-weight: 600;
            color: var(--ink);
        }

        input[type="text"],
        input[type="file"],
        select,
        textarea {
            font-family: inherit;
            font-size: 13px;
            color: var(--ink);
            background: var(--ground);
            border: 1px solid var(--hairline);
            border-radius: 8px;
            padding: 8px 10px;
            width: 100%;
        }
        input[type="text"]:focus,
        select:focus,
        textarea:focus {
            outline: 2px solid var(--accent-tint);
            border-color: var(--accent);
        }
        textarea {
            min-height: 400px;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 13px;
            line-height: 1.55;
            resize: vertical;
        }

        button, .btn-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 8px 13px;
            font-size: 13px;
            font-weight: 500;
            border-radius: 8px;
            border: 1px solid var(--hairline);
            background: var(--surface-raise);
            color: var(--ink);
            text-decoration: none;
            cursor: pointer;
            white-space: nowrap;
        }
        button:hover, .btn-link:hover {
            background: #202b3b;
        }
        .btn-primary {
            background: var(--accent);
            border-color: var(--accent);
            color: #090d14;
            font-weight: 600;
        }
        .btn-primary:hover {
            background: var(--accent-hover);
            border-color: var(--accent-hover);
            color: #ffffff;
        }
        .btn-danger {
            color: var(--danger);
            background: var(--danger-tint);
            border-color: rgba(240, 109, 125, 0.3);
        }
        .btn-danger:hover {
            background: rgba(240, 109, 125, 0.22);
        }
        .btn-sm {
            padding: 5px 9px;
            font-size: 12px;
            border-radius: 7px;
        }

        .table-scroll {
            overflow-x: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        th, td {
            padding: 12px 14px;
            text-align: left;
            border-bottom: 1px solid var(--hairline);
            vertical-align: middle;
        }
        th {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--muted);
            background: var(--surface-raise);
        }
        tr:last-child td { border-bottom: none; }
        tbody tr:hover td { background: var(--accent-tint); }

        .entry-name {
            font-weight: 600;
            color: var(--ink);
            text-decoration: none;
            word-break: break-word;
        }
        a.entry-name {
            color: var(--accent);
        }
        a.entry-name:hover {
            text-decoration: underline;
        }
        .type-pill {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 600;
            background: var(--ground);
            color: var(--muted);
            border: 1px solid var(--hairline);
        }
        .mono {
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 12px;
            color: var(--muted);
        }
        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        /* Modals */
        .modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(5, 8, 12, 0.75);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 18px;
            z-index: 60;
        }
        .modal-backdrop.open { display: flex; }
        .modal-card {
            width: 100%;
            max-width: 420px;
            background: var(--surface);
            border: 1px solid var(--hairline);
            border-radius: 14px;
            padding: 22px;
        }
        .modal-card h2 {
            margin: 0 0 14px;
            font-size: 17px;
            font-weight: 600;
        }
        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            margin-top: 16px;
        }
    </style>
</head>
<body>
<div class="studio">
    <aside class="rail">
        <div class="rail-header">
            <h1 class="brand"><?= fm_h(FM_DISPLAY_TITLE) ?></h1>
            <a class="btn-link btn-sm" href="?dir=<?= rawurlencode(FM_HOME_PATH) ?>">Home</a>
        </div>

        <div class="path-box" aria-label="Full directory path">
            <div class="path-caption">Full Path</div>
            <div class="clickable-full-path">
                <?php foreach ($path_segments as $seg): ?>
                    <?php if (!empty($seg['leading_slash'])): ?><span>/</span><?php endif; ?>
                    <a class="path-seg" href="?dir=<?= rawurlencode((string)$seg['target']) ?>"><?= fm_h((string)$seg['label']) ?></a>
                <?php endforeach; ?>
            </div>
            <form method="post" class="row">
                <input type="hidden" name="csrf_token" value="<?= fm_h($csrf) ?>">
                <input type="hidden" name="current_dir" value="<?= fm_h($current_abs) ?>">
                <input type="hidden" name="action" value="jump_dir">
                <input type="text" name="jump_path" value="<?= fm_h($full_path_slash) ?>" aria-label="Server directory path" required>
                <button type="submit" class="btn-sm">Go</button>
            </form>
        </div>

        <?php if ($editing_file_abs === null): ?>
            <div class="tool-block">
                <h2 class="tool-heading">Upload File</h2>
                <form method="post" enctype="multipart/form-data" class="stack">
                    <input type="hidden" name="csrf_token" value="<?= fm_h($csrf) ?>">
                    <input type="hidden" name="current_dir" value="<?= fm_h($current_abs) ?>">
                    <input type="hidden" name="action" value="upload">
                    <input type="file" name="upload_file" required>
                    <button type="submit" class="btn-primary">Upload File</button>
                </form>
            </div>

            <div class="tool-block">
                <h2 class="tool-heading">New File or Directory</h2>
                <form method="post" class="stack">
                    <input type="hidden" name="csrf_token" value="<?= fm_h($csrf) ?>">
                    <input type="hidden" name="current_dir" value="<?= fm_h($current_abs) ?>">
                    <input type="hidden" name="action" value="create_item">
                    <div class="row">
                        <select name="item_type" aria-label="Item type">
                            <option value="file">File</option>
                            <option value="dir">Directory</option>
                        </select>
                        <input type="text" name="item_name" placeholder="Item name" required>
                    </div>
                    <button type="submit" class="btn-primary">Create Item</button>
                </form>
            </div>
        <?php endif; ?>
    </aside>

    <main class="workspace">
        <?php foreach ($flashes as $f): ?>
            <div class="flash <?= fm_h($f['type']) ?>"><?= fm_h($f['message']) ?></div>
        <?php endforeach; ?>

        <?php if ($editing_file_abs !== null): ?>
            <section class="panel editor-panel">
                <h2 class="editor-title">Editing: <?= fm_h(basename($editing_file_abs)) ?></h2>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= fm_h($csrf) ?>">
                    <input type="hidden" name="current_dir" value="<?= fm_h($current_abs) ?>">
                    <input type="hidden" name="action" value="save_file">
                    <input type="hidden" name="target_path" value="<?= fm_h($editing_file_abs) ?>">
                    <textarea name="file_content"><?= fm_h($editing_file_content) ?></textarea>
                    <div class="row" style="margin-top: 14px;">
                        <button type="submit" class="btn-primary">Save Changes</button>
                        <a class="btn-link" href="?dir=<?= rawurlencode($current_abs) ?>">Cancel</a>
                    </div>
                </form>
            </section>
        <?php else: ?>
            <section class="panel table-scroll">
                <table>
                    <thead>
                    <tr>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Size</th>
                        <th>Modified Date</th>
                        <th>Permissions</th>
                        <th>Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($entries)): ?>
                        <tr>
                            <td colspan="6" style="color: var(--muted); text-align: center; padding: 28px;">
                                This directory is empty or unreadable.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($entries as $item): ?>
                            <tr>
                                <td>
                                    <?php if ($item['is_dir']): ?>
                                        <a class="entry-name" href="?dir=<?= rawurlencode($item['full_path']) ?>"><?= fm_h($item['name']) ?>/</a>
                                    <?php else: ?>
                                        <span class="entry-name"><?= fm_h($item['name']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="type-pill"><?= fm_h($item['type']) ?></span></td>
                                <td class="mono"><?= fm_h($item['size_text']) ?></td>
                                <td class="mono"><?= fm_h($item['modified']) ?></td>
                                <td class="mono"><?= fm_h($item['perms']) ?></td>
                                <td>
                                    <div class="actions">
                                        <?php if (!$item['is_dir']): ?>
                                            <a class="btn-link btn-sm" href="?dir=<?= rawurlencode($current_abs) ?>&amp;download=<?= rawurlencode($item['full_path']) ?>">Download</a>
                                            <?php if ($item['editable']): ?>
                                                <a class="btn-link btn-sm" href="?dir=<?= rawurlencode($current_abs) ?>&amp;edit=<?= rawurlencode($item['full_path']) ?>">Edit</a>
                                            <?php endif; ?>
                                        <?php endif; ?>

                                        <button type="button"
                                                class="btn-sm"
                                                data-open-rename
                                                data-path="<?= fm_h($item['full_path']) ?>"
                                                data-name="<?= fm_h($item['name']) ?>">Rename</button>

                                        <button type="button"
                                                class="btn-sm"
                                                data-open-chmod
                                                data-path="<?= fm_h($item['full_path']) ?>"
                                                data-mode="<?= fm_h($item['perms']) ?>">CHMOD</button>

                                        <form method="post" onsubmit="return confirm('Delete this item permanently?');" style="display:inline;">
                                            <input type="hidden" name="csrf_token" value="<?= fm_h($csrf) ?>">
                                            <input type="hidden" name="current_dir" value="<?= fm_h($current_abs) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="target_path" value="<?= fm_h($item['full_path']) ?>">
                                            <button type="submit" class="btn-danger btn-sm">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </section>
        <?php endif; ?>
    </main>
</div>

<!-- Rename Modal -->
<div id="rename-modal" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="rename-title">
    <div class="modal-card">
        <h2 id="rename-title">Rename Item</h2>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= fm_h($csrf) ?>">
            <input type="hidden" name="current_dir" value="<?= fm_h($current_abs) ?>">
            <input type="hidden" name="action" value="rename">
            <input type="hidden" id="rename-target" name="target_path" value="">
            <input type="text" id="rename-input" name="new_name" required>
            <div class="modal-footer">
                <button type="button" data-close-modal="rename-modal">Cancel</button>
                <button type="submit" class="btn-primary">Rename</button>
            </div>
        </form>
    </div>
</div>

<!-- CHMOD Modal -->
<div id="chmod-modal" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="chmod-title">
    <div class="modal-card">
        <h2 id="chmod-title">Change Permissions (CHMOD)</h2>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= fm_h($csrf) ?>">
            <input type="hidden" name="current_dir" value="<?= fm_h($current_abs) ?>">
            <input type="hidden" name="action" value="chmod">
            <input type="hidden" id="chmod-target" name="target_path" value="">
            <input type="text" id="chmod-input" name="chmod_mode" pattern="[0-7]{3,4}" placeholder="0644" required>
            <div class="modal-footer">
                <button type="button" data-close-modal="chmod-modal">Cancel</button>
                <button type="submit" class="btn-primary">Apply</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    function openModal(id) {
        const el = document.getElementById(id);
        if (el) el.classList.add('open');
    }
    function closeModal(id) {
        const el = document.getElementById(id);
        if (el) el.classList.remove('open');
    }

    document.querySelectorAll('[data-open-rename]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('rename-target').value = btn.getAttribute('data-path') || '';
            const input = document.getElementById('rename-input');
            input.value = btn.getAttribute('data-name') || '';
            openModal('rename-modal');
            input.focus();
            input.select();
        });
    });

    document.querySelectorAll('[data-open-chmod]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('chmod-target').value = btn.getAttribute('data-path') || '';
            const input = document.getElementById('chmod-input');
            input.value = btn.getAttribute('data-mode') || '0644';
            openModal('chmod-modal');
            input.focus();
            input.select();
        });
    });

    document.querySelectorAll('[data-close-modal]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            closeModal(btn.getAttribute('data-close-modal'));
        });
    });

    document.querySelectorAll('.modal-backdrop').forEach(function (backdrop) {
        backdrop.addEventListener('click', function (e) {
            if (e.target === backdrop) {
                backdrop.classList.remove('open');
            }
        });
    });
})();
</script>
</body>
</html>