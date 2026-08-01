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

            $this->correctLineItem($lineItem);
        }
    }

    private function correctLineItem(LineItem $lineItem): void
    {
        $payload = $lineItem->getPayload();
        $payloadActive = isset($payload[TmmsConstants::PAYLOAD_TMMS_ACTIVE]);
        $sessionInputs = $this->extractSessionInputs($payload);

        if (!$payloadActive && $sessionInputs === null) {
            return;
        }

        // Wenn rcTmmsActive gesetzt ist, ist der Position-Payload autoritativ. Fuer leere Felder
        // muss die TMMS-Extension entfernt werden, sonst leakt der Session-Wert (gleicher
        // Produktnummer-Eintrag fuer alle Split-Positionen) in die Anzeige.
        for ($i = 1; $i <= TmmsConstants::INPUT_COUNT; $i++) {
            [$value, $label] = $this->resolveField($i, $payload, $sessionInputs);

            $extensionName = TmmsConstants::extensionName($i);

            if ($value === '') {
                if ($payloadActive) {
                    $lineItem->removeExtension($extensionName);
                }
                continue;
            }

            $lineItem->addExtension(
                $extensionName,
                new ArrayEntity(['value' => $value, 'label' => $label]),
            );
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array<string, string>>|null
     */
    private function extractSessionInputs(array $payload): ?array
    {
        $raw = $payload[TmmsConstants::PAYLOAD_TMMS_INPUTS] ?? null;
        if (!is_array($raw) || $raw === []) {
            return null;
        }

        /** @var array<int, array<string, string>> $raw */
        return $raw;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, array<string, string>>|null $sessionInputs
     * @return array{0: string, 1: string}
     */
    private function resolveField(int $i, array $payload, ?array $sessionInputs): array
    {
        $value = (string) ($payload[TmmsConstants::payloadValueKey($i)] ?? '');
        $label = (string) ($payload[TmmsConstants::payloadLabelKey($i)] ?? '');

        if ($value !== '') {
            return [$value, $label];
        }

        // Defense-in-Depth: Alt-Carts ohne rcTmmsActive haben den Session-Schluessel als einzige Quelle.
        $sessionEntry = $sessionInputs[$i] ?? null;
        if (!is_array($sessionEntry)) {
            return ['', ''];
        }

        $sessionValue = $sessionEntry[TmmsConstants::SESSION_VALUE_KEY] ?? '';
        $sessionLabel = $sessionEntry[TmmsConstants::SESSION_LABEL_KEY] ?? '';

        return [$sessionValue, $sessionLabel];
    }
}
