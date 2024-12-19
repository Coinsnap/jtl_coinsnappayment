<?php declare(strict_types=1);

namespace Plugin\jtl_coinsnappayment;

use JTL\Backend\Notification;
use JTL\Backend\NotificationEntry;
use JTL\Events\Dispatcher;
use JTL\Plugin\Bootstrapper;
use JTL\Plugin\Payment\Method;
use JTL\Shop;
use Plugin\jtl_coinsnappayment\paymentmethod\CoinsnapPayment;

/**
 * Class Bootstrap
 * @package Plugin\jtl_coinsnappayment
 */
class Bootstrap extends Bootstrapper
{
    /**
     * @inheritDoc
     */
    public function boot(Dispatcher $dispatcher): void
    {
        parent::boot($dispatcher);

        if (!Shop::isFrontend()) {
            $dispatcher->listen('backend.notification', [$this, 'checkPayments']);
        }
    }

    /**
     * @return void
     */
    public function checkPayments(): void
    {
        foreach ($this->getPlugin()->getPaymentMethods()->getMethods() as $paymentMethod) {
            $method = Method::create($paymentMethod->getModuleID());
            if ($method instanceof CoinsnapPayment && !$method->isValidIntern()) {
                $note = new NotificationEntry(
                    NotificationEntry::TYPE_WARNING,
                    $paymentMethod->getName(),
                    __('Die Zahlungsart ist nicht konfiguriert'),
                    Shop::getAdminURL() . '/paymentmethods?kZahlungsart=' . $method->getMethod()->getMethodID()
                    . '&token=' . $_SESSION['jtl_token'],
                    'paymentMethodNotConfigured'
                );
                $note->setPluginId($this->getPlugin()->getPluginID());
                Notification::getInstance()->addNotify($note);
            }
        }
    }
}
