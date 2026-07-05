<?php
session_start();
date_default_timezone_set('Asia/Makassar');

define('DB_FILE', __DIR__ . '/attending_db.sqlite');
define('APP_NAME', 'PHPAttend');
define('APP_VERSION', '1.0.0');

if (isset($_GET['sw'])) {
  header('Content-Type: application/javascript; charset=utf-8');
  header('Service-Worker-Allowed: /');
  ?>
  const CACHE_NAME = 'PHPAttend-cache-v1';
  const ASSETS = [
    './',
    './index.php',
    'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',
    'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',
    'https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap',
    'https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0'
  ];

  self.addEventListener('install', (e) => {
    e.waitUntil(
      caches.open(CACHE_NAME).then((cache) => {
        return cache.addAll(ASSETS).catch(() => {});
      }).then(() => self.skipWaiting())
    );
  });

  self.addEventListener('activate', (e) => {
    e.waitUntil(
      caches.keys().then((keys) => {
        return Promise.all(
          keys.map((key) => {
            if (key !== CACHE_NAME) {
              return caches.delete(key);
            }
          })
        );
      }).then(() => self.clients.claim())
    );
  });

  self.addEventListener('fetch', (e) => {
    const url = new URL(e.request.url);
    if (url.search.includes('api=')) {
      return;
    }
    e.respondWith(
      fetch(e.request)
        .then((res) => {
          if (res && res.status === 200) {
            const clone = res.clone();
            caches.open(CACHE_NAME).then((cache) => {
              cache.put(e.request, clone);
            });
          }
          return res;
        })
        .catch(() => {
          return caches.match(e.request).then((cachedRes) => {
            if (cachedRes) return cachedRes;
            if (e.request.mode === 'navigate') {
              return caches.match('./') || caches.match('./index.php');
            }
          });
        })
    );
  });
  <?php
  exit;
}

$manifestData = [
  'short_name' => APP_NAME,
  'name' => APP_NAME . ' System',
  'icons' => [
    [
      'src' => 'data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="%23006874"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/></svg>',
      'type' => 'image/svg+xml',
      'sizes' => '192x192 512x512'
    ]
  ],
  'start_url' => './index.php',
  'background_color' => '#fafdfd',
  'theme_color' => '#006874',
  'display' => 'standalone',
  'orientation' => 'portrait'
];
$manifestBase64 = base64_encode(json_encode($manifestData));

function send_json_response($status, $message, $data = []) {
  header('Content-Type: application/json');
  echo json_encode([
    'success' => $status,
    'message' => $message,
    'data'    => $data
  ]);
  exit;
}

function calculate_distance($lat1, $lon1, $lat2, $lon2) {
  $earthRadius = 6371000;
  $latFrom = deg2rad($lat1);
  $lonFrom = deg2rad($lon1);
  $latTo = deg2rad($lat2);
  $lonTo = deg2rad($lon2);
  $latDelta = $latTo - $latFrom;
  $lonDelta = $lonTo - $lonFrom;
  $a = sin($latDelta / 2) * sin($latDelta / 2) +
       cos($latFrom) * cos($latTo) *
       sin($lonDelta / 2) * sin($lonDelta / 2);
  $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
  return $earthRadius * $c;
}

function get_db_connection() {
  static $pdo = null;
  if ($pdo === null) {
    $dsn = 'sqlite:' . DB_FILE;
    try {
      $pdo = new PDO($dsn);
      $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
      $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
      initialize_tables($pdo);
    } catch (PDOException $e) {
      die("Database connection failed: " . $e->getMessage());
    }
  }
  return $pdo;
}

