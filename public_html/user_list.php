<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/security_headers.php';
require_once __DIR__ . '/includes/theme_head.php';
require_once __DIR__ . '/helpers/session_utils.php';
require_once __DIR__ . '/helpers/db_utils.php';
include 'db.php';

$roles = load_session_roles();
if (!in_array('Admin', $roles, true)) {
    http_response_code(403);
    die('Access denied.');
}

$users = [];
$error = '';
$hasUpdatedAt = false;

try {
    $hasUpdatedAt = column_exists($db, 'users', 'password_updated_at');

    $sql = "SELECT id, email, password_last_set" . ($hasUpdatedAt ? ", password_updated_at" : "") . " FROM users ORDER BY id ASC";
    $stmt = $db->query($sql);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php include __DIR__ . "/includes/head_favicon.php"; ?>
<meta charset="UTF-8">
    <title>User List (Admin)</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php dealerfai_theme_head(); ?>
  <style nonce="<?= dealerfai_csp_nonce() ?>">
        body { font-family: "Segoe UI", sans-serif; background-color: #f4f6f8; margin: 0; }
        header {
            background-color: #0a2e36;
            color: white;
            padding: 30px 40px;
            text-align: center;
        }
        header img {
            max-width: 480px;
            max-height: 180px;
            height: auto;
            display: block;
            margin: 0 auto 10px;
        }
        .container { max-width: 1000px; margin: 40px auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        h2 { color: #0a6280; border-bottom: 2px solid #eee; padding-bottom: 10px; margin-top: 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background-color: #0a6280; color: white; }
        tr:hover { background-color: #f9f9f9; }
        .message { color: red; margin-bottom: 15px; }
        .admin-nav { margin-bottom: 20px; padding: 10px; background-color: #e9ecef; border-radius: 4px; }
        .admin-nav a { margin-right: 15px; text-decoration: none; color: #0a6280; font-weight: bold; }
        .admin-nav a:hover { text-decoration: underline; }
        .admin-nav a.active { color: #333; pointer-events: none; }
    </style>
</head>
<body>
<header>
  <img src="dealerfai_logo_blue.png" alt="DealerFAI Logo">
  <h1>DealerFAI</h1>
</header>
<div class="container">
    <div class="admin-nav">
        <a href="admin_tools.php">Admin Tools</a>
        <a href="user_list.php" class="active">User List</a>
        <a href="logout.php">Logout</a>
    </div>
    <h2>User List (Admin)</h2>
    <?php if (!empty($error)): ?>
        <p class="message"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>
    
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Email</th>
                <th>Last Password Set</th>
                <?php if ($hasUpdatedAt): ?>
                <th>Last Password Update</th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $user): ?>
            <tr>
                <td><?= htmlspecialchars($user['id']) ?></td>
                <td><?= htmlspecialchars($user['email']) ?></td>
                <td><?= htmlspecialchars($user['password_last_set'] ?? 'N/A') ?></td>
                <?php if ($hasUpdatedAt): ?>
                <td><?= htmlspecialchars($user['password_updated_at'] ?? 'N/A') ?></td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($users)): ?>
            <tr><td colspan="<?= $hasUpdatedAt ? 4 : 3 ?>">No users found.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
</body>
</html>
