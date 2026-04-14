<?php
/**
 * Developed by Rameez Scripts
 * WhatsApp: https://wa.me/923224083545 (For Custom Projects)
 * YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
 *
 * Mobile Bottom Navigation Bar
 * Include this file in all dashboard pages (before app-container)
 */

// Get current page from the including file
$mobile_current = isset($current_page) ? $current_page : '';
$mobile_role = isset($role) ? $role : 'Warehouse Clerk';
?>

<!-- Mobile Bottom Navigation Bar -->
<nav class="mobile-bottom-nav" id="mobileBottomNav">
    <a href="dashboard.php" class="mobile-nav-item <?php echo $mobile_current === 'dashboard' ? 'active' : ''; ?>">
        <i class="fas fa-chart-line"></i>
        <span>Dashboard</span>
    </a>

    <a href="suppliers.php" class="mobile-nav-item <?php echo $mobile_current === 'suppliers' ? 'active' : ''; ?>">
        <i class="fas fa-truck-field"></i>
        <span>Suppliers</span>
    </a>

    <!-- Quick Add FAB -->
    <button class="mobile-nav-item mobile-nav-fab" onclick="toggleQuickAdd()" id="quickAddBtn">
        <i class="fas fa-plus" id="quickAddIcon"></i>
        <span>Add</span>
    </button>

    <a href="inventory.php" class="mobile-nav-item <?php echo $mobile_current === 'inventory' ? 'active' : ''; ?>">
        <i class="fas fa-boxes-stacked"></i>
        <span>Inventory</span>
    </a>

    <button class="mobile-nav-item mobile-nav-more" onclick="toggleMobileMenu()">
        <i class="fas fa-bars"></i>
        <span>More</span>
    </button>
</nav>

<!-- Quick Add Popup -->
<div class="quick-add-overlay" id="quickAddOverlay" onclick="closeQuickAdd()"></div>
<div class="quick-add-menu" id="quickAddMenu">
    <a href="purchases.php" class="quick-add-item" onclick="closeQuickAdd()">
        <div class="quick-add-icon" style="background:#0074D9;"><i class="fas fa-cart-shopping"></i></div>
        <span>Purchase</span>
    </a>
    <a href="payments.php" class="quick-add-item" onclick="closeQuickAdd()">
        <div class="quick-add-icon" style="background:#34a853;"><i class="fas fa-credit-card"></i></div>
        <span>Payment</span>
    </a>
    <a href="deliveries.php" class="quick-add-item" onclick="closeQuickAdd()">
        <div class="quick-add-icon" style="background:#fbbc04;"><i class="fas fa-truck-fast"></i></div>
        <span>Delivery</span>
    </a>
    <a href="sales.php" class="quick-add-item" onclick="closeQuickAdd()">
        <div class="quick-add-icon" style="background:#ea4335;"><i class="fas fa-coins"></i></div>
        <span>Sale</span>
    </a>
    <a href="financing.php" class="quick-add-item" onclick="closeQuickAdd()">
        <div class="quick-add-icon" style="background:#9b59b6;"><i class="fas fa-money-bill-transfer"></i></div>
        <span>Financing</span>
    </a>
</div>

<!-- Mobile Sidebar Overlay -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeMobileMenu()"></div>

<script>
/**
 * Quick Add FAB
 */
function toggleQuickAdd() {
    var menu = document.getElementById('quickAddMenu');
    var overlay = document.getElementById('quickAddOverlay');
    var icon = document.getElementById('quickAddIcon');
    var isOpen = menu.classList.contains('open');

    if (isOpen) {
        closeQuickAdd();
    } else {
        menu.classList.add('open');
        overlay.classList.add('open');
        icon.className = 'fas fa-times';
    }
}

function closeQuickAdd() {
    var menu = document.getElementById('quickAddMenu');
    var overlay = document.getElementById('quickAddOverlay');
    var icon = document.getElementById('quickAddIcon');
    if (menu) menu.classList.remove('open');
    if (overlay) overlay.classList.remove('open');
    if (icon) icon.className = 'fas fa-plus';
}

/**
 * Mobile Menu Functions
 */
function toggleMobileMenu() {
    closeQuickAdd();
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');

    if (sidebar) {
        sidebar.classList.toggle('mobile-open');
        overlay.classList.toggle('active');

        if (sidebar.classList.contains('mobile-open')) {
            document.body.style.overflow = 'hidden';
            document.body.style.position = 'fixed';
            document.body.style.width = '100%';
            document.body.style.top = `-${window.scrollY}px`;
        } else {
            const scrollY = document.body.style.top;
            document.body.style.overflow = '';
            document.body.style.position = '';
            document.body.style.width = '';
            document.body.style.top = '';
            window.scrollTo(0, parseInt(scrollY || '0') * -1);
        }
    }
}

function closeMobileMenu() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');

    if (sidebar && sidebar.classList.contains('mobile-open')) {
        sidebar.classList.remove('mobile-open');
        overlay.classList.remove('active');

        const scrollY = document.body.style.top;
        document.body.style.overflow = '';
        document.body.style.position = '';
        document.body.style.width = '';
        document.body.style.top = '';
        window.scrollTo(0, parseInt(scrollY || '0') * -1);
    }
}

// Close mobile menu when clicking a link in sidebar
document.addEventListener('DOMContentLoaded', function() {
    const sidebarLinks = document.querySelectorAll('.sidebar-menu a:not(.submenu-toggle)');
    sidebarLinks.forEach(function(link) {
        link.addEventListener('click', function() {
            closeMobileMenu();
        });
    });

    // Close menu on escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeMobileMenu();
        }
    });

    // Handle swipe to close sidebar
    let touchStartX = 0;
    let touchEndX = 0;
    const sidebar = document.getElementById('sidebar');

    if (sidebar) {
        sidebar.addEventListener('touchstart', function(e) {
            touchStartX = e.changedTouches[0].screenX;
        }, { passive: true });

        sidebar.addEventListener('touchend', function(e) {
            touchEndX = e.changedTouches[0].screenX;
            handleSwipe();
        }, { passive: true });
    }

    function handleSwipe() {
        const swipeThreshold = 50;
        if (touchStartX - touchEndX > swipeThreshold) {
            // Swipe left - close sidebar
            closeMobileMenu();
        }
    }

    // Prevent body scroll when touching sidebar overlay
    const overlay = document.getElementById('sidebarOverlay');
    if (overlay) {
        overlay.addEventListener('touchmove', function(e) {
            e.preventDefault();
        }, { passive: false });
    }
});

// Handle orientation change
window.addEventListener('orientationchange', function() {
    // Small delay to let the browser adjust
    setTimeout(function() {
        if (window.innerWidth > 768) {
            closeMobileMenu();
        }
    }, 100);
});

// Handle resize - close mobile menu if window becomes larger
window.addEventListener('resize', function() {
    if (window.innerWidth > 768) {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        if (sidebar) {
            sidebar.classList.remove('mobile-open');
        }
        if (overlay) {
            overlay.classList.remove('active');
        }
        document.body.style.overflow = '';
        document.body.style.position = '';
        document.body.style.width = '';
        document.body.style.top = '';
    }
});
</script>
