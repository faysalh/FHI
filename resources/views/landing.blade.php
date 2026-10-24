<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Akre Chicken — Reporting</title>
    @include('reports.partials.pwa-head')
    @include('reports.partials.theme')
    <style>
        body.report-app--login {
            min-height: 100vh;
            background: linear-gradient(160deg, #0f172a 0%, #1e3a5f 100%);
        }
        .landing {
            width: min(960px, 100%);
            margin: 0 auto;
            padding: 20px 0 32px;
        }
        .landing-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 18px;
            color: #e2e8f0;
        }
        .landing-bar strong { font-size: 15px; letter-spacing: 0.01em; }
        .landing-bar span { display: block; color: #94a3b8; font-size: 12px; }
        .landing-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 22px;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.15);
            margin-bottom: 14px;
        }
        .landing-card h1 { margin: 0 0 8px; font-size: 1.7rem; line-height: 1.2; }
        .landing-card h2 { margin: 0 0 8px; font-size: 1.15rem; }
        .landing-lead { margin: 0 0 8px; color: #334155; font-size: 15px; }
        .landing-card p { margin: 0 0 14px; color: #64748b; }
        .landing-actions { display: flex; flex-wrap: wrap; gap: 8px; }
        .landing-actions .btn { min-height: 40px; padding: 10px 16px; font-size: 14px; }
        .landing-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 10px;
        }
        .landing-grid article {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 12px;
        }
        .landing-grid h3 { margin: 0 0 4px; font-size: 14px; }
        .landing-grid p { margin: 0; font-size: 13px; }
        .landing-facts { margin: 0; padding-left: 18px; color: #334155; }
        .landing-facts li { margin: 4px 0; }
        .landing-card a.quiet { color: #4f46e5; }
    </style>
</head>
<body class="report-app report-app--login">
<div class="landing">
    <header class="landing-bar">
        <div>
            <strong>Akre Chicken</strong>
            <span>Reporting</span>
        </div>
        <a class="btn btn--primary" href="{{ route('login') }}">Log in</a>
    </header>

    <section class="landing-card">
        <h1>Akre Chicken</h1>
        <p class="landing-lead">Locally grown, and slaughtered the Islamic way.</p>
        <p>Since 2007, the Akre Chicken slaughterhouse has served families with freshly slaughtered frozen chicken. The birds are fed locally, slaughtered by hand under supervision, cleaned thoroughly, and packed with care.</p>
        <div class="landing-actions">
            <a class="btn btn--primary" href="{{ route('login') }}">Log in</a>
            @include('reports.partials.pwa-install')
            <a class="btn btn--secondary" href="https://www.akrechicken.com/" rel="noopener">akrechicken.com</a>
        </div>
    </section>

    <section class="landing-card">
        <h2>What this reporting desk is for</h2>
        <p>A private place for the people who run Akre Chicken to see the business in one screen, then sign in to the part of the work they are allowed to open.</p>
        <div class="landing-grid">
            <article>
                <h3>Sales</h3>
                <p>Customers, items, salesmen, cities, and how one period compares with another.</p>
            </article>
            <article>
                <h3>Stock</h3>
                <p>What is on hand, what is moving, and what the stores are holding.</p>
            </article>
            <article>
                <h3>Money</h3>
                <p>Receipts, what customers owe, and the account book.</p>
            </article>
            <article>
                <h3>Daily work</h3>
                <p>Deliveries, invoices, visits, damaged goods, and salesman sales from the phone.</p>
            </article>
        </div>
    </section>

    <section class="landing-card">
        <h2>The company</h2>
        <ul class="landing-facts">
            <li>A local slaughterhouse in Akre, serving the community since 2007.</li>
            <li>Chicken raised on local, natural feed, then cleaned and frozen.</li>
            <li>Slaughter is done by hand, the Islamic way, under supervision.</li>
            <li>Offices in Duhok, Ruvia, and in Erbil, Italy 1.</li>
            <li>Open Saturday to Thursday, 8:00 a.m. to 4:00 p.m.</li>
        </ul>
    </section>
</div>
@include('reports.partials.mobile-reports')
</body>
</html>
