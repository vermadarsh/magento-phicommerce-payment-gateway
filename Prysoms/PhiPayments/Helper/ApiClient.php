<?php


namespace Prysoms\PhiPayments\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\HTTP\Client\Curl;
use Prysoms\PhiPayments\Helper\Data as phiPaymentHelper;
use Magento\Payment\Model\InfoInterface;

class ApiClient extends AbstractHelper
{
    protected $curl;
    protected phiPaymentHelper $phiPaymentHelper;

    public function __construct(
        Curl              $curl,
        phiPaymentHelper $phiPaymentHelper
    )
    {
        $this->curl = $curl;
        $this->phiPaymentHelper = $phiPaymentHelper;
    }

    /**
     * Refund a transaction via the gateway API
     *
     * @param InfoInterface $payment
     * @param string $transactionId
     * @param float $amount
     * @return array
     */
    public function refundTransaction(InfoInterface $payment, string $transactionId, float $amount): array
    {
        $response = array();

        // Get the payment gateway settings.
        $environment = $this->phiPaymentHelper->getConfigValue('environment');

        if ($environment == 'live') {
            $apiUrl = $this->phiPaymentHelper->getConfigValue('live_tref_api_url');
            $merchantInfo = $this->phiPaymentHelper->getConfigValue('live_merch_info');
        } else {
            $apiUrl = $this->phiPaymentHelper->getConfigValue('sandbox_tref_api_url');
            $merchantInfo = $this->phiPaymentHelper->getConfigValue('sandbox_merch_info');
        }

        // Return false, if the transaction API URL or other settings are not available.
        if (empty($apiUrl) || empty($apiUrl)) {
            $response = array(
                'success' => false,
                'message' => __('Cannot process refund due to PhiPayment Gateway error. Please contact site administrator.')
            );
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
                    $response = array(
                        'success' => false,
                        'message' => __('Cannot process refund due to PhiPayment Gateway settings error. Please contact site administrator.')
                    );
                } else {
                    $payphi_merchant_id = (!empty($payphi_api_credentials['merchId'])) ? $payphi_api_credentials['merchId'] : '';
                    $payphi_hash_calculation_key = (!empty($payphi_api_credentials['hashKey'])) ? $payphi_api_credentials['hashKey'] : '';

                    // Throw error if either of the detail is not present.
                    if (empty($payphi_merchant_id) || empty($payphi_hash_calculation_key)) {
                        $response = array(
                            'success' => false,
                            'message' => __('Cannot process refund due to PhPayment Gateway settings error. Please contact site administrator.')
                        );
                    }
                }
            } else {
                /**
                 * If here, means there are multiple merchants in the admin, and you need to validate.
                 * Validate the checkout merchand ID.
                 */
                $checkoutMerchantId = $payment->getAdditionalInformation('merchantID');
                if (empty($checkoutMerchantId) || is_null($checkoutMerchantId)) {
                    $response = array(
                        'success' => false,
                        'message' => __('Cannot process refund due to PhiPayment Gateway settings error. Please contact site administrator.')
                    );
                } else {
                    /**
                     * Merchant ID is provided on the checkout.
                     * Find the matching merchant ID now.
                     */
                    $db_merchant_ids = array_column($merchantIds, 'merchId');
                    $matching_merchant_id_index = array_search($checkoutMerchantId, $db_merchant_ids);

                    // If the matching merchant ID is not found.
                    if (false === $matching_merchant_id_index) {
                        $response = array(
                            'success' => false,
                            'message' => __('Multiple Merchant IDs present in the database and no matching merchant ID found.')
                        );
                    } else {
                        $payphi_api_credentials = $this->phiPaymentHelper->getMatchingMerchantId($merchantIds, $matching_merchant_id_index);

                        // Validate the payphi credentials.
                        if (empty($payphi_api_credentials) || !is_array($payphi_api_credentials)) {
                            $response = array(
                                'success' => false,
                                'message' => __('Cannot process refund due to PhiPayment Gateway settings error. Please contact site administrator.')
                            );
                        } else {
                            $payphi_merchant_id = (!empty($payphi_api_credentials['merchId'])) ? $payphi_api_credentials['merchId'] : '';
                            $payphi_hash_calculation_key = (!empty($payphi_api_credentials['hashKey'])) ? $payphi_api_credentials['hashKey'] : '';

                            // Throw error if either of the detail is not present.
                            if (empty($payphi_merchant_id) || empty($payphi_hash_calculation_key)) {
                                $response = array(
                                    'success' => false,
                                    'message' => __('Cannot process refund due to PhiPayment Gateway settings error. Please contact site administrator.')
                                );
                            }
                        }
                    }
                }
            }
        }

        if (!empty($response)) {
            return $response;
        }

        /**
         * Fire the payment API now.
         * Prepare the refund parameters.
         */
        $refund_parameters = array(
            'merchantID' => $payphi_merchant_id,
            'merchantTxnNo' => 'payphi-' . time(),
            'originalTxnNo' => $payment->getAdditionalInformation('phi-payment-merchant-transaction-no'),
            'paymentMode' => '',
            'amount' => $amount,
            'transactionType' => 'REFUND',
            'addlParam1' => '',
            'addlParam2' => '',
        );
        $refund_parameters['secureHash'] = $this->phiPaymentHelper->getSecuredHash($refund_parameters, $payphi_hash_calculation_key);

        $refund_parameters = json_encode($refund_parameters);

        // Set up headers and options
        $this->curl->addHeader("Content-Type", "application/json");
        $this->curl->setOption(CURLOPT_TIMEOUT, 600); // Set a timeout of 600 seconds

        $this->curl->post($apiUrl, $refund_parameters);

        if ($this->curl->getStatus() == 200) {
            // Is it's a success.

            $response = $this->curl->getBody(); // Get the response from the API
            $response_body = json_decode($response);

            $payphi_refund_response_code = (!empty($response_body->responseCode)) ? $response_body->responseCode : '';
            $payphi_refund_transaction_id = (!empty($response_body->txnID)) ? $response_body->txnID : '';
            $payphi_refund_date_time = (!empty($response_body->paymentDateTime)) ? $response_body->paymentDateTime : '';
            $payphi_refund_response_desc = (!empty($response_body->respDescription)) ? $response_body->respDescription : '';

            // If the refund is successful.
            if ('P1000' === $payphi_refund_response_code) {
                $response = array(
                    'success' => true,
                    'message' => __('Refund processed by PhiPayment gateway. Transaction ID: ' . $payphi_refund_transaction_id)
                );

            } else {
                // Just in case the refund is not success, do the log.
                $response = array(
                    'success' => false,
                    'message' => __('Refund could not be processed. Response code: ' . $payphi_refund_response_code . ' Response message: ' . $payphi_refund_response_desc)
                );
            }
        } else {
            // Just in case the refund is not success, do the log.
            $response = array(
                'success' => false,
                'message' => __('Online refund could not be processed. Payment Gateway error with message: '.$this->curl->getBody())
            );
        }
        return $response;
    }
}
