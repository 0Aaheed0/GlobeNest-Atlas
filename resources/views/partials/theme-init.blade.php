<script>
    (() => {
        const systemTheme = window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
        let storedTheme = null;

        try {
            storedTheme = localStorage.getItem('atlas-theme');
        } catch {
            storedTheme = null;
        }

        document.documentElement.dataset.theme = storedTheme === 'light' || storedTheme === 'dark'
            ? storedTheme
            : systemTheme;
    })();
</script>
