<?php

declare(strict_types=1);
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="theme-color" content="#1f4b36">
    <meta name="application-name" content="Homestead">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="description" content="Homestead is a household food system for growing, storing, cooking, preserving, planning, and coordinating real food.">
    <title>Homestead · Your Household Food System</title>
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" href="/assets/icons/homestead-icon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/assets/icons/homestead-icon.svg">
    <link rel="stylesheet" href="/assets/css/app.css">
    <script src="/assets/js/pwa.js?v=20260727" defer></script>
</head>
<body>
<a class="skip-link" href="#main-content">Skip to main content</a>
<div class="landing-shell">
    <nav class="landing-nav" aria-label="Primary navigation">
        <a class="landing-brand" href="/" aria-label="Homestead home">
            <span class="landing-brand-mark" aria-hidden="true">H</span>
            <span><strong>Homestead</strong><small>Household food system</small></span>
        </a>
        <div class="landing-links">
            <a href="#system">The system</a>
            <a href="#lifecycle">Food lifecycle</a>
            <a href="#household">Household planning</a>
            <a class="button primary" href="/login.php">Sign in</a>
        </div>
    </nav>

    <main id="main-content">
        <section class="hero-section">
            <div class="hero-copy">
                <p class="eyebrow">A practical operating system for real food</p>
                <h1>Grow it. Store it. Cook it. Preserve it.</h1>
                <p class="page-description">Homestead connects pantry inventory, recipes, family meal planning, garden activity, preservation, shopping, nutrition planning, household tasks, and food costs in one dependable system.</p>
                <div class="hero-actions">
                    <a class="button primary" href="/login.php">Open Homestead</a>
                    <a class="button secondary" href="#system">Explore the system</a>
                    <button class="button secondary" type="button" data-homestead-install hidden>Install Homestead</button>
                </div>
                <div class="hero-proof" aria-label="Homestead benefits">
                    <span>Built for households</span>
                    <span>Manual-first workflows</span>
                    <span>Secure family access</span>
                </div>
            </div>

            <div class="hero-visual" aria-label="Homestead dashboard preview">
                <div class="hero-visual-card main">
                    <div class="hero-board-title">
                        <div><p class="eyebrow">Today at home</p><strong>Household overview</strong></div>
                        <span class="status status-good">On track</span>
                    </div>
                    <div class="hero-board-grid">
                        <div class="hero-board-tile"><b>Pantry</b><strong>84%</strong><p>Core staples in stock</p></div>
                        <div class="hero-board-tile"><b>Garden</b><strong>12</strong><p>Active plantings</p></div>
                        <div class="hero-board-tile"><b>Meals</b><strong>6</strong><p>Planned this week</p></div>
                        <div class="hero-board-tile"><b>Preserve</b><strong>4</strong><p>Batches in progress</p></div>
                    </div>
                </div>
                <div class="hero-visual-card float-one"><strong>Use first</strong><p>Tomatoes, spinach, sourdough starter</p></div>
                <div class="hero-visual-card float-two"><strong>Next action</strong><p>Prep Thursday dinner and move two jars to pantry storage.</p></div>
            </div>
        </section>

        <section id="system" class="landing-section">
            <div class="section-heading">
                <p class="eyebrow">One connected household system</p>
                <h2>Everything around real food, finally working together.</h2>
                <p class="page-description">Each area stays useful on its own while contributing to one shared household food record.</p>
            </div>
            <div class="system-grid">
                <article class="system-card"><span class="system-card-number">01</span><h3>Pantry & storage</h3><p>Track bulk goods, quantities, storage locations, dates, reorder levels, purchases, and the food ledger.</p></article>
                <article class="system-card"><span class="system-card-number">02</span><h3>Recipes & meals</h3><p>Connect recipes to available ingredients, household servings, meal plans, prepared food, and leftovers.</p></article>
                <article class="system-card"><span class="system-card-number">03</span><h3>Garden & harvest</h3><p>Organize zones, crop stages, readings, expected harvests, inventory posting, and preservation destinations.</p></article>
                <article class="system-card"><span class="system-card-number">04</span><h3>Preservation</h3><p>Coordinate canning, dehydrating, fermenting, freezing, storage, reference notes, and use-by dates.</p></article>
                <article class="system-card"><span class="system-card-number">05</span><h3>Daily planning</h3><p>Turn recurring household responsibilities, meal prep, shortages, harvests, and follow-up into assigned tasks.</p></article>
                <article class="system-card"><span class="system-card-number">06</span><h3>Forecasting</h3><p>Estimate days on hand, likely shortages, seasonal readiness, planned production, and household resilience.</p></article>
                <article class="system-card"><span class="system-card-number">07</span><h3>Cost & waste</h3><p>Understand purchase costs, recipe economics, budgets, food waste, supplier comparisons, and estimated savings.</p></article>
                <article class="system-card"><span class="system-card-number">08</span><h3>Family coordination</h3><p>Use roles, permissions, private member records, notifications, shared calendars, and household activity history.</p></article>
            </div>
        </section>

        <section id="lifecycle" class="landing-section">
            <div class="section-heading">
                <p class="eyebrow">A complete food lifecycle</p>
                <h2>From planning to the next restock.</h2>
            </div>
            <div class="lifecycle-strip" aria-label="Homestead food lifecycle">
                <div class="lifecycle-step">Plan</div>
                <div class="lifecycle-step">Stock</div>
                <div class="lifecycle-step">Grow</div>
                <div class="lifecycle-step">Cook</div>
                <div class="lifecycle-step">Preserve</div>
                <div class="lifecycle-step">Track</div>
                <div class="lifecycle-step">Restock</div>
            </div>
        </section>

        <section id="household" class="landing-section feature-split">
            <article class="feature-block green">
                <p class="eyebrow">Family operations</p>
                <h2>Built for the way households actually work.</h2>
                <p class="page-description" style="margin-top:16px">Homestead keeps responsibilities visible without making every family record public to every member.</p>
                <div class="feature-list">
                    <div><strong>Permission-aware access</strong><span>Owners, administrators, adults, youth members, and custom overrides.</span></div>
                    <div><strong>Shared planning</strong><span>Tasks, calendars, notifications, meals, shopping, harvests, and preservation follow-up.</span></div>
                    <div><strong>Accountable history</strong><span>Household activity and food-ledger provenance connect decisions to outcomes.</span></div>
                </div>
            </article>
            <article class="feature-block">
                <p class="eyebrow">Progressive automation</p>
                <h2>Manual first. Device-ready later.</h2>
                <p class="page-description" style="margin-top:16px">The core experience works without sensors or external services, while clean adapter boundaries leave room for future household technology.</p>
                <div class="feature-list">
                    <div><strong>Dependable manual workflows</strong><span>Start with records and routines the household can verify.</span></div>
                    <div><strong>Starter Kits</strong><span>Combine ingredients, equipment, supplies, seeds, instructions, recipes, and opening tasks.</span></div>
                    <div><strong>Future integrations</strong><span>Prepared for sensor, smart-home, messaging, delivery, and purchasing adapters.</span></div>
                </div>
            </article>
        </section>

        <section class="landing-cta">
            <p class="eyebrow">Homestead household access</p>
            <h2>Bring the whole food cycle into one place.</h2>
            <p class="page-description" style="margin:16px auto 24px">Sign in to access your household inventory, family records, recipes, garden, plans, alerts, and operating history.</p>
            <a class="button primary" href="/login.php">Sign in to Homestead</a>
        </section>
    </main>

    <footer class="landing-footer">
        <span>Homestead · Household food system</span>
        <span>Planning and record keeping—not medical, financial, or food-safety certification.</span>
    </footer>
</div>
</body>
</html>
