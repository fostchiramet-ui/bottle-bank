<?php
session_start();
require_once '../db/db.php';
require_once 'google-config.php';

function redirect_to_dashboard($user) {
    // Update Last Activity & Log Login History
    $pdo = Database::connect();
    $pdo->prepare("UPDATE users SET last_activity = NOW() WHERE id = ?")->execute([$user['id']]);
    $log_stmt = $pdo->prepare("INSERT INTO login_logs (user_id, ip_address, user_agent) VALUES (?, ?, ?)");
    $log_stmt->execute([$user['id'], $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']]);

    // Role-Based Redirection
    switch ($user['role']) {
        case 'superadmin':
            header("Location: ../superadmin/superadmin.php");
            break;
        case 'admin':
            header("Location: ../admin/admin.php");
            break;
        case 'user':
            header("Location: ../student/user.php");
            break;
        default:
            header("Location: ../login.php");
            break;
    }
    exit();
}

if (isset($_GET['code'])) {
    try {
        $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);
        if (isset($token['error'])) {
            throw new Exception($token['error_description']);
        }
        $client->setAccessToken($token['access_token']);

        $google_oauth = new Google_Service_Oauth2($client);
        $google_account_info = $google_oauth->userinfo->get();

        $google_id = $google_account_info->id;
        $email = $google_account_info->email;
        $first_name = $google_account_info->givenName;
        $last_name = $google_account_info->familyName;

        $pdo = Database::connect();

        // 1. Check if user already exists with this Google ID
        $stmt = $pdo->prepare("SELECT * FROM users WHERE google_id = ? LIMIT 1");
        $stmt->execute([$google_id]);
        $user = $stmt->fetch();

        if ($user) {
            // User found, log them in
            if ($user['is_active'] == 0) {
                $_SESSION['error'] = "บัญชีนี้ถูกระงับการใช้งาน กรุณาติดต่อผู้ดูแลระบบ";
                header('Location: ../login.php');
                exit();
            }
        } else {
            // 2. If not, check if an account with this email already exists
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user) {
                // Email exists, link this Google ID to the existing account
                $update_stmt = $pdo->prepare("UPDATE users SET google_id = ?, first_name = ?, last_name = ? WHERE id = ?");
                $update_stmt->execute([$google_id, $first_name, $last_name, $user['id']]);
            } else {
                // 3. New user, create a new account
                $insert_stmt = $pdo->prepare(
                    "INSERT INTO users (username, email, google_id, first_name, last_name, role, is_active) 
                     VALUES (?, ?, ?, ?, ?, 'user', 1)"
                );
                // Use email as username for Google sign-ups
                $insert_stmt->execute([$email, $email, $google_id, $first_name, $last_name]);
                $user_id = $pdo->lastInsertId();

                // Fetch the newly created user data
                $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
                $stmt->execute([$user_id]);
                $user = $stmt->fetch();
            }
        }

        // Create session for the user
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['first_name'] = $user['first_name'];
        $_SESSION['last_name'] = $user['last_name'];
        $_SESSION['name'] = $user['first_name'] . ' ' . $user['last_name'];
        $_SESSION['role'] = $user['role'];

        redirect_to_dashboard($user);

    } catch (Exception $e) {
        die("เกิดข้อผิดพลาดในการเชื่อมต่อกับ Google: " . $e->getMessage());
    }
} else {
    // Redirect to Google's consent screen
    $auth_url = $client->createAuthUrl();
    header('Location: ' . filter_var($auth_url, FILTER_SANITIZE_URL));
    exit();
}
                
