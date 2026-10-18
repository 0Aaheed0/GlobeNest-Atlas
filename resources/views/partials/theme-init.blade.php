    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">
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
