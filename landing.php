<?php
if (!isset($conn) || !($conn instanceof mysqli)) {
    require __DIR__ . '/includes/db.php';
}
require_once __DIR__ . '/includes/security.php';

if (isAuthenticated() && basename($_SERVER['SCRIPT_NAME'] ?? '') === 'landing.php') {
    header('Location: index.php');
    exit;
}

$landingServicesResult = $conn->query('SELECT service_name, price, duration_hours, required_staff_count FROM services ORDER BY price ASC');
$landingServices = $landingServicesResult ? $landingServicesResult->fetch_all(MYSQLI_ASSOC) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Book dependable home and business cleaning with clear service pricing and simple booking tracking through CleanManage.">
    <title>CleanManage | Cleaning, clearly managed</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --forest: #153b2d;
            --forest-deep: #102c22;
            --leaf: #397857;
            --lime: #c6dd6b;
            --coral: #df735a;
            --paper: #f4f5ee;
            --white: #fffefa;
            --ink: #17241e;
            --muted: #637168;
            --line: rgba(21, 59, 45, .16);
        }

        * { box-sizing: border-box; }

        html { scroll-behavior: smooth; }

        body {
            margin: 0;
            background: var(--paper);
            color: var(--ink);
            font-family: "DM Sans", sans-serif;
        }

        h1, h2, h3, .brand { font-family: "Manrope", sans-serif; }

        .site-nav {
            position: absolute;
            inset: 0 0 auto;
            z-index: 5;
            color: var(--white);
        }

        .nav-inner {
            min-height: 82px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid rgba(255,255,255,.25);
        }

        .brand {
            color: #fff;
            font-size: 1.15rem;
            font-weight: 800;
            text-decoration: none;
        }

        .nav-links { display: flex; align-items: center; gap: 28px; }
        .nav-links a { color: rgba(255,255,255,.9); text-decoration: none; font-size: .92rem; font-weight: 600; }
        .nav-links a:hover { color: var(--lime); }

        .nav-cta, .button-primary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 46px;
            padding: 0 20px;
            border: 1px solid var(--lime);
            border-radius: 3px;
            background: var(--lime);
            color: var(--forest-deep) !important;
            font-weight: 800 !important;
            text-decoration: none;
            transition: background .18s ease, transform .18s ease;
        }

        .nav-cta:hover, .button-primary:hover { background: #d3e984; transform: translateY(-2px); }

        .hero {
            min-height: 690px;
            position: relative;
            display: flex;
            align-items: center;
            overflow: hidden;
            background: var(--forest-deep);
            color: #fff;
        }

        .hero-image {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: center 48%;
        }

        .hero-shade { position: absolute; inset: 0; background: rgba(13, 39, 28, .63); }

        .hero-content { position: relative; z-index: 1; padding-top: 98px; padding-bottom: 68px; max-width: 780px; }
        .eyebrow { color: var(--lime); font-size: .76rem; font-weight: 800; letter-spacing: .16em; text-transform: uppercase; }
        .hero h1 { margin: 18px 0 16px; font-size: clamp(3.2rem, 7vw, 6.1rem); font-weight: 800; line-height: .98; }
        .hero h1 span { color: var(--lime); }
        .hero-copy { max-width: 610px; color: rgba(255,255,255,.88); font-size: 1.15rem; line-height: 1.7; }
        .hero-actions { display: flex; align-items: center; gap: 22px; flex-wrap: wrap; margin-top: 30px; }
        .button-secondary { color: #fff; font-weight: 700; text-decoration-color: var(--lime); text-underline-offset: 5px; }
        .hero-footnote { margin-top: 42px; color: rgba(255,255,255,.75); font-size: .85rem; }

        .intro-band { background: var(--lime); color: var(--forest-deep); padding: 20px 0; }
        .intro-band .container { display: flex; justify-content: space-between; gap: 24px; flex-wrap: wrap; font-weight: 700; }

        .section { padding: 94px 0; }
        .section-dark { background: var(--forest); color: #fff; }
        .section-heading { max-width: 740px; margin-bottom: 44px; }
        .section-heading h2 { margin: 10px 0 14px; font-size: clamp(2.2rem, 4vw, 3.5rem); line-height: 1.06; font-weight: 800; }
        .section-heading p { color: var(--muted); font-size: 1.05rem; line-height: 1.7; }
        .section-dark .section-heading p { color: rgba(255,255,255,.72); }
        .eyebrow-dark { color: var(--leaf); }

        .steps { counter-reset: step; border-top: 1px solid var(--line); }
        .step { counter-increment: step; display: grid; grid-template-columns: 72px minmax(0, 1fr); gap: 20px; padding: 25px 0; border-bottom: 1px solid var(--line); }
        .step::before { content: "0" counter(step); color: var(--coral); font-family: "Manrope", sans-serif; font-size: 1.25rem; font-weight: 800; }
        .step h3 { margin: 0 0 6px; font-size: 1.2rem; font-weight: 800; }
        .step p { margin: 0; max-width: 680px; color: var(--muted); line-height: 1.65; }

        .why-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 0 36px; border-top: 1px solid rgba(255,255,255,.24); }
        .why-item { padding: 26px 0; border-bottom: 1px solid rgba(255,255,255,.24); }
        .why-number { color: var(--lime); font: 800 1rem "Manrope", sans-serif; }
        .why-item h3 { margin: 15px 0 8px; font-size: 1.15rem; font-weight: 800; }
        .why-item p { margin: 0; color: rgba(255,255,255,.72); line-height: 1.65; }

        .services-section { background: var(--white); }
        .service-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); border-top: 1px solid var(--line); }
        .service-item { min-height: 140px; padding: 22px 22px 22px 0; border-bottom: 1px solid var(--line); }
        .service-item h3 { min-height: 48px; margin: 0 0 10px; font-size: 1.05rem; line-height: 1.35; font-weight: 800; }
        .service-meta { display: flex; justify-content: space-between; gap: 10px; color: var(--muted); font-size: .88rem; }
        .service-price { color: var(--forest); font-weight: 800; }

        .closing { padding: 72px 0; background: var(--coral); color: #291c18; }
        .closing-inner { display: flex; justify-content: space-between; align-items: center; gap: 32px; flex-wrap: wrap; }
        .closing h2 { max-width: 650px; margin: 0; font-size: clamp(2rem, 4vw, 3.4rem); font-weight: 800; }
        .closing .button-primary { background: var(--forest-deep); color: #fff !important; border-color: var(--forest-deep); }
        .closing .button-primary:hover { background: var(--forest); }

        footer { padding: 25px 0; background: var(--forest-deep); color: rgba(255,255,255,.7); font-size: .9rem; }
        footer .container { display: flex; justify-content: space-between; align-items: center; gap: 16px; flex-wrap: wrap; }
        footer a { color: #fff; text-decoration: none; }

        @media (max-width: 767.98px) {
            .nav-inner { min-height: 70px; }
            .nav-links { gap: 12px; }
            .nav-links .nav-section-link { display: none; }
            .nav-cta { min-height: 40px; padding: 0 13px; font-size: .85rem; }
            .hero { min-height: 650px; }
            .hero-content { padding-top: 100px; }
            .hero-copy { font-size: 1rem; }
            .section { padding: 68px 0; }
            .why-grid, .service-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0 20px; }
            .service-item { padding-right: 10px; }
        }

        @media (max-width: 480px) {
            .why-grid, .service-grid { grid-template-columns: 1fr; }
            .step { grid-template-columns: 48px minmax(0, 1fr); gap: 12px; }
            .hero-actions { align-items: flex-start; flex-direction: column; }
        }
    </style>
</head>
<body>
    <header class="site-nav">
        <div class="container nav-inner">
            <a href="index.php" class="brand">CleanManage</a>
            <nav class="nav-links" aria-label="Main navigation">
                <a class="nav-section-link" href="#how-it-works">How it works</a>
                <a class="nav-section-link" href="#services">Services</a>
                <a href="login.php">Sign in</a>
                <a class="nav-cta" href="signup.php">Book a clean</a>
            </nav>
        </div>
    </header>

    <main>
        <section class="hero" aria-labelledby="hero-title">
            <img class="hero-image" src="assets/cleaning-hero.jpg" alt="Cleaner carefully dusting a bathroom mirror" fetchpriority="high">
            <div class="hero-shade"></div>
            <div class="container hero-content">
                <div class="eyebrow">Cleaning, clearly managed</div>
                <h1 id="hero-title">A cleaner space.<br><span>Less to manage.</span></h1>
                <p class="hero-copy">Book home or business cleaning, see what it costs, and follow your service from request to completion in one place.</p>
                <div class="hero-actions">
                    <a href="signup.php" class="button-primary">Create an account</a>
                    <a href="#services" class="button-secondary">Explore cleaning services</a>
                </div>
                <div class="hero-footnote">Residential · Commercial · Move-in and move-out</div>
            </div>
        </section>

        <div class="intro-band"><div class="container"><span>Choose a service</span><span>Request a date</span><span>Track your booking</span><span>Confirm and review</span></div></div>

        <section class="section" id="how-it-works">
            <div class="container">
                <div class="section-heading">
                    <div class="eyebrow eyebrow-dark">A straightforward process</div>
                    <h2>From booking request to a finished clean.</h2>
                    <p>Keep the details together. Your booking, service date, updates, payment submissions and feedback are available through your customer account.</p>
                </div>
                <div class="steps">
                    <article class="step"><div><h3>Choose your service</h3><p>Compare the cleaning options and listed prices, then select the service that fits your home or workplace.</p></div></article>
                    <article class="step"><div><h3>Request a date</h3><p>Create an account, choose a preferred date and share any access notes. The team reviews the request and arranges the cleaner.</p></div></article>
                    <article class="step"><div><h3>Follow the booking</h3><p>Check the booking status and payment record from your account. Once service is marked complete, confirm delivery and leave feedback.</p></div></article>
                </div>
            </div>
        </section>

        <section class="section section-dark" id="why-cleanmanage">
            <div class="container">
                <div class="section-heading">
                    <div class="eyebrow">Why CleanManage</div>
                    <h2>Good service starts with clear coordination.</h2>
                    <p>CleanManage brings customer requests and the company’s service workflow into one organized system.</p>
                </div>
                <div class="why-grid">
                    <article class="why-item"><div class="why-number">01</div><h3>Know the service price</h3><p>See the current catalog price before requesting a booking.</p></article>
                    <article class="why-item"><div class="why-number">02</div><h3>Keep booking details together</h3><p>Review your service, preferred date, status and payment submissions in your account.</p></article>
                    <article class="why-item"><div class="why-number">03</div><h3>Follow service progress</h3><p>Company staff coordinate cleaner assignments and update booking progress.</p></article>
                    <article class="why-item"><div class="why-number">04</div><h3>One account for your details</h3><p>Maintain your contact details and service address for future requests.</p></article>
                    <article class="why-item"><div class="why-number">05</div><h3>Close the loop</h3><p>Confirm completed service and submit a rating or comment for the team.</p></article>
                    <article class="why-item"><div class="why-number">06</div><h3>Designed for the whole company</h3><p>Managers, cleaners, finance, owners and admins each have role-specific workspaces.</p></article>
                </div>
            </div>
        </section>

        <section class="section services-section" id="services">
            <div class="container">
                <div class="section-heading">
                    <div class="eyebrow eyebrow-dark">Our services</div>
                    <h2>Cleaning for the spaces you use every day.</h2>
                    <p>Service names and prices below are loaded from the CleanManage service catalog.</p>
                </div>
                <?php if ($landingServices): ?>
                    <div class="service-grid">
                        <?php foreach ($landingServices as $service): ?>
                            <article class="service-item">
                                <h3><?= htmlspecialchars($service['service_name']) ?></h3>
                                <div class="service-meta"><span><?= htmlspecialchars((string)$service['duration_hours']) ?> hrs · <?= (int)$service['required_staff_count'] ?> cleaner<?= (int)$service['required_staff_count'] === 1 ? '' : 's' ?></span><span class="service-price">R <?= number_format((float)$service['price'], 2) ?></span></div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-muted">Services are being updated. Please check back soon.</p>
                <?php endif; ?>
            </div>
        </section>

        <section class="closing">
            <div class="container closing-inner">
                <h2>Make your next clean easier to arrange.</h2>
                <a href="signup.php" class="button-primary">Start a booking</a>
            </div>
        </section>
    </main>

    <footer><div class="container"><span>CleanManage · Cleaning, clearly managed</span><span><a href="login.php">Sign in</a> · <a href="signup.php">Create account</a></span></div></footer>
</body>
</html>