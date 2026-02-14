<?php
// Yapılandırma ve veritabanı bağlantısını dahil et
require_once 'config/config.php';

// Eğer kullanıcı zaten giriş yapmışsa direkt ana sayfaya yönlendir
if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$hata_mesaji = '';

// Form gönderildiyse işlemleri yap
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($username) || empty($password)) {
        $hata_mesaji = "Lütfen kullanıcı adı ve şifrenizi girin.";
    } else {
        try {
            // Veritabanından kullanıcıyı bul
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :username LIMIT 1");
            $stmt->execute(['username' => $username]);
            $user = $stmt->fetch();

            // Kullanıcı varsa ve şifre (hash ile) eşleşiyorsa
            if ($user && password_verify($password, $user['pass'])) {
                
                // Session değişkenlerini ata
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['ad_soyad'] = $user['ad_soyad'];
                $_SESSION['admin'] = $user['admin'];
                
                // Ana sayfaya yönlendir
                header("Location: index.php");
                exit;
            } else {
                $hata_mesaji = "Kullanıcı adı veya şifre hatalı!";
            }
        } catch (PDOException $e) {
            $hata_mesaji = "Sistem hatası: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Giriş Yap - Reading App</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f3f4f6;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }
        .login-container {
            background-color: white;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 400px;
        }
        .login-container h2 {
            text-align: center;
            color: #1f2937;
            margin-bottom: 1.5rem;
        }
        .form-group {
            margin-bottom: 1rem;
        }
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: #4b5563;
        }
        .form-group input {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            box-sizing: border-box;
            font-size: 1rem;
        }
        .btn-login {
            width: 100%;
            padding: 0.75rem;
            background-color: #3b82f6;
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 1rem;
            cursor: pointer;
            transition: background-color 0.2s;
            margin-top: 1rem;
        }
        .btn-login:hover {
            background-color: #2563eb;
        }
        .error-message {
            background-color: #fee2e2;
            color: #b91c1c;
            padding: 0.75rem;
            border-radius: 4px;
            margin-bottom: 1rem;
            text-align: center;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>

<div class="login-container">
    <h2>Reading App</h2>
    
    <?php if (!empty($hata_mesaji)): ?>
        <div class="error-message">
            <?= htmlspecialchars($hata_mesaji) ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="login.php">
        <div class="form-group">
            <label for="username">Kullanıcı Adı</label>
            <input type="text" id="username" name="username" required autocomplete="username" autofocus>
        </div>
        
        <div class="form-group">
            <label for="password">Şifre</label>
            <input type="password" id="password" name="password" required autocomplete="current-password">
        </div>
        
        <button type="submit" class="btn-login">Giriş Yap</button>
    </form>
</div>

</body>
</html>