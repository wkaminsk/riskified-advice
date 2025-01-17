<?php
namespace Riskified\Advice\Model\Builder;

use Magento\Checkout\Model\Session;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\QuoteIdMaskFactory;
use Riskified\Decider\Model\Api\Log;
use Riskified\Decider\Model\Api\Order\Helper;
use Riskified\Advice\Model\Request\Advice as AdviceRequest;
use Riskified\OrderWebhook\Model\Checkout;
use Riskified\OrderWebhook\Model\PaymentDetails;

class Advice
{
    /**
     * @var AdviceRequest
     */
    private $adviceRequestModel;
    /**
     * @var Session
     */
    private $checkoutSession;
    /**
     * @var
     */
    private $json;
    /**
     * @var QuoteIdMaskFactory
     */
    private $quoteIdMaskFactory;
    /**
     * @var CartRepositoryInterface
     */
    protected $cartRepository;
    protected $logger;
    protected $helper;
    protected $remoteAddress;
    /**
     * Advice constructor.
     * @param QuoteIdMaskFactory $quoteIdMaskFactory
     * @param CartRepositoryInterface $cartRepository
     * @param AdviceRequest $requestAdvice
     * @param Session $checkoutSession
     */
    public function __construct(
        QuoteIdMaskFactory $quoteIdMaskFactory,
        CartRepositoryInterface $cartRepository,
        Helper $helper,
        Log $logger,
        AdviceRequest $requestAdvice,
        Session $checkoutSession,
        RemoteAddress $remoteAddress
    ) {
        $this->quoteIdMaskFactory = $quoteIdMaskFactory;
        $this->adviceRequestModel = $requestAdvice;
        $this->checkoutSession = $checkoutSession;
        $this->cartRepository = $cartRepository;
        $this->logger = $logger;
        $this->helper = $helper;
        $this->remoteAddress = $remoteAddress;
    }

    /**
     * Magento\Quote\Model\Quote\Interceptor
     * @param $params
     * @return Advice
     * @throws \Exception
     */
    public function build($params)
    {
        $quoteId = $params['quote_id'];
        try {
            if (!is_numeric($quoteId)) {
                $quoteIdMask = $this->quoteIdMaskFactory->create()->load($quoteId, 'masked_id');
                $cart = $this->cartRepository->get($quoteIdMask->getQuoteId());
            } else {
                $cart = $this->cartRepository->get($quoteId);
            }
        } catch (\Exception $e) {
            $stdClass = new \stdClass();
            $checkout = new \stdClass();
            $checkout->status = 'notcaptured';
            $stdClass->checkout = $checkout;

            return $stdClass;
        }

        $currencyObject = $cart->getCurrency()->getQuoteCurrencyCode();
        $gateway = $params['gateway'] ? $params['gateway'] : 'unavailable';

        $order_array = [
            'id' => $cart->getId(),
            'email' => $cart->getCustomerEmail(),
            'created_at' => $this->helper->formatDateAsIso8601($cart->getCreatedAt()),
            'currency' => $currencyObject,
            'updated_at' => $this->helper->formatDateAsIso8601($cart->getUpdatedAt()),
            'gateway' => $gateway,
            'source' => 'desktop_web',
            'browser_ip' => $this->remoteAddress->getRemoteAddress(),
            'note' => $cart->getCustomerNote(),
            'total_price' => $cart->getGrandTotal(),
            'subtotal_price' => $cart->getSubtotal(),
            'discount_codes' => null,
            'taxes_included' => true,
            'cart_token' => $this->checkoutSession->getSessionId()
        ];

        $this->helper->setOrder($cart);

        $payload = array_filter($order_array, fn ($val) => $val !== null || $val !== false);
        $checkoutData = new Checkout($payload);

        $checkoutData->customer = $this->helper->getCustomer();
        $checkoutData->shipping_address = $this->helper->getShippingAddress();
        $checkoutData->billing_address = $this->helper->getBillingAddress();
        $checkoutData->payment_details = $this->helper->getPaymentDetails();
        $checkoutData->line_items = $this->helper->getLineItems();
        $checkoutData->client_details = $this->helper->getClientDetails();

        $checkoutData->payment_details = new PaymentDetails(array_filter([
            "credit_card_company" => $params['type'],
            "credit_card_number" => "XXXX-XXXX-XXXX-" . $params['last4'],
            "credit_card_bin" => $params['bin'],
        ], fn ($val) => $val !== null || $val !== false));

        $this->json = $checkoutData;

        return $this;
    }
    /**
     * @return mixed
     * @throws \Riskified\OrderWebhook\Exception\CurlException
     * @throws \Riskified\OrderWebhook\Exception\UnsuccessfulActionException
     */
    public function request(): mixed
    {
        $this->logger->log("Calling advice endpoint: " . $this->json->toJson());
        return $this->adviceRequestModel->call($this->json->toJson());
    }
}
