<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCartSplitter\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Ruhrcoder\RcCartSplitter\TmmsConstants;
use Shopware\Core\Checkout\Cart\Event\BeforeLineItemAddedEvent;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Liefert die TMMS-Eingaben einer neuen Warenkorbposition als Payload-Werte.
 *
 * Erste Quelle sind die Hidden-Felder, die das Storefront-Skript beim Absenden einfügt. Fehlen
 * sie, etwa bei abgeschaltetem JavaScript, liest der Provider die Session, in der TMMS die Werte
 * je Produktnummer ablegt. Die Produktnummer kommt dafür per SQL statt über das Repository: Eine
 * volle ProductEntity bei jedem Hinzufügen zu laden, kostet mehr als die eine Spalte.
 */
final class TmmsCartInputProvider implements CartInputProviderInterface
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly Connection $connection,
        private readonly TmmsPayloadReader $payloadReader,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function provide(BeforeLineItemAddedEvent $event): array
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return [];
        }

        // Das Ereignis feuert nach `Cart::add()`. Lag die Position schon im Warenkorb, steckt die
        // Menge in der vorhandenen Position, und die aus dem Ereignis ist nur noch eine Kopie.
        $lineItem = $event->getCart()->get($event->getLineItem()->getId()) ?? $event->getLineItem();
        $productId = $lineItem->getReferencedId();
        if ($productId === null) {
            return [];
        }

        $requestInputs = $this->payloadReader->readRequestPayload($request, $productId);
        if ($requestInputs !== []) {
            return $requestInputs;
        }

        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session === null) {
            return [];
        }

        $productNumber = $this->fetchProductNumber($productId);
        if ($productNumber === null) {
            return [];
        }

        $sessionInputs = $this->payloadReader->readSessionData($session, $productNumber);
        if ($sessionInputs === []) {
            return [];
        }

        return $this->buildUnifiedFromSession($sessionInputs);
    }

    /**
     * Baut aus den Session-Einträgen dieselbe Payload-Form, die das Storefront-Skript liefert.
     * Sonst sehen Twig-Template (`rcTmmsField<N>Value`) und Anzeigekorrektur die Daten
     * nicht, und alle getrennten Positionen eines Produkts zeigen dieselben Session-Werte.
     *
     * @param array<int, array<string, string>> $sessionInputs
     * @return array<string, mixed>
     */
    private function buildUnifiedFromSession(array $sessionInputs): array
    {
        $unified = [TmmsConstants::PAYLOAD_TMMS_ACTIVE => '1'];

        foreach ($sessionInputs as $count => $data) {
            $unified[TmmsConstants::payloadValueKey($count)] = $data[TmmsConstants::SESSION_VALUE_KEY] ?? '';
            $unified[TmmsConstants::payloadLabelKey($count)] = $data[TmmsConstants::SESSION_LABEL_KEY] ?? '';
        }

        // Der Sammelschlüssel steht zusätzlich da. Die Korrekturen lesen ihn nur, wo `rcTmmsActive`
        // fehlt oder ein Einzelfeld leer ist; neben den Einzelfeldern oben kommt das nicht vor.
        $unified[TmmsConstants::PAYLOAD_TMMS_INPUTS] = $sessionInputs;

        return $unified;
    }

    private function fetchProductNumber(string $productId): ?string
    {
        // `referencedId` ist nicht zwingend eine UUID. Ein Gutschein-Platzhalter trägt dort den
        // Code, ein fremdes Plugin vielleicht eine eigene Kennung. `Uuid::fromHexToBytes()` würfe
        // dann, die Ausnahme stiege bis in den Storefront-Controller, und der Kunde sähe nur
        // „Leider ist etwas schiefgelaufen", ohne Eintrag im Protokoll.
        //
        // Kein Protokolleintrag hier: Der Fall ist erwartbar, eine Warnung je Warenkorb-Zugang
        // wäre Rauschen.
        if (!Uuid::isValid($productId)) {
            return null;
        }

        // Der Primärschlüssel von `product` ist (id, version_id). Gelesen wird die Live-Fassung; ein
        // Entwurf aus der Verwaltung trägt dieselbe Kennung und vielleicht eine andere Nummer.
        try {
            $productNumber = $this->connection->fetchOne(
                'SELECT product_number FROM product WHERE id = :id AND version_id = :version LIMIT 1',
                ['id' => Uuid::fromHexToBytes($productId), 'version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)],
            );
        } catch (\Throwable $error) {
            // `\Throwable` statt nur `DbalException`: Der Provider hängt im Add-to-Cart-Weg, und
            // jede Ausnahme, die hier durchsteigt, bricht das Hinzufügen ab. Ohne Produktnummer
            // fehlt nur der Session-Rückweg, der Artikel landet trotzdem im Warenkorb.
            $this->logger->warning('TMMS-Cart-Provider konnte product_number nicht laden', [
                'productId' => $productId,
                'exception' => $error,
            ]);

            return null;
        }

        return is_string($productNumber) && $productNumber !== '' ? $productNumber : null;
    }
}
