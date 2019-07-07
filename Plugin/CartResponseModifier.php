<?php

namespace Onilab\CartPopup\Plugin;

/**
 * Class CartResponseModifier
 *
 * @package Onilab\CartPopup\Plugin
 */
class CartResponseModifier
{
    /**
     * @var \Onilab\CartPopup\Model\RelatedProductsBlockBuilder
     */
    private $relatedBlockBuilder;

    /**
     * @var \Onilab\CartPopup\Model\OptionsBlockBuilder
     */
    private $optionsBlockBuilder;

    /**
     * @var \Magento\Framework\Message\ManagerInterface
     */
    private $messageManager;

    /**
     * @var \Magento\Catalog\Api\ProductRepositoryInterface
     */
    private $productRepository;

    /**
     * @var \Onilab\CartPopup\Model\LastAddedProductRegistry
     */
    private $registry;

    /**
     * @var \Magento\Framework\Serialize\Serializer\Json
     */
    private $serializer;

    /**
     * @var \Magento\Framework\Data\Form\FormKey\Validator
     */
    private $formKeyValidator;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * CartResponseModifier constructor.
     *
     * @param \Onilab\CartPopup\Model\RelatedProductsBlockBuilder $relatedBlockBuilder
     * @param \Onilab\CartPopup\Model\OptionsBlockBuilder $optionsBlockBuilder
     * @param \Magento\Framework\Message\ManagerInterface $messageManager
     * @param \Magento\Catalog\Api\ProductRepositoryInterface $productRepository
     * @param \Onilab\CartPopup\Model\LastAddedProductRegistry $registry
     * @param \Magento\Framework\Data\Form\FormKey\Validator $formKeyValidator
     * @param \Psr\Log\LoggerInterface $logger
     * @param \Magento\Framework\Serialize\Serializer\Json|null $serializer
     */
    public function __construct(
        \Onilab\CartPopup\Model\RelatedProductsBlockBuilder $relatedBlockBuilder,
        \Onilab\CartPopup\Model\OptionsBlockBuilder $optionsBlockBuilder,
        \Magento\Framework\Message\ManagerInterface $messageManager,
        \Magento\Catalog\Api\ProductRepositoryInterface $productRepository,
        \Onilab\CartPopup\Model\LastAddedProductRegistry $registry,
        \Magento\Framework\Data\Form\FormKey\Validator $formKeyValidator,
        \Psr\Log\LoggerInterface $logger,
        \Magento\Framework\Serialize\Serializer\Json $serializer = null
    ) {
        $this->relatedBlockBuilder = $relatedBlockBuilder;
        $this->optionsBlockBuilder = $optionsBlockBuilder;
        $this->messageManager = $messageManager;
        $this->productRepository = $productRepository;
        $this->registry = $registry;
        $this->formKeyValidator = $formKeyValidator;
        $this->logger = $logger;
        $this->serializer = $serializer ?: \Magento\Framework\App\ObjectManager::getInstance()
            ->get(\Magento\Framework\Serialize\Serializer\Json::class);
    }

    /**
     * @param \Magento\Checkout\Controller\Cart\Add $subject
     * @param mixed $result
     * @return mixed
     */
    public function afterExecute(
        \Magento\Checkout\Controller\Cart\Add $subject,
        $result
    ) {
        /** @var \Magento\Framework\App\Request\Http $request */
        $request = $subject->getRequest();
        /** @var \Magento\Framework\App\Response\Http */
        $response = $subject->getResponse();

        if (!$request->isAjax() ||
            !$response instanceof \Magento\Framework\App\Response\Http
        ) {
            return $result;
        }

        $content = [];

        if ($response->getContent()) {
            $content = $this->serializer->unserialize($response->getContent());
        }

        if (!$this->formKeyValidator->validate($request)) {
            $this->messageManager->addErrorMessage(
                __('We can\'t add this item to your shopping cart right now.')
            );
        }

        $content = $this->prepareResponseContent($content);

        $response->setContent($this->serializer->serialize($content));

        return $result;
    }

    /**
     * @param array $content
     * @return array
     */
    private function prepareResponseContent($content)
    {
        $product = $this->registry->getProduct();
        $quoteItem = $this->registry->getQuoteItem();

        $content['success'] = $product && $quoteItem;

        $content['related_products_block'] = $this->relatedBlockBuilder->build($product);

        /**
         * @TODO refactor
         */
        if ($product &&
            !$quoteItem &&
            $product->isSalable() &&
            (
                $product->getOptions() ||
                $product->hasCustomOptions() ||
                $product->getTypeId() == \Magento\GroupedProduct\Model\Product\Type\Grouped::TYPE_CODE
            )
        ) {
            $content['options_block'] = $this->optionsBlockBuilder->build($product);
        }

        $content['product_id'] = $product ? $product->getId() : null;

        $content['item_id'] = $quoteItem ? $quoteItem->getId() : null;

        if (isset($content['backUrl'])) {
            $content['back_url'] = $content['backUrl'];
            unset($content['backUrl']);
        }

        $content['messages'] = $this->collectMessages();

        return $content;
    }

    /**
     * @return array
     */
    private function collectMessages()
    {
        $result = [];

        foreach ($this->messageManager->getMessages(true)->getItems() as $message) {
            $result[] = [
                'type' => $message->getType(),
                'text' => $message->getText()
            ];
        }

        return $result;
    }
}