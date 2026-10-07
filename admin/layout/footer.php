    </div><!-- End Page Content -->

    <!-- Footer -->
    <footer class="adm-footer border-t border-gray-200 dark:border-gray-700 mt-auto">
        <div class="px-6 py-4 flex flex-col sm:flex-row items-center justify-between gap-2 text-xs text-gray-500 dark:text-gray-400">
            <div class="flex items-center gap-2">
                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                <span>DREAMBD Admin Panel <span class="font-bold text-gray-700 dark:text-gray-300">v2.1</span></span>
            </div>
            <div class="font-mono">
                <?php
                $footerDb = class_exists('DatabaseConfig') ? DatabaseConfig::getName() : 'dream';
                $footerHost = class_exists('DatabaseConfig') ? DatabaseConfig::getHost() : 'localhost';
                echo htmlspecialchars((string) $footerDb . ' @ ' . (string) $footerHost);
                ?>
            </div>
        </div>
    </footer>
</div><!-- End Main Content -->

<script>
// Wait for DOM to be ready
document.addEventListener('DOMContentLoaded', function() {

    // ============ Live Clock ============
    function updateClock() {
        const now = new Date();
        const timeStr = now.toLocaleTimeString('en-US', { hour12: false });
        const dateStr = now.toLocaleDateString('en-US', { year: 'numeric', month: '2-digit', day: '2-digit' });
        const clockEl = document.getElementById('liveClock');
        const dateEl = document.getElementById('liveDate');
        if (clockEl) clockEl.textContent = timeStr;
        if (dateEl) dateEl.textContent = dateStr;
    }
    setInterval(updateClock, 1000);
    updateClock();

    // ============ Sidebar Toggle ============
    window.toggleSidebar = function() {
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        const navbar = document.getElementById('topNavbar');
        const toggleIcon = document.getElementById('sidebarToggleIcon');

        if (!sidebar) return;

        sidebar.classList.toggle('collapsed');
        if (sidebar.classList.contains('collapsed')) {
            if (mainContent) {
                mainContent.classList.remove('ml-64');
                mainContent.classList.add('ml-20');
            }
            if (navbar) {
                navbar.classList.remove('left-64');
                navbar.classList.add('left-20');
            }
            if (toggleIcon) {
                toggleIcon.classList.remove('fa-chevron-left');
                toggleIcon.classList.add('fa-chevron-right');
            }
        } else {
            if (mainContent) {
                mainContent.classList.add('ml-64');
                mainContent.classList.remove('ml-20');
            }
            if (navbar) {
                navbar.classList.add('left-64');
                navbar.classList.remove('left-20');
            }
            if (toggleIcon) {
                toggleIcon.classList.add('fa-chevron-left');
                toggleIcon.classList.remove('fa-chevron-right');
            }
        }
    };

    // ============ Mobile Sidebar ============
    window.toggleMobileSidebar = function() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        if (!sidebar) return;
        const open = sidebar.classList.toggle('mobile-open');
        if (overlay) overlay.classList.toggle('show', open);
    };
    window.closeMobileSidebar = function() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        if (sidebar) sidebar.classList.remove('mobile-open');
        if (overlay) overlay.classList.remove('show');
    };

    // ============ Dark Mode Toggle ============
    window.toggleDarkMode = function() {
        const html = document.documentElement;
        const icon = document.getElementById('darkModeIcon');

        if (html.classList.contains('dark')) {
            html.classList.remove('dark');
            localStorage.setItem('dreambd-theme', 'light');
            if (icon) {
                icon.classList.remove('fa-sun');
                icon.classList.add('fa-moon');
            }
        } else {
            html.classList.add('dark');
            localStorage.setItem('dreambd-theme', 'dark');
            if (icon) {
                icon.classList.remove('fa-moon');
                icon.classList.add('fa-sun');
            }
        }
    };

    // ============ Initialize Dark Mode ============
    (function initDarkMode() {
        const html = document.documentElement;
        const icon = document.getElementById('darkModeIcon');
        const storedTheme = localStorage.getItem('dreambd-theme');

        if (storedTheme === 'dark' || (!storedTheme && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            html.classList.add('dark');
            if (icon) {
                icon.classList.remove('fa-moon');
                icon.classList.add('fa-sun');
            }
        }
    })();

    // ============ User Dropdown Toggle ============
    window.toggleUserMenu = function(e) {
        if (e) e.stopPropagation();
        const dropdown = document.getElementById('userDropdown');
        const notif = document.getElementById('notifDropdown');
        if (notif) notif.classList.add('hidden');
        if (dropdown) {
            dropdown.classList.toggle('hidden');
        }
    };

    // ============ Notifications Dropdown ============
    window.toggleNotifications = function(e) {
        if (e) e.stopPropagation();
        const notif = document.getElementById('notifDropdown');
        const user = document.getElementById('userDropdown');
        if (user) user.classList.add('hidden');
        if (notif) notif.classList.toggle('hidden');
    };

    // Close dropdowns when clicking outside
    document.addEventListener('click', function(e) {
        const dropdown = document.getElementById('userDropdown');
        if (dropdown && !dropdown.contains(e.target) && !e.target.closest('[onclick*="toggleUserMenu"]')) {
            dropdown.classList.add('hidden');
        }
        const notif = document.getElementById('notifDropdown');
        if (notif && !notif.contains(e.target) && !e.target.closest('[onclick*="toggleNotifications"]')) {
            notif.classList.add('hidden');
        }
    });

    // Close mobile sidebar on Escape
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') window.closeMobileSidebar();
    });

    // ============ Auto-hide Alerts ============
    setTimeout(function() {
        document.querySelectorAll('.fade-in').forEach(function(el) {
            if (el.querySelector('.fa-check-circle') || el.querySelector('.fa-exclamation-circle')) {
                el.style.transition = 'opacity 0.5s ease';
                el.style.opacity = '0';
                setTimeout(function() { el.remove(); }, 500);
            }
        });
    }, 5000);

});
</script>
</body>
</html>
