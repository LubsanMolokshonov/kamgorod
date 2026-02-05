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
            color: #2C3E50;
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
            box-shadow: 0 10px 40px rgba(0, 119, 255, 0.1);
        }
        .email-header {
            background: linear-gradient(135deg, #0077FF 0%, #0088FF 50%, #3399FF 100%);
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
        .email-header h1 {
            margin: 0;
            font-size: 26px;
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
            color: #2C3E50;
            font-weight: 500;
        }
        .webinar-card {
            background: linear-gradient(135deg, #E8F1FF 0%, #f8fafc 100%);
            border-radius: 16px;
            padding: 25px;
            margin: 25px 0;
            border-left: 4px solid #0077FF;
        }
        .webinar-card h3 {
            margin: 0 0 15px 0;
            color: #0077FF;
            font-size: 18px;
            font-weight: 600;
        }
        .webinar-details {
            color: #4A5568;
            font-size: 15px;
        }
        .webinar-details p {
            margin: 10px 0;
            display: flex;
            align-items: center;
        }
        .webinar-details .icon {
            width: 20px;
            margin-right: 10px;
            text-align: center;
        }
        .speaker-card {
            display: flex;
            align-items: center;
            background: #f8fafc;
            border-radius: 12px;
            padding: 15px;
            margin: 20px 0;
        }
        .speaker-photo {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            object-fit: cover;
            margin-right: 15px;
            border: 3px solid #0077FF;
        }
        .speaker-info h4 {
            margin: 0 0 5px 0;
            color: #2C3E50;
            font-size: 16px;
        }
        .speaker-info p {
            margin: 0;
            color: #718096;
            font-size: 14px;
        }
        .cta-button {
            display: inline-block;
            background: linear-gradient(135deg, #0077FF 0%, #0066DD 100%);
            color: #ffffff !important;
            text-decoration: none;
            padding: 18px 50px;
            border-radius: 50px;
            font-size: 16px;
            font-weight: 600;
            margin: 25px 0;
            box-shadow: 0 4px 14px rgba(0, 119, 255, 0.4);
            transition: all 0.2s ease;
        }
        .cta-button:hover {
            box-shadow: 0 6px 20px rgba(0, 119, 255, 0.5);
            transform: translateY(-2px);
        }
        .cta-button-green {
            background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
            box-shadow: 0 4px 14px rgba(34, 197, 94, 0.4);
        }
        .cta-button-green:hover {
            box-shadow: 0 6px 20px rgba(34, 197, 94, 0.5);
        }
        .cta-button-secondary {
            background: #ebebf0;
            color: #0077FF !important;
            box-shadow: none;
        }
        .cta-button-secondary:hover {
            background: #E8F1FF;
        }
        .info-block {
            background: #FDF6E3;
            border-radius: 12px;
            padding: 20px;
            margin: 20px 0;
            border-left: 4px solid #F4C430;
        }
        .info-block p {
            margin: 0;
            color: #92400e;
            font-size: 14px;
        }
        .certificate-card {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border-radius: 16px;
            padding: 25px;
            margin: 25px 0;
            text-align: center;
        }
        .certificate-card h3 {
            margin: 0 0 10px 0;
            color: #92400e;
            font-size: 18px;
        }
        .certificate-card .price {
            font-size: 32px;
            font-weight: 700;
            color: #d97706;
            margin: 10px 0;
        }
        .certificate-card .price small {
            font-size: 16px;
            font-weight: 400;
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
            color: #0077FF;
            text-decoration: none;
            font-weight: 500;
        }
        .email-footer a:hover {
            text-decoration: underline;
        }
        .footer-brand {
            font-weight: 600;
            color: #2C3E50;
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
            color: #718096;
        }
        .badge {
            display: inline-block;
            background: #E8F1FF;
            color: #0077FF;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 10px;
        }
        .broadcast-link-box {
            background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
            border-radius: 16px;
            padding: 30px;
            margin: 25px 0;
            text-align: center;
            color: white;
        }
        .broadcast-link-box h2 {
            margin: 0 0 15px 0;
            font-size: 22px;
        }
        .broadcast-link-box .cta-button {
            background: white;
            color: #16a34a !important;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.2);
        }
        .broadcast-url-text {
            margin-top: 15px;
            font-size: 12px;
            opacity: 0.9;
            word-break: break-all;
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
            .webinar-card {
                padding: 20px;
            }
            .speaker-card {
                flex-direction: column;
                text-align: center;
            }
            .speaker-photo {
                margin-right: 0;
                margin-bottom: 10px;
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
