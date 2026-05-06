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
            box-shadow: 0 24px 60px -20px rgba(46,182,224,0.25), 0 8px 24px rgba(20,28,80,0.08);
            border: 1px solid #eceef6;
        }
        .email-header {
            background: linear-gradient(135deg, #2eb6e0 0%, #1e3aa8 100%);
            color: #ffffff;
            padding: 44px 36px;
            text-align: center;
            position: relative;
        }
        .email-header::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: radial-gradient(circle at 80% 20%, rgba(255,255,255,0.12) 0%, transparent 55%),
                        radial-gradient(circle at 20% 80%, rgba(255,255,255,0.06) 0%, transparent 60%);
            pointer-events: none;
        }
        .email-header-content {
            position: relative;
            z-index: 1;
        }
        .logo { margin-bottom: 18px; }
        .logo img { height: 44px; width: auto; }
        .email-header h1 {
            margin: 0;
            font-family: 'Onest', 'Inter', sans-serif;
            font-size: 26px;
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
        .webinar-card {
            background: #f6f7fb;
            border-radius: 20px;
            padding: 26px;
            margin: 24px 0;
            border: 1px solid #eceef6;
            border-left: 4px solid #2eb6e0;
        }
        .webinar-card h3 {
            margin: 0 0 14px 0;
            color: #182f8a;
            font-family: 'Onest', 'Inter', sans-serif;
            font-size: 19px;
            font-weight: 700;
            letter-spacing: -0.01em;
        }
        .webinar-details {
            color: #5a608a;
            font-size: 15px;
        }
        .webinar-details p {
            margin: 10px 0;
        }
        .webinar-details .icon {
            width: 20px;
            margin-right: 10px;
            text-align: center;
            display: inline-block;
        }
        .speaker-card {
            background: #f6f7fb;
            border-radius: 16px;
            padding: 18px;
            margin: 20px 0;
            border: 1px solid #eceef6;
        }
        .speaker-photo {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            object-fit: cover;
            margin-right: 14px;
            border: 3px solid #2eb6e0;
            vertical-align: middle;
        }
        .speaker-info {
            display: inline-block;
            vertical-align: middle;
        }
        .speaker-info h4 {
            margin: 0 0 4px 0;
            color: #0e1330;
            font-family: 'Onest', 'Inter', sans-serif;
            font-size: 16px;
            font-weight: 600;
        }
        .speaker-info p {
            margin: 0;
            color: #5a608a;
            font-size: 14px;
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
        .cta-button-green {
            background: linear-gradient(135deg, #18b89a 0%, #0e9a82 100%);
            box-shadow: 0 8px 22px rgba(24,184,154,0.32);
        }
        .cta-button-secondary {
            background: #ecefff;
            color: #1e3aa8 !important;
            box-shadow: none;
        }
        .info-block {
            background: #fff7e0;
            border: 1px solid #f5e3a8;
            border-radius: 14px;
            padding: 18px 22px;
            margin: 20px 0;
            color: #7a4f00;
        }
        .info-block p {
            margin: 0;
            font-size: 14px;
        }
        .certificate-card {
            background: linear-gradient(135deg, #fff7e0 0%, #ffe9d1 100%);
            border: 1px solid #f5e3a8;
            border-radius: 20px;
            padding: 26px;
            margin: 24px 0;
            text-align: center;
        }
        .certificate-card h3 {
            margin: 0 0 10px 0;
            color: #7a4f00;
            font-family: 'Onest', 'Inter', sans-serif;
            font-size: 19px;
            font-weight: 700;
        }
        .certificate-card .price {
            font-size: 32px;
            font-weight: 700;
            color: #b85a16;
            margin: 10px 0;
            font-family: 'Onest', 'Inter', sans-serif;
            letter-spacing: -0.02em;
        }
        .certificate-card .price small {
            font-size: 16px;
            font-weight: 500;
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
        .badge {
            display: inline-block;
            background: #d8f1f8;
            color: #1e3aa8;
            padding: 5px 14px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 10px;
            letter-spacing: 0.02em;
        }
        .broadcast-link-box {
            background: linear-gradient(135deg, #18b89a 0%, #0e9a82 100%);
            border-radius: 22px;
            padding: 30px;
            margin: 24px 0;
            text-align: center;
            color: #ffffff;
        }
        .broadcast-link-box h2 {
            margin: 0 0 14px 0;
            font-family: 'Onest', 'Inter', sans-serif;
            font-size: 22px;
            font-weight: 700;
        }
        .broadcast-link-box .cta-button {
            background: #ffffff;
            color: #0e9a82 !important;
            box-shadow: 0 8px 22px rgba(0,0,0,0.18);
        }
        .broadcast-url-text {
            margin-top: 14px;
            font-size: 12px;
            opacity: 0.92;
            word-break: break-all;
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
            .webinar-card { padding: 20px; border-radius: 16px; }
            .speaker-card { text-align: center; }
            .speaker-photo {
                margin-right: 0;
                margin-bottom: 10px;
                display: block;
                margin-left: auto;
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
                <p>Вебинары и конкурсы для педагогов</p>
                <p><a href="<?php echo htmlspecialchars($site_url); ?>">fgos.pro</a></p>
                <div class="unsubscribe-link">
                    Вы получили это письмо, потому что зарегистрировались на вебинар.<br>
                    <a href="<?php echo htmlspecialchars($unsubscribe_url); ?>">Отписаться от рассылки</a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
