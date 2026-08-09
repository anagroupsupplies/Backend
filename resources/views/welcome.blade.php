<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f59e0b">
    <meta name="description" content="Antenkayume Shop API status">
    <title>Antenkayume Shop API</title>
    <style>
        :root { color-scheme: dark; font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 1.5rem; color: #f8fafc; background: radial-gradient(circle at top, #292524 0, #111827 42%, #030712 100%); }
        main { width: min(100%, 42rem); text-align: center; }
        .card { position: relative; overflow: hidden; padding: clamp(2rem, 7vw, 4rem); border: 1px solid rgba(255,255,255,.1); border-radius: 2rem; background: rgba(17,24,39,.78); box-shadow: 0 30px 80px rgba(0,0,0,.4); backdrop-filter: blur(18px); }
        .card::before { content: ""; position: absolute; inset: 0 0 auto; height: .3rem; background: linear-gradient(90deg, #f59e0b, #fb923c); }
        .status { display: inline-flex; align-items: center; gap: .55rem; padding: .55rem .9rem; border: 1px solid rgba(52,211,153,.25); border-radius: 999px; color: #6ee7b7; background: rgba(16,185,129,.09); font-size: .82rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }
        .dot { width: .55rem; height: .55rem; border-radius: 50%; background: #34d399; box-shadow: 0 0 0 .3rem rgba(52,211,153,.12); animation: pulse 2s infinite; }
        h1 { margin: 1.6rem 0 .65rem; font-size: clamp(2.1rem, 8vw, 4.2rem); line-height: 1.02; letter-spacing: -.045em; }
        h1 span { color: #f59e0b; }
        .subtitle { margin: 0 auto; max-width: 30rem; color: #94a3b8; font-size: clamp(.95rem, 3vw, 1.1rem); line-height: 1.7; }
        .uptime { margin: 2rem 0; padding: 1.25rem; border: 1px solid rgba(255,255,255,.08); border-radius: 1rem; background: rgba(3,7,18,.45); }
        .uptime-label { display: block; margin-bottom: .4rem; color: #94a3b8; font-size: .75rem; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; }
        #uptime { font-variant-numeric: tabular-nums; font-size: clamp(1.2rem, 5vw, 1.75rem); font-weight: 750; letter-spacing: .02em; }
        .github { display: inline-flex; align-items: center; gap: .7rem; min-height: 3rem; padding: .7rem 1rem; border-radius: .8rem; color: #e2e8f0; text-decoration: none; font-weight: 650; transition: transform .2s, background .2s, color .2s; }
        .github:hover, .github:focus-visible { color: #fff; background: rgba(255,255,255,.08); transform: translateY(-2px); outline: none; }
        .github svg { width: 1.65rem; height: 1.65rem; fill: currentColor; }
        footer { margin-top: 1.25rem; color: #64748b; font-size: .78rem; }
        @keyframes pulse { 50% { box-shadow: 0 0 0 .5rem rgba(52,211,153,0); } }
        @media (prefers-reduced-motion: reduce) { .dot { animation: none; } .github { transition: none; } }
    </style>
</head>
<body>
<main>
    <section class="card" aria-labelledby="api-title">
        <div class="status"><span class="dot" aria-hidden="true"></span>API operational</div>
        <h1 id="api-title">Antenkayume Shop <span>API</span></h1>
        <p class="subtitle">The backend service is online and ready to serve the Antenkayume shopping experience.</p>

        <div class="uptime">
            <span class="uptime-label">Current uptime</span>
            <strong id="uptime" data-started-at="{{ $startedAt }}">Calculating…</strong>
        </div>

        <a class="github" href="https://github.com/sirtheprogrammer" target="_blank" rel="noopener noreferrer" aria-label="Visit sirtheprogrammer on GitHub">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 .7a11.5 11.5 0 0 0-3.64 22.41c.58.1.79-.25.79-.56v-2.23c-3.22.7-3.9-1.37-3.9-1.37-.52-1.34-1.28-1.7-1.28-1.7-1.05-.72.08-.7.08-.7 1.16.08 1.77 1.19 1.77 1.19 1.03 1.77 2.7 1.26 3.36.96.1-.75.4-1.26.73-1.55-2.57-.29-5.27-1.28-5.27-5.68 0-1.26.45-2.28 1.19-3.09-.12-.29-.52-1.46.11-3.05 0 0 .97-.31 3.16 1.18a10.92 10.92 0 0 1 5.76 0c2.2-1.49 3.16-1.18 3.16-1.18.63 1.59.23 2.76.11 3.05.74.81 1.19 1.83 1.19 3.09 0 4.41-2.71 5.38-5.29 5.67.42.36.79 1.06.79 2.14v3.17c0 .31.21.67.8.56A11.5 11.5 0 0 0 12 .7Z"/></svg>
            <span>sirtheprogrammer</span>
        </a>
    </section>
    <footer>Laravel {{ app()->version() }} · {{ app()->environment() }}</footer>
</main>
<script>
    const uptime = document.getElementById('uptime');
    const startedAt = new Date(uptime.dataset.startedAt).getTime();
    const renderUptime = () => {
        const total = Math.max(0, Math.floor((Date.now() - startedAt) / 1000));
        const days = Math.floor(total / 86400);
        const hours = Math.floor((total % 86400) / 3600);
        const minutes = Math.floor((total % 3600) / 60);
        const seconds = total % 60;
        uptime.textContent = `${days}d ${String(hours).padStart(2, '0')}h ${String(minutes).padStart(2, '0')}m ${String(seconds).padStart(2, '0')}s`;
    };
    renderUptime();
    setInterval(renderUptime, 1000);
</script>
</body>
</html>
