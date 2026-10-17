<?php
 declare(strict_types=1);
 $pageTitle = (string) ($pageTitle ?? 'HEX CHEATS SHOP');
 $activePage = (string) ($activePage ?? '');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#16834c">
  <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
</head>
<body>
<div class="wrap hx-page-wrap">
  <?php require __DIR__ . '/_nav.php'; ?>
  <main class="hx-page-main">
