<?php
/**
 * gatewayHeader.php
 * The page shell for the simulated payment provider screens:
 *   payment/tng.php
 *   payment/card.php
 *   payment/success.php
 *
 * These pages stand in for a separate application - the e-wallet or the card
 * provider - so they deliberately do NOT use includes/header.php. A customer
 * handing over a PIN or a card number should not still be looking at the
 * merchant's own menu, and there is nowhere to navigate to on a payment screen
 * anyway: the only ways out are paying or going back.
 *
 * That is why this file exists rather than the pages each writing their own
 * <!DOCTYPE>: the shell is identical for all three, so it is written once.
 *
 * Two variables may be set before including this file:
 *   $gateway_title    text for the browser tab
 *   $gateway_refresh  ['seconds' => int, 'url' => string] to send the browser
 *                     onwards on its own. It has to live in <head> to be valid,
 *                     which is the whole reason the shell takes it as input.
 */

require_once __DIR__ . '/../config/functions.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($gateway_title ?? 'Payment') ?></title>
    <link rel="stylesheet" href="<?= h(asset_url('/css/style.css')) ?>">
    <?php if (!empty($gateway_refresh)): ?>
        <!-- The plain-HTML way back to the merchant. js/booking.js also counts
             down on screen, but this is what runs when JavaScript does not, so
             the customer is never left stranded on the provider's page. -->
        <meta http-equiv="refresh" content="<?= (int)$gateway_refresh['seconds'] ?>;url=<?= h($gateway_refresh['url']) ?>">
    <?php endif; ?>
</head>
<body class="gateway-body">

<main class="gateway-main">
