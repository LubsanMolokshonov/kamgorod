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
            font-family: 'Onest', 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            line-height: 1.6;
            color: #0e1330;
            margin: 0;
            padding: 0;
            background-color: #fbfbfd;
            -webkit-font-smoothing: antialiased;
        }
        .email-wrapper {
            background-color: #fbfbfd;
            padding: 32px 16px;
        }
        .email-container {
            max-width: 600px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 28px;
            overflow: hidden;
            box-shadow: 0 24px 60px -20px rgba(46,77,217,0.25), 0 8px 24px rgba(20,28,80,0.08);
            border: 1px solid #eceef6;
        }
        .email-header {
            background: linear-gradient(135deg, #1e3aa8 0%, #182f8a 50%, #12246d 100%);
            color: #ffffff;
            padding: 44px 36px;
            text-align: center;
            position: relative;
        }
        .email-header::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: radial-gradient(circle at 80% 20%, rgba(255,255,255,0.10) 0%, transparent 55%),
                        radial-gradient(circle at 20% 80%, rgba(46,182,224,0.18) 0%, transparent 60%);
            pointer-events: none;
        }
        .email-header-content {
            position: relative;
            z-index: 1;
        }
        .logo { margin-bottom: 18px; }
        .logo img { height: 44px; width: auto; }
        .logo-text {
            font-size: 22px;
            font-weight: 700;
            letter-spacing: -0.5px;
        }
        .email-header h1 {
            margin: 0;
            font-family: 'Onest', 'Inter', -apple-system, sans-serif;
            font-size: 28px;
            font-weight: 700;
            line-height: 1.2;
            letter-spacing: -0.02em;
        }
        .email-header p {
            margin: 14px 0 0 0;
            opacity: 0.92;
            font-size: 16px;
            font-weight: 400;
        }
        .email-content {
            padding: 40px 36px;
            color: #2a3056;
            font-size: 16px;
        }
        .email-content a { color: #1e3aa8; }
        .greeting {
            font-size: 18px;
            margin-bottom: 20px;
            color: #0e1330;
            font-weight: 600;
            font-family: 'Onest', 'Inter', sans-serif;
        }
        .competition-card {
            background: #f6f7fb;
            border-radius: 20px;
            padding: 26px;
            margin: 24px 0;
            border: 1px solid #eceef6;
        }
        .competition-card h3 {
            margin: 0 0 14px 0;
            color: #182f8a;
            font-family: 'Onest', 'Inter', sans-serif;
            font-size: 19px;
            font-weight: 700;
            letter-spacing: -0.01em;
        }
        .competition-details {
            color: #5a608a;
            font-size: 14.5px;
        }
        .competition-details p {
            margin: 8px 0;
        }
        .competition-details strong {
            color: #2a3056;
            min-width: 140px;
            display: inline-block;
        }
        .price-tag {
            font-size: 32px;
            font-weight: 700;
            color: #182f8a;
            margin: 20px 0 0 0;
            font-family: 'Onest', 'Inter', sans-serif;
            letter-spacing: -0.02em;
        }
        .price-tag small {
            font-size: 16px;
            font-weight: 500;
            color: #5a608a;
        }
        .cta-button {
            display: inline-block;
            background: linear-gradient(135deg, #1e3aa8 0%, #182f8a 100%);
            color: #ffffff !important;
            text-decoration: none;
            padding: 17px 44px;
            border-radius: 14px;
            font-size: 16px;
            font-weight: 600;
            font-family: 'Onest', 'Inter', sans-serif;
            margin: 24px 0;
            box-shadow: 0 8px 22px rgba(30,58,168,0.32);
            letter-spacing: -0.005em;
        }
        .cta-button-outline {
            background: #ffffff;
            border: 2px solid #1e3aa8;
            color: #1e3aa8 !important;
            box-shadow: none;
            padding: 15px 42px;
        }
        .cta-button-green {
            background: linear-gradient(135deg, #18b89a 0%, #0e9a82 100%);
            box-shadow: 0 8px 22px rgba(24,184,154,0.32);
        }
        .benefits-list {
            list-style: none;
            padding: 0;
            margin: 24px 0;
        }
        .benefits-list li {
            padding: 12px 0 12px 40px;
            position: relative;
            color: #2a3056;
            font-size: 15.5px;
            border-bottom: 1px solid #eceef6;
        }
        .benefits-list li:last-child { border-bottom: none; }
        .benefits-list li:before {
            content: "";
            position: absolute;
            left: 0;
            top: 13px;
            width: 24px;
            height: 24px;
            background: linear-gradient(135deg, #18b89a 0%, #0e9a82 100%);
            border-radius: 50%;
        }
        .benefits-list li:after {
            content: "\2713";
            position: absolute;
            left: 7px;
            top: 13px;
            color: #ffffff;
            font-weight: 700;
            font-size: 13px;
        }
        .urgency-banner {
            background: #fff7e0;
            border: 1px solid #f5e3a8;
            border-radius: 14px;
            padding: 18px 22px;
            margin: 24px 0;
            text-align: center;
            color: #7a4f00;
            font-weight: 500;
        }
        .urgency-banner.critical {
            background: #fff0f3;
            border-color: #fbc8d1;
            color: #a01030;
        }
        .promo-banner {
            background: linear-gradient(135deg, #1e3aa8 0%, #2eb6e0 100%);
            border-radius: 24px;
            padding: 30px;
            margin: 24px 0;
            text-align: center;
            color: #ffffff;
            position: relative;
            overflow: hidden;
        }
        .promo-banner::before {
            content: '';
            position: absolute;
            top: -50%; right: -50%;
            width: 100%; height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.12) 0%, transparent 60%);
            pointer-events: none;
        }
        .promo-banner h2 {
            margin: 0 0 10px 0;
            font-family: 'Onest', 'Inter', sans-serif;
            font-size: 22px;
            font-weight: 700;
            letter-spacing: -0.01em;
            position: relative;
        }
        .promo-banner p {
            margin: 0;
            font-size: 16px;
            opacity: 0.95;
            position: relative;
        }
        .promo-banner .promo-code {
            display: inline-block;
            background: rgba(255,255,255,0.22);
            padding: 9px 22px;
            border-radius: 999px;
            font-weight: 700;
            font-size: 18px;
            margin-top: 16px;
            letter-spacing: 1px;
        }
        .info-card {
            background: #f6f7fb;
            border: 1px solid #eceef6;
            border-radius: 16px;
            padding: 20px;
            margin: 20px 0;
        }
        .info-card-icon {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, #1e3aa8 0%, #182f8a 100%);
            border-radius: 14px;
            display: inline-block;
            text-align: center;
            line-height: 48px;
            margin-right: 14px;
            font-size: 22px;
            color: #ffffff;
            vertical-align: middle;
        }
        .info-card-content { display: inline-block; vertical-align: middle; max-width: 80%; }
        .info-card-content h4 {
            margin: 0 0 4px 0;
            color: #0e1330;
            font-family: 'Onest', 'Inter', sans-serif;
            font-size: 16px;
            font-weight: 600;
        }
        .info-card-content p {
            margin: 0;
            color: #5a608a;
            font-size: 14px;
        }
        .email-footer {
            background: #f6f7fb;
            padding: 30px 36px;
            text-align: center;
            font-size: 14px;
            color: #5a608a;
            border-top: 1px solid #eceef6;
        }
        .email-footer p { margin: 8px 0; }
        .email-footer a {
            color: #1e3aa8;
            text-decoration: none;
            font-weight: 500;
        }
        .email-footer a:hover { text-decoration: underline; }
        .footer-brand {
            font-weight: 700;
            color: #0e1330;
            font-size: 16px;
            margin-bottom: 4px;
            font-family: 'Onest', 'Inter', sans-serif;
            letter-spacing: -0.01em;
        }
        .unsubscribe-link {
            margin-top: 22px;
            padding-top: 18px;
            border-top: 1px solid #eceef6;
            font-size: 12px;
            color: #8389ad;
            line-height: 1.55;
        }
        .unsubscribe-link a {
            color: #8389ad;
            font-weight: 400;
            text-decoration: underline;
        }
        .text-center { text-align: center; }
        .text-muted { color: #5a608a; }
        .text-small { font-size: 14px; }
        .badge {
            display: inline-block;
            background: #ecefff;
            color: #182f8a;
            padding: 5px 14px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 10px;
            letter-spacing: 0.02em;
        }
        .badge-green {
            background: #d6f5ec;
            color: #0e9a82;
        }
        .badge-orange {
            background: #ffe9d1;
            color: #b85a16;
        }
        @media only screen and (max-width: 600px) {
            .email-wrapper { padding: 16px 10px; }
            .email-container { border-radius: 20px; }
            .email-header { padding: 32px 24px; }
            .email-header h1 { font-size: 22px; }
            .email-content { padding: 28px 24px; }
            .email-footer { padding: 24px 20px; }
            .cta-button {
                display: block;
                text-align: center;
                padding: 16px 22px;
            }
            .competition-card { padding: 20px; border-radius: 16px; }
            .price-tag { font-size: 26px; }
            .promo-banner { padding: 24px 20px; border-radius: 20px; }
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
                    Вы получили это письмо, потому что <?php echo $footer_reason ?? 'зарегистрировались на конкурс на нашем портале'; ?>.<br>
                    <a href="<?php echo htmlspecialchars($unsubscribe_url); ?>">Отписаться от рассылки</a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
