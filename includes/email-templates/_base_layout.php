<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $email_subject ?? 'ФГОС-Практикум'; ?></title>
    <!--[if mso]>
    <noscript>
        <xml>
            <o:OfficeDocumentSettings>
                <o:PixelsPerInch>96</o:PixelsPerInch>
            </o:OfficeDocumentSettings>
        </xml>
    </noscript>
    <![endif]-->
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            line-height: 1.6;
            color: #333;
            margin: 0;
            padding: 0;
            background-color: #f0f4f8;
            -webkit-font-smoothing: antialiased;
        }
        .email-wrapper {
            background-color: #f0f4f8;
            padding: 30px 15px;
        }
        .email-container {
            max-width: 600px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
        }
        .email-header {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 50%, #1e40af 100%);
            color: white;
            padding: 40px 35px;
            text-align: center;
            position: relative;
        }
        .email-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="80" cy="20" r="40" fill="rgba(255,255,255,0.05)"/><circle cx="20" cy="80" r="30" fill="rgba(255,255,255,0.03)"/></svg>');
            background-size: cover;
            pointer-events: none;
        }
        .email-header-content {
            position: relative;
            z-index: 1;
        }
        .logo {
            margin-bottom: 20px;
        }
        .logo img {
            height: 50px;
            width: auto;
        }
        .logo-text {
            font-size: 22px;
            font-weight: 700;
            letter-spacing: -0.5px;
        }
        .email-header h1 {
            margin: 0;
            font-size: 28px;
            font-weight: 700;
            line-height: 1.3;
            letter-spacing: -0.5px;
        }
        .email-header p {
            margin: 15px 0 0 0;
            opacity: 0.9;
            font-size: 16px;
            font-weight: 400;
        }
        .email-content {
            padding: 40px 35px;
        }
        .greeting {
            font-size: 18px;
            margin-bottom: 20px;
            color: #1e293b;
            font-weight: 500;
        }
        .competition-card {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border-radius: 16px;
            padding: 25px;
            margin: 25px 0;
            border: 1px solid #e2e8f0;
        }
        .competition-card h3 {
            margin: 0 0 15px 0;
            color: #1e40af;
            font-size: 18px;
            font-weight: 600;
        }
        .competition-details {
            color: #64748b;
            font-size: 14px;
        }
        .competition-details p {
            margin: 8px 0;
            display: flex;
            align-items: center;
        }
        .competition-details strong {
            color: #475569;
            min-width: 140px;
        }
        .price-tag {
            font-size: 32px;
            font-weight: 700;
            color: #2563eb;
            margin: 20px 0 0 0;
        }
        .price-tag small {
            font-size: 16px;
            font-weight: 400;
            color: #64748b;
        }
        .cta-button {
            display: inline-block;
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            color: #ffffff !important;
            text-decoration: none;
            padding: 18px 50px;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            margin: 25px 0;
            box-shadow: 0 4px 14px rgba(37, 99, 235, 0.4);
            transition: all 0.2s ease;
        }
        .cta-button:hover {
            box-shadow: 0 6px 20px rgba(37, 99, 235, 0.5);
            transform: translateY(-1px);
        }
        .cta-button-outline {
            background: transparent;
            border: 2px solid #2563eb;
            color: #2563eb !important;
            box-shadow: none;
        }
        .cta-button-outline:hover {
            background: #2563eb;
            color: #ffffff !important;
        }
        .cta-button-green {
            background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
            box-shadow: 0 4px 14px rgba(34, 197, 94, 0.4);
        }
        .benefits-list {
            list-style: none;
            padding: 0;
            margin: 25px 0;
        }
        .benefits-list li {
            padding: 12px 0;
            padding-left: 40px;
            position: relative;
            color: #334155;
            font-size: 15px;
            border-bottom: 1px solid #f1f5f9;
        }
        .benefits-list li:last-child {
            border-bottom: none;
        }
        .benefits-list li:before {
            content: "";
            position: absolute;
            left: 0;
            top: 12px;
            width: 24px;
            height: 24px;
            background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .benefits-list li:after {
            content: "\2713";
            position: absolute;
            left: 6px;
            top: 13px;
            color: white;
            font-weight: bold;
            font-size: 14px;
        }
        .urgency-banner {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border: none;
            border-radius: 12px;
            padding: 20px 25px;
            margin: 25px 0;
            text-align: center;
            color: #92400e;
            font-weight: 500;
        }
        .urgency-banner.critical {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            color: #991b1b;
        }
        .promo-banner {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            border-radius: 16px;
            padding: 30px;
            margin: 25px 0;
            text-align: center;
            color: white;
            position: relative;
            overflow: hidden;
        }
        .promo-banner::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 100%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 60%);
            pointer-events: none;
        }
        .promo-banner h2 {
            margin: 0 0 10px 0;
            font-size: 22px;
            font-weight: 700;
            position: relative;
        }
        .promo-banner p {
            margin: 0;
            font-size: 16px;
            opacity: 0.9;
            position: relative;
        }
        .promo-banner .promo-code {
            display: inline-block;
            background: rgba(255,255,255,0.2);
            padding: 8px 20px;
            border-radius: 8px;
            font-weight: 700;
            font-size: 18px;
            margin-top: 15px;
            letter-spacing: 1px;
        }
        .info-card {
            background: #f8fafc;
            border-radius: 12px;
            padding: 20px;
            margin: 20px 0;
            display: flex;
            align-items: flex-start;
        }
        .info-card-icon {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            flex-shrink: 0;
            font-size: 24px;
        }
        .info-card-content h4 {
            margin: 0 0 5px 0;
            color: #1e293b;
            font-size: 16px;
        }
        .info-card-content p {
            margin: 0;
            color: #64748b;
            font-size: 14px;
        }
        .email-footer {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            padding: 30px 35px;
            text-align: center;
            font-size: 14px;
            color: #64748b;
        }
        .email-footer p {
            margin: 10px 0;
        }
        .email-footer a {
            color: #2563eb;
            text-decoration: none;
            font-weight: 500;
        }
        .email-footer a:hover {
            text-decoration: underline;
        }
        .footer-brand {
            font-weight: 600;
            color: #1e293b;
            font-size: 16px;
            margin-bottom: 5px;
        }
        .unsubscribe-link {
            margin-top: 25px;
            padding-top: 20px;
            border-top: 1px solid #e2e8f0;
            font-size: 12px;
            color: #94a3b8;
        }
        .unsubscribe-link a {
            color: #94a3b8;
            font-weight: 400;
        }
        .text-center {
            text-align: center;
        }
        .text-muted {
            color: #64748b;
        }
        .text-small {
            font-size: 14px;
        }
        .badge {
            display: inline-block;
            background: #dbeafe;
            color: #1d4ed8;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 10px;
        }
        .badge-green {
            background: #dcfce7;
            color: #16a34a;
        }
        .badge-orange {
            background: #ffedd5;
            color: #ea580c;
        }
        @media only screen and (max-width: 600px) {
            .email-wrapper {
                padding: 15px 10px;
            }
            .email-header {
                padding: 30px 25px;
            }
            .email-header h1 {
                font-size: 22px;
            }
            .email-content {
                padding: 30px 25px;
            }
            .email-footer {
                padding: 25px 20px;
            }
            .cta-button {
                display: block;
                text-align: center;
                padding: 16px 25px;
            }
            .competition-card {
                padding: 20px;
            }
            .price-tag {
                font-size: 26px;
            }
        }
    </style>
</head>
<body>
    <div class="email-wrapper">
        <div class="email-container">
            <?php echo $content; ?>

            <div class="email-footer">
                <p class="footer-brand">ФГОС-Практикум</p>
                <p>Всероссийские конкурсы для педагогов и школьников</p>
                <p><a href="<?php echo htmlspecialchars($site_url); ?>">fgos.pro</a></p>
                <div class="unsubscribe-link">
                    Вы получили это письмо, потому что зарегистрировались на конкурс.<br>
                    <a href="<?php echo htmlspecialchars($unsubscribe_url); ?>">Отписаться от рассылки</a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
