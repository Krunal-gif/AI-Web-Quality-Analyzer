<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>AI Web Quality Analyzer</title>
    <meta name="description" content="Find, explain and fix SEO, accessibility, HTML, content, code and security issues in a website or project folder with a local AI.">
    <meta property="og:title" content="AI Web Quality Analyzer">
    <meta property="og:description" content="Find, explain and fix website quality issues with a local AI. Nothing changes until you approve.">
    <link rel="canonical" href="{{ url('/') }}/">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='8' fill='%23009b84'/><text x='16' y='21' font-size='13' font-family='sans-serif' font-weight='700' fill='white' text-anchor='middle'>QA</text></svg>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400..800&family=JetBrains+Mono:wght@400..600&display=swap">
    {{-- theme picked with the moon / sun button (stored in localStorage); without one the system setting is used --}}
    <script>try{var t=localStorage.getItem('theme');if(t==='light'||t==='dark')document.documentElement.dataset.theme=t}catch(e){}</script>
    {{-- page colour before the stylesheet arrives, so there's no white flash in dark mode --}}
    <style>html{background:#f3f7fa;color-scheme:light dark}@media (prefers-color-scheme:dark){html:not([data-theme=light]){background:#0a1628}}html[data-theme=dark]{background:#0a1628;color-scheme:dark}html[data-theme=light]{color-scheme:light}
    .boot{max-width:40rem;margin:20vh auto 0;padding:0 16px;font-family:Inter,system-ui,sans-serif;text-align:center}.boot h1{font-size:1.5rem;margin:0 0 .5rem}.boot p{opacity:.7;margin:0}</style>
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.jsx'])
</head>
<body>
    {{-- shown until React mounts (and to crawlers / no-JS visitors); createRoot().render() replaces it --}}
    <div id="app">
        <main class="boot">
            <h1>AI Web Quality Analyzer</h1>
            <p>Loading… Analyse a website or project folder for SEO, accessibility, HTML, content, code and security issues.</p>
        </main>
    </div>
</body>
</html>
