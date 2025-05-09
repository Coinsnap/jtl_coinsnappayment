<?php

declare(strict_types=1);

namespace Plugin\jtl_coinsnappayment\paymentmethod;

use JTL\Checkout\Bestellung;
use JTL\Plugin\Data\PaymentMethod;
use JTL\Plugin\Helper as PluginHelper;
use JTL\Plugin\Payment\Method;
use JTL\Plugin\PluginInterface;
use JTL\Shop;

/**
 * Class CoinsnapPayment
 * @package Plugin\jtl_coinsnappayment\paymentmethod
 */
require_once(__DIR__ . '/../library/autoload.php');

class CoinsnapPayment extends Method
{
    /** @var PluginInterface */
    private PluginInterface $plugin;

    /** @var PaymentMethod|null */
    private ?PaymentMethod $method;

    /** @var bool */
    private bool $payAgain;

    public const WEBHOOK_EVENTS = ['New', 'Expired', 'Settled', 'Processing'];
    public const REFERRAL_CODE = 'D18284';


    /**
     * @inheritDoc
     */
    public function init(int $nAgainCheckout = 0): self
    {
        parent::init($nAgainCheckout);

        $pluginID       = PluginHelper::getIDByModuleID($this->moduleID);
        $this->plugin   = PluginHelper::getLoaderByPluginID($pluginID)->init($pluginID);
        $this->method   = $this->plugin->getPaymentMethods()->getMethodByID($this->moduleID);
        $this->payAgain = $nAgainCheckout > 0;

        return $this;
    }

    /**
     * @return PaymentMethod
     */
    public function getMethod(): PaymentMethod
    {
        return $this->method;
    }

    /**
     * @inheritDoc
     */
    public function getSetting(string $key): mixed
    {
        $setting = parent::getSetting($key);

        if ($setting === null) {
            $setting = $this->plugin->getConfig()->getValue($this->getMethod()->getModuleID() . '_' . $key);
        }

        return $setting;
    }

    /**
     * @inheritDoc
     */
    public function isValidIntern(array $args_arr = []): bool
    {
        if ($this->method === null) {
            return false;
        }

        $store_id     = $this->getSetting('store_id') ?? '';
        $api_key = $this->getSetting('api_key') ?? '';


        return parent::isValidIntern($args_arr) && $api_key !== '' && $store_id !== '';
    }

    /**
     * @inheritDoc
     */
    public function finalizeOrder(Bestellung $order, string $hash, array $args): bool
    {
        parent::finalizeOrder($order, $hash, $args);

        $invoiceId = isset($args['invoiceId']) ? $args['invoiceId'] : null;
        $client = new \Coinsnap\Client\Invoice($this->getApiUrl(), $this->getApiKey());
        try {
            $client = new \Coinsnap\Client\Invoice($this->getApiUrl(), $this->getApiKey());
            $csinvoice = $client->getInvoice($this->getStoreId(), $invoiceId);
            $status = $csinvoice->getData()['status'];
            // $order_no = $csinvoice->getData()['orderId'];
        } catch (\Throwable $e) {
            //TODO: Redirect user to check
            return false;
        }
        //TODO: Compare invoice hash and query hash
        $_SESSION['coinsnap']['invoice_status'] = $status;
        if ( $status != 'Settled') {
            return false;
        }
        //TODO: Send email if selected in the settings?

        return true;
    }

