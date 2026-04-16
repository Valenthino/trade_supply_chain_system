<?php
require_once 'config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Check session timeout
if (!checkSessionTimeout()) {
    header("Location: login.php");
    exit();
}

$username = $_SESSION['username'];
$role = isset($_SESSION['role']) ? $_SESSION['role'] : 'Warehouse Clerk';
$user_id = $_SESSION['user_id'];
$current_page = 'account';

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    try {
        $conn = getDBConnection();

        switch ($_GET['action']) {
            case 'getAccountInfo':
                // Check if theme columns exist
                $hasThemeColumns = false;
                $check = $conn->query("SHOW COLUMNS FROM users LIKE 'theme_primary'");
                if ($check && $check->num_rows > 0) {
                    $hasThemeColumns = true;
                }

                if ($hasThemeColumns) {
                    $stmt = $conn->prepare("SELECT id, full_name, email, phone, role, profile_image, created_at, theme_primary, theme_secondary, theme_accent, theme_mode FROM users WHERE id = ?");
                } else {
                    $stmt = $conn->prepare("SELECT id, full_name, email, phone, role, profile_image, created_at FROM users WHERE id = ?");
                }

                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows == 1) {
                    $user = $result->fetch_assoc();
                    $allowUserUploads = getSetting('allow_user_profile_uploads', '1') === '1';

                    echo json_encode([
                        'success' => true,
                        'data' => [
                            'id' => $user['id'],
                            'full_name' => $user['full_name'],
                            'email' => $user['email'],
                            'phone' => $user['phone'] ?? '',
                            'role' => $user['role'],
                            'profile_image' => $user['profile_image'],
                            'created_at' => date('M d, Y H:i:s', strtotime($user['created_at'])),
                            'allow_user_uploads' => $allowUserUploads,
                            'theme_primary' => $user['theme_primary'] ?? '#001f3f',
                            'theme_secondary' => $user['theme_secondary'] ?? '#003366',
                            'theme_accent' => $user['theme_accent'] ?? '#0074D9',
                            'theme_mode' => $user['theme_mode'] ?? 'light'
                        ]
                    ]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'User not found']);
                }

                $stmt->close();
                $conn->close();
                exit();

            case 'saveTheme':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $theme_primary = isset($_POST['theme_primary']) ? trim($_POST['theme_primary']) : '#001f3f';
                $theme_secondary = isset($_POST['theme_secondary']) ? trim($_POST['theme_secondary']) : '#003366';
                $theme_accent = isset($_POST['theme_accent']) ? trim($_POST['theme_accent']) : '#0074D9';
                $theme_mode = isset($_POST['theme_mode']) ? trim($_POST['theme_mode']) : 'light';

                // Validate hex colors
                $color_pattern = '/^#[0-9A-Fa-f]{6}$/';
                if (!preg_match($color_pattern, $theme_primary) ||
                    !preg_match($color_pattern, $theme_secondary) ||
                    !preg_match($color_pattern, $theme_accent)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid color format. Use hex colors like #001f3f']);
                    exit();
                }

                // Validate theme mode
                if (!in_array($theme_mode, ['light', 'dark'])) {
                    $theme_mode = 'light';
                }

                $result = setUserTheme($user_id, $theme_primary, $theme_secondary, $theme_accent, $theme_mode);

                if ($result) {
                    logActivity($user_id, $username, 'Theme Updated', "Updated UI colors: Primary=$theme_primary, Secondary=$theme_secondary, Accent=$theme_accent, Mode=$theme_mode");
                    echo json_encode(['success' => true, 'message' => 'Theme settings saved successfully']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to save theme settings']);
                }
                exit();

            case 'resetTheme':
                $result = setUserTheme($user_id, '#001f3f', '#003366', '#0074D9', 'light');

                if ($result) {
                    logActivity($user_id, $username, 'Theme Reset', 'Reset UI colors to default');
                    echo json_encode(['success' => true, 'message' => 'Theme reset to default']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to reset theme']);
                }
                exit();

            case 'updateProfile':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $newFullName = isset($_POST['full_name']) ? trim($_POST['full_name']) : '';
                $phone = isset($_POST['phone']) ? trim($_POST['phone']) : null;
                $email = isset($_POST['email']) ? trim($_POST['email']) : '';

                // Validate inputs
                if (empty($newFullName) || empty($email)) {
                    echo json_encode(['success' => false, 'message' => 'Full name and email are required']);
                    exit();
                }

                if (strlen($newFullName) > 150) {
                    echo json_encode(['success' => false, 'message' => 'Full name must be 150 characters or less']);
                    exit();
                }

                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid email format']);
                    exit();
                }

                // Check if email is already taken by another user
                $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                $stmt->bind_param("si", $email, $user_id);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows > 0) {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Email already in use by another account']);
                    exit();
                }
                $stmt->close();

                // Update profile
                $stmt = $conn->prepare("UPDATE users SET full_name = ?, email = ?, phone = ? WHERE id = ?");
                $stmt->bind_param("sssi", $newFullName, $email, $phone, $user_id);

                if ($stmt->execute()) {
                    // Update session
                    $_SESSION['username'] = $newFullName;
                    $_SESSION['email'] = $email;

                    // Log activity
                    logActivity($user_id, $newFullName, 'Profile Updated', 'Updated name, email, and phone');

                    $stmt->close();
                    $conn->close();

                    echo json_encode(['success' => true, 'message' => 'Profile updated successfully', 'new_username' => $newFullName]);
                } else {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to update profile']);
                }
                exit();

            case 'changePassword':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }

                $currentPassword = isset($_POST['current_password']) ? $_POST['current_password'] : '';
                $newPassword = isset($_POST['new_password']) ? $_POST['new_password'] : '';
                $confirmPassword = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';

                // Validate inputs
                if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
                    echo json_encode(['success' => false, 'message' => 'All fields are required']);
                    exit();
                }

                if ($newPassword !== $confirmPassword) {
                    echo json_encode(['success' => false, 'message' => 'New passwords do not match']);
                    exit();
                }

                $newPassword = validatePassword($newPassword);
                if ($newPassword === false) {
                    echo json_encode(['success' => false, 'message' => 'Password must be 6-255 characters']);
                    exit();
                }

                // Verify current password
                $stmt = $conn->prepare("SELECT password_hash FROM users WHERE id = ?");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows == 1) {
                    $user = $result->fetch_assoc();

                    if (!password_verify($currentPassword, $user['password_hash'])) {
                        $stmt->close();
                        $conn->close();
                        echo json_encode(['success' => false, 'message' => 'Current password is incorrect']);
                        exit();
                    }
                } else {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'User not found']);
                    exit();
                }
                $stmt->close();

                // Update password
                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                $stmt->bind_param("si", $hashedPassword, $user_id);

                if ($stmt->execute()) {
                    // Log activity
                    logActivity($user_id, $username, 'Password Changed', 'User changed their password');

                    $stmt->close();
                    $conn->close();

                    echo json_encode(['success' => true, 'message' => 'Password changed successfully']);
                } else {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to change password']);
                }
                exit();

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                exit();
        }
    } catch (Exception $e) {
        error_log("Account.php error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
        exit();
    }
}

