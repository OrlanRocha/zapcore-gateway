<?php
$isAuthenticated = \App\Core\Auth::check();
$usePublicShell = !empty($forcePublicLayout);
$appName = $_ENV['APP_NAME'] ?? 'ZapCore Gateway';
$appUrl = rtrim($_ENV['APP_URL'] ?? 'http://localhost:8080', '/');
$metaTitle = $pageTitle ?? $appName;
$metaDescription = $pageDescription ?? 'Gerencie instancias WhatsApp, mensagens, filas e webhooks em um painel direto e seguro.';
$metaImage = $appUrl . '/images/zapcore-social.png';
$canonicalUrl = $appUrl . parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$versionFile = $_ENV['APP_VERSION_FILE'] ?? dirname(__DIR__, 4) . '/VERSION';
$appVersion = is_file($versionFile) ? trim((string) file_get_contents($versionFile)) : 'dev';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="application-name" content="<?= htmlspecialchars($appName) ?>">
    <meta name="description" content="<?= htmlspecialchars($metaDescription) ?>">
    <meta name="theme-color" content="#111827">
    <meta name="color-scheme" content="light">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="ZapCore">
    <meta name="format-detection" content="telephone=no">
    <meta name="robots" content="<?= $isAuthenticated ? 'noindex, nofollow' : 'index, follow' ?>">

    <meta property="og:locale" content="pt_BR">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="<?= htmlspecialchars($appName) ?>">
    <meta property="og:title" content="<?= htmlspecialchars($metaTitle) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($metaDescription) ?>">
    <meta property="og:url" content="<?= htmlspecialchars($canonicalUrl) ?>">
    <meta property="og:image" content="<?= htmlspecialchars($metaImage) ?>">
    <meta property="og:image:secure_url" content="<?= htmlspecialchars($metaImage) ?>">
    <meta property="og:image:type" content="image/png">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="ZapCore Gateway, integracao WhatsApp para operacoes">

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= htmlspecialchars($metaTitle) ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($metaDescription) ?>">
    <meta name="twitter:image" content="<?= htmlspecialchars($metaImage) ?>">
    <meta name="twitter:image:alt" content="ZapCore Gateway, integracao WhatsApp para operacoes">

    <title><?= htmlspecialchars($metaTitle) ?></title>
    <link rel="canonical" href="<?= htmlspecialchars($canonicalUrl) ?>">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" type="image/svg+xml" href="/images/zapcore-icon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="/images/favicon-32.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/images/apple-touch-icon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="/css/style.css" rel="stylesheet">
</head>
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
    <script src="/js/pwa.js" defer></script>
    <?php if (isset($scripts)) echo $scripts; ?>
</body>
</html>
