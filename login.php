<?php
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/security.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_SESSION['user_id'])) {
    redirect('index.php');
}

requireCsrfToken('login.php');

$errors = [];
$loginAttempts = (int)($_SESSION['login_attempts'] ?? 0);
$lockoutUntil = (int)($_SESSION['login_lockout_until'] ?? 0);

if ($lockoutUntil > time()) {
    $errors[] = 'Too many failed login attempts. Please try again in a few minutes.';
} else {
    $_SESSION['login_attempts'] = 0;
    $_SESSION['login_lockout_until'] = 0;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $selectedRole = normalizeUserRole(trim($_POST['role'] ?? 'customer'));

        if (!in_array($selectedRole, allowedRoles(), true)) {
            $errors[] = 'Please select a valid role.';
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email.';
        }

        if ($password === '') {
            $errors[] = 'Password is required.';
        }

        if (!$errors) {
            $stmt = $conn->prepare('SELECT id, name, email, password, role FROM users WHERE email = ? AND role = ? LIMIT 1');
            $stmt->bind_param('ss', $email, $selectedRole);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
            $stmt->close();

            if ($user && password_verify($password, $user['password'])) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int)$user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['user_email'] = $user['email'] ?? '';
                $_SESSION['user_role'] = normalizeUserRole($user['role']);
                unset($_SESSION['login_attempts'], $_SESSION['login_lockout_until']);
                redirect('index.php');
            }

            $_SESSION['login_attempts'] = $loginAttempts + 1;
            if ($_SESSION['login_attempts'] >= 5) {
                $_SESSION['login_lockout_until'] = time() + 300;
                $_SESSION['login_attempts'] = 0;
                $errors[] = 'Too many failed login attempts. Please try again in 5 minutes.';
            } else {
                $errors[] = 'Invalid email or password.';
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
    <title>Login - CleanManage</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --bg: #F8FAF8;
            --bg-soft: #EEF5F0;
            --surface: #FFFFFF;
            --primary: #0F4C3A;
            --primary-2: #1E6A4F;
            --primary-3: #7BA388;
            --gold: #C5A880;
            --gold-soft: #F4ECDF;
            --text: #1D2A24;
            --muted: #61736B;
            --line: rgba(15, 76, 58, 0.12);
            --danger: #D75B5B;
            --shadow: 0 24px 60px rgba(15, 76, 58, 0.12);
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, var(--bg) 0%, var(--bg-soft) 100%);
            color: var(--text);
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
        }

        .auth-shell {
            width: min(100%, 480px);
            padding: 28px;
        }

        .auth-card {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 28px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .brand-panel {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-2) 100%);
            color: white;
            padding: 26px 28px 20px;
            text-align: center;
            position: relative;
        }

        .brand-panel::after {
            content: "";
            position: absolute;
            inset: 0;
            background: linear-gradient(120deg, rgba(197, 168, 128, 0.18), transparent 35%);
            pointer-events: none;
        }

        .brand-panel > * {
            position: relative;
            z-index: 1;
        }

        .brand-tag {
            display: inline-block;
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.18);
            border-radius: 999px;
            padding: 6px 12px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
        }

        .brand-panel h2 {
            margin: 12px 0 6px;
            font-weight: 800;
            letter-spacing: -0.04em;
        }

        .brand-panel p {
            margin: 0;
            opacity: 0.9;
        }

        .card-body {
            padding: 28px 28px 22px;
        }

        .form-label {
            font-weight: 600;
            color: var(--text);
        }

        .form-control {
            border-radius: 12px;
            border: 1px solid rgba(15, 76, 58, 0.15);
            padding: 0.8rem 0.9rem;
            background: rgba(248, 250, 248, 0.9);
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .form-control:focus {
            border-color: #0F4C3A;
            box-shadow: 0 0 0 0.2rem rgba(15, 76, 58, 0.18);
            outline: none;
        }

        .input-field {
            position: relative;
        }

        .text-toggle,
        .password-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            border: none;
            background: transparent;
            color: var(--muted);
            padding: 4px 6px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
        }

        .text-toggle:hover,
        .text-toggle:focus,
        .password-toggle:hover,
        .password-toggle:focus {
            background: rgba(15, 76, 58, 0.06);
            color: var(--primary);
            outline: none;
        }

        .text-toggle svg,
        .password-toggle svg {
            width: 18px;
            height: 18px;
            stroke: currentColor;
        }

        .btn-primary {
            background: #0F4C3A;
            border: none;
            border-radius: 12px;
            padding: 0.8rem 1rem;
            font-weight: 700;
            box-shadow: 0 12px 24px rgba(15, 76, 58, 0.18);
            transition: background 0.2s ease, transform 0.2s ease;
        }

        .btn-primary:hover,
        .btn-primary:focus,
        .btn-primary:active {
            background: #C5A880 !important;
            border-color: #C5A880 !important;
            transform: translateY(-1px);
            box-shadow: 0 12px 24px rgba(197, 168, 128, 0.25);
            outline: none;
        }

        .btn-primary:focus,
        .btn-primary:focus-visible {
            box-shadow: 0 0 0 0.2rem rgba(15, 76, 58, 0.18), 0 12px 24px rgba(15, 76, 58, 0.18);
        }

        .btn-outline-primary {
            border-radius: 12px;
            border: 1.5px solid var(--primary);
            color: var(--primary);
            background: var(--surface);
            font-weight: 600;
            padding: 0.8rem 1rem;
        }

        .btn-outline-primary:hover {
            background: var(--primary);
            border-color: var(--primary);
            color: #fff;
        }

        .alert-danger {
            border: none;
            background: rgba(215, 91, 91, 0.08);
            color: #7e2a2a;
            border-radius: 14px;
        }

        .helper-note {
            color: var(--muted);
            font-size: 0.84rem;
            text-align: center;
            margin-top: 18px;
        }

        .forgot-link {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
        }

        .forgot-link:hover {
            color: var(--primary-2);
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="auth-shell">
        <div class="auth-card">
            <div class="brand-panel">
                <span class="brand-tag">CleanManage</span>
                <h2>Welcome to CleanManage</h2>
                <p>Sign in to your dashboard</p>
            </div>
            <div class="card-body">
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            <?php foreach ($errors as $error): ?>
                                <li><?= htmlspecialchars($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form method="POST" novalidate>
                    <?= csrfField() ?>
                    <div class="mb-3">
                        <label class="form-label" for="login-role">Role</label>
                        <select id="login-role" name="role" class="form-select form-control" required>
                            <option value="admin" <?= (($_POST['role'] ?? '') === 'admin') ? 'selected' : '' ?>>Admin</option>
                            <option value="owner" <?= (($_POST['role'] ?? '') === 'owner') ? 'selected' : '' ?>>Business Owner</option>
                            <option value="manager" <?= (($_POST['role'] ?? '') === 'manager') ? 'selected' : '' ?>>Manager</option>
                            <option value="finance" <?= (($_POST['role'] ?? '') === 'finance') ? 'selected' : '' ?>>Finance</option>
                            <option value="cleaner" <?= (($_POST['role'] ?? '') === 'cleaner') ? 'selected' : '' ?>>Cleaner</option>
                            <option value="customer" <?= (($_POST['role'] ?? 'customer') === 'customer') ? 'selected' : '' ?>>Customer</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <div class="input-field">
                            <input type="email" name="email" class="form-control pe-5" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                            <button type="button" class="text-toggle" aria-label="Show email" title="Show email">
                                <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"></path>
                                    <circle cx="12" cy="12" r="3"></circle>
                                </svg>
                            </button>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Password</label>
                        <div class="input-field">
                            <input type="password" name="password" class="form-control pe-5" required>
                            <button type="button" class="password-toggle" aria-label="Show password" title="Show password">
                                <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"></path>
                                    <circle cx="12" cy="12" r="3"></circle>
                                </svg>
                            </button>
                        </div>
                    </div>
                    <div class="d-flex justify-content-end mb-3">
                        <a href="reset-password.php" class="forgot-link">Forgot password?</a>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Login</button>
                </form>

                <div class="mt-3 text-center small">
                    <span class="text-muted">Don't have an account?</span>
                    <a href="register.php" class="text-decoration-none fw-semibold" style="color: var(--primary);">Sign up</a>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.querySelectorAll('.password-toggle').forEach((toggle) => {
            toggle.addEventListener('click', function () {
                const input = this.parentElement.querySelector('input');
                const isPassword = input.type === 'password';
                input.type = isPassword ? 'text' : 'password';
                this.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
                this.title = isPassword ? 'Hide password' : 'Show password';
                this.innerHTML = isPassword ? `
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M3 3l18 18"></path>
                        <path d="M10.58 10.58A2 2 0 0 0 13.42 13.42"></path>
                        <path d="M9.88 5.08A9.94 9.94 0 0 1 12 5c6.5 0 10 7 10 7a17.16 17.16 0 0 1-4.39 5.44"></path>
                        <path d="M6.61 6.61A16.7 16.7 0 0 0 2 12s3.5 7 10 7a9.75 9.75 0 0 0 5.39-1.61"></path>
                    </svg>
                ` : `
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"></path>
                        <circle cx="12" cy="12" r="3"></circle>
                    </svg>
                `;
            });
        });

        document.querySelectorAll('.text-toggle').forEach((toggle) => {
            toggle.addEventListener('click', function () {
                const input = this.parentElement.querySelector('input');
                const isPassword = input.type === 'password';
                input.type = isPassword ? 'text' : 'password';
                this.setAttribute('aria-label', isPassword ? 'Hide email' : 'Show email');
                this.title = isPassword ? 'Hide email' : 'Show email';
                this.innerHTML = isPassword ? `
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M3 3l18 18"></path>
                        <path d="M10.58 10.58A2 2 0 0 0 13.42 13.42"></path>
                        <path d="M9.88 5.08A9.94 9.94 0 0 1 12 5c6.5 0 10 7 10 7a17.16 17.16 0 0 1-4.39 5.44"></path>
                        <path d="M6.61 6.61A16.7 16.7 0 0 0 2 12s3.5 7 10 7a9.75 9.75 0 0 0 5.39-1.61"></path>
                    </svg>
                ` : `
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"></path>
                        <circle cx="12" cy="12" r="3"></circle>
                    </svg>
                `;
            });
        });
    </script>
</body>
</html>
