<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCartSplitter\Subscriber;

use Ruhrcoder\RcCartSplitter\Service\TmmsPayloadReader;
use Ruhrcoder\RcCartSplitter\TmmsConstants;
use Shopware\Core\Checkout\Cart\Event\BeforeLineItemAddedEvent;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final class TmmsInputCaptureSubscriber implements EventSubscriberInterface
{
    /** @param EntityRepository<ProductCollection> $productRepository */
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly EntityRepository $productRepository,
        private readonly TmmsPayloadReader $payloadReader,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            BeforeLineItemAddedEvent::class => ['onBeforeLineItemAdded', 100],
        ];
    }

    /**
     * Liest die TMMS-Kundeneingaben und speichert sie im LineItem-Payload.
     *
     * Quelle 1 (bevorzugt): Hidden-Felder aus dem Request-Payload (vom JS injiziert)
     * Quelle 2 (Fallback): TMMS-Session-Daten
     */
    public function onBeforeLineItemAdded(BeforeLineItemAddedEvent $event): void
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return;
        }

        $lineItem = $event->getCart()->get($event->getLineItem()->getId()) ?? $event->getLineItem();
        $productId = $lineItem->getReferencedId();
        if ($productId === null) {
            return;
        }

        // Quelle 1: Hidden-Felder aus dem Request-Payload
        $requestInputs = $this->payloadReader->readRequestPayload($request, $productId);
        if ($requestInputs !== []) {
            foreach ($requestInputs as $key => $value) {
                $lineItem->setPayloadValue($key, $value);
            }

            return;
        }

        // Quelle 2: Fallback auf TMMS-Session-Daten
        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session === null) {
            return;
        }

        $productNumber = $this->getProductNumber($productId, $event->getSalesChannelContext()->getContext());
        if ($productNumber === null) {
            return;
        }

        $inputs = $this->payloadReader->readSessionData($session, $productNumber);
        if ($inputs === []) {
            return;
        }

        $lineItem->setPayloadValue(TmmsConstants::PAYLOAD_TMMS_INPUTS, $inputs);
    }

    private function getProductNumber(string $productId, Context $context): ?string
    {
        $criteria = new Criteria([$productId]);

        $product = $this->productRepository->search($criteria, $context)->first();

        return $product instanceof ProductEntity ? $product->getProductNumber() : null;
    }
}
