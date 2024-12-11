<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Prysoms\PhiPayments\Controller\Payment;

use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\Action;
use Prysoms\PhiPayments\Helper\Data as phiPaymentHelper;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Payment\Model\Method\Logger;
use Magento\Framework\App\RequestInterface;
use Magento\Quote\Api\CartRepositoryInterface;

/**
 * Class Start
 */
class Start extends Action
{

    protected phiPaymentHelper $phiPaymentHelper;
    protected CheckoutSession $checkoutSession;
    protected Curl $curl;
    protected Logger $logger;
    protected RequestInterface $request;
    protected CartRepositoryInterface $quoteRepository;

    public function __construct(
        Context $context,
        phiPaymentHelper $phiPaymentHelper,
        CheckoutSession $checkoutSession,
        Curl $curl,
        RequestInterface $request,
        CartRepositoryInterface $quoteRepository,
        Logger $logger
    ) {
        $this->phiPaymentHelper = $phiPaymentHelper;
        $this->checkoutSession = $checkoutSession;
        $this->curl = $curl;
        $this->logger = $logger;
        $this->request = $request;
        $this->quoteRepository = $quoteRepository;
        parent::__construct($context);
    }
    /**
     * Start PhiPayment Checkout by requesting initial token and dispatching customer to PhiPayment Gateway
     *
     * @return void
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function execute(): void
    {
        $is_checkout_error = false;

        // Get order and customer details
        $environment = $this->phiPaymentHelper->getConfigValue('environment');

        if ($environment == 'live') {
            $apiUrl = $this->phiPaymentHelper->getConfigValue('live_sale_api_url');
            $merchantInfo = $this->phiPaymentHelper->getConfigValue('live_merch_info');
        } else {
            $apiUrl = $this->phiPaymentHelper->getConfigValue('sandbox_sale_api_url');
            $merchantInfo = $this->phiPaymentHelper->getConfigValue('sandbox_merch_info');
        }

        // Return false, if the transaction API URL or other settings are not available.
        if (empty($apiUrl) || empty($apiUrl)) {
            $is_checkout_error = true;
            $payment_settings_error = sprintf(__('Cannot process payment due to %1$sgateway settings%2$s error. Please contact site administrator.', 'phi-payment-gateway'), '<strong>', '</strong>');
        } else {
            // This means the merchant ID is not available, we need to see how many merchant IDs are configured in the admin.
            $merchantIds = json_decode($merchantInfo);
            $merchantIdsCount = count($merchantIds);

            /**
             * If there is only 1 merchant configuration in the admin, then no need for any kind of validation.
             * Proceed with that merchant details.
             */
            if (1 === $merchantIdsCount) {
                $payphi_api_credentials = $this->phiPaymentHelper->getMatchingMerchantId($merchantIds);

                // Validate the payphi credentials.
                if (empty($payphi_api_credentials) || !is_array($payphi_api_credentials)) {
                    $is_checkout_error = true;
                    $payment_settings_error = sprintf(__('Cannot process payment due to %1$sgateway settings%2$s error. Please contact site administrator.', 'phi-payment-gateway'), '<strong>', '</strong>');
                } else {
                    $payphi_merchant_id = (!empty($payphi_api_credentials['merchId'])) ? $payphi_api_credentials['merchId'] : '';
                    $payphi_hash_calculation_key = (!empty($payphi_api_credentials['hashKey'])) ? $payphi_api_credentials['hashKey'] : '';

                    // Throw error if either of the detail is not present.
                    if (empty($payphi_merchant_id) || empty($payphi_hash_calculation_key)) {
                        $is_checkout_error = true;
                        $payment_settings_error = sprintf(__('Cannot process payment due to %1$sgateway settings%2$s error. Please contact site administrator.', 'phi-payment-gateway'), '<strong>', '</strong>');
                    }
                }
            } else {
                /**
                 * If here, means there are multiple merchants in the admin, and you need to validate.
                 * Validate the checkout merchand ID.
                 */
                $checkoutMerchantId = $this->request->getParam('checkout_merchant_id', null);
                if (empty($checkoutMerchantId) || is_null($checkoutMerchantId)) {
                    $is_checkout_error = true;
                    $payment_settings_error = sprintf(__('%1$sMerchant ID%2$s is the required field.', 'phi-payment-gateway'), '<strong>', '</strong>');
                } else {
                    /**
                     * Merchant ID is provided on the checkout.
                     * Find the matching merchant ID now.
                     */
                    $db_merchant_ids = array_column($merchantIds, 'merchId');
                    $matching_merchant_id_index = array_search($checkoutMerchantId, $db_merchant_ids);

                    // If the matching merchant ID is not found.
                    if (false === $matching_merchant_id_index) {
                        $is_checkout_error = true;
                        $payment_settings_error = sprintf(__('Multiple %1$smerchant ID%2$s present in the database and no matching merchant ID found.', 'phi-payment-gateway'), '<strong>', '</strong>');
                    } else {
                        $payphi_api_credentials = $this->phiPaymentHelper->getMatchingMerchantId($merchantIds, $matching_merchant_id_index);

                        // Validate the payphi credentials.
                        if (empty($payphi_api_credentials) || !is_array($payphi_api_credentials)) {
                            $is_checkout_error = true;
                            $payment_settings_error = sprintf(__('Cannot process payment due to %1$sgateway settings%2$s error. Please contact site administrator.', 'phi-payment-gateway'), '<strong>', '</strong>');
                        } else {
                            $payphi_merchant_id = (!empty($payphi_api_credentials['merchId'])) ? $payphi_api_credentials['merchId'] : '';
                            $payphi_hash_calculation_key = (!empty($payphi_api_credentials['hashKey'])) ? $payphi_api_credentials['hashKey'] : '';

                            // Throw error if either of the detail is not present.
                            if (empty($payphi_merchant_id) || empty($payphi_hash_calculation_key)) {
                                $is_checkout_error = true;
                                $payment_settings_error = sprintf(__('Cannot process payment due to %1$sgateway settings%2$s error. Please contact site administrator.', 'phi-payment-gateway'), '<strong>', '</strong>');
                            }
                        }
                    }
                }
            }
        }

        try {

            // Return, if there is checkout error.
            if ($is_checkout_error) {
                // Payment failed, throw an exception with the response message
                throw new LocalizedException(__('Payment failed: %1', $payment_settings_error ?? 'Unknown error'));
            }

            $quote = $this->checkoutSession->getQuote();
            $payment = $quote->getPayment();

            $billingAddress = $quote->getBillingAddress();

            $quote->collectTotals();

            // Data to send to the PhiPayment API
            $requestData = [
                'merchantId' => $payphi_merchant_id,
                'merchantTxnNo' => 'payphi-' . time(),
                'amount' => round((float)$quote->getBaseGrandTotal(), 2),
                'currencyCode' => '356',
                'payType' => '0',
                'customerEmailID' => $billingAddress->getEmail(),
                'transactionType' => 'SALE',
                'txnDate' => gmdate('YmdHis'),
                'customerID' => '12345',
                'customerMobileNo' => $billingAddress->getTelephone(),
                'addlParam1' => $this->request->getParam('addlParam1', null),
                'addlParam2' => $quote->getId(),
                'returnURL' => $this->phiPaymentHelper->getCallbackUrl($quote->getId()), // Define the callback for API response
            ];

            $requestData['secureHash'] = $this->phiPaymentHelper->getSecuredHash($requestData, $payphi_hash_calculation_key);

            $requestData = json_encode($requestData); // JSON encoded.

            // Set up headers and options
            $this->curl->addHeader("Content-Type", "application/json");
            $this->curl->setOption(CURLOPT_TIMEOUT, 600); // Set a timeout of 600 seconds

            $this->curl->post($apiUrl, $requestData);

            if ($this->curl->getStatus() == 200) {
                $response = $this->curl->getBody(); // Get the response from the API
                // Log the response for debugging purposes
                $this->logger->debug(['response' => $response]);

                $response_body        = json_decode( $response );
                $payphi_response_code = ( ! empty( $response_body->responseCode ) ) ? $response_body->responseCode : '';

                // If the response code is valid from PayPhi.
                if ( ! empty( $payphi_response_code ) ) {
                    if ( 'R1000' === $payphi_response_code ) {
                        $this->logger->debug( ["SUCCESS" => "Transaction request success. Code: {$payphi_response_code}"] ); // Write the log.
                        $payphi_redirect_uri     = ( ! empty( $response_body->redirectURI ) ) ? $response_body->redirectURI : '';
                        $transaction_ctx         = ( ! empty( $response_body->tranCtx ) ) ? $response_body->tranCtx : '';
                        $merchant_transaction_no = ( ! empty( $response_body->merchantTxnNo ) ) ? $response_body->merchantTxnNo : '';

                        // If the redirect is available.
                        if ( ! empty( $payphi_redirect_uri ) ) {
                            $payphi_redirect_uri = "{$payphi_redirect_uri}/?tranCtx={$transaction_ctx}";

                            $this->logger->debug( ["NOTICE" => "Redirecting to: {$payphi_redirect_uri}"]); // Write the log.

                            $quote->reserveOrderId();
                            $this->quoteRepository->save($quote);

                            $this->getResponse()->setRedirect($payphi_redirect_uri);

                            return ;
                        }
                    } else {
                        // Just in case the payment was not made.
                        $this->logger->debug( ["ERROR" => "Payment initial reqest failed. Response code received: {$payphi_response_code}"] ); // Write the log.
                        throw new LocalizedException(__('Payment initial request failed. Response code received: %1', $payphi_response_code));
                    }
                }

            } else {
                // Payment failed, throw an exception with the response message
                throw new LocalizedException(__('Payment failed: %1', $response['message'] ?? 'Unknown error'));
            }
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            $this->messageManager->addExceptionMessage($e, $e->getMessage());
        } catch (\Exception $e) {
            // Log error and throw exception to Magento
            $this->logger->debug(['Error in PaymentMethod' => $e->getMessage()]);

            $this->messageManager->addExceptionMessage(
                $e,
                __('We can\'t start PhiPayment Checkout.')
            );
        }

        $this->_redirect('checkout/cart');
    }
}
