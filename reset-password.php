<?php
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/security.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$errors = [];
$success = '';
$token = $_GET['token'] ?? '';
$mode = $token !== '' ? 'reset' : 'request';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($mode === 'request') {
        $email = trim($_POST['email'] ?? '');
        if (!validateEmail($email)) {
            $errors[] = 'Please enter a valid email address.';
        } else {
            $userStmt = $conn->prepare('SELECT id, name FROM users WHERE email = ? LIMIT 1');
            $userStmt->bind_param('s', $email);
            $userStmt->execute();
            $user = $userStmt->get_result()->fetch_assoc();
            $userStmt->close();

            if ($user) {
                $token = bin2hex(random_bytes(32));
                $expiresAt = date('Y-m-d H:i:s', time() + 3600);
                $updateStmt = $conn->prepare('UPDATE users SET reset_token = ?, reset_expires = ? WHERE id = ?');
                $updateStmt->bind_param('ssi', $token, $expiresAt, $user['id']);
                $updateStmt->execute();
                $updateStmt->close();

                $resetLink = 'http://localhost:8000/reset-password.php?token=' . urlencode($token);
                $message = "Hello " . $user['name'] . ",\n\nUse the link below to reset your CleanManage password:\n\n" . $resetLink . "\n\nThis link expires in 1 hour.";
                @mail($email, 'CleanManage password reset', $message, "From: no-reply@cleanmanage.local\r\nReply-To: no-reply@cleanmanage.local\r\nX-Mailer: PHP/" . phpversion());
                $success = 'If an account exists for that email, a reset link has been sent.';
            } else {
                $success = 'If an account exists for that email, a reset link has been sent.';
            }
        }
    } else {
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if ($password === '') {
            $errors[] = 'Password is required.';
        }

        $passwordErrors = evaluatePasswordStrength($password);
        if (!empty($passwordErrors)) {
            $errors = array_merge($errors, $passwordErrors);
        }

        if ($password !== $confirmPassword) {
            $errors[] = 'Passwords do not match.';
        }

        if (empty($errors)) {
            $stmt = $conn->prepare('SELECT id FROM users WHERE reset_token = ? AND reset_expires > NOW() LIMIT 1');
            $stmt->bind_param('s', $token);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$row) {
                $errors[] = 'This password reset link is invalid or expired.';
            } else {
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $update = $conn->prepare('UPDATE users SET password = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?');
                $update->bind_param('si', $hashed, $row['id']);
                $update->execute();
                $update->close();
                $success = 'Password reset successful. You can sign in now.';
                $mode = 'done';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset password - CleanManage</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --bg: #F8FAF8;
            --bg-soft: #EEF5F0;
            --surface: #FFFFFF;
            --primary: #0F4C3A;
            --primary-2: #1E6A4F;
            --gold: #C5A880;
            --text: #1D2A24;
            --muted: #61736B;
            --line: rgba(15, 76, 58, 0.12);
            --danger: #D75B5B;
            --shadow: 0 24px 60px rgba(15, 76, 58, 0.12);
        }
        body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center; background:linear-gradient(135deg, var(--bg) 0%, var(--bg-soft) 100%); font-family:"Segoe UI", Tahoma, Geneva, Verdana, sans-serif; }
        .auth-shell { width:min(100%, 480px); padding:28px; }
        .auth-card { background:var(--surface); border:1px solid var(--line); border-radius:28px; box-shadow:var(--shadow); overflow:hidden; }
        .brand-panel { background:linear-gradient(135deg, var(--primary) 0%, var(--primary-2) 100%); color:white; padding:26px 28px 20px; text-align:center; }
        .brand-tag { display:inline-block; background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.18); border-radius:999px; padding:6px 12px; font-size:11px; font-weight:700; letter-spacing:0.12em; text-transform:uppercase; }
        .brand-panel h2 { margin:12px 0 6px; font-weight:800; letter-spacing:-0.04em; }
        .card-body { padding:28px 28px 22px; }
        .form-control { border-radius:12px; border:1px solid rgba(15, 76, 58, 0.15); padding:0.8rem 0.9rem; background:rgba(248, 250, 248, 0.9); }
        .form-control:focus { border-color: var(--primary); box-shadow: 0 0 0 0.2rem rgba(15, 76, 58, 0.16); }
        .btn-primary { background: linear-gradient(135deg, var(--primary) 0%, var(--primary-2) 100%); border:none; border-radius:12px; padding:0.8rem 1rem; font-weight:700; }
        .alert-danger, .alert-success { border:none; border-radius:14px; }
        .alert-danger { background:rgba(215, 91, 91, 0.08); color:#7e2a2a; }
        .alert-success { background:rgba(15, 76, 58, 0.08); color:#0f4c3a; }
        .link-muted { color:var(--primary); text-decoration:none; font-weight:600; }
    </style>
</head>
<body>
    <div class="auth-shell">
        <div class="auth-card">
            <div class="brand-panel">
                <span class="brand-tag">CleanManage</span>
                <h2><?= $mode === 'reset' ? 'Set a new password' : 'Reset your password' ?></h2>
            </div>
            <div class="card-body">
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div>
                <?php endif; ?>
                <?php if ($success !== ''): ?>
                    <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
                <?php endif; ?>

                <?php if ($mode === 'request' && $success === ''): ?>
                    <form method="POST">
                        <?= csrfField() ?>
                        <div class="mb-3">
                            <label class="form-label">Email address</label>
                            <input type="email" name="email" class="form-control" required>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Send reset link</button>
                    </form>
                <?php elseif ($mode === 'reset' && $success === ''): ?>
                    <form method="POST">
                        <?= csrfField() ?>
                        <div class="mb-3">
                            <label class="form-label">New password</label>
                            <input type="password" name="password" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Confirm password</label>
                            <input type="password" name="confirm_password" class="form-control" required>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Update password</button>
                    </form>
                <?php endif; ?>

                <?php if ($success !== '' || $mode === 'done'): ?>
                    <div class="mt-3 text-center">
                        <a href="login.php" class="link-muted">Back to login</a>
                    </div>
                <?php else: ?>
                    <div class="mt-3 text-center">
                        <a href="login.php" class="link-muted">Cancel</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
