<?php

declare(strict_types=1);
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="theme-color" content="#090806">
    <meta name="application-name" content="Homestead">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="description" content="Homestead brings pantry inventory, recipes, garden monitoring, preservation, planning, and household coordination into one connected food system.">
    <title>Homestead · Your Household Food System</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/homestead-icon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="assets/icons/homestead-icon.svg">
    <link rel="stylesheet" href="assets/css/homestead-public.css?v=20260727-1">
    <script src="assets/js/pwa.js?v=20260727-2" defer></script>
    <script src="assets/js/homestead-public.js?v=20260727-1" defer></script>
</head>
<body class="public-site">
<a class="skip-link" href="#main-content">Skip to main content</a>

<div class="public-frame">
    <header class="site-header">
        <a class="site-brand" href="./" aria-label="Homestead home">
            <span class="brand-seal"><svg viewBox="0 0 48 48" aria-hidden="true">
<circle cx="24" cy="24" r="22" fill="none" stroke="currentColor" stroke-width="1.5"/>
<path d="M24 10v27M24 15c-5-1-8-4-9-8M24 20c5-1 8-4 9-8M24 25c-5-1-8-4-9-8M24 30c5-1 8-4 9-8M18 37h12" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
</svg></span>
            <span class="brand-word">Homestead</span>
        </a>

        <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="site-navigation" data-nav-toggle>
            <span class="visually-hidden">Toggle navigation</span>
            <span></span><span></span><span></span>
        </button>

        <nav id="site-navigation" class="site-navigation" aria-label="Primary navigation" data-nav-panel>
            <a href="#features">Features</a>
            <a href="#lifecycle">How it works</a>
            <a href="#garden">Garden</a>
            <a href="#preserve">Preservation</a>
        </nav>

        <div class="header-actions">
            <a class="text-link" href="login.php">Sign in</a>
            <a class="gold-button compact" href="login.php">Open Homestead</a>
        </div>
    </header>

    <main id="main-content">
        <section class="landing-hero">
            <div class="hero-copy">
                <p class="gold-kicker">A household food system</p>
                <h1>Grow it.<br>Store it.<br>Cook it.<br>Preserve it.<span class="leaf-mark" aria-hidden="true">⌁</span></h1>
                <p class="hero-description">Homestead helps your household plan, grow, cook, store, and preserve real food with confidence—all in one place.</p>
                <div class="hero-actions">
                    <a class="gold-button" href="login.php">Open Homestead</a>
                    <a class="outline-button" href="#features">Explore Features</a>
                    <button class="outline-button" type="button" data-homestead-install hidden>Install App</button>
                </div>
                <div class="trust-row" aria-label="Homestead platform benefits">
                    <span>One connected system</span>
                    <span>Household permissions</span>
                    <span>Manual-first workflows</span>
                </div>
            </div>

            <div class="device-stage" aria-label="Homestead dashboard previews">
                <div class="still-life" aria-hidden="true"></div>
                <div class="desktop-device">
                    <div class="device-bezel">
                        <div class="preview-app">
                            <aside class="preview-sidebar">
                                <strong>Homestead</strong>
                                <nav aria-label="Dashboard preview navigation">
                                    <span class="active">⌂ <b>Home</b></span>
                                    <span>▣ Inventory</span>
                                    <span>⌑ Recipes</span>
                                    <span>♧ Garden</span>
                                    <span>☼ Grow Lights</span>
                                    <span>▤ Preservation</span>
                                    <span>✓ Planning</span>
                                </nav>
                            </aside>
                            <div class="preview-canvas">
                                <div class="preview-title">
                                    <strong>Homestead Dashboard</strong>
                                    <span>•••</span>
                                </div>
                                <div class="preview-metrics">
                                    <article><small>Pantry Inventory</small><b>612 items</b><em>$1,248 value</em></article>
                                    <article><small>Recipe Suggestions</small><b>8 ideas</b><em>Based on your pantry</em></article>
                                    <article><small>Garden Overview</small><b>72.4°</b><em>45% RH</em></article>
                                    <article><small>Grow Lights</small><b>3 ready</b><em>2 in use</em></article>
                                </div>
                                <div class="preview-middle">
                                    <article class="garden-health">
                                        <div>
                                            <small>Garden Health</small>
                                            <b>Everything looks good</b>
                                            <em>Your plants are thriving.</em>
                                            <span class="health-bar"><i></i></span>
                                        </div>
                                        <div class="garden-image" aria-hidden="true"></div>
                                    </article>
                                    <article class="schedule-card">
                                        <small>Grow Light Schedule</small>
                                        <b>Room A</b><em>16h on / 8h off</em>
                                        <b>Room B</b><em>16h on / 8h off</em>
                                    </article>
                                </div>
                                <div class="preview-bottom">
                                    <article><small>Preservation</small><b>12 items</b><em>Stored this month</em></article>
                                    <article><small>Expiring Soon</small><b>6 items</b><em>Within 14 days</em></article>
                                    <article><small>Dehydration</small><b>4 trays</b><em>In progress</em></article>
                                    <article><small>Fermentation</small><b>2 batches</b><em>In progress</em></article>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="phone-device">
                    <div class="phone-screen">
                        <div class="phone-status"><span>9:41</span><span>● ◔</span></div>
                        <div class="phone-head"><strong>Homestead</strong><span>⌾</span></div>
                        <small>Today</small>
                        <article class="phone-garden">
                            <div><b>Garden</b><span>Everything looks good</span></div>
                            <div class="phone-garden-image"></div>
                        </article>
                        <article><b>Grow Lights</b><span>3 ready · 2 in use</span></article>
                        <article><b>Expiring Soon</b><span>6 items · within 14 days</span></article>
                        <article><b>Preservation</b><span>2 in progress</span></article>
                        <nav aria-label="Mobile preview navigation"><span>⌂</span><span>▣</span><span>♧</span><span>✓</span><span>•••</span></nav>
                    </div>
                </div>
            </div>
        </section>

        <section id="features" class="section-block feature-section">
            <div class="section-heading">
                <p class="gold-kicker">One connected workspace</p>
                <h2>Everything you need in one place</h2>
                <span class="ornament" aria-hidden="true"></span>
            </div>

            <div class="feature-card-grid">
                <article class="feature-card">
                    <div class="line-icon" aria-hidden="true">
                        <svg viewBox="0 0 64 64"><path d="M20 18h24l-2 36H22l-2-36Zm-3 0h30M25 18v-6h14v6M26 28h12M27 36h10M28 44h8"/></svg>
                    </div>
                    <h3>Pantry Inventory</h3>
                    <p>Track what you have, get low-stock alerts, and reduce waste.</p>
                </article>
                <article class="feature-card">
                    <div class="line-icon" aria-hidden="true">
                        <svg viewBox="0 0 64 64"><path d="M18 12h28v40H18zM23 8h28v40M25 22h14M25 29h12M25 36h16M25 43h9"/></svg>
                    </div>
                    <h3>Recipes & Meal Planning</h3>
                    <p>Find recipes from your ingredients and plan meals with ease.</p>
                </article>
                <article class="feature-card">
                    <div class="line-icon" aria-hidden="true">
                        <svg viewBox="0 0 64 64"><path d="M32 53V29M32 36c-9 0-15-5-17-13 9-1 15 4 17 13Zm0-8c8 0 14-5 16-13-8-1-14 4-16 13ZM24 53h16"/></svg>
                    </div>
                    <h3>Garden Monitoring</h3>
                    <p>Monitor temperature, humidity, soil, and plant health.</p>
                </article>
                <article class="feature-card">
                    <div class="line-icon" aria-hidden="true">
                        <svg viewBox="0 0 64 64"><path d="M20 28h24M24 28l3-10h10l3 10M17 28h30l-3 15H20l-3-15ZM25 43v8M39 43v8M22 51h20M32 12v6"/></svg>
                    </div>
                    <h3>Grow Light Schedules</h3>
                    <p>Coordinate light schedules by room, stage, and crop.</p>
                </article>
                <article class="feature-card">
                    <div class="line-icon" aria-hidden="true">
                        <svg viewBox="0 0 64 64"><path d="M20 18h24l-2 36H22l-2-36Zm-3 0h30M25 18v-6h14v6M28 31c3-3 5-3 8 0s5 3 8 0M28 40c3-3 5-3 8 0s5 3 8 0"/></svg>
                    </div>
                    <h3>Preservation Tracking</h3>
                    <p>Track ferments, cans, dehydrated foods, and shelf life.</p>
                </article>
            </div>
        </section>

        <section id="lifecycle" class="section-block lifecycle-section">
            <div class="section-heading">
                <p class="gold-kicker">A dependable household rhythm</p>
                <h2>How it works</h2>
                <span class="ornament" aria-hidden="true"></span>
            </div>
            <div class="lifecycle-grid">
                <article>
                    <span class="step-number">1</span>
                    <div class="step-icon" aria-hidden="true">▤</div>
                    <h3>Plan</h3>
                    <p>Inventory, check supplies, and plan meals or growing work.</p>
                </article>
                <article>
                    <span class="step-number">2</span>
                    <div class="step-icon" aria-hidden="true">♧</div>
                    <h3>Grow</h3>
                    <p>Monitor your garden and environment for healthy results.</p>
                </article>
                <article>
                    <span class="step-number">3</span>
                    <div class="step-icon" aria-hidden="true">◒</div>
                    <h3>Cook</h3>
                    <p>Use what you have, create meals, and track prepared food.</p>
                </article>
                <article>
                    <span class="step-number">4</span>
                    <div class="step-icon" aria-hidden="true">▣</div>
                    <h3>Preserve</h3>
                    <p>Extend the harvest and keep each batch accountable.</p>
                </article>
            </div>
        </section>

        <section class="system-showcase">
            <article id="garden" class="showcase-card garden-showcase">
                <div class="showcase-photo"></div>
                <div>
                    <p class="gold-kicker">Garden intelligence</p>
                    <h2>Know what your plants need.</h2>
                    <p>Bring grow-light schedules, sensor readings, plant stages, garden tasks, and expected harvests into the same household record.</p>
                    <a class="inline-link" href="login.php">Open garden workspace <span>→</span></a>
                </div>
            </article>
            <article id="preserve" class="showcase-card preservation-showcase">
                <div class="showcase-photo"></div>
                <div>
                    <p class="gold-kicker">Preservation records</p>
                    <h2>Keep every batch traceable.</h2>
                    <p>Document canning, fermenting, dehydrating, freezing, labels, storage locations, and use-by dates without losing the household context.</p>
                    <a class="inline-link" href="login.php">Open preservation workspace <span>→</span></a>
                </div>
            </article>
        </section>

        <section class="final-cta">
            <div class="cta-photo" aria-hidden="true"></div>
            <div class="cta-copy">
                <p class="gold-kicker">Your household food system</p>
                <h2>Build a more capable home.</h2>
                <p>Plan, grow, cook, preserve, and coordinate with confidence.</p>
                <a class="gold-button" href="login.php">Open Homestead</a>
                <div class="trust-row compact-trust">
                    <span>Permission-aware</span>
                    <span>Deployment-safe</span>
                    <span>Built for real routines</span>
                </div>
            </div>
        </section>
    </main>

    <footer class="site-footer">
        <a class="site-brand footer-brand" href="./">
            <span class="brand-seal"><svg viewBox="0 0 48 48" aria-hidden="true">
<circle cx="24" cy="24" r="22" fill="none" stroke="currentColor" stroke-width="1.5"/>
<path d="M24 10v27M24 15c-5-1-8-4-9-8M24 20c5-1 8-4 9-8M24 25c-5-1-8-4-9-8M24 30c5-1 8-4 9-8M18 37h12" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
</svg></span>
            <span class="brand-word">Homestead</span>
        </a>
        <nav aria-label="Footer navigation">
            <a href="#features">Features</a>
            <a href="#lifecycle">How it works</a>
            <a href="login.php">Sign in</a>
        </nav>
        <p>© <?= date('Y') ?> Homestead</p>
    </footer>
</div>
</body>
</html>
