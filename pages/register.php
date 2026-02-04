<?php
session_start();
require_once '../includes/db.php';

$pdo = getDBConnection();
$errors = [];
$success = '';

// Fetch roles for dropdown
$roles = [];
try {
    $stmt = $pdo->query("SELECT role_id, role_name FROM system_roles ORDER BY role_id");
    $roles = $stmt->fetchAll();
} catch(PDOException $e) {
    error_log("Roles fetch error: " . $e->getMessage());
    $errors[] = "Unable to load roles. Please try again later.";
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Sanitize and validate inputs
    $role_id = filter_input(INPUT_POST, 'role_id', FILTER_VALIDATE_INT);
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // Validation
    if (!$role_id || $role_id < 1 || $role_id > 2) {
        $errors[] = "Please select a valid role.";
    }
    
    if (empty($username)) {
        $errors[] = "Username is required.";
    } elseif (strlen($username) < 3 || strlen($username) > 20) {
        $errors[] = "Username must be between 3 and 20 characters.";
    } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        $errors[] = "Username can only contain letters, numbers, and underscores.";
    }
    
    if (empty($email)) {
        $errors[] = "Email is required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid email address.";
    } elseif (strlen($email) > 50) {
        $errors[] = "Email must be less than 50 characters.";
    }
    
    if (empty($password)) {
        $errors[] = "Password is required.";
    } elseif (strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters long.";
    } elseif (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        $errors[] = "Password must contain at least one uppercase letter, one lowercase letter, and one number.";
    }
    
    if ($password !== $confirm_password) {
        $errors[] = "Passwords do not match.";
    }
    
    // Check if username or email already exists
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM system_users WHERE username = ? OR email = ?");
            $stmt->execute([$username, $email]);
            $count = $stmt->fetchColumn();
            
            if ($count > 0) {
                $stmt = $pdo->prepare("SELECT username FROM system_users WHERE username = ?");
                $stmt->execute([$username]);
                if ($stmt->fetch()) {
                    $errors[] = "Username already exists.";
                }
                
                $stmt = $pdo->prepare("SELECT email FROM system_users WHERE email = ?");
                $stmt->execute([$email]);
                if ($stmt->fetch()) {
                    $errors[] = "Email already exists.";
                }
            }
        } catch(PDOException $e) {
            error_log("Duplicate check error: " . $e->getMessage());
            $errors[] = "An error occurred. Please try again.";
        }
    }
    
    // If no errors, insert user
    if (empty($errors)) {
        try {
            // Hash the password
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
            // Insert user
            $stmt = $pdo->prepare("INSERT INTO system_users (role_id, username, email, user_password) 
                                   VALUES (?, ?, ?, ?)");
            $stmt->execute([$role_id, $username, $email, $hashed_password]);
            
            $user_id = $pdo->lastInsertId();
            
            // Automatically log in the user after registration
            $_SESSION['user_id'] = $user_id;
            $_SESSION['username'] = $username;
            $_SESSION['email'] = $email;
            
            // Redirect to dashboard
            header("Location: ../index.php?registered=1");
            exit();
            
        } catch(PDOException $e) {
            error_log("Registration error: " . $e->getMessage());
            $errors[] = "Registration failed. Please try again later.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Quiz System</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        
        .register-container {
            background: white;
            padding: 40px;
            border-radius: 15px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.2);
            width: 100%;
            max-width: 500px;
            animation: slideIn 0.5s ease-out;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .logo {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .logo h1 {
            color: #667eea;
            font-size: 28px;
            margin-bottom: 5px;
        }
        
        .logo p {
            color: #666;
            font-size: 14px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #555;
            font-weight: 600;
            font-size: 14px;
        }
        
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 15px;
            transition: all 0.3s;
        }
        
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .error-messages {
            background-color: #ffebee;
            border: 1px solid #ffcdd2;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .error-messages ul {
            list-style: none;
            margin: 0;
            padding: 0;
        }
        
        .error-messages li {
            color: #c62828;
            font-size: 14px;
            margin-bottom: 8px;
            padding-left: 20px;
            position: relative;
        }
        
        .error-messages li:before {
            content: "⚠️";
            position: absolute;
            left: 0;
            top: 0;
        }
        
        .error-messages li:last-child {
            margin-bottom: 0;
        }
        
        .success-message {
            background-color: #e8f5e9;
            border: 1px solid #c8e6c9;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            color: #2e7d32;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .success-message:before {
            content: "✅";
            font-size: 16px;
        }
        
        .register-btn {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
        }
        
        .register-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
        }
        
        .register-btn:active {
            transform: translateY(0);
        }
        
        .register-btn:before {
            content: "📝";
        }
        
        .login-link {
            text-align: center;
            margin-top: 25px;
            padding-top: 25px;
            border-top: 1px solid #eee;
        }
        
        .login-link p {
            color: #666;
            font-size: 14px;
        }
        
        .login-link a {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s;
        }
        
        .login-link a:hover {
            color: #5a67d8;
            text-decoration: underline;
        }
        
        .password-requirements {
            background-color: #f5f5f5;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 15px;
            margin-top: 10px;
            font-size: 12px;
            color: #666;
        }
        
        .password-requirements h4 {
            margin-bottom: 8px;
            color: #555;
        }
        
        .password-requirements ul {
            list-style: none;
            margin: 0;
            padding: 0;
        }
        
        .password-requirements li {
            margin-bottom: 5px;
            padding-left: 20px;
            position: relative;
        }
        
        .password-requirements li:before {
            content: "•";
            position: absolute;
            left: 10px;
            color: #667eea;
        }
        
        .password-strength {
            margin-top: 10px;
            height: 5px;
            border-radius: 5px;
            background-color: #eee;
            overflow: hidden;
        }
        
        .password-strength-bar {
            height: 100%;
            width: 0%;
            transition: all 0.3s;
        }
        
        @media (max-width: 480px) {
            .register-container {
                padding: 30px 20px;
            }
        }
    </style>
</head>
<body>
    <div class="register-container">
        <div class="logo">
            <h1>Create Account</h1>
            <p>Join our quiz community</p>
        </div>
        
        <?php if (!empty($errors)): ?>
            <div class="error-messages">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="success-message"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        
        <form method="POST" action="" id="registerForm">
            
            <input type="hidden" name="role_id" value="2">
            
            <div class="form-group">
                <label for="username">Username *</label>
                <input type="text" id="username" name="username" required
                       placeholder="Choose a username (3-20 characters)"
                       minlength="3" maxlength="20"
                       value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
            </div>
            
            <div class="form-group">
                <label for="email">Email *</label>
                <input type="email" id="email" name="email" required
                       placeholder="Enter your email"
                       maxlength="50"
                       value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
            </div>
            
            <div class="form-group">
                <label for="password">Password *</label>
                <input type="password" id="password" name="password" required
                       placeholder="Create a strong password"
                       minlength="8">
                
                <div class="password-strength" id="passwordStrength">
                    <div class="password-strength-bar" id="passwordStrengthBar"></div>
                </div>
                
                <div class="password-requirements">
                    <h4>Password Requirements:</h4>
                    <ul>
                        <li>At least 8 characters long</li>
                        <li>Contains uppercase letters (A-Z)</li>
                        <li>Contains lowercase letters (a-z)</li>
                        <li>Contains numbers (0-9)</li>
                    </ul>
                </div>
            </div>
            
            <div class="form-group">
                <label for="confirm_password">Confirm Password *</label>
                <input type="password" id="confirm_password" name="confirm_password" required
                       placeholder="Confirm your password">
            </div>
            
            <button type="submit" class="register-btn" id="registerBtn">Create Account</button>
        </form>
        
        <div class="login-link">
            <p>Already have an account? <a href="login.php">Login here</a></p>
        </div>
    </div>

    <script>
        // Password strength indicator
        const passwordInput = document.getElementById('password');
        const passwordStrengthBar = document.getElementById('passwordStrengthBar');
        const confirmPasswordInput = document.getElementById('confirm_password');
        const registerBtn = document.getElementById('registerBtn');
        
        passwordInput.addEventListener('input', function() {
            const password = this.value;
            let strength = 0;
            
            // Length check
            if (password.length >= 8) strength += 25;
            if (password.length >= 12) strength += 10;
            
            // Character variety checks
            if (/[A-Z]/.test(password)) strength += 20;
            if (/[a-z]/.test(password)) strength += 20;
            if (/[0-9]/.test(password)) strength += 20;
            if (/[^A-Za-z0-9]/.test(password)) strength += 15;
            
            // Clamp strength to 100%
            strength = Math.min(strength, 100);
            
            // Update strength bar
            passwordStrengthBar.style.width = strength + '%';
            
            // Change color based on strength
            if (strength < 40) {
                passwordStrengthBar.style.backgroundColor = '#ff4444';
            } else if (strength < 70) {
                passwordStrengthBar.style.backgroundColor = '#ffbb33';
            } else {
                passwordStrengthBar.style.backgroundColor = '#00C851';
            }
        });
        
        // Password confirmation check
        function validatePasswordMatch() {
            const password = passwordInput.value;
            const confirmPassword = confirmPasswordInput.value;
            
            if (confirmPassword && password !== confirmPassword) {
                confirmPasswordInput.style.borderColor = '#ff4444';
                confirmPasswordInput.style.boxShadow = '0 0 0 3px rgba(255, 68, 68, 0.1)';
                return false;
            } else {
                confirmPasswordInput.style.borderColor = passwordInput.value ? '#00C851' : '#e0e0e0';
                confirmPasswordInput.style.boxShadow = confirmPassword ? '0 0 0 3px rgba(0, 200, 81, 0.1)' : 'none';
                return true;
            }
        }
        
        confirmPasswordInput.addEventListener('input', validatePasswordMatch);
        passwordInput.addEventListener('input', validatePasswordMatch);
        
        // Form validation and submission
        document.getElementById('registerForm').addEventListener('submit', function(e) {
            // Validate password match
            if (!validatePasswordMatch()) {
                e.preventDefault();
                alert('Passwords do not match!');
                confirmPasswordInput.focus();
                return false;
            }
            
            // Validate password strength
            const password = passwordInput.value;
            const hasUpperCase = /[A-Z]/.test(password);
            const hasLowerCase = /[a-z]/.test(password);
            const hasNumbers = /\d/.test(password);
            
            if (password.length < 8 || !hasUpperCase || !hasLowerCase || !hasNumbers) {
                e.preventDefault();
                alert('Password does not meet the requirements. Please check the password rules.');
                passwordInput.focus();
                return false;
            }
            
            // Change button to loading state
            registerBtn.innerHTML = 'Creating Account...';
            registerBtn.disabled = true;
            
            return true;
        });
        
        // Auto-focus on first field
        document.getElementById('username').focus();
        
        // Show/hide password toggle
        const passwordGroup = passwordInput.parentElement;
        const passwordToggle = document.createElement('span');
        passwordToggle.style.cssText = `
            position: absolute;
            right: 15px;
            top: 40px;
            cursor: pointer;
            font-size: 18px;
            user-select: none;
            z-index: 2;
        `;
        passwordToggle.textContent = '👁️';
        passwordGroup.style.position = 'relative';
        
        passwordToggle.addEventListener('click', function() {
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                this.textContent = '👁️‍🗨️';
            } else {
                passwordInput.type = 'password';
                this.textContent = '👁️';
            }
        });
        
        passwordGroup.appendChild(passwordToggle);
        
        // Add toggle for confirm password
        const confirmToggle = passwordToggle.cloneNode(true);
        confirmToggle.style.top = '40px';
        confirmToggle.addEventListener('click', function() {
            if (confirmPasswordInput.type === 'password') {
                confirmPasswordInput.type = 'text';
                this.textContent = '👁️‍🗨️';
            } else {
                confirmPasswordInput.type = 'password';
                this.textContent = '👁️';
            }
        });
        confirmPasswordInput.parentElement.style.position = 'relative';
        confirmPasswordInput.parentElement.appendChild(confirmToggle);
    </script>
</body>
</html>