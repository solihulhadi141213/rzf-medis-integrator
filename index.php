<?php
// Landing Page RZF Medis Integrator
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RZF Medis Integrator</title>

    <!-- Bootstrap & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.5/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">

    <style>
        body {
            font-family: "Segoe UI", sans-serif;
            background: #f4f8fb;
            overflow-x: hidden;
            scroll-behavior: smooth;
            padding-top: 88px;
        }

        .hero {
            position: relative;
            min-height: 100vh;
            background: linear-gradient(135deg, #0d6efd, #20c997);
            color: #fff;
            overflow: hidden;
        }

        .hero::before {
            content: '';
            position: absolute;
            width: 420px;
            height: 420px;
            background: rgba(255, 255, 255, .12);
            border-radius: 50%;
            top: -120px;
            right: -120px;
        }

        .hero::after {
            content: '';
            position: absolute;
            width: 350px;
            height: 350px;
            background: rgba(255, 255, 255, .08);
            border-radius: 50%;
            bottom: -120px;
            left: -120px;
        }

        .site-navbar {
            background: rgba(10, 25, 47, .92);
            backdrop-filter: blur(14px);
            border-bottom: 1px solid rgba(255, 255, 255, .08);
            box-shadow: 0 10px 30px rgba(0, 0, 0, .18);
        }

        .site-navbar .navbar-brand,
        .site-navbar .nav-link {
            color: #fff !important;
        }

        .site-navbar .nav-link {
            opacity: .9;
            font-weight: 500;
            padding-left: .9rem;
            padding-right: .9rem;
        }

        .site-navbar .nav-link:hover,
        .site-navbar .nav-link:focus,
        .site-navbar .nav-link.active {
            opacity: 1;
            color: #8be9fd !important;
        }

        .navbar-toggler {
            border-color: rgba(255, 255, 255, .25);
        }

        .navbar-toggler:focus {
            box-shadow: 0 0 0 .2rem rgba(13, 110, 253, .25);
        }

        .hero-content {
            position: relative;
            z-index: 2;
        }

        .glass {
            background: rgba(255, 255, 255, .15);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, .2);
            border-radius: 20px;
        }

        .feature-card {
            border: none;
            border-radius: 18px;
            transition: .3s;
            height: 100%;
        }

        .feature-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 20px 35px rgba(0, 0, 0, .15);
        }

        .feature-icon {
            width: 65px;
            height: 65px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #0d6efd;
            color: white;
            font-size: 28px;
            margin-bottom: 15px;
        }

        .stat-box {
            text-align: center;
        }

        .stat-box h2 {
            font-weight: 700;
            margin-bottom: 0;
        }

        .btn-doc {
            padding: 12px 28px;
            border-radius: 50px;
        }

        footer {
            color: #6c757d;
            padding: 30px 0;
        }
    </style>
