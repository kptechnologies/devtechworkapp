<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
start_session();
$v = fn($f) => $f . '?v=' . @filemtime(__DIR__ . '/' . $f);
$name = htmlspecialchars((string)cfg('app_name'), ENT_QUOTES);
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#534AB7">
<meta name="csrf" content="<?= htmlspecialchars($_SESSION['csrf']) ?>">
<title><?= $name ?></title>
<link rel="icon" href="<?= $v('assets/icon.svg') ?>" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.19.0/dist/tabler-icons.min.css">
<link rel="stylesheet" href="<?= $v('assets/app.css') ?>">
<script>try{var t=localStorage.getItem('dt-theme');if(t)document.documentElement.dataset.theme=t;}catch(e){}</script>
</head>
<body>
<div id="app"><div class="boot"><div class="spinner"></div></div></div>
<div id="overlay-root"></div>
<div id="toasts" aria-live="polite"></div>
<noscript><p style="padding:24px">This portal needs JavaScript turned on.</p></noscript>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script src="<?= $v('assets/js/core.js') ?>"></script>
<script src="<?= $v('assets/js/reports.js') ?>"></script>
<script src="<?= $v('assets/js/wallet.js') ?>"></script>
<script src="<?= $v('assets/js/admin.js') ?>"></script>
</body>
</html>
