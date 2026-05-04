<?php
$preheader = (string) ($preheader ?? '');
$companyName = (string) ($companyName ?? '');
$companyEmail = (string) ($companyEmail ?? '');
$companyAddress = (string) ($companyAddress ?? '');
$companyWebsite = (string) ($companyWebsite ?? '');
$quoteTitle = (string) ($quoteTitle ?? '');
$quoteDateLabel = (string) ($quoteDateLabel ?? '');
$quotationStatusLabel = (string) ($quotationStatusLabel ?? '');
$customerName = (string) ($customerName ?? '');
$customerCompany = (string) ($customerCompany ?? '');
$customerEmail = (string) ($customerEmail ?? '');
$customerPhone = (string) ($customerPhone ?? '');
$senderName = (string) ($senderName ?? '');
$senderEmail = (string) ($senderEmail ?? '');
$senderPhone = (string) ($senderPhone ?? '');
$description = (string) ($description ?? '');
$projects = $projects ?? [];
$summaryItems = $summaryItems ?? [];

$projects = is_array($projects) ? $projects : [];
$summaryItems = is_array($summaryItems) ? $summaryItems : [];
$subtotal = (string) ($subtotal ?? '');
$discountLabel = (string) ($discountLabel ?? '');
$discountSubtitle = (string) ($discountSubtitle ?? '');
$discountAmount = (string) ($discountAmount ?? '');
$totalAmount = (string) ($totalAmount ?? '');
$showActions = (bool) ($showActions ?? false);
$acceptUrl = (string) ($acceptUrl ?? '');
$rejectUrl = (string) ($rejectUrl ?? '');
$showContract = (bool) ($showContract ?? false);
$contractUrl = (string) ($contractUrl ?? '');
$contractTitle = (string) ($contractTitle ?? '');
$contractSubtitle = (string) ($contractSubtitle ?? '');
$expiryLabel = (string) ($expiryLabel ?? '');
$footerLine1 = (string) ($footerLine1 ?? '');
$footerLine2 = (string) ($footerLine2 ?? '');
$calendarIconUrl = (string) ($calendarIconUrl ?? '');
$contractIconUrl = (string) ($contractIconUrl ?? '');
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html dir="ltr" lang="en">
  <head>
    <meta content="text/html; charset=UTF-8" http-equiv="Content-Type" />
    <meta name="x-apple-disable-message-reformatting" />
    <style></style>
  </head>
  <body style="background-color:rgb(241,241,241)">
    <div
      style="display:none;overflow:hidden;line-height:1px;opacity:0;max-height:0;max-width:0"
      data-skip-in-text="true">
      <?= esc($preheader) ?>
      <div><?= str_repeat('&nbsp;', 60) ?></div>
    </div>
    <table
      border="0"
      width="100%"
      cellpadding="0"
      cellspacing="0"
      role="presentation"
      align="center">
      <tbody>
        <tr>
          <td
            style='background-color:rgb(241,241,241);font-family:ui-sans-serif,system-ui,sans-serif,"Apple Color Emoji","Segoe UI Emoji","Segoe UI Symbol","Noto Color Emoji"'>
            <table
              align="center"
              width="100%"
              border="0"
              cellpadding="0"
              cellspacing="0"
              role="presentation"
              style="max-width:42.5rem;background-color:rgb(255,255,255);margin-right:auto;margin-left:auto;border-style:solid;border-width:1px;border-color:rgb(228,228,231);margin-bottom:1.5rem;margin-top:1.5rem">
              <tbody>
                <tr style="width:100%">
                  <td>
                    <table
                      align="center"
                      width="100%"
                      border="0"
                      cellpadding="0"
                      cellspacing="0"
                      role="presentation"
                      style="background-color:#b72a0f">
                      <tbody>
                        <tr>
                          <td>
                            <table
                              align="center"
                              width="100%"
                              border="0"
                              cellpadding="0"
                              cellspacing="0"
                              role="presentation"
                              style="padding-right:2.5rem;padding-left:2.5rem;padding-bottom:2.25rem;padding-top:2.25rem">
                              <tbody>
                                <tr>
                                  <td>
                                    <table
                                      align="center"
                                      width="100%"
                                      border="0"
                                      cellpadding="0"
                                      cellspacing="0"
                                      role="presentation">
                                      <tbody style="width:100%">
                                        <tr style="width:100%">
                                          <td
                                            data-id="__react-email-column"
                                            style="vertical-align:top;width:55%">
                                            <p
                                              style="font-size:1rem;line-height:1.5;color:rgb(255,255,255);font-weight:700;margin:0rem;margin-bottom:0.125rem;letter-spacing:0.3px;margin-top:0rem;margin-left:0rem;margin-right:0rem">
                                              <?= esc($companyName) ?>
                                            </p>
                                            <p
                                              style="font-size:0.75rem;line-height:1.3333333333333333;color:rgb(230,180,171);margin:0rem;margin-bottom:0.625rem;margin-top:0rem;margin-left:0rem;margin-right:0rem">
                                              <?= esc($companyEmail) ?>
                                            </p>
                                            <p
                                              style="font-size:0.75rem;line-height:1.3333333333333333;color:rgb(233,191,183);margin:0rem;margin-bottom:0.125rem;margin-top:0rem;margin-left:0rem;margin-right:0rem">
                                              <?= esc($companyAddress) ?>
                                            </p>
                                            <p
                                              style="font-size:0.75rem;line-height:1.3333333333333333;color:rgb(233,191,183);margin:0rem;margin-top:0rem;margin-bottom:0rem;margin-left:0rem;margin-right:0rem">
                                              <?= esc($companyWebsite) ?>
                                            </p>
                                          </td>
                                          <td
                                            data-id="__react-email-column"
                                            style="vertical-align:top;width:45%;text-align:right">
                                            <p
                                              style="font-size:28px;line-height:24px;color:rgb(248,234,231);font-weight:800;letter-spacing:-0.025em;margin:0rem;margin-bottom:0.125rem;margin-top:0rem;margin-left:0rem;margin-right:0rem">
                                              Quotation
                                            </p>
                                            <p
                                              style='font-size:0.75rem;line-height:1.3333333333333333;color:rgb(226,170,159);font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;font-weight:600;margin:0rem;margin-bottom:0.75rem;margin-top:0rem;margin-left:0rem;margin-right:0rem'>
                                              <?= esc($quoteTitle) ?>
                                            </p>
                                            <p
                                              style="font-size:0.75rem;line-height:1.3333333333333333;color:rgb(226,170,159);margin:0rem;margin-bottom:0.5rem;margin-top:0rem;margin-left:0rem;margin-right:0rem">
                                              Issue Date:
                                              <span
                                                style="color:rgb(248,234,231);font-weight:500"
                                                ><?= esc($quoteDateLabel) ?></span>
                                            </p>
                                            <p
                                              style="font-size:10px;line-height:24px;display:inline-block;background-color:rgb(194,74,51);color:rgb(255,255,255);font-weight:700;letter-spacing:0.1em;padding-right:0.5rem;padding-left:0.5rem;padding-bottom:0.125rem;padding-top:0.125rem;border-radius:0.375rem;border-style:solid;border-width:1px;border-color:rgb(201,95,75);margin:0rem;margin-top:0rem;margin-bottom:0rem;margin-left:0rem;margin-right:0rem">
                                              <?= esc($quotationStatusLabel) ?>
                                            </p>
                                          </td>
                                        </tr>
                                      </tbody>
                                    </table>
                                  </td>
                                </tr>
                              </tbody>
                            </table>
                          </td>
                        </tr>
                      </tbody>
                    </table>
                    <table
                      align="center"
                      width="100%"
                      border="0"
                      cellpadding="0"
                      cellspacing="0"
                      role="presentation"
                      style="padding-right:2.5rem;padding-left:2.5rem;padding-bottom:1.75rem;padding-top:1.75rem">
                      <tbody>
                        <tr>
                          <td>
                            <table
                              align="center"
                              width="100%"
                              border="0"
                              cellpadding="0"
                              cellspacing="0"
                              role="presentation">
                              <tbody style="width:100%">
                                <tr style="width:100%">
                                  <td
                                    data-id="__react-email-column"
                                    style="vertical-align:top;width:50%;padding-right:1rem">
                                    <p
                                      style="font-size:10px;line-height:24px;color:rgb(113,113,122);font-weight:700;letter-spacing:1.5px;text-transform:uppercase;margin:0rem;margin-bottom:0.75rem;margin-top:0rem;margin-left:0rem;margin-right:0rem">
                                      Bill To
                                    </p>
                                    <p
                                      style="font-size:0.875rem;line-height:1.4285714285714286;color:rgb(9,9,11);font-weight:600;margin:0rem;margin-bottom:0.125rem;margin-top:0rem;margin-left:0rem;margin-right:0rem">
                                      <?= esc($customerName) ?>
                                    </p>
                                    <p
                                      style="font-size:13px;line-height:24px;color:rgb(113,113,122);margin:0rem;margin-bottom:0.125rem;margin-top:0rem;margin-left:0rem;margin-right:0rem">
                                      <?= esc($customerCompany) ?>
                                    </p>
                                    <p
                                      style="font-size:13px;line-height:24px;color:rgb(113,113,122);margin:0rem;margin-bottom:0.125rem;margin-top:0rem;margin-left:0rem;margin-right:0rem">
                                      <?= esc($customerEmail) ?>
                                    </p>
                                    <p
                                      style="font-size:13px;line-height:24px;color:rgb(113,113,122);margin:0rem;margin-top:0rem;margin-bottom:0rem;margin-left:0rem;margin-right:0rem">
                                      <?= esc($customerPhone) ?>
                                    </p>
                                  </td>
                                  <td
                                    data-id="__react-email-column"
                                    style="vertical-align:top;width:50%;padding-left:1rem">
                                    <p
                                      style="font-size:10px;line-height:24px;color:rgb(113,113,122);font-weight:700;letter-spacing:1.5px;text-transform:uppercase;margin:0rem;margin-bottom:0.75rem;margin-top:0rem;margin-left:0rem;margin-right:0rem">
                                      From
                                    </p>
                                    <p
                                      style="font-size:0.875rem;line-height:1.4285714285714286;color:rgb(9,9,11);font-weight:600;margin:0rem;margin-bottom:0.125rem;margin-top:0rem;margin-left:0rem;margin-right:0rem">
                                      <?= esc($senderName) ?>
                                    </p>
                                    <p
                                      style="font-size:13px;line-height:24px;color:rgb(113,113,122);margin:0rem;margin-bottom:0.125rem;margin-top:0rem;margin-left:0rem;margin-right:0rem">
                                      <?= esc($senderEmail) ?>
                                    </p>
                                    <p
                                      style="font-size:13px;line-height:24px;color:rgb(113,113,122);margin:0rem;margin-top:0rem;margin-bottom:0rem;margin-left:0rem;margin-right:0rem">
                                      <?= esc($senderPhone) ?>
                                    </p>
                                  </td>
                                </tr>
                              </tbody>
                            </table>
                          </td>
                        </tr>
                      </tbody>
                    </table>
                    <hr
                      style="width:100%;border:none;border-top:1px solid #eaeaea;border-color:rgb(228,228,231);margin:0rem" />
                    <table
                      align="center"
                      width="100%"
                      border="0"
                      cellpadding="0"
                      cellspacing="0"
                      role="presentation"
                      style="padding-right:2.5rem;padding-left:2.5rem;padding-bottom:1.75rem;padding-top:1.75rem">
                      <tbody>
                        <tr>
                          <td>
                            <p
                              style="font-size:0.875rem;line-height:1.625;color:rgb(9,9,11);margin:0rem;margin-top:0rem;margin-bottom:0rem;margin-left:0rem;margin-right:0rem">
                              <?= esc($description) ?>
                            </p>
                          </td>
                        </tr>
                      </tbody>
                    </table>
                    <?php if ($expiryLabel !== ''): ?>
                    <table
                      align="center"
                      width="100%"
                      border="0"
                      cellpadding="0"
                      cellspacing="0"
                      role="presentation"
                      style="padding-right:2.5rem;padding-left:2.5rem;padding-bottom:1rem">
                      <tbody>
                        <tr>
                          <td>
                            <p
                              style="font-size:12px;line-height:1.5;color:rgb(146,64,14);background-color:rgb(255,251,235);border:1px solid rgb(253,230,138);border-radius:0.5rem;padding:8px 10px;margin:0rem">
                              Public response link expires on <?= esc($expiryLabel) ?>.
                            </p>
                          </td>
                        </tr>
                      </tbody>
                    </table>
                    <?php endif; ?>
                    <hr
                      style="width:100%;border:none;border-top:1px solid #eaeaea;border-color:rgb(228,228,231);margin:0rem" />
                    <table
                      align="center"
                      width="100%"
                      border="0"
                      cellpadding="0"
                      cellspacing="0"
                      role="presentation"
                      style="padding-right:2.5rem;padding-left:2.5rem;padding-bottom:1.75rem;padding-top:1.75rem">
                      <tbody>
                        <tr>
                          <td>
                            <p
                              style="font-size:10px;line-height:24px;color:rgb(113,113,122);font-weight:700;letter-spacing:1.5px;text-transform:uppercase;margin:0rem;margin-bottom:0.75rem;margin-top:0rem;margin-left:0rem;margin-right:0rem">
                              Projects
                            </p>
                            <?php foreach ($projects as $project): ?>
                            <?php
                            $projectMetaLine = (string) ($project['metaLine'] ?? '');
                            $projectDateLabel = (string) ($project['dateLabel'] ?? '');
                            $projectServices = (string) ($project['servicesText'] ?? '');
                            $projectBoxStyle = ($project['isHighlighted'] ?? false)
                                ? 'border-radius:0.75rem;padding:1rem;margin-bottom:0.5rem;border-style:solid;border-width:1px;border-color:rgb(228,228,231);background-color:rgb(244,244,245)'
                                : 'border-radius:0.75rem;padding:1rem;margin-bottom:0.5rem;border-style:solid;border-width:1px;border-color:rgb(228,228,231);background-color:rgb(255,255,255)';
                            ?>
                            <table
                              align="center"
                              width="100%"
                              border="0"
                              cellpadding="0"
                              cellspacing="0"
                              role="presentation"
                              style="<?= esc($projectBoxStyle) ?>">
                              <tbody>
                                <tr>
                                  <td>
                                    <table
                                      align="center"
                                      width="100%"
                                      border="0"
                                      cellpadding="0"
                                      cellspacing="0"
                                      role="presentation">
                                      <tbody style="width:100%">
                                        <tr style="width:100%">
                                          <td data-id="__react-email-column">
                                            <table
                                              align="center"
                                              width="100%"
                                              border="0"
                                              cellpadding="0"
                                              cellspacing="0"
                                              role="presentation">
                                              <tbody style="width:100%">
                                                <tr style="width:100%">
                                                  <td
                                                    data-id="__react-email-column"
                                                    style="width:1.75rem;vertical-align:middle">
                                                    <p
                                                      style="font-size:11px;line-height:1;background-color:rgb(244,244,245);color:rgb(113,113,122);font-weight:600;text-align:center;width:1.5rem;border-radius:0.375rem;margin:0rem;padding-right:0.25rem;padding-left:0.25rem;padding-bottom:0.125rem;padding-top:0.125rem;display:inline-block;margin-top:0rem;margin-bottom:0rem;margin-left:0rem;margin-right:0rem">
                                                      <?= esc((string) ($project['index'] ?? '')) ?>
                                                    </p>
                                                  </td>
                                                  <td
                                                    data-id="__react-email-column">
                                                    <p
                                                      style="font-size:0.875rem;line-height:1.4285714285714286;color:rgb(9,9,11);font-weight:600;margin:0rem;margin-top:0rem;margin-bottom:0rem;margin-left:0rem;margin-right:0rem">
                                                      <?= esc((string) ($project['title'] ?? '')) ?>
                                                    </p>
                                                  </td>
                                                </tr>
                                              </tbody>
                                            </table>
                                          </td>
                                          <td
                                            data-id="__react-email-column"
                                            style="text-align:right;vertical-align:middle">
                                            <p
                                              style="font-size:0.875rem;line-height:1.4285714285714286;color:rgb(9,9,11);font-weight:600;margin:0rem;margin-top:0rem;margin-bottom:0rem;margin-left:0rem;margin-right:0rem">
                                              <?= esc((string) ($project['amount'] ?? '')) ?>
                                            </p>
                                          </td>
                                        </tr>
                                      </tbody>
                                    </table>
                                    <?php if ($projectMetaLine !== '' || $projectDateLabel !== ''): ?>
                                    <p
                                      style="font-size:0.75rem;line-height:1.3333333333333333;color:rgb(113,113,122);margin:0rem;margin-top:0.375rem;margin-bottom:0rem;margin-left:0rem;margin-right:0rem">
                                      <?= esc($projectMetaLine) ?>
                                      <?php if ($calendarIconUrl !== ''): ?>
                                      <img
                                        alt="calendar"
                                        height="12"
                                        src="<?= esc($calendarIconUrl) ?>"
                                        style="display:inline;outline:none;border:none;text-decoration:none;vertical-align:middle"
                                        width="12" />
                                      <?php endif; ?>
                                      <?= esc($projectDateLabel) ?>
                                    </p>
                                    <?php endif; ?>
                                    <?php if ($projectServices !== ''): ?>
                                    <p
                                      style="font-size:11px;line-height:24px;color:rgb(47,58,88);font-weight:500;margin:0rem;margin-top:0.375rem;background-color:rgb(234,235,238);padding-right:0.5rem;padding-left:0.5rem;padding-bottom:0.25rem;padding-top:0.25rem;border-radius:0.375rem;display:inline-block;margin-bottom:0rem;margin-left:0rem;margin-right:0rem">
                                      <?= esc($projectServices) ?>
                                    </p>
                                    <?php else: ?>
                                    <p
                                      style="font-size:11px;line-height:24px;color:rgb(71,85,105);font-weight:500;margin:0rem;margin-top:0.375rem;background-color:rgb(241,245,249);padding-right:0.5rem;padding-left:0.5rem;padding-bottom:0.25rem;padding-top:0.25rem;border-radius:0.375rem;display:inline-block;margin-bottom:0rem;margin-left:0rem;margin-right:0rem">
                                      No tagged services
                                    </p>
                                    <?php endif; ?>
                                    <p
                                      style="font-size:13px;line-height:1.625;color:rgb(113,113,122);margin:0rem;margin-top:0.5rem;padding-top:0.5rem;border-top-style:solid;border-top-width:1px;border-color:rgb(228,228,231);margin-bottom:0rem;margin-left:0rem;margin-right:0rem">
                                      <?= esc((string) ($project['description'] ?? '')) ?>
                                    </p>
                                  </td>
                                </tr>
                              </tbody>
                            </table>
                            <?php endforeach; ?>
                          </td>
                        </tr>
                      </tbody>
                    </table>
                    <hr
                      style="width:100%;border:none;border-top:1px solid #eaeaea;border-color:rgb(228,228,231);margin:0rem" />
                    <table
                      align="center"
                      width="100%"
                      border="0"
                      cellpadding="0"
                      cellspacing="0"
                      role="presentation"
                      style="padding-right:2.5rem;padding-left:2.5rem;padding-bottom:1.75rem;padding-top:1.75rem">
                      <tbody>
                        <tr>
                          <td>
                            <p
                              style="font-size:10px;line-height:24px;color:rgb(113,113,122);font-weight:700;letter-spacing:1.5px;text-transform:uppercase;margin:0rem;margin-bottom:0.75rem;margin-top:0rem;margin-left:0rem;margin-right:0rem">
                              Financial Summary
                            </p>
                            <table
                              align="center"
                              width="100%"
                              border="0"
                              cellpadding="0"
                              cellspacing="0"
                              role="presentation"
                              style="margin-left:auto;max-width:320px">
                              <tbody>
                                <tr>
                                  <td>
                                    <?php foreach ($summaryItems as $summary): ?>
                                    <table
                                      align="center"
                                      width="100%"
                                      border="0"
                                      cellpadding="0"
                                      cellspacing="0"
                                      role="presentation"
                                      style="margin-bottom:0.25rem">
                                      <tbody style="width:100%">
                                        <tr style="width:100%">
                                          <td data-id="__react-email-column">
                                            <p
                                              style="font-size:13px;line-height:24px;color:rgb(113,113,122);margin:0rem;margin-top:0rem;margin-bottom:0rem;margin-left:0rem;margin-right:0rem">
                                              <?= esc((string) ($summary['label'] ?? '')) ?>
                                            </p>
                                          </td>
                                          <td
                                            data-id="__react-email-column"
                                            style="text-align:right">
                                            <p
                                              style="font-size:13px;line-height:24px;color:rgb(9,9,11);margin:0rem;margin-top:0rem;margin-bottom:0rem;margin-left:0rem;margin-right:0rem">
                                              <?= esc((string) ($summary['amount'] ?? '')) ?>
                                            </p>
                                          </td>
                                        </tr>
                                      </tbody>
                                    </table>
                                    <?php endforeach; ?>
                                    <hr
                                      style="width:100%;border:none;border-top:1px solid #eaeaea;border-color:rgb(228,228,231);margin-bottom:0.5rem;margin-top:0.5rem" />
                                    <table
                                      align="center"
                                      width="100%"
                                      border="0"
                                      cellpadding="0"
                                      cellspacing="0"
                                      role="presentation"
                                      style="margin-bottom:0.25rem">
                                      <tbody style="width:100%">
                                        <tr style="width:100%">
                                          <td data-id="__react-email-column">
                                            <p
                                              style="font-size:13px;line-height:24px;color:rgb(113,113,122);margin:0rem;margin-top:0rem;margin-bottom:0rem;margin-left:0rem;margin-right:0rem">
                                              Subtotal
                                            </p>
                                          </td>
                                          <td
                                            data-id="__react-email-column"
                                            style="text-align:right">
                                            <p
                                              style="font-size:13px;line-height:24px;color:rgb(9,9,11);font-weight:600;margin:0rem;margin-top:0rem;margin-bottom:0rem;margin-left:0rem;margin-right:0rem">
                                              <?= esc($subtotal) ?>
                                            </p>
                                          </td>
                                        </tr>
                                      </tbody>
                                    </table>
                                    <table
                                      align="center"
                                      width="100%"
                                      border="0"
                                      cellpadding="0"
                                      cellspacing="0"
                                      role="presentation"
                                      style="margin-bottom:0.25rem">
                                      <tbody style="width:100%">
                                        <tr style="width:100%">
                                          <td data-id="__react-email-column">
                                            <p
                                              style="font-size:13px;line-height:24px;color:rgb(5,150,105);margin:0rem;margin-top:0rem;margin-bottom:0rem;margin-left:0rem;margin-right:0rem">
                                              <?= esc($discountLabel) ?>
                                              <?php if ($discountSubtitle !== ''): ?>
                                              <span style="font-size:11px;color:rgb(80,182,150)"> <?= esc($discountSubtitle) ?></span>
                                              <?php endif; ?>
                                            </p>
                                          </td>
                                          <td
                                            data-id="__react-email-column"
                                            style="text-align:right">
                                            <p
                                              style="font-size:13px;line-height:24px;color:rgb(5,150,105);font-weight:600;margin:0rem;margin-top:0rem;margin-bottom:0rem;margin-left:0rem;margin-right:0rem">
                                              -<?= esc($discountAmount) ?>
                                            </p>
                                          </td>
                                        </tr>
                                      </tbody>
                                    </table>
                                    <hr
                                      style="width:100%;border:none;border-top:1px solid #eaeaea;border-color:rgb(228,228,231);margin-bottom:0.5rem;margin-top:0.5rem" />
                                    <table
                                      align="center"
                                      width="100%"
                                      border="0"
                                      cellpadding="0"
                                      cellspacing="0"
                                      role="presentation">
                                      <tbody style="width:100%">
                                        <tr style="width:100%">
                                          <td data-id="__react-email-column">
                                            <p
                                              style="font-size:0.875rem;line-height:1.4285714285714286;color:rgb(9,9,11);font-weight:700;margin:0rem;margin-top:0rem;margin-bottom:0rem;margin-left:0rem;margin-right:0rem">
                                              Total
                                            </p>
                                          </td>
                                          <td
                                            data-id="__react-email-column"
                                            style="text-align:right">
                                            <p
                                              style="font-size:1.25rem;line-height:1.4;color:rgb(9,9,11);font-weight:800;margin:0rem;margin-top:0rem;margin-bottom:0rem;margin-left:0rem;margin-right:0rem">
                                              <?= esc($totalAmount) ?>
                                            </p>
                                          </td>
                                        </tr>
                                      </tbody>
                                    </table>
                                  </td>
                                </tr>
                              </tbody>
                            </table>
                          </td>
                        </tr>
                      </tbody>
                    </table>
                    <?php if ($showContract): ?>
                    <hr
                      style="width:100%;border:none;border-top:1px solid #eaeaea;border-color:rgb(228,228,231);margin:0rem" />
                    <table
                      align="center"
                      width="100%"
                      border="0"
                      cellpadding="0"
                      cellspacing="0"
                      role="presentation"
                      style="padding-right:2.5rem;padding-left:2.5rem;padding-bottom:1.75rem;padding-top:1.75rem">
                      <tbody>
                        <tr>
                          <td>
                            <table
                              align="center"
                              width="100%"
                              border="0"
                              cellpadding="0"
                              cellspacing="0"
                              role="presentation"
                              style="border-radius:0.75rem;border-style:dashed;border-width:2px;border-color:rgb(109,117,138);background-color:rgb(234,235,238);padding-right:1.25rem;padding-left:1.25rem;padding-bottom:1rem;padding-top:1rem">
                              <tbody>
                                <tr>
                                  <td>
                                    <table
                                      align="center"
                                      width="100%"
                                      border="0"
                                      cellpadding="0"
                                      cellspacing="0"
                                      role="presentation">
                                      <tbody style="width:100%">
                                        <tr style="width:100%">
                                          <td
                                            data-id="__react-email-column"
                                            style="width:2.75rem;vertical-align:middle">
                                            <?php if ($contractIconUrl !== ''): ?>
                                            <img
                                              alt="contract"
                                              height="22"
                                              src="<?= esc($contractIconUrl) ?>"
                                              style="display:block;outline:none;border:none;text-decoration:none"
                                              width="22" />
                                            <?php endif; ?>
                                          </td>
                                          <td
                                            data-id="__react-email-column"
                                            style="vertical-align:middle">
                                            <p
                                              style="font-size:10px;line-height:24px;color:rgb(47,58,88);font-weight:700;letter-spacing:0.05em;margin:0rem;margin-bottom:0.125rem;margin-top:0rem;margin-left:0rem;margin-right:0rem">
                                              Attached Contract
                                            </p>
                                            <p
                                              style="font-size:0.875rem;line-height:1.4285714285714286;color:rgb(9,9,11);font-weight:600;margin:0rem;margin-bottom:0.125rem;margin-top:0rem;margin-left:0rem;margin-right:0rem">
                                              <?= esc($contractTitle) ?>
                                            </p>
                                            <?php if ($contractSubtitle !== ''): ?>
                                            <p
                                              style="font-size:0.75rem;line-height:1.3333333333333333;color:rgb(113,113,122);margin:0rem;margin-top:0rem;margin-bottom:0rem;margin-left:0rem;margin-right:0rem">
                                              <?= esc($contractSubtitle) ?>
                                            </p>
                                            <?php endif; ?>
                                          </td>
                                          <td
                                            data-id="__react-email-column"
                                            style="width:7.5rem;text-align:right;vertical-align:middle">
                                            <a
                                              href="<?= esc($contractUrl) ?>"
                                              style="color:rgb(9,9,11);text-decoration-line:none;display:inline-block;background-color:rgb(255,255,255);border-style:solid;border-width:1px;border-color:rgb(228,228,231);border-radius:0.5rem;font-size:0.75rem;line-height:1.3333333333333333;font-weight:500;padding-right:0.875rem;padding-left:0.875rem;padding-bottom:0.375rem;padding-top:0.375rem;text-decoration:none"
                                              target="_blank"
                                              >View Contract</a>
                                          </td>
                                        </tr>
                                      </tbody>
                                    </table>
                                  </td>
                                </tr>
                              </tbody>
                            </table>
                          </td>
                        </tr>
                      </tbody>
                    </table>
                    <?php endif; ?>
                    <?php if ($showActions): ?>
                    <hr
                      style="width:100%;border:none;border-top:1px solid #eaeaea;border-color:rgb(228,228,231);margin:0rem" />
                    <table
                      align="center"
                      width="100%"
                      border="0"
                      cellpadding="0"
                      cellspacing="0"
                      role="presentation"
                      style="padding-right:2.5rem;padding-left:2.5rem;padding-bottom:2rem;padding-top:2rem;text-align:center">
                      <tbody>
                        <tr>
                          <td>
                            <p
                              style="font-size:1.125rem;line-height:1.5555555555555556;color:rgb(9,9,11);font-weight:700;margin:0rem;margin-bottom:0.5rem;margin-top:0rem;margin-left:0rem;margin-right:0rem">
                              Your Response
                            </p>
                            <p
                              style="font-size:0.875rem;line-height:1.5;color:rgb(113,113,122);margin:0rem;margin-bottom:1.5rem;margin-top:0rem;margin-left:0rem;margin-right:0rem">
                              Please review the quotation above and let us know
                              if you would like to proceed.
                            </p>
                            <table
                              align="center"
                              width="100%"
                              border="0"
                              cellpadding="0"
                              cellspacing="0"
                              role="presentation">
                              <tbody style="width:100%">
                                <tr style="width:100%">
                                  <td
                                    data-id="__react-email-column"
                                    style="text-align:center;padding-right:0.5rem;padding-left:0.5rem">
                                    <a
                                      href="<?= esc($acceptUrl) ?>"
                                      style="line-height:1.4285714285714286;text-decoration:none;display:block;max-width:100%;mso-padding-alt:0px;background-color:rgb(5,150,105);border-radius:0.75rem;color:rgb(255,255,255);font-size:0.875rem;font-weight:700;text-decoration-line:none;text-align:center;padding-bottom:14px;padding-top:14px;padding-right:28px;padding-left:28px"
                                      target="_blank"
                                      >Accept Quotation</a>
                                  </td>
                                  <td
                                    data-id="__react-email-column"
                                    style="text-align:center;padding-right:0.5rem;padding-left:0.5rem">
                                    <a
                                      href="<?= esc($rejectUrl) ?>"
                                      style="line-height:1.4285714285714286;text-decoration:none;display:block;max-width:100%;mso-padding-alt:0px;background-color:rgb(255,255,255);border-radius:0.75rem;color:rgb(220,38,38);font-size:0.875rem;font-weight:700;text-decoration-line:none;text-align:center;padding-bottom:14px;padding-top:14px;padding-right:28px;padding-left:28px;border-style:dashed;border-width:2px;border-color:rgb(252,165,165)"
                                      target="_blank"
                                      >Decline Quotation</a>
                                  </td>
                                </tr>
                              </tbody>
                            </table>
                            <p
                              style="font-size:11px;line-height:24px;color:rgb(113,113,122);margin:0rem;margin-top:1rem;margin-bottom:0rem;margin-left:0rem;margin-right:0rem">
                              Clicking Accept or Decline will open a
                              confirmation page.
                            </p>
                          </td>
                        </tr>
                      </tbody>
                    </table>
                    <?php endif; ?>
                    <hr
                      style="width:100%;border:none;border-top:1px solid #eaeaea;border-color:rgb(228,228,231);margin:0rem" />
                    <table
                      align="center"
                      width="100%"
                      border="0"
                      cellpadding="0"
                      cellspacing="0"
                      role="presentation"
                      style="padding-right:2.5rem;padding-left:2.5rem;padding-bottom:1.25rem;padding-top:1.25rem;text-align:center">
                      <tbody>
                        <tr>
                          <td>
                            <p
                              style="font-size:11px;line-height:24px;color:rgb(113,113,122);margin:0rem;margin-bottom:0.125rem;margin-top:0rem;margin-left:0rem;margin-right:0rem">
                              <?= esc($footerLine1) ?>
                            </p>
                            <p
                              style="font-size:11px;line-height:24px;color:rgb(113,113,122);margin:0rem;margin-bottom:0.125rem;margin-top:0rem;margin-left:0rem;margin-right:0rem">
                              <?= esc($footerLine2) ?>
                            </p>
                            <p
                              style="font-size:10px;line-height:24px;color:rgb(170,170,175);margin:0rem;margin-top:0.5rem;margin-bottom:0rem;margin-left:0rem;margin-right:0rem">
                              This is a quotation - not a tax invoice.
                            </p>
                          </td>
                        </tr>
                      </tbody>
                    </table>
                  </td>
                </tr>
              </tbody>
            </table>
          </td>
        </tr>
      </tbody>
    </table>
  </body>
</html>
