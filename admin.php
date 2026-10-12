<?php
require_once __DIR__ . '/backup_lib.php';
$user = backupRequireAdmin();

$stats = $GLOBALS['pdo']->query('SELECT COUNT(*) AS total_users, SUM(role = \'admin\') AS total_admins FROM users')->fetch();
$users = $GLOBALS['pdo']->query('SELECT id, name, email, role, email_verified, created_at FROM users ORDER BY created_at DESC')->fetchAll();
$backupHistory = backupHistory(50);
$lastSuccess = $GLOBALS['pdo']->query('SELECT completed_at FROM ' . BACKUP_HISTORY_TABLE . ' WHERE operation = \'backup\' AND status = \'success\' ORDER BY completed_at DESC LIMIT 1')->fetchColumn();
$lastError = $GLOBALS['pdo']->query('SELECT message FROM ' . BACKUP_HISTORY_TABLE . ' WHERE status = \'failed\' ORDER BY id DESC LIMIT 1')->fetchColumn();
$backupErrorMessage = getFlash('error');
$backupSuccessMessage = getFlash('success');
$backupCsrfToken = backupCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1.0" />
	<title>Admin Dashboard | InfoSec</title>
	<link rel="stylesheet" href="style.css" />
</head>
<body>
	<header>
		<h1>InfoSec System</h1>
		<nav>
			<a href="index.php">Home</a>
			<a href="admin.php">Admin Dashboard</a>
			<a href="logout.php">Logout</a>
		</nav>
	</header>

	<main class="dashboard-wrap">
		<section class="dashboard-card admin-dashboard">
			<h2>Admin Dashboard</h2>
			<p class="subtitle">Welcome, <?php echo htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8'); ?>.</p>

			<div class="user-meta">
				<div class="meta-box">
					<strong>Total accounts</strong>
					<span><?php echo number_format((int) $stats['total_users']); ?></span>
				</div>
				<div class="meta-box">
					<strong>Admin accounts</strong>
					<span><?php echo number_format((int) $stats['total_admins']); ?></span>
				</div>
			</div>

			<h3 class="admin-section-title">User accounts</h3>
			<div class="admin-table-wrap">
				<table class="admin-table">
					<thead>
						<tr>
							<th>Name</th>
							<th>Email</th>
							<th>Role</th>
							<th>Email status</th>
							<th>Created</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($users as $account): ?>
							<tr>
								<td><?php echo htmlspecialchars($account['name'], ENT_QUOTES, 'UTF-8'); ?></td>
								<td><?php echo htmlspecialchars($account['email'], ENT_QUOTES, 'UTF-8'); ?></td>
								<td><?php echo htmlspecialchars(ucfirst($account['role']), ENT_QUOTES, 'UTF-8'); ?></td>
								<td><?php echo $account['email_verified'] ? 'Verified' : 'Pending'; ?></td>
								<td><?php echo htmlspecialchars($account['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<section id="backups" class="admin-backups">
				<div class="backup-heading">
					<div>
						<h3>Google Drive Backups</h3>
						<p>Database and project files stored in the Project Backups folder.</p>
					</div>
					<span class="backup-connection"><?php echo htmlspecialchars(backupConnectionLabel(), ENT_QUOTES, 'UTF-8'); ?></span>
				</div>

				<?php if ($backupErrorMessage): ?>
					<div class="alert error"><?php echo htmlspecialchars($backupErrorMessage, ENT_QUOTES, 'UTF-8'); ?></div>
				<?php endif; ?>

				<?php if ($backupSuccessMessage): ?>
					<div class="alert success"><?php echo htmlspecialchars($backupSuccessMessage, ENT_QUOTES, 'UTF-8'); ?></div>
				<?php endif; ?>

				<div class="user-meta backup-meta">
					<div class="meta-box">
						<strong>Last successful backup</strong>
						<span><?php echo $lastSuccess ? htmlspecialchars($lastSuccess, ENT_QUOTES, 'UTF-8') . ' UTC' : 'Never'; ?></span>
					</div>
					<div class="meta-box">
						<strong>Retention</strong>
						<span><?php echo (int) BACKUP_RETENTION_DAYS; ?> days</span>
					</div>
					<div class="meta-box">
						<strong>Most recent error</strong>
						<span><?php echo $lastError ? htmlspecialchars($lastError, ENT_QUOTES, 'UTF-8') : 'None'; ?></span>
					</div>
				</div>

				<div class="backup-actions">
					<?php if (backupGoogleConfigured()): ?>
						<?php if (backupDriveConnected()): ?>
							<a class="btn" href="google_drive_oauth.php">Reconnect Google Drive</a>
						<?php else: ?>
							<a class="btn" href="google_drive_oauth.php">Connect Google Drive</a>
						<?php endif; ?>
					<?php else: ?>
						<p>Google OAuth settings are missing. See <a href="BACKUPS.md">the backup setup guide</a>.</p>
					<?php endif; ?>

					<form method="post" action="backup_action.php">
						<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($backupCsrfToken, ENT_QUOTES, 'UTF-8'); ?>" />
						<input type="hidden" name="action" value="backup" />
						<button class="auth-button" type="submit" <?php echo backupDriveConnected() ? '' : 'disabled'; ?>>Create backup now</button>
					</form>
				</div>

				<h3 class="admin-section-title">Backup history</h3>
				<div class="admin-table-wrap">
					<table class="admin-table">
						<thead>
							<tr>
								<th>Date (UTC)</th>
								<th>Operation</th>
								<th>Filename</th>
								<th>Size</th>
								<th>Status</th>
								<th>Details</th>
								<th>Action</th>
							</tr>
						</thead>
						<tbody>
							<?php if (!$backupHistory): ?>
								<tr><td colspan="7">No backups have been run yet.</td></tr>
							<?php endif; ?>
							<?php foreach ($backupHistory as $entry): ?>
								<tr>
									<td><?php echo htmlspecialchars($entry['started_at'], ENT_QUOTES, 'UTF-8'); ?></td>
									<td><?php echo htmlspecialchars(ucfirst($entry['operation']), ENT_QUOTES, 'UTF-8'); ?></td>
									<td><?php echo htmlspecialchars($entry['file_name'] ?: 'Not created', ENT_QUOTES, 'UTF-8'); ?></td>
									<td><?php echo htmlspecialchars(backupFormatSize($entry['size_bytes'] === null ? null : (int) $entry['size_bytes']), ENT_QUOTES, 'UTF-8'); ?></td>
									<td><span class="backup-status status-<?php echo htmlspecialchars($entry['status'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(ucfirst($entry['status']), ENT_QUOTES, 'UTF-8'); ?></span></td>
									<td><?php echo htmlspecialchars($entry['message'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
									<td>
										<?php if ($entry['operation'] === 'backup' && $entry['status'] === 'success'): ?>
											<form method="post" action="backup_action.php" onsubmit="return confirm('Restore this backup? Current database data will be overwritten after a safety backup is uploaded.');">
												<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($backupCsrfToken, ENT_QUOTES, 'UTF-8'); ?>" />
												<input type="hidden" name="action" value="restore" />
												<input type="hidden" name="history_id" value="<?php echo (int) $entry['id']; ?>" />
												<button class="backup-restore-button" type="submit">Restore</button>
											</form>
										<?php else: ?>
											<span>Not available</span>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</section>
		</section>
	</main>
</body>
</html>
