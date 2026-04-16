<?php
require_once 'config.php';

// If already logged in, redirect to dashboard
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

$error = '';
$csrf_token = generateCSRFToken();

// get login logo from settings
$defaultLogo = "app_logo_login.png";
$loginLogo = getSetting('login_logo_url', '');
$loginLogo = !empty($loginLogo) ? $loginLogo : $defaultLogo;

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // CSRF protection
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid request. Please try again.';
    } else {
        $email = isset($_POST['email']) ? trim($_POST['email']) : '';
        $password = isset($_POST['password']) ? $_POST['password'] : '';

        // Input validation
        $email = validateEmail($email);
        $password = validatePassword($password);

        if ($email === false) {
            $error = 'Invalid email format.';
        } elseif ($password === false) {
            $error = 'Invalid password format.';
        } else {
            // Check rate limiting
            if (checkLoginAttempts($email)) {
                $error = 'Too many login attempts. Please try again in 15 minutes.';
            } else {
                try {
                    $conn = getDBConnection();

                    $stmt = $conn->prepare("SELECT id, full_name, email, password_hash, role, is_active FROM users WHERE email = ?");
                    $stmt->bind_param("s", $email);
                    $stmt->execute();
                    $result = $stmt->get_result();
                } catch (Exception $e) {
                    // Check if it's a table/column not found error
                    if (strpos($e->getMessage(), "doesn't exist") !== false || strpos($e->getMessage(), "Unknown column") !== false) {
                        $error = 'Database not set up. Please run <a href="setup.php" class="text-brand-500 underline">setup.php</a> first.';
                    } else {
                        $error = 'Database error. Please contact administrator.';
                        error_log("Login error: " . $e->getMessage());
                    }
                    if (isset($stmt)) $stmt->close();
                    if (isset($conn)) $conn->close();
                    goto skip_login;
                }

                if ($result->num_rows == 1) {
                    $user = $result->fetch_assoc();

                    // Check if account is active
                    if (!$user['is_active']) {
                        $stmt->close();
                        $conn->close();
                        $error = 'Your account has been deactivated. Please contact an administrator.';
                        goto skip_login;
                    }

                    if (password_verify($password, $user['password_hash'])) {
                        // Clear failed login attempts
                        clearLoginAttempts($email);

                        // Regenerate session ID to prevent session fixation
                        session_regenerate_id(true);

                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['username'] = $user['full_name'];
                        $_SESSION['email'] = $user['email'];
                        $_SESSION['role'] = $user['role'];
                        $_SESSION['LAST_ACTIVITY'] = time();

                        // Update last_login timestamp
                        $updateLogin = $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                        $updateLogin->bind_param("i", $user['id']);
                        $updateLogin->execute();
                        $updateLogin->close();

                        // Log successful login
                        logActivity($user['id'], $user['full_name'], 'Login', 'User logged in successfully');

                        $stmt->close();
                        $conn->close();

                        header("Location: dashboard.php");
                        exit();
                    } else {
                        recordLoginAttempt($email);
                        $error = 'Invalid email or password';
                    }
                } else {
                    recordLoginAttempt($email);
                    $error = 'Invalid email or password';
                }

                $stmt->close();
                $conn->close();
            }
        }
        skip_login:
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="mobile-web-app-capable" content="yes">
  <title>Commodity Flow &mdash; Sign In</title>

  <script src="https://cdn.tailwindcss.com"></script>
  <script>
  tailwind.config = {
    darkMode: 'class',
    theme: {
      extend: {
        fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] },
        colors: {
          brand: {
            50:  '#f0f9f9',
            100: '#d9f2f0',
            200: '#b5e6e3',
            300: '#82d3cf',
            400: '#4db8b4',
            500: '#2d9d99',
            600: '#247f7c',
            700: '#1d6462',
            800: '#185150',
            900: '#164342',
          }
        }
      }
    }
  }
  </script>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="min-h-screen bg-gradient-to-br from-slate-50 to-brand-50 dark:from-slate-900 dark:to-slate-800 flex items-center justify-center p-4 font-sans antialiased">

  <div class="w-full max-w-md">
    <!-- Logo & branding -->
    <div class="text-center mb-8">
      <div class="w-16 h-16 rounded-2xl bg-brand-500 flex items-center justify-center mx-auto mb-4 shadow-lg">
        <svg width="32" height="32" viewBox="0 0 32 32" fill="none"><path d="M8 24 C8 16 16 8 26 8 C24 16 18 22 8 24Z" fill="white" opacity="0.9"/><circle cx="24" cy="10" r="2" fill="white" opacity="0.7"/></svg>
      </div>
      <h1 class="text-2xl font-bold text-slate-800 dark:text-white">Commodity Flow</h1>
      <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Supply Chain Management</p>
    </div>

    <!-- Login card -->
    <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-lg border border-slate-200 dark:border-slate-700 p-8">
      <h2 class="text-lg font-bold text-slate-800 dark:text-white mb-6">Sign in to your account</h2>

      <?php if ($error): ?>
        <div class="mb-4 flex items-start gap-2 bg-rose-50 dark:bg-rose-900/20 border border-rose-200 dark:border-rose-800 text-rose-700 dark:text-rose-400 px-4 py-3 rounded-lg text-sm">
          <i class="fas fa-exclamation-circle mt-0.5 flex-shrink-0"></i>
          <span><?php echo $error; ?></span>
        </div>
      <?php endif; ?>

      <form method="POST" action="">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

        <div class="mb-4">
          <label class="block text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-1">
            <i class="fas fa-envelope mr-1"></i> Email
          </label>
          <input type="email" id="email" name="email" required autofocus autocomplete="email" maxlength="200"
            class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2.5 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors"
            placeholder="you@example.com">
        </div>

        <div class="mb-6">
          <label class="block text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-1">
            <i class="fas fa-lock mr-1"></i> Password
          </label>
          <input type="password" id="password" name="password" required autocomplete="current-password" minlength="6"
            class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2.5 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors"
            placeholder="Enter your password">
        </div>

        <button type="submit" class="w-full bg-brand-500 hover:bg-brand-600 text-white font-semibold py-2.5 rounded-lg shadow-sm transition-colors flex items-center justify-center gap-2">
          <i class="fas fa-sign-in-alt text-sm"></i> Sign In
        </button>
      </form>
    </div>

    <p class="text-center text-xs text-slate-400 dark:text-slate-500 mt-6">&copy; 2025 Commodity Flow. All rights reserved.</p>
  </div>

  <!-- Theme init -->
  <script>
  (function(){
    var t = localStorage.getItem('cp_theme');
    if (t === 'dark' || (!t && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
      document.documentElement.classList.add('dark');
    }
  })();
  </script>
</body>
</html>