// Handle profile image upload (separate from AJAX JSON responses)
if (isset($_POST['action']) && $_POST['action'] === 'uploadProfileImage') {
    header('Content-Type: application/json');

    try {
        // Check if user uploads are allowed
        $allowUserUploads = getSetting('allow_user_profile_uploads', '1') === '1';
        if (!$allowUserUploads && $role !== 'Admin') {
            echo json_encode(['success' => false, 'message' => 'Profile uploads are currently disabled']);
            exit();
        }

        if (!isset($_FILES['profile_image']) || $_FILES['profile_image']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => 'No file uploaded or upload error']);
            exit();
        }

        // Upload the file
        $uploadResult = uploadProfileImage($_FILES['profile_image'], $user_id);

        if (!$uploadResult['success']) {
            echo json_encode($uploadResult);
            exit();
        }

        // Get old profile image
        $oldImage = getProfileImage($user_id);

        // Update database
        $conn = getDBConnection();
        $stmt = $conn->prepare("UPDATE users SET profile_image = ? WHERE id = ?");
        $stmt->bind_param("si", $uploadResult['filename'], $user_id);

        if ($stmt->execute()) {
            // Delete old image if exists
            if ($oldImage) {
                deleteProfileImage($oldImage);
            }

            // Log activity
            logActivity($user_id, $username, 'Profile Image Updated', 'User uploaded a new profile image');

            $stmt->close();
            $conn->close();

            echo json_encode([
                'success' => true,
                'message' => 'Profile image uploaded successfully',
                'image_url' => $uploadResult['filename']
            ]);
        } else {
            $stmt->close();
            $conn->close();
            echo json_encode(['success' => false, 'message' => 'Failed to update profile image']);
        }
    } catch (Exception $e) {
        error_log("Profile image upload error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    }
    exit();
}

// If we reach here, render the HTML page
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="mobile-web-app-capable" content="yes">
  <title>Commodity Flow &mdash; My Account</title>

  <!-- Tailwind CSS CDN -->
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
            },
            slate: { 850: '#172032' }
          },
          boxShadow: {
            'card': '0 1px 3px 0 rgba(0,0,0,0.06), 0 1px 2px -1px rgba(0,0,0,0.04)',
            'card-hover': '0 4px 12px 0 rgba(0,0,0,0.08)',
          }
        }
      }
    }
  </script>

  <!-- Google Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

  <!-- Font Awesome -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

  <!-- App Styles -->
  <link rel="stylesheet" href="styles.css?v=4.0">

  <!-- jQuery + SweetAlert2 -->
  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

  <style>
    ::-webkit-scrollbar { width: 5px; height: 5px; }
    ::-webkit-scrollbar-track { background: transparent; }
    ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
    .dark ::-webkit-scrollbar-thumb { background: #334155; }

    @keyframes shimmer { 0%{background-position:-400px 0} 100%{background-position:400px 0} }
    .skeleton {
      background: linear-gradient(90deg, #f1f5f9 25%, #e2e8f0 50%, #f1f5f9 75%);
      background-size: 400px 100%;
      animation: shimmer 1.4s ease infinite;
      border-radius: 6px;
    }
    .dark .skeleton {
      background: linear-gradient(90deg, #1e293b 25%, #273349 50%, #1e293b 75%);
      background-size: 400px 100%;
    }

    #sidebar { transition: width 280ms cubic-bezier(.16,1,.3,1); }
    .sidebar-label { transition: opacity 200ms, width 200ms; }
    .app-collapsed #sidebar { width: 64px; }
    .app-collapsed .sidebar-label { opacity: 0; width: 0; overflow: hidden; }
    .app-collapsed .sidebar-section-label { opacity: 0; }
    .app-collapsed .logo-text { opacity: 0; width: 0; overflow: hidden; }

    .nav-link.active { background: rgba(45,157,153,0.12); color: #2d9d99; }
    .dark .nav-link.active { background: rgba(45,157,153,0.15); color: #4db8b4; }
    .nav-link.active .nav-icon { color: #2d9d99; }
    .dark .nav-link.active .nav-icon { color: #4db8b4; }
    .nav-link.active::before {
      content: '';
      position: absolute; left: 0; top: 15%; bottom: 15%;
      width: 3px; background: #2d9d99; border-radius: 0 3px 3px 0;
    }
  </style>
</head>

<body class="h-full bg-slate-50 text-slate-800 font-sans antialiased dark:bg-slate-900 dark:text-slate-200">

  <?php include 'mobile-menu.php'; ?>

  <div class="flex h-full overflow-hidden" id="appRoot">

    <?php include 'sidebar.php'; ?>

    <!-- MAIN -->
    <div class="flex-1 flex flex-col min-w-0 overflow-hidden">

      <!-- HEADER -->
      <header class="h-14 bg-white dark:bg-slate-800 border-b border-slate-200 dark:border-slate-700 flex items-center gap-4 px-5 flex-shrink-0">
        <button id="mobileSidebarBtn" class="lg:hidden text-slate-500 hover:text-slate-700 dark:hover:text-slate-300">
          <i class="fas fa-bars text-sm"></i>
        </button>
        <div class="flex items-center gap-2">
          <i class="fas fa-circle-user text-brand-500 text-sm"></i>
          <h1 class="text-base font-bold text-slate-800 dark:text-white">My Account</h1>
        </div>
        <div class="ml-auto flex items-center gap-3">
          <span class="text-xs text-slate-500 dark:text-slate-400">Welcome, <?php echo htmlspecialchars($username); ?></span>
        </div>
      </header>

      <!-- MAIN CONTENT -->
      <main class="flex-1 overflow-y-auto p-5 space-y-5">

        <!-- Profile Image Card -->
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card p-6">
          <h3 class="text-sm font-bold text-slate-800 dark:text-white mb-4"><i class="fas fa-image mr-1 text-brand-500"></i> Profile Image</h3>
          <div class="flex items-start gap-6">
            <!-- Profile image display -->
            <div class="flex flex-col items-center gap-2">
              <div class="w-24 h-24 rounded-full bg-slate-100 dark:bg-slate-700 overflow-hidden flex items-center justify-center border-2 border-slate-200 dark:border-slate-600">
                <img id="currentProfileImage" src="" alt="Profile" class="w-full h-full object-cover" style="display:none;">
                <i id="defaultProfileIcon" class="fas fa-user text-3xl text-slate-400" style="display:none;"></i>
              </div>
              <span class="text-xs text-slate-500">Current Image</span>
            </div>
            <!-- Upload form -->
            <div class="flex-1">
              <form id="profileImageForm" enctype="multipart/form-data">
                <div class="mb-3">
                  <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1"><i class="fas fa-upload mr-1"></i> Upload New Profile Image</label>
                  <input type="file" id="profileImageInput" name="profile_image" accept="image/jpeg,image/png,image/gif,image/webp" class="w-full text-sm text-slate-700 dark:text-slate-300 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-brand-50 file:text-brand-600 hover:file:bg-brand-100">
                  <p class="text-xs text-slate-400 mt-1"><i class="fas fa-info-circle mr-1"></i> Accepted: JPG, PNG, GIF, WEBP (Max 2MB)</p>
                </div>
                <div class="flex gap-2" id="uploadSection" style="display:none;">
                  <button type="submit" class="bg-brand-500 hover:bg-brand-600 text-white text-xs font-semibold px-3.5 py-2 rounded-lg transition-colors" id="uploadBtn"><i class="fas fa-upload mr-1"></i> Upload</button>
                  <button type="button" class="bg-slate-100 hover:bg-slate-200 text-slate-700 dark:bg-slate-700 dark:hover:bg-slate-600 dark:text-slate-300 text-xs font-semibold px-3.5 py-2 rounded-lg transition-colors" onclick="cancelUpload()"><i class="fas fa-times mr-1"></i> Cancel</button>
                </div>
              </form>
              <div id="uploadDisabledMessage" class="bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-400 text-xs p-3 rounded-lg mt-2" style="display:none;">
                <i class="fas fa-exclamation-triangle mr-1"></i> Profile image uploads are currently disabled by administrator.
              </div>
            </div>
          </div>
        </div>

        <!-- Account Information Card -->
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card p-6">
          <h3 class="text-sm font-bold text-slate-800 dark:text-white mb-4"><i class="fas fa-info-circle mr-1 text-brand-500"></i> Account Information</h3>
          <div class="flex items-center py-3 border-b border-slate-100 dark:border-slate-700">
            <div class="text-xs font-semibold text-slate-500 w-36"><i class="fas fa-user mr-1"></i> Full Name:</div>
            <div class="text-sm text-slate-800 dark:text-slate-200" id="display-fullname">Loading...</div>
          </div>
          <div class="flex items-center py-3 border-b border-slate-100 dark:border-slate-700">
            <div class="text-xs font-semibold text-slate-500 w-36"><i class="fas fa-envelope mr-1"></i> Email:</div>
            <div class="text-sm text-slate-800 dark:text-slate-200" id="display-email">Loading...</div>
          </div>
          <div class="flex items-center py-3 border-b border-slate-100 dark:border-slate-700">
            <div class="text-xs font-semibold text-slate-500 w-36"><i class="fas fa-phone mr-1"></i> Phone:</div>
            <div class="text-sm text-slate-800 dark:text-slate-200" id="display-phone">Loading...</div>
          </div>
          <div class="flex items-center py-3 border-b border-slate-100 dark:border-slate-700">
            <div class="text-xs font-semibold text-slate-500 w-36"><i class="fas fa-user-tag mr-1"></i> Role:</div>
            <div class="text-sm text-slate-800 dark:text-slate-200" id="display-role">Loading...</div>
          </div>
          <div class="flex items-center py-3">
            <div class="text-xs font-semibold text-slate-500 w-36"><i class="fas fa-calendar-alt mr-1"></i> Member Since:</div>
            <div class="text-sm text-slate-800 dark:text-slate-200" id="display-created">Loading...</div>
          </div>
        </div>

        <!-- Update Profile Card -->
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card p-6">
          <h3 class="text-sm font-bold text-slate-800 dark:text-white mb-4"><i class="fas fa-edit mr-1 text-brand-500"></i> Update Profile</h3>
          <form id="profileForm">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
              <div>
                <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1"><i class="fas fa-user mr-1"></i> Full Name *</label>
                <input type="text" id="fullName" name="full_name" required maxlength="150" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
              </div>
              <div>
                <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1"><i class="fas fa-envelope mr-1"></i> Email *</label>
                <input type="email" id="email" name="email" required maxlength="200" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
              </div>
              <div>
                <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1"><i class="fas fa-phone mr-1"></i> Phone</label>
                <input type="tel" id="phone" name="phone" maxlength="20" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
              </div>
            </div>
            <div class="mt-4">
              <button type="submit" class="bg-brand-500 hover:bg-brand-600 text-white text-xs font-semibold px-3.5 py-2 rounded-lg transition-colors">
                <i class="fas fa-save mr-1"></i> Save Changes
              </button>
            </div>
          </form>
        </div>

        <!-- Change Password Card -->
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card p-6">
          <h3 class="text-sm font-bold text-slate-800 dark:text-white mb-4"><i class="fas fa-lock mr-1 text-brand-500"></i> Change Password</h3>
          <form id="passwordForm">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
              <div>
                <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1"><i class="fas fa-lock mr-1"></i> Current Password *</label>
                <input type="password" id="current_password" name="current_password" required class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
              </div>
              <div>
                <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1"><i class="fas fa-key mr-1"></i> New Password *</label>
                <input type="password" id="new_password" name="new_password" required minlength="6" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
              </div>
              <div>
                <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1"><i class="fas fa-key mr-1"></i> Confirm New Password *</label>
                <input type="password" id="confirm_password" name="confirm_password" required minlength="6" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors">
              </div>
            </div>
            <div class="mt-4">
              <button type="submit" class="bg-brand-500 hover:bg-brand-600 text-white text-xs font-semibold px-3.5 py-2 rounded-lg transition-colors">
                <i class="fas fa-key mr-1"></i> Change Password
              </button>
            </div>
          </form>
        </div>

        <!-- UI Customization Card -->
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-card p-6">
          <div class="flex items-center justify-between mb-4">
            <h3 class="text-sm font-bold text-slate-800 dark:text-white"><i class="fas fa-palette mr-1 text-brand-500"></i> UI Customization</h3>
            <button class="bg-slate-100 hover:bg-slate-200 text-slate-700 dark:bg-slate-700 dark:hover:bg-slate-600 dark:text-slate-300 text-xs font-semibold px-3.5 py-2 rounded-lg transition-colors" onclick="resetTheme()">
              <i class="fas fa-undo mr-1"></i> Reset to Default
            </button>
          </div>

          <p class="text-xs text-slate-400 mb-5">
            <i class="fas fa-info-circle mr-1"></i> Customize your dashboard colors. Changes are saved per user and will apply across all pages.
          </p>

          <!-- Color Preview -->
          <div class="bg-slate-50 dark:bg-slate-700/50 rounded-lg border border-slate-200 dark:border-slate-600 p-4 mb-5" id="themePreview">
            <h4 class="text-xs font-semibold text-slate-600 dark:text-slate-300 mb-3"><i class="fas fa-eye mr-1"></i> Live Preview</h4>
            <div class="flex gap-3 flex-wrap items-center">
              <div id="previewPrimary" class="w-20 h-12 rounded-lg flex items-center justify-center text-white text-xs font-semibold">Primary</div>
              <div id="previewSecondary" class="w-20 h-12 rounded-lg flex items-center justify-center text-white text-xs font-semibold">Secondary</div>
              <div id="previewAccent" class="w-20 h-12 rounded-lg flex items-center justify-center text-white text-xs font-semibold">Accent</div>
              <button class="ml-4 px-3 py-2 rounded-lg text-xs font-semibold text-white" id="previewButton" type="button">
                <i class="fas fa-check mr-1"></i> Sample Button
              </button>
            </div>
          </div>

          <form id="themeForm">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
              <div>
                <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1"><i class="fas fa-square mr-1" id="primaryColorIcon"></i> Primary Color</label>
                <div class="flex gap-2 items-center">
                  <input type="color" id="theme_primary" name="theme_primary" value="#001f3f" class="w-14 h-10 p-0.5 cursor-pointer border-2 border-slate-200 dark:border-slate-600 rounded-lg">
                  <input type="text" id="theme_primary_hex" value="#001f3f" maxlength="7" class="flex-1 bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors uppercase">
                </div>
                <p class="text-xs text-slate-400 mt-1">Sidebar & headers</p>
              </div>
              <div>
                <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1"><i class="fas fa-square mr-1" id="secondaryColorIcon"></i> Secondary Color</label>
                <div class="flex gap-2 items-center">
                  <input type="color" id="theme_secondary" name="theme_secondary" value="#003366" class="w-14 h-10 p-0.5 cursor-pointer border-2 border-slate-200 dark:border-slate-600 rounded-lg">
                  <input type="text" id="theme_secondary_hex" value="#003366" maxlength="7" class="flex-1 bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors uppercase">
                </div>
                <p class="text-xs text-slate-400 mt-1">Hover states & gradients</p>
              </div>
              <div>
                <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1"><i class="fas fa-square mr-1" id="accentColorIcon"></i> Accent Color</label>
                <div class="flex gap-2 items-center">
                  <input type="color" id="theme_accent" name="theme_accent" value="#0074D9" class="w-14 h-10 p-0.5 cursor-pointer border-2 border-slate-200 dark:border-slate-600 rounded-lg">
                  <input type="text" id="theme_accent_hex" value="#0074D9" maxlength="7" class="flex-1 bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-400 transition-colors uppercase">
                </div>
                <p class="text-xs text-slate-400 mt-1">Buttons & links</p>
              </div>
            </div>

            <!-- Theme Mode -->
            <div class="mt-5">
              <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-2"><i class="fas fa-adjust mr-1"></i> Default Theme Mode</label>
              <div class="flex gap-4 flex-wrap">
                <label class="flex items-center gap-2 cursor-pointer px-4 py-3 border-2 border-slate-200 dark:border-slate-600 rounded-lg transition-colors hover:border-brand-400" id="lightModeOption">
                  <input type="radio" name="theme_mode" value="light" id="theme_mode_light" checked class="w-4 h-4 accent-brand-500">
                  <i class="fas fa-sun text-amber-400"></i>
                  <span class="text-sm text-slate-700 dark:text-slate-300">Light Mode</span>
                </label>
                <label class="flex items-center gap-2 cursor-pointer px-4 py-3 border-2 border-slate-200 dark:border-slate-600 rounded-lg transition-colors hover:border-brand-400" id="darkModeOption">
                  <input type="radio" name="theme_mode" value="dark" id="theme_mode_dark" class="w-4 h-4 accent-brand-500">
                  <i class="fas fa-moon text-blue-400"></i>
                  <span class="text-sm text-slate-700 dark:text-slate-300">Dark Mode</span>
                </label>
              </div>
            </div>

            <!-- Preset Colors -->
            <div class="mt-5">
              <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-2"><i class="fas fa-swatchbook mr-1"></i> Quick Presets</label>
              <div class="flex gap-2.5 flex-wrap">
                <button type="button" class="preset-btn w-10 h-10 rounded-full border-2 border-slate-200 dark:border-slate-600 cursor-pointer hover:scale-110 transition-transform" onclick="applyPreset('#001f3f', '#003366', '#0074D9')" style="background: linear-gradient(135deg, #001f3f 50%, #0074D9 50%);" title="Navy Blue (Default)"></button>
                <button type="button" class="preset-btn w-10 h-10 rounded-full border-2 border-slate-200 dark:border-slate-600 cursor-pointer hover:scale-110 transition-transform" onclick="applyPreset('#1a1a2e', '#16213e', '#e94560')" style="background: linear-gradient(135deg, #1a1a2e 50%, #e94560 50%);" title="Dark Rose"></button>
                <button type="button" class="preset-btn w-10 h-10 rounded-full border-2 border-slate-200 dark:border-slate-600 cursor-pointer hover:scale-110 transition-transform" onclick="applyPreset('#2d3436', '#636e72', '#00b894')" style="background: linear-gradient(135deg, #2d3436 50%, #00b894 50%);" title="Emerald Dark"></button>
                <button type="button" class="preset-btn w-10 h-10 rounded-full border-2 border-slate-200 dark:border-slate-600 cursor-pointer hover:scale-110 transition-transform" onclick="applyPreset('#4a0e4e', '#810e7a', '#c92bc8')" style="background: linear-gradient(135deg, #4a0e4e 50%, #c92bc8 50%);" title="Purple Magic"></button>
                <button type="button" class="preset-btn w-10 h-10 rounded-full border-2 border-slate-200 dark:border-slate-600 cursor-pointer hover:scale-110 transition-transform" onclick="applyPreset('#1b4332', '#2d6a4f', '#40916c')" style="background: linear-gradient(135deg, #1b4332 50%, #40916c 50%);" title="Forest Green"></button>
                <button type="button" class="preset-btn w-10 h-10 rounded-full border-2 border-slate-200 dark:border-slate-600 cursor-pointer hover:scale-110 transition-transform" onclick="applyPreset('#7f5539', '#9c6644', '#dda15e')" style="background: linear-gradient(135deg, #7f5539 50%, #dda15e 50%);" title="Warm Brown"></button>
                <button type="button" class="preset-btn w-10 h-10 rounded-full border-2 border-slate-200 dark:border-slate-600 cursor-pointer hover:scale-110 transition-transform" onclick="applyPreset('#03045e', '#0077b6', '#00b4d8')" style="background: linear-gradient(135deg, #03045e 50%, #00b4d8 50%);" title="Ocean Blue"></button>
                <button type="button" class="preset-btn w-10 h-10 rounded-full border-2 border-slate-200 dark:border-slate-600 cursor-pointer hover:scale-110 transition-transform" onclick="applyPreset('#3d0066', '#7b2cbf', '#c77dff')" style="background: linear-gradient(135deg, #3d0066 50%, #c77dff 50%);" title="Violet Dream"></button>
              </div>
            </div>

            <div class="mt-5 flex gap-2">
              <button type="submit" class="bg-brand-500 hover:bg-brand-600 text-white text-xs font-semibold px-3.5 py-2 rounded-lg transition-colors">
                <i class="fas fa-save mr-1"></i> Save Theme Settings
              </button>
              <button type="button" class="bg-emerald-500 hover:bg-emerald-600 text-white text-xs font-semibold px-3.5 py-2 rounded-lg transition-colors" onclick="applyThemePreview()">
                <i class="fas fa-eye mr-1"></i> Preview Changes
              </button>
            </div>
          </form>
        </div>

      </main>
    </div>
  </div>

    <script>
        let allowUserUploads = true;

        $(document).ready(function() {
            loadAccountInfo();
        });

        function loadAccountInfo() {
            $.ajax({
                url: '?action=getAccountInfo',
                method: 'GET',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        const data = response.data;

                        const roleBadgeMap = {
                            'Admin': 'role-admin', 'Manager': 'role-manager',
                            'Procurement Officer': 'role-procurement', 'Sales Officer': 'role-sales',
                            'Finance Officer': 'role-finance', 'Fleet Manager': 'role-fleet',
                            'Warehouse Clerk': 'role-warehouse'
                        };

                        // Display account info
                        document.getElementById('display-fullname').textContent = data.full_name;
                        document.getElementById('display-email').textContent = data.email;
                        document.getElementById('display-phone').textContent = data.phone || 'Not set';
                        const cls = roleBadgeMap[data.role] || 'role-warehouse';
                        document.getElementById('display-role').innerHTML =
                            `<span class="status-badge ${cls}">${data.role}</span>`;
                        document.getElementById('display-created').textContent = data.created_at;

                        // Populate form
                        document.getElementById('fullName').value = data.full_name;
                        document.getElementById('email').value = data.email;
                        document.getElementById('phone').value = data.phone || '';

                        // Handle profile image
                        allowUserUploads = data.allow_user_uploads;
                        if (data.profile_image) {
                            document.getElementById('currentProfileImage').src = data.profile_image;
                            document.getElementById('currentProfileImage').style.display = 'block';
                            document.getElementById('defaultProfileIcon').style.display = 'none';
                        } else {
                            document.getElementById('currentProfileImage').style.display = 'none';
                            document.getElementById('defaultProfileIcon').style.display = 'block';
                        }

                        // Check if uploads are allowed
                        if (!allowUserUploads && data.role !== 'Admin') {
                            document.getElementById('profileImageInput').disabled = true;
                            document.getElementById('uploadDisabledMessage').style.display = 'block';
                        }

                        // Load theme values
                        loadThemeValues(data);
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: response.message || 'Failed to load account info'
                        });
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Connection Error',
                        text: 'Could not load account information'
                    });
                }
            });
        }

        // Profile image file selection
        document.getElementById('profileImageInput').addEventListener('change', function(e) {
            if (this.files && this.files[0]) {
                const file = this.files[0];

                // Validate file size
                if (file.size > 2 * 1024 * 1024) {
                    Swal.fire({
                        icon: 'error',
                        title: 'File Too Large',
                        text: 'Profile image must be less than 2MB'
                    });
                    this.value = '';
                    return;
                }

                // Show upload button
                document.getElementById('uploadSection').style.display = 'flex';

                // Preview image
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('currentProfileImage').src = e.target.result;
                    document.getElementById('currentProfileImage').style.display = 'block';
                    document.getElementById('defaultProfileIcon').style.display = 'none';
                };
                reader.readAsDataURL(file);
            }
        });

        // Cancel upload
        function cancelUpload() {
            document.getElementById('profileImageInput').value = '';
            document.getElementById('uploadSection').style.display = 'none';
            loadAccountInfo(); // Reload to show original image
        }

        // Profile image upload form
        document.getElementById('profileImageForm').addEventListener('submit', function(e) {
            e.preventDefault();

            const formData = new FormData();
            const fileInput = document.getElementById('profileImageInput');

            if (!fileInput.files || !fileInput.files[0]) {
                Swal.fire({
                    icon: 'error',
                    title: 'No File Selected',
                    text: 'Please select an image to upload'
                });
                return;
            }

            formData.append('profile_image', fileInput.files[0]);
            formData.append('action', 'uploadProfileImage');

            Swal.fire({
                title: 'Uploading...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            $.ajax({
                url: '',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Success!',
                            text: response.message,
                            timer: 2000,
                            showConfirmButton: false
                        });

                        // Reset form and hide upload buttons
                        document.getElementById('profileImageInput').value = '';
                        document.getElementById('uploadSection').style.display = 'none';

                        // Reload account info to show new image
                        setTimeout(() => loadAccountInfo(), 500);
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Upload Failed',
                            text: response.message
                        });
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Upload Error:', error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Upload Failed',
                        text: 'Connection error: ' + error
                    });
                }
            });
        });

        // Profile Update Form
        document.getElementById('profileForm').addEventListener('submit', function(e) {
            e.preventDefault();

            const formData = new FormData(this);

            Swal.fire({
                title: 'Updating Profile...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            $.ajax({
                url: '?action=updateProfile',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Success!',
                            text: response.message,
                            timer: 2000,
                            showConfirmButton: false
                        });

                        // Update displayed name if it changed
                        if (response.new_username) {
                            document.getElementById('display-fullname').textContent = response.new_username;
                        }

                        setTimeout(() => loadAccountInfo(), 100);
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: response.message
                        });
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'Connection error: ' + error
                    });
                }
            });
        });

        // Password Change Form
        document.getElementById('passwordForm').addEventListener('submit', function(e) {
            e.preventDefault();

            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;

            if (newPassword !== confirmPassword) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'New passwords do not match'
                });
                return;
            }

            const formData = new FormData(this);

            Swal.fire({
                title: 'Changing Password...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            $.ajax({
                url: '?action=changePassword',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Success!',
                            text: response.message,
                            timer: 2000,
                            showConfirmButton: false
                        });

                        // Clear password form
                        document.getElementById('passwordForm').reset();
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: response.message
                        });
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'Connection error: ' + error
                    });
                }
            });
        });

        // =============================================
        // THEME CUSTOMIZATION FUNCTIONS
        // =============================================

        // Sync color picker with hex input
        document.getElementById('theme_primary').addEventListener('input', function() {
            document.getElementById('theme_primary_hex').value = this.value.toUpperCase();
            updatePreview();
            updateColorIcons();
        });

        document.getElementById('theme_secondary').addEventListener('input', function() {
            document.getElementById('theme_secondary_hex').value = this.value.toUpperCase();
            updatePreview();
            updateColorIcons();
        });

        document.getElementById('theme_accent').addEventListener('input', function() {
            document.getElementById('theme_accent_hex').value = this.value.toUpperCase();
            updatePreview();
            updateColorIcons();
        });

        // Sync hex input with color picker
        document.getElementById('theme_primary_hex').addEventListener('input', function() {
            if (/^#[0-9A-Fa-f]{6}$/.test(this.value)) {
                document.getElementById('theme_primary').value = this.value;
                updatePreview();
                updateColorIcons();
            }
        });

        document.getElementById('theme_secondary_hex').addEventListener('input', function() {
            if (/^#[0-9A-Fa-f]{6}$/.test(this.value)) {
                document.getElementById('theme_secondary').value = this.value;
                updatePreview();
                updateColorIcons();
            }
        });

        document.getElementById('theme_accent_hex').addEventListener('input', function() {
            if (/^#[0-9A-Fa-f]{6}$/.test(this.value)) {
                document.getElementById('theme_accent').value = this.value;
                updatePreview();
                updateColorIcons();
            }
        });

        // Update preview colors
        function updatePreview() {
            const primary = document.getElementById('theme_primary').value;
            const secondary = document.getElementById('theme_secondary').value;
            const accent = document.getElementById('theme_accent').value;

            document.getElementById('previewPrimary').style.background = primary;
            document.getElementById('previewSecondary').style.background = secondary;
            document.getElementById('previewAccent').style.background = accent;
            document.getElementById('previewButton').style.background = primary;
            document.getElementById('previewButton').style.color = 'white';
        }

        // Update color icons
        function updateColorIcons() {
            document.getElementById('primaryColorIcon').style.color = document.getElementById('theme_primary').value;
            document.getElementById('secondaryColorIcon').style.color = document.getElementById('theme_secondary').value;
            document.getElementById('accentColorIcon').style.color = document.getElementById('theme_accent').value;
        }

        // Apply preset colors
        function applyPreset(primary, secondary, accent) {
            document.getElementById('theme_primary').value = primary;
            document.getElementById('theme_primary_hex').value = primary.toUpperCase();
            document.getElementById('theme_secondary').value = secondary;
            document.getElementById('theme_secondary_hex').value = secondary.toUpperCase();
            document.getElementById('theme_accent').value = accent;
            document.getElementById('theme_accent_hex').value = accent.toUpperCase();
            updatePreview();
            updateColorIcons();

            Swal.fire({
                icon: 'info',
                title: 'Preset Applied',
                text: 'Click "Save Theme Settings" to apply permanently',
                timer: 2000,
                showConfirmButton: false
            });
        }

        // Apply theme preview (live preview without saving)
        function applyThemePreview() {
            const primary = document.getElementById('theme_primary').value;
            const secondary = document.getElementById('theme_secondary').value;
            const accent = document.getElementById('theme_accent').value;

            document.documentElement.style.setProperty('--navy-primary', primary);
            document.documentElement.style.setProperty('--navy-light', secondary);
            document.documentElement.style.setProperty('--navy-dark', primary);
            document.documentElement.style.setProperty('--navy-hover', secondary);
            document.documentElement.style.setProperty('--navy-accent', accent);

            Swal.fire({
                icon: 'success',
                title: 'Preview Applied!',
                text: 'This is a temporary preview. Save to make it permanent.',
                timer: 3000,
                showConfirmButton: false
            });
        }

        // Save theme settings
        document.getElementById('themeForm').addEventListener('submit', function(e) {
            e.preventDefault();

            const formData = new FormData();
            formData.append('theme_primary', document.getElementById('theme_primary').value);
            formData.append('theme_secondary', document.getElementById('theme_secondary').value);
            formData.append('theme_accent', document.getElementById('theme_accent').value);
            formData.append('theme_mode', document.querySelector('input[name="theme_mode"]:checked').value);

            Swal.fire({
                title: 'Saving Theme...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            $.ajax({
                url: '?action=saveTheme',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // Apply the theme
                        applyThemePreview();

                        // Save theme mode to localStorage
                        const themeMode = document.querySelector('input[name="theme_mode"]:checked').value;
                        localStorage.setItem('theme', themeMode);
                        if (themeMode === 'dark') {
                            document.body.classList.add('dark-mode');
                        } else {
                            document.body.classList.remove('dark-mode');
                        }

                        Swal.fire({
                            icon: 'success',
                            title: 'Theme Saved!',
                            text: 'Your custom theme has been saved.',
                            timer: 2000,
                            showConfirmButton: false
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: response.message
                        });
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'Connection error: ' + error
                    });
                }
            });
        });

        // Reset theme to default
        function resetTheme() {
            Swal.fire({
                icon: 'warning',
                title: 'Reset Theme?',
                text: 'This will reset your colors to the default Navy Blue theme.',
                showCancelButton: true,
                confirmButtonColor: '#001f3f',
                confirmButtonText: 'Yes, Reset',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    $.ajax({
                        url: '?action=resetTheme',
                        method: 'POST',
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                // Reset form values
                                document.getElementById('theme_primary').value = '#001f3f';
                                document.getElementById('theme_primary_hex').value = '#001F3F';
                                document.getElementById('theme_secondary').value = '#003366';
                                document.getElementById('theme_secondary_hex').value = '#003366';
                                document.getElementById('theme_accent').value = '#0074D9';
                                document.getElementById('theme_accent_hex').value = '#0074D9';
                                document.getElementById('theme_mode_light').checked = true;

                                // Apply default theme
                                document.documentElement.style.setProperty('--navy-primary', '#001f3f');
                                document.documentElement.style.setProperty('--navy-light', '#003366');
                                document.documentElement.style.setProperty('--navy-dark', '#001f3f');
                                document.documentElement.style.setProperty('--navy-hover', '#003366');
                                document.documentElement.style.setProperty('--navy-accent', '#0074D9');

                                // Set light mode
                                localStorage.setItem('theme', 'light');
                                document.body.classList.remove('dark-mode');

                                updatePreview();
                                updateColorIcons();

                                Swal.fire({
                                    icon: 'success',
                                    title: 'Theme Reset!',
                                    text: 'Colors have been reset to default.',
                                    timer: 2000,
                                    showConfirmButton: false
                                });
                            } else {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Error',
                                    text: response.message
                                });
                            }
                        },
                        error: function(xhr, status, error) {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: 'Connection error: ' + error
                            });
                        }
                    });
                }
            });
        }

        // Load theme values into form when account info is loaded
        function loadThemeValues(data) {
            if (data.theme_primary) {
                document.getElementById('theme_primary').value = data.theme_primary;
                document.getElementById('theme_primary_hex').value = data.theme_primary.toUpperCase();
            }
            if (data.theme_secondary) {
                document.getElementById('theme_secondary').value = data.theme_secondary;
                document.getElementById('theme_secondary_hex').value = data.theme_secondary.toUpperCase();
            }
            if (data.theme_accent) {
                document.getElementById('theme_accent').value = data.theme_accent;
                document.getElementById('theme_accent_hex').value = data.theme_accent.toUpperCase();
            }
            if (data.theme_mode === 'dark') {
                document.getElementById('theme_mode_dark').checked = true;
            } else {
                document.getElementById('theme_mode_light').checked = true;
            }

            updatePreview();
            updateColorIcons();
        }
    </script>

  <!-- Theme init & i18n -->
  <script>
  (function() {
    var _store = {};
    try { _store = window.localStorage; } catch(e) { _store = { getItem: function(){return null;}, setItem: function(){} }; }

    // Theme
    var html = document.documentElement;
    var dark = _store.getItem('cp_theme') === 'dark' || (_store.getItem('cp_theme') === null && window.matchMedia('(prefers-color-scheme: dark)').matches);
    html.classList.toggle('dark', dark);

    // Sidebar collapse
    var appRoot = document.getElementById('appRoot');
    var collapseBtn = document.getElementById('sidebarCollapseBtn');
    if (collapseBtn) {
      collapseBtn.addEventListener('click', function() {
        appRoot.classList.toggle('app-collapsed');
        var ic = document.getElementById('collapseIcon');
        if (ic) ic.style.transform = appRoot.classList.contains('app-collapsed') ? 'rotate(180deg)' : '';
      });
    }

    // i18n
    var currentLang = _store.getItem('cp_lang') || 'en';
    function applyTranslations() {
      document.querySelectorAll('[data-t]').forEach(function(el) {
        var key = el.getAttribute('data-t');
        if (key) el.textContent = key;
      });
    }
    var langBtn = document.getElementById('langToggleBtn');
    if (langBtn) {
      langBtn.addEventListener('click', function() {
        currentLang = (currentLang === 'en') ? 'fr' : 'en';
        _store.setItem('cp_lang', currentLang);
        document.documentElement.lang = currentLang;
      });
    }

    // Theme toggle
    var themeBtn = document.getElementById('themeToggleBtn');
    var themeIcon = document.getElementById('themeIcon');
    function applyThemeUI() {
      if (themeIcon) themeIcon.className = dark ? 'fas fa-sun w-4 text-sm' : 'fas fa-moon w-4 text-sm';
      var lbl = document.getElementById('themeLabel');
      if (lbl) lbl.textContent = dark ? 'Light Mode' : 'Dark Mode';
    }
    applyThemeUI();
    if (themeBtn) {
      themeBtn.addEventListener('click', function() {
        dark = !dark;
        html.classList.toggle('dark', dark);
        _store.setItem('cp_theme', dark ? 'dark' : 'light');
        applyThemeUI();
      });
    }

    // Mobile sidebar
    var mobileBtn = document.getElementById('mobileSidebarBtn');
    var sidebar = document.getElementById('sidebar');
    if (mobileBtn && sidebar) {
      mobileBtn.addEventListener('click', function() {
        sidebar.classList.toggle('open');
      });
    }
  })();
  </script>

</body>
</html>
