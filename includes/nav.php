<?php
// includes/nav.php — role-specific sidebar navigation
$role = $user['role'];
$ini  = initials($user['full_name']);
$nav  = match($role) {
    'Admin'   => [
        ['section'=>'Main'],
        ['href'=>'dashboard.php',       'icon'=>'bi-grid-1x2',        'label'=>'Dashboard',       'page'=>'dashboard'],
        ['section'=>'Organisation'],
        ['href'=>'users.php',           'icon'=>'bi-people',           'label'=>'User Management', 'page'=>'users'],
        ['href'=>'team_management.php', 'icon'=>'bi-diagram-3',        'label'=>'Team Management', 'page'=>'team_management'],
        ['section'=>'Account'],
        ['href'=>'profile.php',         'icon'=>'bi-person-circle',    'label'=>'My Profile',      'page'=>'profile'],
        ['href'=>'logout.php',          'icon'=>'bi-box-arrow-right',  'label'=>'Sign Out',        'page'=>'', 'danger'=>true],
    ],
    'Manager' => [
        ['section'=>'Main'],
        ['href'=>'dashboard.php',  'icon'=>'bi-grid-1x2',        'label'=>'Dashboard',        'page'=>'dashboard'],
        ['section'=>'OKR'],
        ['href'=>'objectives.php', 'icon'=>'bi-bullseye',         'label'=>'Objectives',       'page'=>'objectives'],
        ['href'=>'key_results.php','icon'=>'bi-check2-circle',    'label'=>'Key Results',      'page'=>'key_results'],
        ['href'=>'progress.php',   'icon'=>'bi-graph-up',         'label'=>'Progress Tracker', 'page'=>'progress'],
        ['section'=>'Team'],
        ['href'=>'team.php',       'icon'=>'bi-person-workspace', 'label'=>'Team Overview',    'page'=>'team'],
        ['section'=>'Account'],
        ['href'=>'profile.php',    'icon'=>'bi-person-circle',    'label'=>'My Profile',       'page'=>'profile'],
        ['href'=>'logout.php',     'icon'=>'bi-box-arrow-right',  'label'=>'Sign Out',         'page'=>'', 'danger'=>true],
    ],
    default   => [
        ['section'=>'Main'],
        ['href'=>'dashboard.php',  'icon'=>'bi-grid-1x2',        'label'=>'Dashboard',        'page'=>'dashboard'],
        ['section'=>'OKR'],
        ['href'=>'objectives.php', 'icon'=>'bi-bullseye',         'label'=>'Objectives',       'page'=>'objectives'],
        ['href'=>'key_results.php','icon'=>'bi-check2-circle',    'label'=>'Key Results',      'page'=>'key_results'],
        ['href'=>'progress.php',   'icon'=>'bi-graph-up',         'label'=>'Progress Tracker', 'page'=>'progress'],
        ['section'=>'Account'],
        ['href'=>'profile.php',    'icon'=>'bi-person-circle',    'label'=>'My Profile',       'page'=>'profile'],
        ['href'=>'logout.php',     'icon'=>'bi-box-arrow-right',  'label'=>'Sign Out',         'page'=>'', 'danger'=>true],
    ],
};
?>
<aside class="sidebar" id="sidebar">
  <div class="sidebar-brand">
    <div class="brand-icon">
      <svg width="22" height="22" fill="none" stroke="white" stroke-width="2.2" viewBox="0 0 24 24">
        <path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/>
      </svg>
    </div>
    <div class="brand-text">
      <div class="name">ONOW Enable</div>
      <div class="sub">OKR System</div>
    </div>
  </div>
  <nav class="sidebar-nav">
    <?php foreach ($nav as $item): ?>
      <?php if (isset($item['section'])): ?>
        <div class="nav-section-label"><?= $item['section'] ?></div>
      <?php else: ?>
        <div class="nav-item">
          <a href="<?= $item['href'] ?>" class="nav-link <?= $currentPage===$item['page']?'active':'' ?> <?= !empty($item['danger'])?'text-danger':'' ?>">
            <i class="bi <?= $item['icon'] ?>"></i> <?= $item['label'] ?>
          </a>
        </div>
      <?php endif; ?>
    <?php endforeach; ?>
  </nav>
  <div class="sidebar-footer">
    <a href="profile.php" class="user-card">
      <div class="user-avatar" style="background:<?= htmlspecialchars($user['avatar_color']) ?>"><?= $ini ?></div>
      <div class="user-info">
        <div class="uname"><?= htmlspecialchars($user['full_name']) ?></div>
        <div class="urole"><?= $user['role'] ?></div>
      </div>
      <i class="bi bi-chevron-right ms-auto text-muted small"></i>
    </a>
  </div>
</aside>
