<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCartSplitter\Subscriber;

use Ruhrcoder\RcCartSplitter\TmmsConstants;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Framework\Struct\ArrayEntity;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Offcanvas\OffcanvasCartPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

// Laeuft nach TMMS (Prio -50) und ueberschreibt dessen Extensions, weil TMMS die Werte
// aus der Session pro Produktnummer setzt und damit alle Split-Positionen identisch macht.
final class CartDisplayCorrectionSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            OffcanvasCartPageLoadedEvent::class => ['onCartPageLoaded', -50],
            CheckoutCartPageLoadedEvent::class => ['onCartPageLoaded', -50],
            CheckoutConfirmPageLoadedEvent::class => ['onCartPageLoaded', -50],
        ];
    }

    public function onCartPageLoaded(OffcanvasCartPageLoadedEvent|CheckoutCartPageLoadedEvent|CheckoutConfirmPageLoadedEvent $event): void
    {
        $lineItems = $event->getPage()->getCart()->getLineItems()->getElements();

        foreach ($lineItems as $lineItem) {
            if ($lineItem->getType() !== LineItem::PRODUCT_LINE_ITEM_TYPE) {
                continue;
            }

            $payload = $lineItem->getPayload();
            if (!isset($payload[TmmsConstants::PAYLOAD_TMMS_ACTIVE])) {
                continue;
            }

            for ($i = 1; $i <= TmmsConstants::INPUT_COUNT; $i++) {
                $valueKey = TmmsConstants::PAYLOAD_FIELD_PREFIX . $i . TmmsConstants::PAYLOAD_FIELD_VALUE_SUFFIX;
                $value = (string) ($payload[$valueKey] ?? '');

                if ($value === '') {
                    continue;
                }

                $lineItem->addExtension(
                    'tmmsLineItemCustomerInput' . $i,
                    new ArrayEntity(['value' => $value]),
                );
            }
        }
    }
}
