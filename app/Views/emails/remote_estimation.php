<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc($subject ?? 'Remote Estimation') ?></title>
</head>
<body style="margin:0;padding:0;background-color:#f4f6f8;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#f4f6f8;padding:24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;background:#ffffff;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;">
                    <tr>
                        <td style="background:#0f172a;padding:20px 24px;text-align:center;">
                            <img src="<?= esc(base_url('assets/images/logo.png')) ?>" alt="Remote Estimation" style="max-width:180px;height:auto;display:block;margin:0 auto 12px auto;">
                            <h1 style="margin:0;color:#ffffff;font-size:22px;line-height:1.3;">Remote Estimation</h1>
                            <p style="margin:6px 0 0 0;color:#cbd5e1;font-size:13px;">Project communication and updates</p>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:24px;">
                            <p style="margin:0 0 14px 0;font-size:15px;">Hello <?= esc($recipientName ?? 'User') ?>,</p>

                            <?php if (! empty($headline ?? '')): ?>
                                <h2 style="margin:0 0 12px 0;font-size:20px;color:#111827;line-height:1.35;"><?= esc($headline) ?></h2>
                            <?php endif; ?>

                            <div style="font-size:15px;line-height:1.7;color:#374151;">
                                <?= $contentHtml ?? '<p style="margin:0;">No content provided.</p>' ?>
                            </div>

                            <?php if (! empty($actionUrl ?? '') && ! empty($actionText ?? '')): ?>
                                <p style="margin:24px 0 0 0;">
                                    <a href="<?= esc($actionUrl) ?>" style="display:inline-block;background:#0f172a;color:#ffffff;text-decoration:none;padding:10px 18px;border-radius:6px;font-size:14px;">
                                        <?= esc($actionText) ?>
                                    </a>
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:16px 24px;background:#f8fafc;border-top:1px solid #e5e7eb;">
                            <p style="margin:0;font-size:12px;color:#6b7280;">
                                This email was sent by Remote Estimation.
                            </p>
                            <p style="margin:6px 0 0 0;font-size:12px;color:#9ca3af;">
                                &copy; <?= esc((string) ($year ?? date('Y'))) ?> Remote Estimation. All rights reserved.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
