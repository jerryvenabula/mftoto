<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml"
      lang="id"
      xml:lang="id">

<head>
    <meta http-equiv="Content-Type"
          content="application/xhtml+xml; charset=UTF-8" />

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0" />

    <meta name="robots" content="noindex, nofollow" />

    <title>Testing &amp; Maintenance | Created by Jerry</title>

    <meta name="description"
          content="Halaman testing dan maintenance website. Created by Jerry." />

    <style type="text/css">
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html,
        body {
            width: 100%;
            min-height: 100%;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            background: #070b14;
            color: #ffffff;
            overflow-x: hidden;
        }

        .page {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 30px 20px;
            position: relative;
            overflow: hidden;
        }

        /* Background */
        .glow {
            position: absolute;
            width: 420px;
            height: 420px;
            border-radius: 50%;
            background: rgba(0, 200, 255, 0.10);
            filter: blur(80px);
            top: -150px;
            left: -120px;
        }

        .glow-two {
            position: absolute;
            width: 400px;
            height: 400px;
            border-radius: 50%;
            background: rgba(125, 70, 255, 0.10);
            filter: blur(90px);
            right: -130px;
            bottom: -150px;
        }

        .grid {
            position: absolute;
            inset: 0;
            opacity: 0.12;
            background-image:
                linear-gradient(rgba(255,255,255,0.08) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255,255,255,0.08) 1px, transparent 1px);
            background-size: 45px 45px;
        }

        /* Card */
        .container {
            width: 100%;
            max-width: 760px;
            position: relative;
            z-index: 2;
        }

        .card {
            position: relative;
            padding: 55px 45px;
            text-align: center;
            background: rgba(13, 19, 32, 0.88);
            border: 1px solid rgba(255,255,255,0.10);
            border-radius: 28px;
            box-shadow:
                0 30px 80px rgba(0,0,0,0.45),
                inset 0 1px rgba(255,255,255,0.06);
            backdrop-filter: blur(18px);
        }

        /* Status */
        .status {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 9px 16px;
            border-radius: 999px;
            background: rgba(255, 180, 0, 0.08);
            border: 1px solid rgba(255, 190, 0, 0.20);
            color: #ffc857;
            font-size: 12px;
            font-weight: bold;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            margin-bottom: 28px;
        }

        .status-dot {
            width: 8px;
            height: 8px;
            background: #ffc857;
            border-radius: 50%;
            box-shadow: 0 0 12px rgba(255,200,80,0.8);
            animation: pulse 1.6s infinite;
        }

        @keyframes pulse {
            0%, 100% {
                opacity: 1;
                transform: scale(1);
            }

            50% {
                opacity: 0.45;
                transform: scale(0.75);
            }
        }

        /* Icon */
        .icon {
            width: 90px;
            height: 90px;
            margin: 0 auto 28px;
            border-radius: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 42px;
            background: linear-gradient(
                145deg,
                rgba(0, 200, 255, 0.16),
                rgba(125, 70, 255, 0.16)
            );
            border: 1px solid rgba(0, 210, 255, 0.20);
            box-shadow:
                0 0 35px rgba(0, 200, 255, 0.10);
        }

        h1 {
            font-size: clamp(34px, 6vw, 58px);
            line-height: 1.05;
            letter-spacing: -2px;
            margin-bottom: 18px;
        }

        .gradient {
            background: linear-gradient(
                90deg,
                #ffffff,
                #65dfff,
                #9b7cff
            );
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .subtitle {
            max-width: 560px;
            margin: 0 auto;
            color: #9ba7ba;
            font-size: 16px;
            line-height: 1.8;
        }

        /* Info */
        .info {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-top: 35px;
        }

        .info-box {
            padding: 17px 12px;
            border-radius: 15px;
            background: rgba(255,255,255,0.035);
            border: 1px solid rgba(255,255,255,0.07);
        }

        .info-title {
            display: block;
            color: #68758a;
            font-size: 10px;
            letter-spacing: 1px;
            text-transform: uppercase;
            margin-bottom: 7px;
        }

        .info-value {
            color: #e9eef7;
            font-size: 13px;
            font-weight: bold;
        }

        /* Button */
        .actions {
            margin-top: 35px;
        }

        .btn {
            display: inline-block;
            padding: 14px 26px;
            border-radius: 12px;
            color: #ffffff;
            text-decoration: none;
            font-size: 13px;
            font-weight: bold;
            border: 1px solid rgba(255,255,255,0.12);
            background: rgba(255,255,255,0.05);
            transition: 0.25s ease;
        }

        .btn:hover {
            transform: translateY(-2px);
            background: rgba(255,255,255,0.10);
        }

        /* Footer */
        .footer {
            margin-top: 38px;
            padding-top: 20px;
            border-top: 1px solid rgba(255,255,255,0.07);
            color: #596579;
            font-size: 11px;
            letter-spacing: 0.5px;
        }

        .footer strong {
            color: #8794a8;
        }

        /* Mobile */
        @media (max-width: 600px) {

            .page {
                padding: 20px 14px;
            }

            .card {
                padding: 40px 22px;
                border-radius: 22px;
            }

            .icon {
                width: 76px;
                height: 76px;
                font-size: 34px;
                border-radius: 20px;
            }

            h1 {
                letter-spacing: -1px;
            }

            .subtitle {
                font-size: 14px;
            }

            .info {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

<div class="page">

    <div class="glow"></div>
    <div class="glow-two"></div>
    <div class="grid"></div>

    <main class="container">

        <section class="card">

            <div class="status">
                <span class="status-dot"></span>
                System Maintenance
            </div>

            <div class="icon">
                &#9881;
            </div>

            <h1>
                <span class="gradient">Website Testing</span>
            </h1>

            <p class="subtitle">
                Halaman ini sedang dalam tahap pengembangan,
                testing, dan maintenance. Kami sedang melakukan
                beberapa penyesuaian agar sistem dapat berjalan
                dengan optimal.
            </p>

            <div class="info">

                <div class="info-box">
                    <span class="info-title">Status</span>
                    <span class="info-value">Testing</span>
                </div>

                <div class="info-box">
                    <span class="info-title">System</span>
                    <span class="info-value">Online</span>
                </div>

                <div class="info-box">
                    <span class="info-title">Version</span>
                    <span class="info-value">v1.0.0</span>
                </div>

            </div>

            <div class="actions">
                <a class="btn" href="">
                    Refresh Page
                </a>
            </div>

            <footer class="footer">
                Created by <strong>Jerry</strong>
                &#8226;
                Testing &amp; Maintenance
            </footer>

        </section>

    </main>

</div>

</body>
</html>
