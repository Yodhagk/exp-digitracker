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
    </script>
    <?php if (!empty($extra_js)) echo $extra_js; ?>
</body>
</html>
