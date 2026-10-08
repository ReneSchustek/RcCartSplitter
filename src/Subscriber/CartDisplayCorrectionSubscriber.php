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
 * Korrigiert die TMMS-Anzeige in Offcanvas-Warenkorb, Warenkorb und Bestellbestätigung.
 *
 * TMMS hängt an jede Position Extensions mit den Session-Werten der Produktnummer, sodass alle
 * getrennten Positionen eines Artikels dasselbe zeigen. Priorität -50 lässt diesen Subscriber
 * nach TMMS (Priorität 0) laufen und dessen Extensions mit den Werten der Position überschreiben.
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

        // Mit `rcTmmsActive` ist der Payload der Position maßgeblich. Für ein leeres Feld wird die
        // TMMS-Extension entfernt, sonst zeigte die Position den Session-Wert einer anderen.
        // Ohne Marker ist unklar, ob der Payload vollständig ist; dann wird nur ergänzt.
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

        // Den Inhalt schreibt der eigene Provider; er wird hier nicht noch einmal geprüft.
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

        // Positionen ohne `rcTmmsActive` tragen nur den Sammelschlüssel; er ist dann die einzige Quelle.
        $sessionEntry = $sessionInputs[$i] ?? null;
        if (!is_array($sessionEntry)) {
            return ['', ''];
        }

        $sessionValue = $sessionEntry[TmmsConstants::SESSION_VALUE_KEY] ?? '';
        $sessionLabel = $sessionEntry[TmmsConstants::SESSION_LABEL_KEY] ?? '';

        return [$sessionValue, $sessionLabel];
    }
}
