<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/security_headers.php';
require_once __DIR__ . '/includes/theme_head.php';
include 'auth.php';
include 'db.php';
require_once __DIR__ . '/helpers/session_utils.php';

$roles = load_session_roles();
if (!in_array('Admin', $roles, true)) {
    http_response_code(403);
    die('Access denied. Admins only.');
}

$adminAlertCount = get_admin_alert_count($db);

// Filters
$filterEvent = trim((string)($_GET['event'] ?? ''));
$filterIp    = trim((string)($_GET['ip'] ?? ''));
$filterEmail = trim((string)($_GET['email'] ?? ''));
$page        = max(1, (int)($_GET['page'] ?? 1));
$perPage     = 50;
$offset      = ($page - 1) * $perPage;

$where  = ['1=1'];
$params = [];

if ($filterEvent !== '') {
    $where[]  = 'event_type = ?';
    $params[] = $filterEvent;
}
if ($filterIp !== '') {
    $where[]  = 'ip_address LIKE ?';
    $params[] = '%' . $filterIp . '%';
}
if ($filterEmail !== '') {
    $where[]  = 'email LIKE ?';
    $params[] = '%' . $filterEmail . '%';
}

$whereClause = implode(' AND ', $where);

// Total count for pagination
$countStmt = $db->prepare("SELECT COUNT(*) FROM security_log WHERE $whereClause");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