    /**
     * @inheritDoc
     */
    public function redirectOnPaymentSuccess(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function redirectOnCancel(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function handleNotification(Bestellung $order, string $hash, array $args): void
    {
        parent::handleNotification($order, $hash, $args);
        //TODO: Consider partial payment and paid after expiration
        $allowedStatuses = [ 'Settled'];
        if (isset($_SESSION['coinsnap']['invoice_status']) && in_array($_SESSION['coinsnap']['invoice_status'], $allowedStatuses)) {
            $this->addIncomingPayment($order, (object)[
                'fBetrag'           => $order->fGesamtsumme,
                'fZahlungsgebuehr'  => 0,
            ]);
            $this->setOrderStatusToPaid($order);
            unset[$_SESSION['coinsnap']['invoice_status']];
        }
    }

    /**
     * @inheritDoc
     */
    public function canPayAgain(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function preparePaymentProcess(Bestellung $order): void
    {
        parent::preparePaymentProcess($order);

        $webhook_url = $this->get_webhook_url();


        if (! $this->webhookExists($this->getStoreId(), $this->getApiKey(), $webhook_url)) {
            if (! $this->registerWebhook($this->getStoreId(), $this->getApiKey(), $webhook_url)) {
                echo('unable to set Webhook url');
                exit;
            }
        }

        $smarty       = Shop::Smarty();
        $localization = $this->plugin->getLocalization();

        if ($this->payAgain) {
            $paymentHash = $this->getOrderHash($order);
            if ($paymentHash === null) {
                $this->getDB()->insert('tbestellid', (object)[
                    'kBestellung' => $order->kBestellung,
                    'cId'         => \uniqid('', true)
                ]);
                $paymentHash = $this->generateHash($order);
            }
        } else {
            $paymentHash = $this->generateHash($order);
        }
        $return_url = $this->getReturnURL($order);
        if ($this->duringCheckout) {
            $return_url = $this->getNotificationURL($paymentHash);
        }



        // $return_url = $this->getReturnURL($order);
        $amount = (float) $order->fGesamtsumme * $_SESSION['Waehrung']->fFaktor;
        $currency = strtoupper($order->Waehrung->cISO);
        $buyerEmail = $order->oRechnungsadresse->cMail;
        $buyerName = $order->oRechnungsadresse->cVorname . ' ' . $order->oRechnungsadresse->cNachname;
        $invoice_no = $order->cBestellNr;

        $checkoutOptions = new \Coinsnap\Client\InvoiceCheckoutOptions();
        $checkoutOptions->setRedirectURL($return_url);
        $client = new \Coinsnap\Client\Invoice($this->getApiUrl(), $this->getApiKey());
        $camount = \Coinsnap\Util\PreciseNumber::parseFloat($amount, 2);

        $metadata = [];
        $metadata['orderNumber'] = $invoice_no;
        $metadata['customerName'] = $buyerName;

        $csinvoice = $client->createInvoice(
            $this->getStoreId(),
            strtoupper($currency),
            $camount,
            $invoice_no,
            $buyerEmail,
            $buyerName,
            $return_url,
            self::REFERRAL_CODE,
            $metadata,
            $checkoutOptions
        );


        $payurl = $csinvoice->getData()['checkoutLink'];
        if (!empty($payurl)) {
            \header('Location: ' . $payurl);
        }
        exit;
    }


    public function get_webhook_url()
    {
        return Shop::getURL() . '/coinsnap-notify';
    }
    public function getStoreId()
    {

        return $this->getSetting('store_id');
    }
    public function getApiKey()
    {
        return $this->getSetting('api_key');
    }

    public function getApiUrl()
    {
        return 'https://app.coinsnap.io';
    }

    public function webhookExists(string $storeId, string $apiKey, string $webhook): bool
    {
        try {
            $whClient = new \Coinsnap\Client\Webhook($this->getApiUrl(), $apiKey);
            $Webhooks = $whClient->getWebhooks($storeId);

            foreach ($Webhooks as $Webhook) {
                //self::deleteWebhook($storeId,$apiKey, $Webhook->getData()['id']);
                if ($Webhook->getData()['url'] == $webhook) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            return false;
        }

        return false;
    }
    public function registerWebhook(string $storeId, string $apiKey, string $webhook): bool
    {
        try {
            $whClient = new \Coinsnap\Client\Webhook($this->getApiUrl(), $apiKey);

            $webhook = $whClient->createWebhook(
                $storeId,   //$storeId
                $webhook, //$url
                self::WEBHOOK_EVENTS,
                null    //$secret
            );

            return true;
        } catch (\Throwable $e) {
            return false;
        }

        return false;
    }

    public function deleteWebhook(string $storeId, string $apiKey, string $webhookid): bool
    {

        try {
            $whClient = new \Coinsnap\Client\Webhook($this->getApiUrl(), $apiKey);

            $webhook = $whClient->deleteWebhook(
                $storeId,   //$storeId
                $webhookid, //$url
            );
            return true;
        } catch (\Throwable $e) {

            return false;
        }
    }
}
