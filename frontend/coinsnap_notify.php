<?php

declare(strict_types=1);

namespace Plugin\jtl_coinsnappayment;

use JTL\Plugin\Payment\LegacyMethod;
use JTL\Checkout\Bestellung;
use JTL\Plugin\Helper;
use JTL\Shop;

require_once(__DIR__ . '/../library/autoload.php');

\ob_start();
\error_reporting(0);
\ini_set('display_errors', 0);

$bootstrapper = __DIR__ . '/../../../includes/globalinclude.php';

$exit = static function ($error = false) {
    \ob_end_clean();
    \http_response_code(($error !== true) ? 200 : 503);
    exit;
};

if (!\file_exists($bootstrapper)) {
    $exit(true);
}

require_once $bootstrapper;



$db = Shop::Container()->getDB();
$logger = Shop::Container()->getLogService();
$oPlugin = Helper::getPluginById('jtl_coinsnappayment');

if ($oPlugin === null) {
    Shop::Container()->getLogService()->error("Coinsnap Notify: Plugin 'jtl_coinsnappayment' not found");
    $exit(true);
}

$payment = null;
$moduleID   = 'kPlugin_' . $oPlugin->getID() . '_coinsnappayment';

$payment = LegacyMethod::create($moduleID);
if ($payment === null) {
    Shop::Container()->getLogService()->error('Coinsnap Notify: Missing payment provider');
    $exit(true);
}

$store_id =  $payment->getSetting('store_id');
$api_key =  $payment->getSetting('api_key');


$notify_json = file_get_contents('php://input');

$notify_ar = json_decode($notify_json, true);
$invoice_id = $notify_ar['invoiceId'];
$ApiUrl = 'https://app.coinsnap.io';

try {
    $client = new \Coinsnap\Client\Invoice($ApiUrl, $api_key);
    $csinvoice = $client->getInvoice($store_id, $invoice_id);
    $status = $csinvoice->getData()['status'];
    $order_no = $csinvoice->getData()['orderId'];
} catch (\Throwable $e) {
    echo "Error";
    exit;
}

$data = Shop::Container()->getDB()->select(
    'tbestellung',
    'cBestellNr',
    $order_no,
    null,
    null,
    null,
    null,
    false,
    'kBestellung'
);

$orderId = (int) $data->kBestellung;

$order   = new Bestellung($orderId);

$order->fuelleBestellung(false, 0, false);


if ((int)$order->kBestellung === 0) {
    Shop::Container()->getLogService()->error('Coinsnap Notify:' . $orderId . ' Missing payment provider');
    echo 'Coinsnap Notify:' . $orderId . ' Missing payment provider';
}

// validation
if (!\in_array((int)$order->cStatus, [\BESTELLUNG_STATUS_OFFEN, \BESTELLUNG_STATUS_IN_BEARBEITUNG], true)) {
    // order status has already been set
    $exit();
}


if ($status == 'Expired') {
    $order_status = 'fail';
} elseif ($status == 'Processing') {
    $order_status = 'paid';
} elseif ($status == 'Settled') {
    $order_status = 'paid';
}


switch ($status) {
    case 'Processing':
        $payment->addIncomingPayment($order, (object)[
            'fBetrag'          => $csinvoice->getData()['amount'],
            'cISO' => $csinvoice->getData()['currency'],
            'cHinweis'         => $csinvoice->getData()['invoiceId'],
        ]);
        $payment->setOrderStatusToPaid($order);
        break;
        // case 'Settled':
        // case 'fail':
        //      PayPalHelper::sendPaymentDeniedMail($order->oKunde, $order);
        //      break;
}
echo "OK";
exit;
