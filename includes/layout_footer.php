        </div>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.querySelectorAll('form[method="get"] input[type="search"][name]').forEach((searchInput) => {
        const searchForm = searchInput.form;
        if (!searchForm) return;

        let hadSearchTerm = searchInput.value.trim() !== '';
        searchInput.addEventListener('input', () => {
            const hasSearchTerm = searchInput.value.trim() !== '';
            if (hadSearchTerm && !hasSearchTerm) {
                if (typeof searchForm.requestSubmit === 'function') {
                    searchForm.requestSubmit();
                } else {
                    searchForm.submit();
                }
            }
            hadSearchTerm = hasSearchTerm;
        });
    });
</script>
</body>
</html>