</head>
<body>

    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark site-navbar fixed-top py-3">
        <div class="container">
            <a class="navbar-brand fw-bold" href="#">
                <i class="bi bi-hospital-fill me-2"></i>RZF Medis Integrator
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#topNavbar" aria-controls="topNavbar" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="topNavbar">
                <ul class="navbar-nav ms-auto mb-2 mb-lg-0 align-items-lg-center">
                    <li class="nav-item">
                        <a class="nav-link active" href="#beranda">Beranda</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#fitur">Fitur</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#statistik">Statistik</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#kontak">Kontak</a>
                    </li>
                    <li class="nav-item ms-lg-2">
                        <a class="btn btn-outline-light btn-sm rounded-pill px-3" href="https://github.com/solihulhadi141213/rzf-medis-integrator" target="_blank" rel="noopener">
                            <i class="bi bi-github me-1"></i> Repo
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="hero d-flex align-items-center" id="beranda">
        <div class="container hero-content">
            <div class="row align-items-center">
                
                <!-- Kiri: Penjelasan Utama -->
                <div class="col-lg-7">
                    <span class="badge bg-light text-primary px-3 py-2 mb-3">
                        Healthcare Middleware Platform
                    </span>
                    <h1 class="display-4 fw-bold mb-4">
                        Integrasi Sistem Informasi Kesehatan Menjadi Lebih Mudah
                    </h1>
                    <p class="lead mb-4">
                        Platform middleware untuk menghubungkan SIMRS, Klinik, Puskesmas, Laboratorium, PACS, SATUSEHAT, serta berbagai layanan API kesehatan dalam satu sistem yang aman, cepat, dan mudah dikembangkan.
                    </p>

                    <div class="d-flex gap-3 flex-wrap">
                        <a href="https://github.com/solihulhadi141213/rzf-medis-integrator" class="btn btn-light btn-lg btn-doc">
                            <i class="bi bi-github me-2"></i> GITHUB
                        </a>
                        <a href="https://rsuelsyifa.postman.co/workspace/c0687880-a5b8-40fd-91fa-b141d98ad5dc" class="btn btn-outline-light btn-lg btn-doc">
                            <i class="bi bi-code-slash me-2"></i> REST API
                        </a>
                    </div>

                    <div class="row mt-5" id="statistik">
                        <div class="col-4 stat-box">
                            <h2>REST</h2>
                            <small>API Service</small>
                        </div>
                        <div class="col-4 stat-box">
                            <h2>24/7</h2>
                            <small>Available</small>
                        </div>
                        <div class="col-4 stat-box">
                            <h2>Secure</h2>
                            <small>Token Authentication</small>
                        </div>
                    </div>
                </div>

                <!-- Kanan: Fitur Utama (Glassmorphism) -->
                <div class="col-lg-5 mt-5 mt-lg-0">
                    <div class="glass p-4 shadow-lg" id="fitur">
                        <h4 class="mb-4 text-white">
                            <i class="bi bi-stars me-2"></i>Fitur Utama
                        </h4>

                        <div class="row g-3">
                            <div class="col-12">
                                <div class="feature-card card p-3">
                                    <div class="feature-icon bg-primary">
                                        <i class="bi bi-shield-lock"></i>
                                    </div>
                                    <h5>Autentikasi Aman</h5>
                                    <p class="mb-0 text-muted">
                                        API Key, Token Authentication, Rate Limiter dan validasi endpoint.
                                    </p>
                                </div>
                            </div>

                            <div class="col-12">
                                <div class="feature-card card p-3">
                                    <div class="feature-icon bg-success">
                                        <i class="bi bi-journal-medical"></i>
                                    </div>
                                    <h5>Referensi Medis</h5>
                                    <p class="mb-0 text-muted">
                                        ICD, Body Site, Region, Terminologi Medis dan data master lainnya.
                                    </p>
                                </div>
                            </div>

                            <div class="col-12">
                                <div class="feature-card card p-3">
                                    <div class="feature-icon bg-warning text-dark">
                                        <i class="bi bi-hdd-network"></i>
                                    </div>
                                    <h5>Storage & Proxy</h5>
                                    <p class="mb-0 text-muted">
                                        Mendukung penyimpanan file, gambar medis, DICOM dan proxy service.
                                    </p>
                                </div>
                            </div>

                            <div class="col-12">
                                <div class="feature-card card p-3">
                                    <div class="feature-icon bg-danger">
                                        <i class="bi bi-diagram-3"></i>
                                    </div>
                                    <h5>Integrasi</h5>
                                    <p class="mb-0 text-muted">
                                        Terintegrasi dengan SIMRS, SATUSEHAT, PACS, LIS dan sistem eksternal.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="text-center" id="kontak">
        <div class="container">
            <strong>RZF Medis Integrator</strong><br>
            <p class="mb-2">Middleware Platform untuk Integrasi Sistem Informasi Kesehatan berbasis REST API.</p>
            <small class="text-muted">
                &copy; <?= date('Y'); ?> RZF Medis Integrator. All Rights Reserved.
            </small>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.5/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
