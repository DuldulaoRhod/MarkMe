        </main>
    </div>
</div>
<?php require __DIR__ . '/../confirm_dialog.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php /* Loaded first and on its own: an error anywhere in script.js must not
         be able to take the session-expiry check down with it. */ ?>
<script src="<?= BASE_URL ?>/assets/js/session.js?v=<?= filemtime(__DIR__ . '/../../assets/js/session.js') ?>"></script>
<script src="<?= BASE_URL ?>/assets/js/script.js?v=<?= filemtime(__DIR__ . '/../../assets/js/script.js') ?>"></script>
</body>
</html>