function initialize_tables($pdo) {
  $queries = [
    "CREATE TABLE IF NOT EXISTS users (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      username TEXT UNIQUE NOT NULL,
      password TEXT NOT NULL,
      role TEXT DEFAULT 'user',
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE TABLE IF NOT EXISTS settings (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      target_lat REAL NOT NULL,
      target_lng REAL NOT NULL,
      radius INTEGER NOT NULL,
      start_time TEXT NOT NULL,
      end_time TEXT NOT NULL
    )",
    "CREATE TABLE IF NOT EXISTS attendance (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      user_id INTEGER NOT NULL,
      date TEXT NOT NULL,
      time TEXT NOT NULL,
      lat REAL NOT NULL,
      lng REAL NOT NULL,
      distance REAL NOT NULL,
      status TEXT NOT NULL,
      location_name TEXT,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (user_id) REFERENCES users(id)
    )"
  ];

  foreach ($queries as $query) {
    $pdo->exec($query);
  }

  try {
    $pdo->exec("ALTER TABLE attendance ADD COLUMN location_name TEXT");
  } catch (PDOException $e) {}

  $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = 'admin'");
  $stmt->execute();
  if ($stmt->fetchColumn() == 0) {
    $hash = password_hash('Admin', PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO users (username, password, role) VALUES ('admin', ?, 'admin')");
    $stmt->execute([$hash]);
  }

  $stmt = $pdo->prepare("SELECT COUNT(*) FROM settings");
  $stmt->execute();
  if ($stmt->fetchColumn() == 0) {
    $stmt = $pdo->prepare("INSERT INTO settings (target_lat, target_lng, radius, start_time, end_time) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([35.70597, 139.7194733, 100, '08:00:00', '09:00:00']);
  }
}

function user_login($username, $password) {
  $db = get_db_connection();
  $stmt = $db->prepare("SELECT id, username, password, role FROM users WHERE username = ?");
  $stmt->execute([$username]);
  $user = $stmt->fetch();

  if ($user && password_verify($password, $user['password'])) {
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'];
    return [
      'id' => $user['id'],
      'username' => $user['username'],
      'role' => $user['role']
    ];
  }
  return false;
}

function user_get($id) {
  $db = get_db_connection();
  $stmt = $db->prepare("SELECT id, username, role FROM users WHERE id = ?");
  $stmt->execute([$id]);
  return $stmt->fetch();
}

function user_register($username, $password) {
  if (empty($username) || empty($password)) {
    throw new Exception("Username and password are required.");
  }
  $db = get_db_connection();
  $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
  $stmt->execute([$username]);
  if ($stmt->fetchColumn() > 0) {
    throw new Exception("Username already exists.");
  }
  $hash = password_hash($password, PASSWORD_DEFAULT);
  $stmt = $db->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
  return $stmt->execute([$username, $hash]);
}

function user_get_all() {
  $db = get_db_connection();
  $stmt = $db->query("SELECT id, username, role, created_at FROM users WHERE role != 'admin' ORDER BY created_at DESC");
  return $stmt->fetchAll();
}

function user_delete($id) {
  if ($id == $_SESSION['user_id']) {
    throw new Exception("Cannot delete your own account.");
  }
  $db = get_db_connection();
  $stmt = $db->prepare("DELETE FROM users WHERE id = ? AND role != 'admin'");
  return $stmt->execute([$id]);
}

function settings_get() {
  $db = get_db_connection();
  $stmt = $db->query("SELECT * FROM settings ORDER BY id DESC LIMIT 1");
  return $stmt->fetch();
}

function settings_update($lat, $lng, $radius, $start_time, $end_time) {
  $db = get_db_connection();
  $stmt = $db->prepare("UPDATE settings SET target_lat = ?, target_lng = ?, radius = ?, start_time = ?, end_time = ? WHERE id = 1");
  return $stmt->execute([$lat, $lng, $radius, $start_time, $end_time]);
}

function attendance_mark($userId, $lat, $lng, $gpsTime) {
  $db = get_db_connection();
  $timestamp = intval($gpsTime / 1000);
  $date = date('Y-m-d', $timestamp);
  $time = date('H:i:s', $timestamp);
  
  $stmt = $db->prepare("SELECT status, location_name FROM attendance WHERE user_id = ? AND date = ?");
  $stmt->execute([$userId, $date]);
  $existing = $stmt->fetch();
  
  if ($existing) {
    return [
      'status' => 'already_marked',
      'message' => 'Attendance already marked for today.',
      'record_status' => $existing['status'],
      'distance' => null,
      'location_name' => $existing['location_name']
    ];
  }

  $settings = settings_get();
  $distance = calculate_distance($lat, $lng, $settings['target_lat'], $settings['target_lng']);

  $startTime = $settings['start_time'];
  if (strlen($startTime) === 5) $startTime .= ':00';
  
  $endTime = $settings['end_time'];
  if (strlen($endTime) === 5) $endTime .= ':00';

  if ($distance <= $settings['radius']) {
    if ($time <= $startTime) {
      $status = 'Present (On Time)';
    } elseif ($time <= $endTime) {
      $status = 'Present (Late)';
    } else {
      $status = 'Absent (Too Late)';
    }
  } else {
    $status = 'Absent (Out of Range)';
  }

  $location_name = 'Unknown Location';
  $url = "https://nominatim.openstreetmap.org/reverse?format=json&lat={$lat}&lon={$lng}";
  $opts = ['http' => ['header' => "User-Agent: AttendingApp/1.0.0\r\n"]];
  $context = stream_context_create($opts);
  $res = @file_get_contents($url, false, $context);
  if ($res) {
    $json = json_decode($res, true);
    if (isset($json['display_name'])) {
      $location_name = $json['display_name'];
    }
  }

  $stmt = $db->prepare("INSERT INTO attendance (user_id, date, time, lat, lng, distance, status, location_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
  $stmt->execute([$userId, $date, $time, $lat, $lng, $distance, $status, $location_name]);

  return [
    'status' => 'success',
    'message' => 'Attendance marked successfully.',
    'record_status' => $status,
    'distance' => round($distance, 2),
    'location_name' => $location_name
  ];
}

function attendance_get_user_history($userId, $offset = 0, $limit = 25) {
  $db = get_db_connection();
  $stmt = $db->prepare("SELECT * FROM attendance WHERE user_id = ? ORDER BY date DESC, time DESC LIMIT ? OFFSET ?");
  $stmt->bindValue(1, $userId, PDO::PARAM_INT);
  $stmt->bindValue(2, (int)$limit, PDO::PARAM_INT);
  $stmt->bindValue(3, (int)$offset, PDO::PARAM_INT);
  $stmt->execute();
  return $stmt->fetchAll();
}

function attendance_get_all_history($offset = 0, $limit = 25) {
  $db = get_db_connection();
  $stmt = $db->prepare("
    SELECT a.*, u.username 
    FROM attendance a 
    JOIN users u ON a.user_id = u.id 
    WHERE u.role != 'admin'
    ORDER BY a.date DESC, a.time DESC
    LIMIT ? OFFSET ?
  ");
  $stmt->bindValue(1, (int)$limit, PDO::PARAM_INT);
  $stmt->bindValue(2, (int)$offset, PDO::PARAM_INT);
  $stmt->execute();
  return $stmt->fetchAll();
}

function attendance_get_today_stats() {
  $db = get_db_connection();
  $date = date('Y-m-d');
  $stmt = $db->prepare("
    SELECT a.status, COUNT(*) as count 
    FROM attendance a 
    JOIN users u ON a.user_id = u.id 
    WHERE a.date = ? AND u.role != 'admin' 
    GROUP BY a.status
  ");
  $stmt->execute([$date]);
  $raw = $stmt->fetchAll();
  $stats = ['present' => 0, 'absent' => 0];
  foreach ($raw as $row) {
    if (strpos($row['status'], 'Present') !== false) {
      $stats['present'] += $row['count'];
    } else {
      $stats['absent'] += $row['count'];
    }
  }
  return $stats;
}

if (isset($_GET['api'])) {
  $action = $_GET['api'];
  
  try {
    switch ($action) {
      case 'login':
        $data = user_login($_POST['username'] ?? '', $_POST['password'] ?? '');
        if ($data) {
          send_json_response(true, 'Login successful', $data);
        } else {
          send_json_response(false, 'Invalid credentials');
        }
        break;

      case 'register':
        try {
          user_register($_POST['username'] ?? '', $_POST['password'] ?? '');
          send_json_response(true, 'Registration successful. Please login.');
        } catch (Exception $e) {
          send_json_response(false, $e->getMessage());
        }
        break;

      case 'logout':
        session_destroy();
        send_json_response(true, 'Logged out');
        break;

      case 'session':
        if (isset($_SESSION['user_id'])) {
          $userData = user_get($_SESSION['user_id']);
          if ($userData) {
            send_json_response(true, 'Authenticated', $userData);
          } else {
            send_json_response(false, 'Not authenticated');
          }
        } else {
          send_json_response(false, 'Not authenticated');
        }
        break;

      case 'update_user_info':
        if (!isset($_SESSION['user_id'])) throw new Exception("Unauthorized");
        $newUsername = $_POST['username'] ?? '';
        $newPassword = $_POST['password'] ?? '';
        $db = get_db_connection();
        if (!empty($newUsername)) {
          $stmt = $db->prepare("UPDATE users SET username = ? WHERE id = ?");
          $stmt->execute([$newUsername, $_SESSION['user_id']]);
          $_SESSION['username'] = $newUsername;
        }
        if (!empty($newPassword)) {
          $hash = password_hash($newPassword, PASSWORD_DEFAULT);
          $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
          $stmt->execute([$hash, $_SESSION['user_id']]);
        }
        send_json_response(true, 'Information updated');
        break;

      case 'mark_attendance':
        if (!isset($_SESSION['user_id'])) throw new Exception("Unauthorized");
        $lat = $_POST['lat'] ?? null;
        $lng = $_POST['lng'] ?? null;
        $gpsTime = $_POST['gps_time'] ?? null;
        if (!$lat || !$lng || !$gpsTime) throw new Exception("Location or GPS time missing");
        $result = attendance_mark($_SESSION['user_id'], $lat, $lng, $gpsTime);
        send_json_response(true, $result['message'], $result);
        break;

      case 'get_history':
        if (!isset($_SESSION['user_id'])) throw new Exception("Unauthorized");
        $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 25;
        $data = attendance_get_user_history($_SESSION['user_id'], $offset, $limit);
        send_json_response(true, 'History fetched', $data);
        break;

      case 'get_settings':
        if (!isset($_SESSION['user_id'])) throw new Exception("Unauthorized");
        $data = settings_get();
        send_json_response(true, 'Settings fetched', $data);
        break;

      case 'admin_get_all_attendance':
        if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') throw new Exception("Unauthorized");
        $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 25;
        $data = attendance_get_all_history($offset, $limit);
        send_json_response(true, 'All attendance fetched', $data);
        break;

      case 'admin_get_stats':
        if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') throw new Exception("Unauthorized");
        $data = attendance_get_today_stats();
        send_json_response(true, 'Stats fetched', $data);
        break;

      case 'admin_update_settings':
        if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') throw new Exception("Unauthorized");
        settings_update(
          $_POST['lat'], $_POST['lng'], $_POST['radius'], $_POST['start_time'], $_POST['end_time']
        );
        send_json_response(true, 'Settings updated');
        break;

      case 'admin_get_users':
        if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') throw new Exception("Unauthorized");
        $data = user_get_all();
        send_json_response(true, 'Users fetched', $data);
        break;

      case 'admin_delete_user':
        if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') throw new Exception("Unauthorized");
        user_delete($_POST['id']);
        send_json_response(true, 'User deleted');
        break;

      default:
        send_json_response(false, 'Invalid API endpoint');
    }
  } catch (Exception $e) {
    send_json_response(false, $e->getMessage());
  }
  exit;
}
?>
<!DOCTYPE html>
<html>
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= APP_NAME ?></title>
    
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%23006874'%3E%3Cpath d='M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z'/%3E%3C/svg%3E">
    <meta property="og:title" content="<?= APP_NAME ?>">
    <meta property="og:description" content="Attendance Application">
    <meta property="og:type" content="website">
    <meta property="og:url" content="/">
    <link rel="manifest" href="data:application/manifest+json;base64,<?= $manifestBase64 ?>">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="<?= APP_NAME ?>">
    <link rel="apple-touch-icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%23006874'%3E%3Cpath d='M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z'/%3E%3C/svg%3E">
    <meta name="theme-color" content="#006874">
    
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin=""/>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0" />

    <style>
      :root {
        --md-sys-color-primary: #006874;
        --md-sys-color-on-primary: #ffffff;
        --md-sys-color-primary-container: #97f0ff;
        --md-sys-color-on-primary-container: #001f24;
        --md-sys-color-secondary: #4a6267;
        --md-sys-color-on-secondary: #ffffff;
        --md-sys-color-secondary-container: #cde7ec;
        --md-sys-color-on-secondary-container: #051f23;
        --md-sys-color-error: #ba1a1a;
        --md-sys-color-on-error: #ffffff;
        --md-sys-color-background: #fafdfd;
        --md-sys-color-on-background: #191c1d;
        --md-sys-color-surface: #fafdfd;
        --md-sys-color-on-surface: #191c1d;
        --md-sys-color-surface-variant: #dbe4e6;
        --md-sys-color-on-surface-variant: #3f484a;
        --md-sys-color-outline: #6f797a;
        --md-sys-elevation-1: 0px 1px 3px 1px rgba(0,0,0,0.15), 0px 1px 2px 0px rgba(0,0,0,0.3);
        --md-sys-elevation-2: 0px 2px 6px 2px rgba(0,0,0,0.15), 0px 1px 2px 0px rgba(0,0,0,0.3);
        --md-sys-elevation-3: 0px 4px 8px 3px rgba(0,0,0,0.15), 0px 1px 3px 0px rgba(0,0,0,0.3);
        --md-sys-shape-corner-medium: 12px;
        --md-sys-shape-corner-large: 16px;
        --md-sys-shape-corner-full: 9999px;
        --md-sys-font-family: 'Roboto', sans-serif;
      }

      @media (prefers-color-scheme: dark) {
        :root {
          --md-sys-color-primary: #4fd8eb;
          --md-sys-color-on-primary: #00363d;
          --md-sys-color-primary-container: #004f58;
          --md-sys-color-on-primary-container: #97f0ff;
          --md-sys-color-secondary: #b1cbd0;
          --md-sys-color-on-secondary: #1c3438;
          --md-sys-color-secondary-container: #334b4f;
          --md-sys-color-on-secondary-container: #cde7ec;
          --md-sys-color-error: #ffb4ab;
          --md-sys-color-on-error: #690005;
          --md-sys-color-background: #191c1d;
          --md-sys-color-on-background: #e1e3e3;
          --md-sys-color-surface: #191c1d;
          --md-sys-color-on-surface: #e1e3e3;
          --md-sys-color-surface-variant: #3f484a;
          --md-sys-color-on-surface-variant: #bfc8ca;
          --md-sys-color-outline: #899294;
        }
      }

      * {
        box-sizing: border-box;
        margin: 0;
        padding: 0;
      }

      html, body {
        height: 100%;
        width: 100%;
        font-family: var(--md-sys-font-family);
        background-color: var(--md-sys-color-background);
        color: var(--md-sys-color-on-background);
        overflow: hidden;
        font-size: 16px;
      }

      #app-container {
        display: flex;
        flex-direction: column;
        height: 100%;
        width: 100%;
      }

      .view {
        display: none;
        flex: 1;
        overflow-y: auto;
        overflow-x: hidden;
        padding-bottom: 80px;
        animation: fadeIn 0.3s ease;
      }

      .view.active {
        display: block;
      }

      @keyframes fadeIn {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
      }

      .md-top-app-bar {
        height: 64px;
        display: flex;
        align-items: center;
        padding: 0 16px;
        background-color: var(--md-sys-color-surface);
        color: var(--md-sys-color-on-surface);
        z-index: 10;
        position: sticky;
        top: 0;
        box-shadow: var(--md-sys-elevation-1);
      }

      .md-top-app-bar .title {
        font-size: 22px;
        font-weight: 500;
        flex: 1;
      }

      .md-bottom-nav {
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        height: 80px;
        background-color: var(--md-sys-color-surface);
        display: flex;
        justify-content: space-around;
        align-items: center;
        z-index: 100;
        box-shadow: 0px -1px 3px rgba(0,0,0,0.1);
      }

      .md-bottom-nav-item {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        text-decoration: none;
        color: var(--md-sys-color-on-surface-variant);
        min-width: 64px;
        height: 100%;
        cursor: pointer;
        transition: color 0.2s;
      }

      .md-bottom-nav-item .icon-container {
        width: 64px;
        height: 32px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 16px;
        transition: background-color 0.2s;
        margin-bottom: 4px;
      }

      .md-bottom-nav-item.active {
        color: var(--md-sys-color-on-surface);
      }

      .md-bottom-nav-item.active .icon-container {
        background-color: var(--md-sys-color-secondary-container);
        color: var(--md-sys-color-on-secondary-container);
      }

      .md-bottom-nav-item .label {
        font-size: 12px;
        font-weight: 500;
      }

      .md-card {
        background-color: var(--md-sys-color-surface-variant);
        color: var(--md-sys-color-on-surface-variant);
        border-radius: var(--md-sys-shape-corner-medium);
        padding: 16px;
        margin: 16px;
        box-shadow: var(--md-sys-elevation-1);
        position: relative;
        overflow: hidden;
      }
      
      .md-card-elevated {
        background-color: var(--md-sys-color-surface);
        box-shadow: var(--md-sys-elevation-2);
      }

      .md-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        height: 40px;
        padding: 0 24px;
        border-radius: var(--md-sys-shape-corner-full);
        font-size: 14px;
        font-weight: 500;
        border: none;
        cursor: pointer;
        outline: none;
        transition: background-color 0.2s, box-shadow 0.2s;
        font-family: var(--md-sys-font-family);
      }

      .md-btn-filled {
        background-color: var(--md-sys-color-primary);
        color: var(--md-sys-color-on-primary);
      }
      
      .md-btn-filled:hover {
        box-shadow: var(--md-sys-elevation-1);
      }

      .md-btn-text {
        background-color: transparent;
        color: var(--md-sys-color-primary);
        padding: 0 12px;
      }

      .md-text-field {
        position: relative;
        margin-bottom: 24px;
        width: 100%;
      }

      .md-text-field input {
        width: 100%;
        height: 56px;
        padding: 16px 16px 0 16px;
        border: 1px solid var(--md-sys-color-outline);
        border-radius: 4px;
        background-color: transparent;
        color: var(--md-sys-color-on-background);
        font-size: 16px;
        outline: none;
        transition: border-color 0.2s;
      }
      
      .md-text-field input:focus {
        border-color: var(--md-sys-color-primary);
        border-width: 2px;
      }

      .md-text-field label {
        position: absolute;
        left: 16px;
        top: 18px;
        color: var(--md-sys-color-on-surface-variant);
        font-size: 16px;
        pointer-events: none;
        transition: 0.2s ease all;
        background: var(--md-sys-color-background);
        padding: 0 4px;
      }

      .md-text-field input:focus ~ label,
      .md-text-field input:valid ~ label,
      .md-text-field input.has-value ~ label {
        top: -10px;
        font-size: 12px;
        color: var(--md-sys-color-primary);
      }

      .md-list {
        list-style: none;
        padding: 8px 0;
      }

      .md-list-item {
        display: flex;
        align-items: center;
        padding: 12px 16px;
        cursor: pointer;
        position: relative;
        overflow: hidden;
        border-bottom: 1px solid var(--md-sys-color-surface-variant);
      }

      .md-list-item-content {
        flex: 1;
        display: flex;
        flex-direction: column;
      }

      .md-list-item-title {
        font-size: 16px;
        color: var(--md-sys-color-on-background);
      }

      .md-list-item-subtitle {
        font-size: 14px;
        color: var(--md-sys-color-on-surface-variant);
        margin-top: 4px;
      }

      #md-snackbar {
        position: fixed;
        bottom: -60px;
        left: 16px;
        right: 16px;
        background-color: #313033;
        color: #F4EFF4;
        padding: 14px 16px;
        border-radius: 4px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        box-shadow: var(--md-sys-elevation-2);
        transition: bottom 0.3s cubic-bezier(0.2, 0, 0, 1);
        z-index: 9999;
        font-size: 14px;
      }

      #md-snackbar.show {
        bottom: 96px;
      }

      .w-100 { width: 100%; }
      .mt-2 { margin-top: 16px; }
      .mb-2 { margin-bottom: 16px; }
      .text-center { text-align: center; }
      .flex-row { display: flex; align-items: center; }
      .flex-between { display: flex; justify-content: space-between; align-items: center; }
      .font-bold { font-weight: bold; }
      
      .status-present { color: #146c2e; font-weight: bold; }
      .status-absent { color: var(--md-sys-color-error); font-weight: bold; }
      @media (prefers-color-scheme: dark) {
        .status-present { color: #81c995; }
      }

      #map-container {
        width: 100%;
        height: 250px;
        border-radius: var(--md-sys-shape-corner-medium);
        margin-bottom: 16px;
        z-index: 1;
      }

      .auth-container {
        display: flex;
        flex-direction: column;
        justify-content: center;
        height: 100%;
        padding: 24px;
      }
      
      .auth-title {
        font-size: 32px;
        font-weight: 700;
        margin-bottom: 8px;
        color: var(--md-sys-color-primary);
      }
      
      .auth-subtitle {
        margin-bottom: 32px;
        color: var(--md-sys-color-on-surface-variant);
      }
      
      .stats-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 8px;
        margin-bottom: 16px;
      }
      
      .stat-card {
        background: var(--md-sys-color-surface-variant);
        border-radius: 12px;
        padding: 12px;
        text-align: center;
      }
      
      .stat-value {
        font-size: 24px;
        font-weight: bold;
        color: var(--md-sys-color-primary);
      }
      .stat-label {
        font-size: 12px;
        color: var(--md-sys-color-on-surface-variant);
      }

      #loader {
        position: fixed;
        top: 0; left: 0; right: 0; bottom: 0;
        background: var(--md-sys-color-background);
        z-index: 99999;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
      }
      .spinner {
        width: 48px;
        height: 48px;
        border: 5px solid var(--md-sys-color-surface-variant);
        border-bottom-color: var(--md-sys-color-primary);
        border-radius: 50%;
        animation: rotation 1s linear infinite;
      }
      @keyframes rotation {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
      }

      .avatar-container {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        overflow: hidden;
        margin: 0 16px;
        background: var(--md-sys-color-surface-variant);
        flex-shrink: 0;
      }
      .avatar-container img {
        width: 100%;
        height: 100%;
        object-fit: cover;
      }
    </style>
  </head>
  <body>
    <div id="loader">
      <div class="spinner"></div>
      <div style="margin-top: 16px; font-weight: 500;">PHPAttend...</div>
      <button onclick="if(window.caches){caches.keys().then(ks=>ks.forEach(k=>caches.delete(k)))};localStorage.clear();sessionStorage.clear();window.location.reload(true);" class="md-btn md-btn-text" style="margin-top: 24px;">Clear Cache</button>
    </div>

    <div id="app-container">

      <div id="view-login" class="view">
        <div class="auth-container">
          <div class="auth-title">Welcome</div>
          <div class="auth-subtitle">Sign in to mark your attendance.</div>
          
          <form id="form-login" onsubmit="app.login(event)">
            <div class="md-text-field">
              <input type="text" id="login-username" required autocomplete="username">
              <label>Username</label>
            </div>
            
            <div class="md-text-field">
              <input type="password" id="login-password" required autocomplete="current-password">
              <label>Password</label>
            </div>
            
            <button type="submit" class="md-btn md-btn-filled w-100">Login</button>
            
            <div class="text-center mt-2">
              <button type="button" class="md-btn md-btn-text" onclick="app.navigate('register')">Create an account</button>
            </div>
          </form>
        </div>
      </div>

      <div id="view-register" class="view">
        <div class="auth-container">
          <div class="auth-title">Register</div>
          <div class="auth-subtitle">Create a new account.</div>
          
          <form id="form-register" onsubmit="app.register(event)">
            <div class="md-text-field">
              <input type="text" id="reg-username" required autocomplete="username">
              <label>Username</label>
            </div>
            
            <div class="md-text-field">
              <input type="password" id="reg-password" required autocomplete="new-password">
              <label>Password</label>
            </div>
            
            <button type="submit" class="md-btn md-btn-filled w-100">Register</button>
            
            <div class="text-center mt-2">
              <button type="button" class="md-btn md-btn-text" onclick="app.navigate('login')">Back to Login</button>
            </div>
          </form>
        </div>
      </div>

      <div id="user-top-bar" class="md-top-app-bar" style="display:none;">
        <div class="title" id="top-bar-title">PHPAttend</div>
        <div id="user-avatar-container" class="avatar-container">
          <img id="user-avatar" src="" alt="Avatar">
        </div>
        <button class="md-btn md-btn-text" onclick="app.logout()" style="min-width:auto; padding: 8px;">
          <span class="material-symbols-outlined">logout</span>
        </button>
      </div>

      <div id="view-dashboard" class="view">
        <div class="md-card md-card-elevated">
          <h3 style="margin-bottom: 8px;">Your Location</h3>
          <div id="map-container"></div>
          <div id="location-status" style="font-size: 14px; color: var(--md-sys-color-on-surface-variant);">
            Locating you...
          </div>
        </div>

        <div class="md-card">
          <h3 style="margin-bottom: 8px;">Today's Attendance</h3>
          <div id="attendance-status-card">
            Fetching status...
          </div>
        </div>
      </div>

      <div id="view-history" class="view">
        <div class="md-card">
          <h3 style="margin-bottom: 16px;">Attendance History</h3>
          <ul class="md-list" id="history-list">
          </ul>
        </div>
      </div>

      <div id="view-user-settings" class="view">
        <div class="md-card">
          <h3 style="margin-bottom: 16px;">Update Information</h3>
          <form onsubmit="app.saveUserSettings(event)">
            <div class="md-text-field">
              <input type="text" id="edit-username">
              <label>New Username</label>
            </div>
            <div class="md-text-field">
              <input type="password" id="edit-password">
              <label>New Password (leave blank to keep)</label>
            </div>
            <button type="submit" class="md-btn md-btn-filled w-100">Save Information</button>
          </form>
        </div>
      </div>

      <div id="user-bottom-nav" class="md-bottom-nav" style="display:none;">
        <a class="md-bottom-nav-item active" onclick="app.navigate('dashboard')">
          <div class="icon-container"><span class="material-symbols-outlined">home</span></div>
          <span class="label">Home</span>
        </a>
        <a class="md-bottom-nav-item" onclick="app.navigate('history')">
          <div class="icon-container"><span class="material-symbols-outlined">history</span></div>
          <span class="label">History</span>
        </a>
        <a class="md-bottom-nav-item" onclick="app.navigate('user-settings')">
          <div class="icon-container"><span class="material-symbols-outlined">settings</span></div>
          <span class="label">Settings</span>
        </a>
      </div>

      <div id="admin-top-bar" class="md-top-app-bar" style="display:none;">
        <div class="title" id="admin-top-bar-title">Admin Panel</div>
        <div id="admin-avatar-container" class="avatar-container">
          <img id="admin-avatar" src="" alt="Avatar">
        </div>
        <button class="md-btn md-btn-text" onclick="app.logout()" style="min-width:auto; padding: 8px;">
          <span class="material-symbols-outlined">logout</span>
        </button>
      </div>

      <div id="view-admin-dashboard" class="view">
        <div class="md-card md-card-elevated">
          <h3 style="margin-bottom: 16px;">Today's Statistics</h3>
          <div class="stats-grid">
            <div class="stat-card">
              <div class="stat-value" id="stat-present">0</div>
              <div class="stat-label">On Time</div>
            </div>
            <div class="stat-card">
              <div class="stat-value" id="stat-absent" style="color: var(--md-sys-color-error);">0</div>
              <div class="stat-label">Absent</div>
            </div>
          </div>
        </div>

        <div class="md-card">
          <h3 style="margin-bottom: 16px;">All Attendance Logs</h3>
          <ul class="md-list" id="admin-history-list">
          </ul>
        </div>
      </div>

      <div id="view-admin-settings" class="view">
        <div class="md-card">
          <h3 style="margin-bottom: 16px;">System Configuration</h3>
          <form id="form-settings" onsubmit="app.saveSettings(event)">
            <div class="md-text-field">
              <input type="text" id="set-lat" required>
              <label>Target Latitude</label>
            </div>
            <div class="md-text-field">
              <input type="text" id="set-lng" required>
              <label>Target Longitude</label>
            </div>
            <div class="md-text-field">
              <input type="number" id="set-radius" required>
              <label>Allowed Radius (Meters)</label>
            </div>
            <div class="flex-row" style="gap: 16px;">
              <div class="md-text-field">
                <input type="time" id="set-start" required>
                <label style="top: -10px; font-size: 12px; color: var(--md-sys-color-primary);">Work Start Time</label>
              </div>
              <div class="md-text-field">
                <input type="time" id="set-end" required>
                <label style="top: -10px; font-size: 12px; color: var(--md-sys-color-primary);">Late Threshold Time</label>
              </div>
            </div>
            <button type="submit" class="md-btn md-btn-filled w-100">Save Settings</button>
          </form>
        </div>
        
        <div class="md-card">
          <h3 style="margin-bottom: 16px;">User Management</h3>
          <ul class="md-list" id="admin-user-list">
          </ul>
        </div>
      </div>

      <div id="admin-bottom-nav" class="md-bottom-nav" style="display:none;">
        <a class="md-bottom-nav-item active" onclick="app.navigate('admin-dashboard')">
          <div class="icon-container"><span class="material-symbols-outlined">dashboard</span></div>
          <span class="label">Overview</span>
        </a>
        <a class="md-bottom-nav-item" onclick="app.navigate('admin-settings')">
          <div class="icon-container"><span class="material-symbols-outlined">settings</span></div>
          <span class="label">Settings</span>
        </a>
      </div>

    </div>

    <div id="md-snackbar">
      <span id="snackbar-msg">Message here</span>
    </div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
    <script>
      const app = {
        state: {
          user: null,
          currentRoute: 'login',
          map: null,
          mapMarker: null,
          targetCircle: null,
          userLat: null,
          userLng: null,
          gpsTime: null,
          settings: null,
          attendanceChecked: false,
          userHistoryOffset: 0,
          userHistoryLoading: false,
          userHistoryHasMore: true,
          adminHistoryOffset: 0,
          adminHistoryLoading: false,
          adminHistoryHasMore: true
        },

        init: async function() {
          this.setupInputs();
          await this.checkSession();
          
          document.getElementById('view-admin-dashboard').addEventListener('scroll', (e) => {
            const el = e.target;
            if (el.scrollHeight - el.scrollTop <= el.clientHeight + 50) {
              this.loadAdminHistoryMore();
            }
          });

          document.getElementById('view-history').addEventListener('scroll', (e) => {
            const el = e.target;
            if (el.scrollHeight - el.scrollTop <= el.clientHeight + 50) {
              this.loadUserHistoryMore();
            }
          });

          setTimeout(() => {
            document.getElementById('loader').style.display = 'none';
          }, 500);
        },

        navigate: function(route) {
          this.state.currentRoute = route;
          
          let path = window.location.pathname;
          let isAccessAdmin = new URLSearchParams(window.location.search).get('access') === 'admin';
          
          if (route.startsWith('admin-') || (isAccessAdmin && (route === 'login' || route === 'register'))) {
            window.history.replaceState(null, '', path + '?access=admin');
            isAccessAdmin = true;
          } else {
            window.history.replaceState(null, '', path);
            isAccessAdmin = false;
          }
          
          document.querySelectorAll('.view').forEach(v => v.classList.remove('active'));
          document.getElementById('user-top-bar').style.display = 'none';
          document.getElementById('user-bottom-nav').style.display = 'none';
          document.getElementById('admin-top-bar').style.display = 'none';
          document.getElementById('admin-bottom-nav').style.display = 'none';
          
          switch(route) {
            case 'login':
            case 'register':
              if (this.state.user) {
                this.navigate(this.state.user.role === 'admin' ? 'admin-dashboard' : 'dashboard');
                return;
              }
              const titleEl = document.querySelector('#view-' + route + ' .auth-title');
              if (titleEl) {
                if (isAccessAdmin && route === 'login') {
                  titleEl.innerText = 'Admin Login';
                } else if (route === 'login') {
                  titleEl.innerText = 'Welcome';
                }
              }
              document.getElementById(`view-${route}`).classList.add('active');
              break;
              
            case 'dashboard':
              if (!this.requireAuth('user')) return;
              document.getElementById('view-dashboard').classList.add('active');
              document.getElementById('user-top-bar').style.display = 'flex';
              document.getElementById('user-bottom-nav').style.display = 'flex';
              document.getElementById('top-bar-title').innerText = 'PHPAttend';
              this.updateNavHighlight('user-bottom-nav', 0);
              this.initMap();
              this.fetchSettings().then(() => this.requestLocation());
              break;
              
            case 'history':
              if (!this.requireAuth('user')) return;
              document.getElementById('view-history').classList.add('active');
              document.getElementById('user-top-bar').style.display = 'flex';
              document.getElementById('user-bottom-nav').style.display = 'flex';
              document.getElementById('top-bar-title').innerText = 'PHPAttend';
              this.updateNavHighlight('user-bottom-nav', 1);
              this.loadUserHistory();
              break;

            case 'user-settings':
              if (!this.requireAuth('user')) return;
              document.getElementById('view-user-settings').classList.add('active');
              document.getElementById('user-top-bar').style.display = 'flex';
              document.getElementById('user-bottom-nav').style.display = 'flex';
              document.getElementById('top-bar-title').innerText = 'Settings';
              if (this.state.user) {
                document.getElementById('edit-username').value = this.state.user.username;
                document.getElementById('edit-username').classList.add('has-value');
              }
              this.updateNavHighlight('user-bottom-nav', 2);
              break;
              
            case 'admin-dashboard':
              if (!this.requireAuth('admin')) return;
              document.getElementById('view-admin-dashboard').classList.add('active');
              document.getElementById('admin-top-bar').style.display = 'flex';
              document.getElementById('admin-bottom-nav').style.display = 'flex';
              document.getElementById('admin-top-bar-title').innerText = 'Admin Overview';
              this.updateNavHighlight('admin-bottom-nav', 0);
              this.loadAdminDashboard();
              break;
              
            case 'admin-settings':
              if (!this.requireAuth('admin')) return;
              document.getElementById('view-admin-settings').classList.add('active');
              document.getElementById('admin-top-bar').style.display = 'flex';
              document.getElementById('admin-bottom-nav').style.display = 'flex';
              document.getElementById('admin-top-bar-title').innerText = 'System Config';
              this.updateNavHighlight('admin-bottom-nav', 1);
              this.loadAdminSettings();
              break;
          }
        },

        requireAuth: function(role) {
          if (!this.state.user) {
            this.navigate('login');
            return false;
          }
          if (role === 'admin' && this.state.user.role !== 'admin') {
            this.navigate('dashboard');
            return false;
          }
          if (role === 'user' && this.state.user.role === 'admin') {
            this.navigate('admin-dashboard');
            return false;
          }
          return true;
        },

        updateNavHighlight: function(navId, index) {
          const nav = document.getElementById(navId);
          const items = nav.querySelectorAll('.md-bottom-nav-item');
          items.forEach((item, i) => {
            if (i === index) item.classList.add('active');
            else item.classList.remove('active');
          });
        },

        apiCall: async function(endpoint, method = 'GET', data = null) {
          try {
            let options = { method: method };
            if (data && method !== 'GET') {
              const formData = new FormData();
              for (const key in data) {
                formData.append(key, data[key]);
              }
              options.body = formData;
            }
            const res = await fetch(`?api=${endpoint}`, options);
            return await res.json();
          } catch (err) {
            this.showSnackbar("Network error occurred.");
            return { success: false, message: "Network error" };
          }
        },

        checkSession: async function() {
          const res = await this.apiCall('session');
          if (res.success) {
            this.state.user = res.data;
            this.updateAvatars();
            this.navigate(this.state.user.role === 'admin' ? 'admin-dashboard' : 'dashboard');
          } else {
            this.navigate('login');
          }
        },

        login: async function(e) {
          e.preventDefault();
          const btn = e.target.querySelector('button');
          btn.innerText = 'Loading...';
          
          const res = await this.apiCall('login', 'POST', {
            username: document.getElementById('login-username').value,
            password: document.getElementById('login-password').value
          });
          
          btn.innerText = 'Login';
          
          if (res.success) {
            this.state.user = res.data;
            this.state.attendanceChecked = false;
            this.updateAvatars();
            this.showSnackbar(res.message);
            this.navigate(this.state.user.role === 'admin' ? 'admin-dashboard' : 'dashboard');
            e.target.reset();
          } else {
            this.showSnackbar(res.message);
          }
        },

        register: async function(e) {
          e.preventDefault();
          const res = await this.apiCall('register', 'POST', {
            username: document.getElementById('reg-username').value,
            password: document.getElementById('reg-password').value
          });
          this.showSnackbar(res.message);
          if (res.success) {
            this.navigate('login');
            e.target.reset();
          }
        },

        logout: async function() {
          await this.apiCall('logout');
          this.state.user = null;
          this.state.attendanceChecked = false;
          this.showSnackbar("Logged out successfully");
          this.navigate('login');
        },

        saveUserSettings: async function(e) {
          e.preventDefault();
          const uInput = document.getElementById('edit-username').value;
          const pInput = document.getElementById('edit-password').value;
          const res = await this.apiCall('update_user_info', 'POST', {
            username: uInput,
            password: pInput
          });
          this.showSnackbar(res.message);
          if (res.success && uInput) {
            this.state.user.username = uInput;
            this.updateAvatars();
          }
          document.getElementById('edit-password').value = '';
          document.getElementById('edit-password').classList.remove('has-value');
        },

        generateAvatar: function(name) {
          const canvas = document.createElement('canvas');
          canvas.width = 100;
          canvas.height = 100;
          const ctx = canvas.getContext('2d');
          const colors = ['#F44336', '#E91E63', '#9C27B0', '#673AB7', '#3F51B5', '#2196F3', '#03A9F4', '#00BCD4', '#009688', '#4CAF50', '#8BC34A', '#CDDC39', '#FFEB3B', '#FFC107', '#FF9800', '#FF5722'];
          const charCode = name.charCodeAt(0) || 32;
          const bgColor = colors[charCode % colors.length];

          ctx.fillStyle = bgColor;
          ctx.fillRect(0, 0, 100, 100);
          ctx.font = 'bold 50px Arial';
          ctx.fillStyle = '#FFFFFF';
          ctx.textAlign = 'center';
          ctx.textBaseline = 'middle';
          ctx.fillText(name.charAt(0).toUpperCase(), 50, 50);

          return canvas.toDataURL('image/png');
        },

        updateAvatars: function() {
          if (!this.state.user) return;
          const src = this.generateAvatar(this.state.user.username);
          const uAvatar = document.getElementById('user-avatar');
          const aAvatar = document.getElementById('admin-avatar');
          if (uAvatar) uAvatar.src = src;
          if (aAvatar) aAvatar.src = src;
        },

        initMap: function() {
          if (this.state.map) {
            this.state.map.invalidateSize();
            return;
          }
          this.state.map = L.map('map-container').setView([35.70597, 139.7194733], 13);
          L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '© OpenStreetMap'
          }).addTo(this.state.map);
        },

        fetchSettings: async function() {
          const res = await this.apiCall('get_settings');
          if (res.success) {
            this.state.settings = res.data;
            this.drawTargetOnMap();
          }
        },

        drawTargetOnMap: function() {
          if (!this.state.map || !this.state.settings) return;
          const lat = this.state.settings.target_lat;
          const lng = this.state.settings.target_lng;
          const radius = this.state.settings.radius;

          if (this.state.targetCircle) {
            this.state.targetCircle.setLatLng([lat, lng]);
            this.state.targetCircle.setRadius(radius);
          } else {
            this.state.targetCircle = L.circle([lat, lng], {
              color: 'var(--md-sys-color-primary)',
              fillColor: 'var(--md-sys-color-primary-container)',
              fillOpacity: 0.3,
              radius: radius
            }).addTo(this.state.map);
          }
        },

        requestLocation: function() {
          const statusDiv = document.getElementById('location-status');
          statusDiv.innerHTML = "Locating you... Please allow location access.";
          
          const handlePosition = (lat, lng, timestamp) => {
            this.state.userLat = lat;
            this.state.userLng = lng;
            this.state.gpsTime = timestamp;
            statusDiv.innerHTML = `Location acquired: Lat ${lat.toFixed(5)}, Lng ${lng.toFixed(5)}`;
            const userLatLng = [lat, lng];
            this.state.map.setView(userLatLng, 16);
            if (this.state.mapMarker) {
              this.state.mapMarker.setLatLng(userLatLng);
            } else {
              this.state.mapMarker = L.marker(userLatLng).addTo(this.state.map);
            }
            if (!this.state.attendanceChecked) {
              this.markAttendance();
            }
          };

          if (!navigator.geolocation) {
            statusDiv.innerHTML = "GPS not supported by your browser.";
            this.showSnackbar("GPS not supported.");
            return;
          }

          navigator.geolocation.getCurrentPosition(
            (position) => handlePosition(position.coords.latitude, position.coords.longitude, position.timestamp),
            (error) => {
              let errMsg = "GPS location failed.";
              if (!window.isSecureContext) errMsg = "HTTPS is required for GPS.";
              else if (error.code === 1) errMsg = "Location permission denied.";
              else if (error.code === 2) errMsg = "GPS signal unavailable.";
              else if (error.code === 3) errMsg = "GPS request timed out.";
              
              statusDiv.innerHTML = errMsg;
              this.showSnackbar(errMsg);
            },
            { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 }
          );
        },

        markAttendance: async function() {
          if (!this.state.userLat || !this.state.userLng || !this.state.gpsTime) {
            this.showSnackbar("Location not available yet.");
            return;
          }
          
          const card = document.getElementById('attendance-status-card');
          card.innerHTML = `<div class="text-center"><div class="spinner" style="width:24px;height:24px;border-width:3px;"></div><div class="mt-2">Verifying location & time...</div></div>`;
          
          const res = await this.apiCall('mark_attendance', 'POST', {
            lat: this.state.userLat,
            lng: this.state.userLng,
            gps_time: this.state.gpsTime
          });
          
          this.state.attendanceChecked = true;
          
          if (res.success || res.data?.status === 'already_marked') {
            if (res.message) this.showSnackbar(res.message);
            this.renderAttendanceStatus(res.data.record_status, res.data.distance, res.data.location_name);
          } else {
            card.innerHTML = `<div class="status-absent">Error: ${res.message}</div>`;
          }
        },

        renderAttendanceStatus: function(status, distance = null, location_name = '') {
          const card = document.getElementById('attendance-status-card');
          let colorClass = 'status-absent';
          let icon = 'cancel';
          
          if (status.includes('Present')) {
            colorClass = 'status-present';
            icon = 'check_circle';
          }
          
          let distText = distance !== null ? `<div class="mt-2" style="font-size:12px; color:var(--md-sys-color-on-surface-variant);">Distance from target: ${distance} meters</div>` : '';
          let locText = location_name ? `<div class="mt-1" style="font-size:12px; color:var(--md-sys-color-on-surface-variant);">${location_name}</div>` : '';
          
          card.innerHTML = `
            <div class="flex-row" style="gap: 12px; font-size: 18px;">
              <span class="material-symbols-outlined ${colorClass}" style="font-size: 32px;">${icon}</span>
              <div class="${colorClass}">${status}</div>
            </div>
            ${distText}
            ${locText}
          `;
        },

        loadUserHistory: function() {
          this.state.userHistoryOffset = 0;
          this.state.userHistoryHasMore = true;
          const list = document.getElementById('history-list');
          list.innerHTML = '';
          this.loadUserHistoryMore();
        },

        loadUserHistoryMore: async function() {
          if (this.state.userHistoryLoading || !this.state.userHistoryHasMore) return;
          this.state.userHistoryLoading = true;
          
          const list = document.getElementById('history-list');
          const loaderId = 'user-history-loader';
          
          const loader = document.createElement('div');
          loader.id = loaderId;
          loader.className = 'text-center mt-2';
          loader.innerText = 'Loading...';
          list.appendChild(loader);

          const res = await this.apiCall(`get_history&offset=${this.state.userHistoryOffset}&limit=25`);
          
          document.getElementById(loaderId)?.remove();
          this.state.userHistoryLoading = false;

          if (res.success) {
            if (res.data.length < 25) this.state.userHistoryHasMore = false;
            this.state.userHistoryOffset += res.data.length;
            
            if (res.data.length === 0 && this.state.userHistoryOffset === 0) {
              list.innerHTML = '<div class="text-center mt-2" style="color:var(--md-sys-color-outline);">No records found.</div>';
              return;
            }
            
            res.data.forEach(record => {
              let iconClass = record.status.includes('Present') ? 'status-present' : 'status-absent';
              let icon = record.status.includes('Present') ? 'check_circle' : 'cancel';
              let loc = record.location_name ? `<br><small>${record.location_name}</small>` : '';
              
              list.innerHTML += `
                <li class="md-list-item">
                  <span class="material-symbols-outlined ${iconClass}" style="margin-right: 16px;">${icon}</span>
                  <div class="md-list-item-content">
                    <div class="md-list-item-title">${record.date} at ${record.time}</div>
                    <div class="md-list-item-subtitle ${iconClass}">${record.status}${loc}</div>
                  </div>
                </li>
              `;
            });
          }
        },

        loadAdminDashboard: async function() {
          const statsRes = await this.apiCall('admin_get_stats');
          if (statsRes.success) {
            document.getElementById('stat-present').innerText = statsRes.data.present;
            document.getElementById('stat-absent').innerText = statsRes.data.absent;
          }
          this.state.adminHistoryOffset = 0;
          this.state.adminHistoryHasMore = true;
          const list = document.getElementById('admin-history-list');
          list.innerHTML = '';
          this.loadAdminHistoryMore();
        },

        loadAdminHistoryMore: async function() {
          if (this.state.adminHistoryLoading || !this.state.adminHistoryHasMore) return;
          this.state.adminHistoryLoading = true;
          
          const list = document.getElementById('admin-history-list');
          const loaderId = 'admin-history-loader';
          
          const loader = document.createElement('div');
          loader.id = loaderId;
          loader.className = 'text-center mt-2';
          loader.innerText = 'Loading...';
          list.appendChild(loader);

          const res = await this.apiCall(`admin_get_all_attendance&offset=${this.state.adminHistoryOffset}&limit=25`);
          
          document.getElementById(loaderId)?.remove();
          this.state.adminHistoryLoading = false;

          if (res.success) {
            if (res.data.length < 25) this.state.adminHistoryHasMore = false;
            this.state.adminHistoryOffset += res.data.length;
            
            if (res.data.length === 0 && this.state.adminHistoryOffset === 0) {
              list.innerHTML = '<div class="text-center mt-2" style="color:var(--md-sys-color-outline);">No records found.</div>';
              return;
            }
            
            res.data.forEach(record => {
              let iconClass = record.status.includes('Present') ? 'status-present' : 'status-absent';
              let loc = record.location_name ? `<br><small>${record.location_name}</small>` : '';
              list.innerHTML += `
                <li class="md-list-item">
                  <div class="md-list-item-content">
                    <div class="md-list-item-title font-bold">${record.username}</div>
                    <div class="md-list-item-subtitle">${record.date} ${record.time}${loc}</div>
                  </div>
                  <div class="${iconClass}" style="font-size: 14px;">${record.status}</div>
                </li>
              `;
            });
          }
        },

        loadAdminSettings: async function() {
          const setRes = await this.apiCall('get_settings');
          if (setRes.success) {
            document.getElementById('set-lat').value = setRes.data.target_lat;
            document.getElementById('set-lng').value = setRes.data.target_lng;
            document.getElementById('set-radius').value = setRes.data.radius;
            document.getElementById('set-start').value = setRes.data.start_time;
            document.getElementById('set-end').value = setRes.data.end_time;
            document.querySelectorAll('#form-settings input').forEach(input => {
              if(input.value) input.classList.add('has-value');
            });
          }

          const list = document.getElementById('admin-user-list');
          list.innerHTML = '<div class="text-center mt-2">Loading...</div>';
          const usersRes = await this.apiCall('admin_get_users');
          if (usersRes.success) {
            list.innerHTML = '';
            usersRes.data.forEach(u => {
              if (u.role === 'admin') return;
              const pfp = this.generateAvatar(u.username);
              list.innerHTML += `
                <li class="md-list-item">
                  <div class="avatar-container" style="margin-right:16px;">
                    <img src="${pfp}">
                  </div>
                  <div class="md-list-item-content">
                    <div class="md-list-item-title">${u.username}</div>
                    <div class="md-list-item-subtitle">Joined: ${u.created_at}</div>
                  </div>
                  <button class="md-btn md-btn-text" style="color:var(--md-sys-color-error);" onclick="app.deleteUser(${u.id})">
                    <span class="material-symbols-outlined">delete</span>
                  </button>
                </li>
              `;
            });
          }
        },

        saveSettings: async function(e) {
          e.preventDefault();
          const res = await this.apiCall('admin_update_settings', 'POST', {
            lat: document.getElementById('set-lat').value,
            lng: document.getElementById('set-lng').value,
            radius: document.getElementById('set-radius').value,
            start_time: document.getElementById('set-start').value,
            end_time: document.getElementById('set-end').value
          });
          this.showSnackbar(res.message);
        },

        deleteUser: async function(id) {
          if(confirm("Are you sure you want to delete this user?")) {
            const res = await this.apiCall('admin_delete_user', 'POST', { id: id });
            this.showSnackbar(res.message);
            this.loadAdminSettings();
          }
        },

        showSnackbar: function(msg) {
          const sb = document.getElementById('md-snackbar');
          document.getElementById('snackbar-msg').innerText = msg;
          sb.classList.add('show');
          setTimeout(() => sb.classList.remove('show'), 3000);
        },

        setupInputs: function() {
          const inputs = document.querySelectorAll('.md-text-field input');
          inputs.forEach(input => {
            input.addEventListener('input', () => {
              if (input.value.trim() !== '') {
                input.classList.add('has-value');
              } else {
                input.classList.remove('has-value');
              }
            });
          });
        }
      };

      window.addEventListener('DOMContentLoaded', () => {
        app.init();
        if ('serviceWorker' in navigator) {
          navigator.serviceWorker.register('index.php?sw=1', { scope: './' })
            .catch(err => console.error('Service Worker registration failed:', err));
        }
      });
    </script>
  </body>
</html>