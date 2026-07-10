<?php
/* Admin Topbar partial — include inside .admin-main */
// NOTE: emitCspHeader() must be called BEFORE any HTML output on each page.
// It cannot be called here because output has already started by the time
// this partial is included. Each admin page calls emitCspHeader() at the top.
// Guarded because sidebar.php (included before this partial) already emits
// HTML, so headers are typically already sent by the time we get here.
if (!headers_sent()) {
    header('X-Robots-Tag: noindex, nofollow');
}
$adminName = htmlspecialchars($_SESSION['admin_full_name'] ?? $_SESSION['admin_username'] ?? 'Admin');
?>
<div class="admin-topbar">
  <h5><?= $topbarTitle ?? 'Admin Panel' ?></h5>
  <div class="topbar-right">
    <button id="themeToggleBtn" class="theme-toggle">
      <span class="toggle-icon"><i class="fa-solid fa-sun"></i></span> Light
    </button>
    <div class="admin-info"><i class="fa-solid fa-user-gear"></i> <?= $adminName ?> &mdash; <?= date('d M Y') ?></div>
  </div>
</div>
