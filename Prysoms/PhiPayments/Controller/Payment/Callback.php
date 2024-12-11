<?php

namespace Prysoms\PhiPayments\Controller\Payment;

use Magento\Checkout\Model\Type\Onepage;
use Magento\Customer\Model\Group;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderFactory;
use Magento\Quote\Model\QuoteManagement;
use Psr\Log\LoggerInterface;
use Magento\Quote\Model\QuoteFactory;
use Magento\Framework\Session\SessionManagerInterface;
use Magento\Checkout\Helper\Data as CheckoutHelper;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Framework\Session\Generic as SessionGeneric;
use Magento\Quote\Api\CartRepositoryInterface;

class Callback extends Action implements CsrfAwareActionInterface
{
    protected CheckoutSession $checkoutSession;
    protected CustomerSession $customerSession;
    protected EncryptorInterface $encryptor;
    protected QuoteManagement $quoteManagement;
    protected OrderFactory $orderFactory;
    protected LoggerInterface $logger;
    protected QuoteFactory $quoteFactory;
    protected SessionManagerInterface $sessionManager;
    protected Quote $_quote;
    protected CheckoutHelper $checkoutHelper;
    protected OrderSender $orderSender;
    protected Order $_order;
    protected SessionGeneric $_session;
    protected CartRepositoryInterface $cartRepository;

    public function __construct(
        Context                 $context,
        CheckoutSession         $checkoutSession,
        CustomerSession         $customerSession,
        EncryptorInterface      $encryptor,
        QuoteManagement         $quoteManagement,
        OrderFactory            $orderFactory,
        QuoteFactory            $quoteFactory,
        SessionManagerInterface $sessionManager,
        CheckoutHelper          $checkoutHelper,
        OrderSender             $orderSender,
        SessionGeneric           $session,
        CartRepositoryInterface   $cartRepository,
        LoggerInterface         $logger
    )
    {
        parent::__construct($context);
        $this->checkoutSession = $checkoutSession;
        $this->customerSession = $customerSession;
        $this->encryptor = $encryptor;
        $this->quoteManagement = $quoteManagement;
        $this->orderFactory = $orderFactory;
        $this->logger = $logger;
        $this->quoteFactory = $quoteFactory;
        $this->sessionManager = $sessionManager;
        $this->checkoutHelper = $checkoutHelper;
        $this->orderSender = $orderSender;
        $this->cartRepository = $cartRepository;
        $this->_session = $session;
    }

    /**
     * Cancel Express Checkout
     *
     * @return void
     */
    public function execute(): void
    {
        // Retrieve the encrypted session ID from the callback URL
        $encryptedData = urldecode($this->getRequest()->getParam('return_data'));

        if ($encryptedData) {
            $return_data = json_decode($this->encryptor->decrypt($encryptedData), true);

            if ($return_data) {
                // Set the session ID
                $this->sessionManager->setSessionId($return_data['session_id']);
                $this->customerSession->setSessionId($return_data['customer_session_id']);
                $this->checkoutSession->setSessionId($return_data['checkout_session_id']);

                // Start or resume the session with the specified ID
                $this->sessionManager->start();


                $quoteId = $return_data['quote_id'];
                $this->_quote = $this->quoteFactory->create()->load($quoteId);

                $response = $this->getRequest()->getParams();

                if ($response['responseCode'] == '000') {
                    try {

                        // Place order with quote if the payment was successful
                        $this->place();

                        // prepare session to success or cancellation page
                        $this->checkoutSession->clearHelperData();

                        // "last successful quote"
                        $quoteId = $this->_quote->getId();
                        $this->checkoutSession->setLastQuoteId($quoteId)->setLastSuccessQuoteId($quoteId);

                        // an order may be created
                        $order = $this->_order;
                        if ($order) {
                            // Get the payment object

                            // Load the invoice from the order
                            $invoice = $order->getInvoiceCollection()->getFirstItem();
                            // Set the transaction ID in the invoice
                            $invoice->setTransactionId($response['txnID']);

                            // Save the updated invoice
                            $invoice->save();

                            $payment = $order->getPayment();

                            $payment->setAdditionalInformation('phi-payment-transaction-ID', $response['txnID']);
                            $payment->setAdditionalInformation('phi-payment-merchant-transaction-no', $response['merchantTxnNo']);
                            $payment->setAdditionalInformation('phi-payment-transaction-api-final-response-code', $response['responseCode']);
                            $payment->setAdditionalInformation('phi-payment-transaction-api-final-response-desc', $response['respDescription']);
                            $payment->setAdditionalInformation('checkout-merchant-id', $response['merchantId']);
                            $payment->setAdditionalInformation('checkout-paymentmode-id', $response['paymentMode']);

                            // Set additional flags on payment to indicate capture has been received
                            $payment->setIsTransactionPending(false);
                            $payment->setIsTransactionClosed(true);
                            $payment->setIsFraudDetected(false);
                            $payment->setAmountPaid($this->_quote->getGrandTotal());
                            $payment->setBaseAmountPaid($this->_quote->getBaseGrandTotal());
                            $payment->setTransactionId($response['txnID']);
                            $payment->setLastTransId($response['txnID']);

                            // Save the payment and order
                            $payment->save();
                            $order->save();

                            $this->checkoutSession->setLastOrderId($order->getId())
                                ->setLastRealOrderId($order->getIncrementId())
                                ->setLastOrderStatus($order->getStatus());
                        }

                        $this->_eventManager->dispatch(
                            'checkout_submit_all_after',
                            [
                                'order' => $order,
                                'quote' => $this->_quote
                            ]
                        );

                        $this->_session->unsQuoteId(); // clean quote from session that was set in OnAuthorization

                        $this->_redirect('checkout/onepage/success');
                        return;
                    } catch (\Exception $e) {
                        $this->messageManager->addErrorMessage(__('Unable to place the order.'));
                    }
                } elseif ($response['responseCode'] == '020') {
                    $this->messageManager->addErrorMessage(__('Payment was Cancelled by the Customer. Please try again.'));
                } elseif ($response['responseCode'] == '039') {
                    $this->messageManager->addErrorMessage(__('Payment Transaction has been rejected by the PhiPayment Gateway. Please try again.'));
                }
            } else {
                // Handle failure case
                $this->messageManager->addErrorMessage(__('Payment was not successful. Please try again.'));
            }
        } else {
            // Handle failure case
            $this->messageManager->addErrorMessage(__('Payment was not successful. Please try again.'));
        }

        /** @var \Magento\Framework\Controller\Result\Redirect $resultRedirect */
        $this->_redirect('checkout/cart');
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        // TODO: Implement createCsrfValidationException() method.
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        // TODO: Implement validateForCsrf() method.
        return true;
    }