// Rows
$rowStmt = $db->prepare("
    SELECT sl.id, sl.event_type, sl.user_id, sl.email, sl.ip_address,
           sl.actor_user_id, sl.details, sl.created_at,
           u.full_name  AS user_name,
           a.full_name  AS actor_name
    FROM security_log sl
    LEFT JOIN users u ON u.id = sl.user_id
    LEFT JOIN users a ON a.id = sl.actor_user_id
    WHERE $whereClause
    ORDER BY sl.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$rowStmt->execute($params);
$rows = $rowStmt->fetchAll(PDO::FETCH_ASSOC);

// Distinct event types for filter dropdown
$eventTypes = $db->query("SELECT DISTINCT event_type FROM security_log ORDER BY event_type")->fetchAll(PDO::FETCH_COLUMN);

// Badge colour map
$badgeMap = [
    'login_success'       => '#1a7f4b',
    'login_failure'       => '#c0392b',
    'login_rate_limited'  => '#e67e22',
    'mfa_success'         => '#1a7f4b',
    'mfa_failure'         => '#c0392b',
    'mfa_reset'           => '#8e44ad',
    'mfa_required'        => '#2980b9',
    'mfa_disabled'        => '#7f8c8d',
    'password_reset_sent' => '#2980b9',
    'password_changed'    => '#27ae60',
    'session_timeout'     => '#e67e22',
    'logout'              => '#7f8c8d',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php include __DIR__ . "/includes/head_favicon.php"; ?>
  <meta charset="UTF-8">
  <title>Security Log – DealerFAI</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php dealerfai_theme_head(); ?>
  <style nonce="<?= dealerfai_csp_nonce() ?>">
    *, *::before, *::after { box-sizing: border-box; }
    body { margin: 0; font-family: "Segoe UI", sans-serif; background: #f4f6f8; color: #111; }
    header {
      background: #0a2e36; color: #fff; padding: 16px 28px;
      display: flex; align-items: center; justify-content: space-between;
    }
    header h1 { margin: 0; font-size: 20px; }
    header nav a { color: #cde; text-decoration: none; margin-left: 20px; font-size: 14px; }
    header nav a:hover { color: #fff; }
    .badge {
      display: inline-block; min-width: 16px; padding: 2px 7px;
      border-radius: 999px; background: #d7263d; color: #fff;
      font-size: 11px; font-weight: bold; margin-left: 5px; vertical-align: middle;
    }
    main { padding: 28px 32px; max-width: 1400px; margin: 0 auto; }
    h2 { margin: 0 0 20px; color: #0a2e36; font-size: 22px; }

    /* Filter bar */
    .filter-bar {
      display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end;
      background: #fff; padding: 16px 20px; border-radius: 8px;
      box-shadow: 0 1px 4px rgba(0,0,0,0.08); margin-bottom: 20px;
    }
    .filter-bar label { display: flex; flex-direction: column; font-size: 12px; font-weight: 600; color: #556; gap: 4px; }
    .filter-bar select, .filter-bar input[type="text"] {
      padding: 7px 10px; border: 1px solid #ccd; border-radius: 5px;
      font-size: 13px; min-width: 160px;
    }
    .filter-bar button {
      padding: 8px 18px; background: #0a6280; color: #fff; border: none;
      border-radius: 5px; font-size: 13px; cursor: pointer; align-self: flex-end;
    }
    .filter-bar button:hover { background: #084f68; }
    .filter-bar a.reset {
      align-self: flex-end; padding: 8px 14px; color: #556; font-size: 13px;
      text-decoration: none; border: 1px solid #ccd; border-radius: 5px;
    }
    .filter-bar a.reset:hover { background: #f0f2f4; }

    /* Stats row */
    .stats { display: flex; gap: 14px; margin-bottom: 20px; flex-wrap: wrap; }
    .stat-card {
      background: #fff; border-radius: 8px; padding: 14px 20px;
      box-shadow: 0 1px 4px rgba(0,0,0,0.07); flex: 1; min-width: 140px; max-width: 220px;
    }
    .stat-card .num { font-size: 26px; font-weight: 700; color: #0a2e36; }
    .stat-card .lbl { font-size: 12px; color: #778; margin-top: 2px; }

    /* Table */
    .card { background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); overflow: hidden; }
    table { width: 100%; border-collapse: collapse; font-size: 13px; }
    thead th {
      background: #0a2e36; color: #fff; padding: 11px 14px;
      text-align: left; font-weight: 600; white-space: nowrap;
    }
    tbody tr:nth-child(even) { background: #f8fafc; }
    tbody tr:hover { background: #eef4f8; }
    tbody td { padding: 10px 14px; vertical-align: middle; border-bottom: 1px solid #eee; }
    .event-pill {
      display: inline-block; padding: 3px 9px; border-radius: 999px;
      font-size: 11px; font-weight: 700; color: #fff; white-space: nowrap;
    }
    .detail-pre {
      font-family: ui-monospace, monospace; font-size: 11px; color: #445;
      background: #f4f6f8; padding: 4px 8px; border-radius: 4px;
      max-width: 280px; word-break: break-all; white-space: pre-wrap;
    }
    .no-rows { padding: 40px; text-align: center; color: #778; }

    /* Pagination */
    .pagination { display: flex; gap: 6px; margin-top: 18px; justify-content: center; flex-wrap: wrap; }
    .pagination a, .pagination span {
      padding: 6px 12px; border-radius: 5px; font-size: 13px;
      border: 1px solid #ccd; text-decoration: none; color: #0a6280;
    }
    .pagination a:hover { background: #eef4f8; }
    .pagination span.current { background: #0a6280; color: #fff; border-color: #0a6280; }
    .pagination span.disabled { color: #aab; cursor: default; }
  </style>
</head>
<body>
<header>
  <h1>🔐 Security Log</h1>
  <nav>
    <a href="dashboard.php">Dashboard</a>
    <a href="admin_error_alerts.php">Error Alerts<?php if ($adminAlertCount > 0): ?><span class="badge"><?= $adminAlertCount ?></span><?php endif; ?></a>
    <a href="admin_tools.php">Admin Tools</a>
    <a href="logout.php">Logout</a>
  </nav>
</header>

<main>
  <h2>Security Event Log</h2>

  <?php
  // Quick stats
  $stats = [];
  try {
      $s = $db->query("
          SELECT event_type, COUNT(*) AS cnt
          FROM security_log
          WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
          GROUP BY event_type
      ");
      foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
          $stats[$row['event_type']] = (int)$row['cnt'];
      }
  } catch (Throwable $e) {}
  $failures24h = ($stats['login_failure'] ?? 0) + ($stats['mfa_failure'] ?? 0);
  $logins24h   = $stats['login_success'] ?? 0;
  $resets24h   = $stats['password_reset_sent'] ?? 0;
  $mfaAdm24h   = ($stats['mfa_reset'] ?? 0) + ($stats['mfa_required'] ?? 0) + ($stats['mfa_disabled'] ?? 0);
  ?>
  <div class="stats">
    <div class="stat-card">
      <div class="num"><?= $logins24h ?></div>
      <div class="lbl">Successful Logins (24h)</div>
    </div>
    <div class="stat-card">
      <div class="num" style="color:<?= $failures24h > 10 ? '#c0392b' : '#0a2e36' ?>"><?= $failures24h ?></div>
      <div class="lbl">Auth Failures (24h)</div>
    </div>
    <div class="stat-card">
      <div class="num"><?= $resets24h ?></div>
      <div class="lbl">Password Resets (24h)</div>
    </div>
    <div class="stat-card">
      <div class="num"><?= $mfaAdm24h ?></div>
      <div class="lbl">Admin MFA Actions (24h)</div>
    </div>
    <div class="stat-card">
      <div class="num"><?= number_format($totalRows) ?></div>
      <div class="lbl">Total Events (filtered)</div>
    </div>
  </div>

  <!-- Filter bar -->
  <form method="get" class="filter-bar">
    <label>Event Type
      <select name="event">
        <option value="">— All —</option>
        <?php foreach ($eventTypes as $et): ?>
          <option value="<?= htmlspecialchars($et) ?>" <?= $filterEvent === $et ? 'selected' : '' ?>>
            <?= htmlspecialchars($et) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>IP Address
      <input type="text" name="ip" value="<?= htmlspecialchars($filterIp) ?>" placeholder="e.g. 192.168">
    </label>
    <label>Email
      <input type="text" name="email" value="<?= htmlspecialchars($filterEmail) ?>" placeholder="e.g. user@example.com">
    </label>
    <button type="submit">Filter</button>
    <a class="reset" href="security_log.php">Reset</a>
  </form>

  <div class="card">
    <?php if (empty($rows)): ?>
      <div class="no-rows">No security events found matching your filters.</div>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>Timestamp</th>
            <th>Event</th>
            <th>User</th>
            <th>Email</th>
            <th>IP Address</th>
            <th>Actor (Admin)</th>
            <th>Details</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
            <?php
              $colour = $badgeMap[$row['event_type']] ?? '#555';
              $ts = $row['created_at'] ? date('M j, Y g:i:s A', strtotime($row['created_at'])) : '—';
              $details = '';
              if ($row['details']) {
                  $decoded = json_decode($row['details'], true);
                  $details = is_array($decoded) ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : $row['details'];
              }
            ?>
            <tr>
              <td><?= (int)$row['id'] ?></td>
              <td style="white-space:nowrap; color:#444; font-size:12px;"><?= htmlspecialchars($ts) ?></td>
              <td>
                <span class="event-pill" style="background:<?= $colour ?>">
                  <?= htmlspecialchars($row['event_type']) ?>
                </span>
              </td>
              <td><?= $row['user_name'] ? htmlspecialchars($row['user_name']) : ($row['user_id'] ? '#' . (int)$row['user_id'] : '—') ?></td>
              <td style="font-size:12px;"><?= htmlspecialchars($row['email'] ?? '—') ?></td>
              <td style="font-family:monospace; font-size:12px;"><?= htmlspecialchars($row['ip_address']) ?></td>
              <td><?= $row['actor_name'] ? htmlspecialchars($row['actor_name']) : ($row['actor_user_id'] ? '#' . (int)$row['actor_user_id'] : '—') ?></td>
              <td><?= $details ? '<div class="detail-pre">' . htmlspecialchars($details) . '</div>' : '—' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <!-- Pagination -->
  <?php if ($totalPages > 1): ?>
    <?php
      $qs = array_filter(['event' => $filterEvent, 'ip' => $filterIp, 'email' => $filterEmail]);
    ?>
    <div class="pagination">
      <?php if ($page > 1): ?>
        <a href="?<?= http_build_query(array_merge($qs, ['page' => $page - 1])) ?>">← Prev</a>
      <?php else: ?>
        <span class="disabled">← Prev</span>
      <?php endif; ?>

      <?php for ($p = max(1, $page - 3); $p <= min($totalPages, $page + 3); $p++): ?>
        <?php if ($p === $page): ?>
          <span class="current"><?= $p ?></span>
        <?php else: ?>
          <a href="?<?= http_build_query(array_merge($qs, ['page' => $p])) ?>"><?= $p ?></a>
        <?php endif; ?>
      <?php endfor; ?>

      <?php if ($page < $totalPages): ?>
        <a href="?<?= http_build_query(array_merge($qs, ['page' => $page + 1])) ?>">Next →</a>
      <?php else: ?>
        <span class="disabled">Next →</span>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</main>
</body>
</html>
