<?php

namespace Prysoms\PhiPayments\Model;

use Prysoms\PhiPayments\Helper\ApiClient;
use Magento\Framework\Api\AttributeValueFactory;
use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Payment\Helper\Data;
use Magento\Payment\Model\InfoInterface;
use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Model\Method\Logger;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface;

class PaymentMethod extends AbstractMethod
{
    protected $_code = 'phipayment';
    protected $_isInitializeNeeded = false;
    protected $_canAuthorize = true;
    protected $_canCapture = true;
    protected $_canCapturePartial = false;
    protected $_canRefund = true;
    protected $_canRefundInvoicePartial = true;
    protected $_isGateway = true; // Indicates this is an online payment method
    protected $_canUseInternal = true;
    protected $_canUseCheckout = true;
    protected $_canFetchTransactionInfo = true;
    protected $_canReviewPayment = true;

    // API client to interact with the payment gateway
    protected $apiClient;

    protected $transactionBuilder;

    public function __construct(
        Context $context,
        Registry $registry,
        ExtensionAttributesFactory $extensionFactory,
        AttributeValueFactory $customAttributeFactory,
        Data $paymentData,
        ScopeConfigInterface $scopeConfig,
        Logger $logger,
        ApiClient $apiClient, // Your API client/helper class
        BuilderInterface $transactionBuilder,
        AbstractResource $resource = null,
        AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            $resource,
            $resourceCollection,
            $data
        );
        $this->apiClient = $apiClient;
        $this->transactionBuilder = $transactionBuilder;
    }
    /**
     * Refund the payment
     *
     * @param InfoInterface $payment
     * @param float $amount
     * @return $this
     * @throws LocalizedException
     */
    public function refund(InfoInterface $payment, $amount)
    {
        $transactionId = $payment->getLastTransId();

        if (!$transactionId) {
            throw new LocalizedException(__('Transaction ID is missing for the refund.'));
        }

        // Call the gateway API to process the refund
        //$response = $this->apiClient->refundTransaction($payment, $transactionId, $amount);

//        if (!$response['success']) {
//            throw new LocalizedException(__('Refund failed: %1', $response['message']));
//        }

        // Log the response or handle additional actions if necessary
        //$this->_logger->debug(__('Refund successful. Transaction ID: %1', $response['transaction_id']));

        return $this;
    }

    /**
     * Capture payment
     *
     * @param \Magento\Framework\DataObject|InfoInterface|Payment $payment
     * @param float $amount
     * @return $this
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function capture(InfoInterface $payment, $amount): static
    {
        $order = $payment->getOrder();

        $transaction = $this->transactionBuilder->setPayment($payment)
            ->setOrder($order)
            ->setTransactionId($payment->getTransactionId())
            ->setFailSafe(true)
            ->build(Transaction::TYPE_CAPTURE);

        $formatedPrice = $order->getBaseCurrency()->formatTxt($amount);

        $message = __(
            'The amount of %1 have been captured by  PhiPayment Gateway Online.',
            $formatedPrice
        );

        $payment->addTransactionCommentsToOrder($transaction, $message);

        return $this;
    }
}
