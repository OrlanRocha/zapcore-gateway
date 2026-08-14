<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($_ENV['APP_NAME'] ?? 'ZapCore Gateway') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="/css/style.css" rel="stylesheet">
</head>
<?php
$isAuthenticated = \App\Core\Auth::check();
$usePublicShell = !empty($forcePublicLayout);
$versionFile = $_ENV['APP_VERSION_FILE'] ?? dirname(__DIR__, 4) . '/VERSION';
$appVersion = is_file($versionFile) ? trim((string) file_get_contents($versionFile)) : 'dev';
?>
<body class="<?= $isAuthenticated && !$usePublicShell ? 'app-screen' : 'login-screen' ?>">
    <?php if ($isAuthenticated && !$usePublicShell): ?>
    <?php $authUser = \App\Core\Auth::user(); ?>
    <header class="top-nav">
        <div class="d-flex align-items-center gap-4">
            <h1 class="logo-text">zc.</h1>
            <nav class="nav-links d-none d-md-flex gap-2">
                <a href="/dashboard" class="<?= strpos($_SERVER['REQUEST_URI'], '/dashboard') === 0 ? 'active' : '' ?>">Dashboard</a>
                <a href="/instances" class="<?= strpos($_SERVER['REQUEST_URI'], '/instances') === 0 ? 'active' : '' ?>">Instancias</a>
                <?php if ($authUser && $authUser->role === 'admin'): ?>
                    <a href="/users" class="<?= strpos($_SERVER['REQUEST_URI'], '/users') === 0 ? 'active' : '' ?>">Usuarios</a>
                <?php endif; ?>
            </nav>
        </div>

        <div class="d-flex align-items-center gap-3">
            <div class="text-end d-none d-md-block" style="line-height:1.2;">
                <div class="fw-bold" style="font-size: 0.9rem;"><?= htmlspecialchars($authUser->name) ?></div>
                <div class="text-muted" style="font-size: 0.75rem;"><?= htmlspecialchars($authUser->email) ?> · <?= htmlspecialchars($authUser->role) ?> · v<?= htmlspecialchars($appVersion) ?></div>
            </div>
            <a href="/profile" class="btn btn-light rounded-circle shadow-sm" title="Meu perfil" style="width: 40px; height: 40px; display: flex; align-items: center; justify-content: center;">
                <i class="fas fa-user text-dark"></i>
            </a>
            <a href="/logout" class="btn btn-light rounded-circle shadow-sm" style="width: 40px; height: 40px; display: flex; align-items: center; justify-content: center;">
                <i class="fas fa-sign-out-alt text-danger"></i>
            </a>
        </div>
    </header>
    <?php endif; ?>

    <main class="container-fluid px-4 pb-5">
        <?php include __DIR__ . "/../$view.php"; ?>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <?php if (isset($scripts)) echo $scripts; ?>
</body>
</html>
