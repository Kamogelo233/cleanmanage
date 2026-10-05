<?php
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/security.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_SESSION['user_id'])) {
    redirect('index.php');
}

requireCsrfToken('signup.php');

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $role = 'customer';

    if ($name === '') {
        $errors[] = 'Full name is required.';
    }

    if (!validateEmail($email)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if (!validatePhone($phone)) {
        $errors[] = 'Please enter a valid phone number.';
    }

    $passwordErrors = evaluatePasswordStrength($password);
    if (!empty($passwordErrors)) {
        $errors = array_merge($errors, $passwordErrors);
    }

    if ($password !== $confirmPassword) {
        $errors[] = 'Passwords do not match.';
    }

    if (!$errors) {
        $existing = $conn->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $existing->bind_param('s', $email);
        $existing->execute();
        $exists = $existing->get_result()->fetch_assoc();
        $existing->close();

        if ($exists) {
            $errors[] = 'This email is already registered.';
        } else {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare('INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)');
            $stmt->bind_param('ssss', $name, $email, $hashed, $role);
            $stmt->execute();
            $userId = (int)$conn->insert_id;
            $stmt->close();

            $customerStmt = $conn->prepare('INSERT INTO customers (name, phone, email) VALUES (?, ?, ?)');
            $customerStmt->bind_param('sss', $name, $phone, $email);
            $customerStmt->execute();
            $customerStmt->close();

            session_regenerate_id(true);
            $_SESSION['user_id'] = $userId;
            $_SESSION['user_name'] = $name;
            $_SESSION['user_email'] = $email;
            $_SESSION['user_role'] = $role;

            redirect('index.php');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Up - CleanManage</title>
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
            width: min(100%, 560px);
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
        }

        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 0.2rem rgba(15, 76, 58, 0.12);
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
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-2) 100%);
            border: none;
            border-radius: 12px;
            padding: 0.8rem 1rem;
            font-weight: 700;
            box-shadow: 0 12px 24px rgba(15, 76, 58, 0.18);
        }

        .alert-danger {
            border: none;
            background: rgba(215, 91, 91, 0.08);
            color: #7e2a2a;
            border-radius: 14px;
        }

        .signin-link {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
        }

        .signin-link:hover {
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
                <h2>Create account</h2>
                <p>Join and manage your bookings</p>
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
                        <label class="form-label">Full Name</label>
                        <div class="input-field">
                            <input type="text" name="name" class="form-control pe-5" required value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
                            <button type="button" class="text-toggle" aria-label="Show full name" title="Show full name">
                                <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"></path>
                                    <circle cx="12" cy="12" r="3"></circle>
                                </svg>
                            </button>
                        </div>
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
                        <label class="form-label">Phone</label>
                        <div class="input-field">
                            <input type="text" name="phone" class="form-control pe-5" required value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
                            <button type="button" class="text-toggle" aria-label="Show phone" title="Show phone">
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
                    <div class="mb-3">
                        <label class="form-label">Confirm Password</label>
                        <div class="input-field">
                            <input type="password" name="confirm_password" class="form-control pe-5" required>
                            <button type="button" class="password-toggle" aria-label="Show password" title="Show password">
                                <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"></path>
                                    <circle cx="12" cy="12" r="3"></circle>
                                </svg>
                            </button>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Create Account</button>
                </form>

                <div class="mt-3 text-center">
                    <a href="login.php" class="signin-link">Already have an account? Sign in</a>
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
                const isText = input.type === 'text';
                input.type = isText ? 'password' : 'text';
                this.setAttribute('aria-label', isText ? 'Show ' + input.name : 'Hide ' + input.name);
                this.title = isText ? 'Show ' + input.name : 'Hide ' + input.name;
                this.innerHTML = isText ? `
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"></path>
                        <circle cx="12" cy="12" r="3"></circle>
                    </svg>
                ` : `
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M3 3l18 18"></path>
                        <path d="M10.58 10.58A2 2 0 0 0 13.42 13.42"></path>
                        <path d="M9.88 5.08A9.94 9.94 0 0 1 12 5c6.5 0 10 7 10 7a17.16 17.16 0 0 1-4.39 5.44"></path>
                        <path d="M6.61 6.61A16.7 16.7 0 0 0 2 12s3.5 7 10 7a9.75 9.75 0 0 0 5.39-1.61"></path>
                    </svg>
                `;
            });
        });
    </script>
</body>
</html>
