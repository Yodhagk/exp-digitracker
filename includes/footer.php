        </div><!-- /page-content -->
    </div><!-- /main-wrapper -->

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const sidebar = document.getElementById('sidebar');
        const mainWrapper = document.getElementById('main-wrapper');
        document.getElementById('sidebar-toggle').addEventListener('click', function () {
            sidebar.classList.toggle('collapsed');
            mainWrapper.classList.toggle('expanded');
        });

        // ── Light/dark theme toggle ──────────────────────────
        (function () {
            const root = document.documentElement;
            const btn  = document.getElementById('theme-toggle');
            if (!btn) return;
            const icon = btn.querySelector('i');
            function sync() {
                const isLight = root.getAttribute('data-theme') === 'light';
                icon.className = isLight ? 'fas fa-sun' : 'fas fa-moon';
                btn.title = isLight ? 'Switch to dark theme' : 'Switch to light theme';
            }
            sync();
            btn.addEventListener('click', function () {
                const next = root.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
                root.setAttribute('data-theme', next);
                try { localStorage.setItem('digitracker-theme', next); } catch (e) {}
                sync();
            });
        })();
    </script>
    <?php if (!empty($extra_js)) echo $extra_js; ?>
</body>
</html>
