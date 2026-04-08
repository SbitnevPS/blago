<?php
// admin/includes/footer.php
?>
    </main>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Закрытие уведомлений
        document.querySelectorAll('.alert .btn-close').forEach(btn => {
            btn.addEventListener('click', () => {
                btn.closest('.alert').remove();
            });
        });
        
        // Автоматическое скрытие уведомлений через 5 секунд
        setTimeout(() => {
            document.querySelectorAll('.alert:not(.alert-permanent)').forEach(alert => {
                alert.style.opacity = '0';
                alert.style.transform = 'translateY(-10px)';
                setTimeout(() => alert.remove(), 300);
            });
        }, 5000);
    </script>
</body>
</html>
