<?php
// Hamburger menülü navigasyon. Önce sayfada $nav_current tanımlanmalı (opsiyonel): '', 'kitaplar', 'raflar', 'okumalar', 'ayarlar'
if (!isset($nav_current)) $nav_current = '';
$logout_url = (basename($_SERVER['PHP_SELF']) == 'index.php') ? 'index.php?logout=1' : 'logout.php';
$user_name = isset($_SESSION['ad_soyad']) ? $_SESSION['ad_soyad'] : '';
?>
<style>
.navbar {
    background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
    padding: 0.75rem 1.25rem 1rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06), 0 1px 0 rgba(0,0,0,0.04);
    border-bottom: 1px solid rgba(0,0,0,0.06);
}
.nav-left { display: flex; flex-direction: row; align-items: center; gap: 0.75rem; }
.nav-brand { margin: 0; font-size: 1.35rem; font-weight: 700; letter-spacing: -0.02em; }
.nav-brand a { color: #1e40af; text-decoration: none; transition: color 0.2s ease; }
.nav-brand a:hover { color: #2563eb; }
.nav-hamburger {
    display: flex; align-items: center; justify-content: center;
    width: 40px; height: 36px; padding: 0; border: 1px solid #e2e8f0; border-radius: 8px;
    background: #fff; color: #475569; cursor: pointer; font-size: 1.25rem; line-height: 1;
    transition: background 0.2s, color 0.2s;
}
.nav-hamburger:hover { background: #f1f5f9; color: #1e293b; }
.nav-hamburger:focus { outline: none; box-shadow: 0 0 0 2px #2563eb; }
.nav-drawer-overlay {
    display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.3); z-index: 999;
    opacity: 0; transition: opacity 0.2s ease;
}
.nav-drawer-overlay.open { display: block; opacity: 1; }
.nav-drawer {
    position: fixed; top: 0; left: 0; width: 280px; max-width: 85vw; height: 100%;
    background: #fff; box-shadow: 4px 0 20px rgba(0,0,0,0.12); z-index: 1000;
    transform: translateX(-100%); transition: transform 0.25s ease;
    overflow-y: auto; padding: 1.25rem 0;
}
.nav-drawer.open { transform: translateX(0); }
.nav-drawer .nav-link, .nav-drawer .nav-current, .nav-drawer .nav-sep, .nav-drawer .nav-user {
    display: block; padding: 0.65rem 1.25rem; font-size: 0.95rem; text-decoration: none;
    color: #334155; transition: background 0.15s, color 0.15s; border: none; width: 100%; text-align: left;
    font-family: inherit; cursor: pointer; background: none;
}
.nav-drawer .nav-link:hover { background: #f1f5f9; color: #1e40af; }
.nav-drawer .nav-link.nav-btn-primary { background: #2563eb; color: #fff !important; }
.nav-drawer .nav-link.nav-btn-primary:hover { background: #1d4ed8; }
.nav-drawer .nav-link.nav-btn-secondary { background: #10b981; color: #fff !important; }
.nav-drawer .nav-link.nav-btn-secondary:hover { background: #059669; }
.nav-drawer .nav-current { color: #1e293b; background: #e2e8f0; font-weight: 500; cursor: default; }
.nav-drawer .nav-sep { border-top: 1px solid #e2e8f0; margin: 0.5rem 0; padding: 0.5rem 1.25rem 0; height: 0; overflow: hidden; }
.nav-drawer .nav-user { color: #64748b; font-size: 0.9rem; cursor: default; }
.nav-drawer .nav-link.btn-logout { color: #dc2626; }
.nav-drawer .nav-link.btn-logout:hover { background: #fef2f2; color: #b91c1c; }
</style>
<nav class="navbar">
    <div class="nav-left">
        <h1 class="nav-brand"><a href="index.php">Reading App</a></h1>
        <button type="button" class="nav-hamburger" id="navHamburger" aria-label="Menüyü aç">☰</button>
    </div>
</nav>
<div class="nav-drawer-overlay" id="navOverlay"></div>
<div class="nav-drawer" id="navDrawer">
    <a href="kitap.php" class="nav-link nav-btn-primary">+Kitap</a>
    <a href="raf.php" class="nav-link nav-btn-secondary">+Raf</a>
    <?php if ($nav_current === 'kitaplar'): ?><span class="nav-current">📚 Kitaplar</span><?php else: ?><a href="kitaplar.php" class="nav-link">📚 Kitaplar</a><?php endif; ?>
    <?php if ($nav_current === 'raflar'): ?><span class="nav-current">📂 Raflar</span><?php else: ?><a href="raflar.php" class="nav-link">📂 Raflar</a><?php endif; ?>
    <?php if ($nav_current === 'okumalar'): ?><span class="nav-current">📖 Okumalar</span><?php else: ?><a href="okumalar.php" class="nav-link">📖 Okumalar</a><?php endif; ?>
    <span class="nav-sep"></span>
    <span class="nav-user"><?= htmlspecialchars($user_name) ?></span>
    <?php if ($nav_current === 'ayarlar'): ?><span class="nav-current">⚙️ Ayarlar</span><?php else: ?><a href="ayarlar.php" class="nav-link">⚙️ Ayarlar</a><?php endif; ?>
    <a href="<?= htmlspecialchars($logout_url) ?>" class="nav-link btn-logout">🚪 Çıkış</a>
</div>
<script>
(function(){
    var btn = document.getElementById('navHamburger');
    var drawer = document.getElementById('navDrawer');
    var overlay = document.getElementById('navOverlay');
    if (!btn || !drawer) return;
    function open() { drawer.classList.add('open'); overlay.classList.add('open'); }
    function close() { drawer.classList.remove('open'); overlay.classList.remove('open'); }
    function toggle() { drawer.classList.contains('open') ? close() : open(); }
    btn.addEventListener('click', toggle);
    overlay.addEventListener('click', close);
    drawer.querySelectorAll('a').forEach(function(a) { a.addEventListener('click', close); });
})();
</script>
