<?php
$activeNav  = $activeNav ?? 'dashboard';
$logoPath   = !empty($s['school_logo_path']) ? $s['school_logo_path'] : 'assets/img/logo.png';
$schoolName = $s['school_name'] ?? 'CDTI';
$navItems   = [
  'dashboard' => ['icon'=>'<i class="fa-solid fa-chart-line"></i>',      'label'=>'Dashboard',           'href'=> BASE_URL.'/admin/dashboard',  'perm'=>'dashboard'],
  'students'  => ['icon'=>'<i class="fa-solid fa-user-graduate"></i>',   'label'=>'Students / Placements','href'=> BASE_URL.'/admin/students',   'perm'=>'students'],
  'sms'       => ['icon'=>'<i class="fa-solid fa-message"></i>',         'label'=>'Send Bulk SMS',        'href'=> BASE_URL.'/admin/sms',        'perm'=>'sms'],
  'import'    => ['icon'=>'<i class="fa-solid fa-file-import"></i>',     'label'=>'Import Data',          'href'=> BASE_URL.'/admin/import',     'perm'=>'import'],
  'houses'    => ['icon'=>'<i class="fa-solid fa-house-chimney"></i>',   'label'=>'Houses',               'href'=> BASE_URL.'/admin/houses',     'perm'=>'houses'],
  'settings'  => ['icon'=>'<i class="fa-solid fa-sliders"></i>',         'label'=>'Settings',             'href'=> BASE_URL.'/admin/settings',   'perm'=>'settings'],
  'users'     => ['icon'=>'<i class="fa-solid fa-users-gear"></i>',      'label'=>'Manage Users',         'href'=> BASE_URL.'/admin/users',      'perm'=>'users'],
  'profile'   => ['icon'=>'<i class="fa-solid fa-user-gear"></i>',       'label'=>'My Profile',           'href'=> BASE_URL.'/admin/profile',    'perm'=>null],
];
$adminName = htmlspecialchars($_SESSION['admin_full_name'] ?? $_SESSION['admin_username'] ?? 'Admin');
$adminRole = $_SESSION['admin_role'] ?? 'staff';
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<aside class="admin-sidebar" id="adminSidebar">
  <div class="sidebar-header">
    <img src="<?= asset($logoPath) ?>" alt="Logo">
    <div>
      <div class="brand-name"><?= htmlspecialchars($schoolName) ?></div>
      <div class="brand-sub">Admin Portal</div>
    </div>
  </div>
  <nav class="sidebar-nav">
    <?php foreach ($navItems as $key => $nav):
      // null perm = always visible (e.g. profile)
      if ($nav['perm'] !== null && !hasPermission($nav['perm'])) continue;
    ?>
    <a href="<?= $nav['href'] ?>" class="<?= $activeNav === $key ? 'active' : '' ?>">
      <span class="nav-icon"><?= $nav['icon'] ?></span>
      <?= $nav['label'] ?>
      <?php if ($key === 'users' && $adminRole === 'superadmin'): ?>
        <span style="font-size:0.6rem;background:rgba(0,185,107,0.2);color:#2dc653;border:1px solid rgba(0,185,107,0.3);padding:1px 6px;border-radius:10px;margin-left:auto;">SA</span>
      <?php endif; ?>
    </a>
    <?php endforeach; ?>
  </nav>
  <div class="sidebar-footer">
    <a href="<?= BASE_URL ?>/admin/logout"><i class="fa-solid fa-right-from-bracket"></i> Logout (<?= $adminName ?>)</a>
  </div>
</aside>
