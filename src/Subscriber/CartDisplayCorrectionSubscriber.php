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

/**
 * Korrigiert die TMMS "Eingabe prüfen"-Anzeige im Warenkorb.
 *
 * TMMS setzt die LineItem-Extension tmmsLineItemCustomerInput{i} aus der Session,
 * die pro Produktnummer gespeichert ist. Bei Split-Positionen steht dort immer
 * der gleiche Wert. Dieser Subscriber läuft NACH TMMS (Priorität -50) und
 * überschreibt die Extensions mit den korrekten Werten aus dem Payload.
 */
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

            $active = $lineItem->getPayloadValue(TmmsConstants::PAYLOAD_TMMS_ACTIVE);
            if ($active === null) {
                continue;
            }

            for ($i = 1; $i <= TmmsConstants::INPUT_COUNT; $i++) {
                $valueKey = TmmsConstants::PAYLOAD_FIELD_PREFIX . $i . TmmsConstants::PAYLOAD_FIELD_VALUE_SUFFIX;
                $value = $lineItem->getPayloadValue($valueKey) ?? '';

                $lineItem->addExtension(
                    'tmmsLineItemCustomerInput' . $i,
                    new ArrayEntity(['value' => $value]),
                );
            }
        }
    }
}