    /**
     * Place the order when customer returned from PayPal until this moment all quote data must be valid.
     *
     * @return void
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function place(): void
    {

        if ($this->getCheckoutMethod() == Onepage::METHOD_GUEST) {
            $this->prepareGuestQuote();
        }

        $this->ignoreAddressValidation();
        $this->_quote->collectTotals();
        $order = $this->quoteManagement->submit($this->_quote);

        if (!$order) {
            return;
        }

        switch ($order->getState()) {
            // even after placement paypal can disallow to authorize/capture, but will wait until bank transfers money
            case Order::STATE_PENDING_PAYMENT:
                // TODO
                break;
            // regular placement, when everything is ok
            case Order::STATE_PROCESSING:
            case Order::STATE_COMPLETE:
            case Order::STATE_PAYMENT_REVIEW:
                try {
                    if (!$order->getEmailSent()) {
                        $this->orderSender->send($order);
                    }
                } catch (\Exception $e) {
                    $this->logger->critical($e);
                }
                $this->checkoutSession->start();
                break;
            default:
                break;
        }
        $this->_order = $order;
    }

    /**
     * Get checkout method
     *
     * @return string
     */
    public function getCheckoutMethod(): string
    {
        if ($this->customerSession->isLoggedIn()) {
            return Onepage::METHOD_CUSTOMER;
        }
        if (!$this->_quote->getCheckoutMethod()) {
            if ($this->checkoutHelper->isAllowedGuestCheckout($this->_quote)) {
                $this->_quote->setCheckoutMethod(Onepage::METHOD_GUEST);
            } else {
                $this->_quote->setCheckoutMethod(Onepage::METHOD_REGISTER);
            }
        }
        return $this->_quote->getCheckoutMethod();
    }

    /**
     * Prepare quote for guest checkout order submit
     *
     * @return $this
     */
    protected function prepareGuestQuote()
    {
        $quote = $this->_quote;
        $billingAddress = $quote->getBillingAddress();

        /* Check if Guest customer provided an email address on checkout page, and in case
        it was provided, use it as priority, if not, use email address returned from PayPal.
        (Guest customer can place order two ways: - from checkout page, where guest is asked to provide
        an email address that later can be used for account creation; - from mini shopping cart, directly
        proceeding to PayPal without providing an email address */
        $email = $billingAddress->getOrigData('email') !== null
            ? $billingAddress->getOrigData('email') : $billingAddress->getEmail();

        $quote->setCustomerId(null)
            ->setCustomerEmail($email)
            ->setCustomerFirstname($billingAddress->getFirstname())
            ->setCustomerLastname($billingAddress->getLastname())
            ->setCustomerIsGuest(true)
            ->setCustomerGroupId(Group::NOT_LOGGED_IN_ID);
        return $this;
    }

    /**
     * Make sure addresses will be saved without validation errors
     *
     * @return void
     */
    private function ignoreAddressValidation(): void
    {
        $this->_quote->getBillingAddress()->setShouldIgnoreValidation(true);
        if (!$this->_quote->getIsVirtual()) {
            $this->_quote->getShippingAddress()->setShouldIgnoreValidation(true);
            if (!$this->_quote->getBillingAddress()->getEmail()) {
                $this->_quote->getBillingAddress()->setSameAsBilling(1);
            }
        }
    }
}
