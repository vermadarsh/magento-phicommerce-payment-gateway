<?php


namespace Prysoms\PhiPayments\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\View\Asset\Repository as AssetRepository;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Session\SessionManagerInterface;
use Magento\Framework\Encryption\EncryptorInterface;

class Data extends AbstractHelper
{
    const XML_PATH_MODULE = 'payment/phipayment/';

    protected AssetRepository $assetRepository;

    protected CheckoutSession $checkoutSession;
    protected CustomerSession $customerSession;
    protected SessionManagerInterface $session;
    protected EncryptorInterface $encryptor;

    public function __construct(
        Context $context,
        CheckoutSession $checkoutSession,
        CustomerSession $customerSession,
        SessionManagerInterface $session,
        EncryptorInterface $encryptor,
        AssetRepository $assetRepository
    ) {
        parent::__construct($context);
        $this->checkoutSession = $checkoutSession;
        $this->customerSession = $customerSession;
        $this->session = $session;
        $this->encryptor = $encryptor;
        $this->assetRepository = $assetRepository;
    }
    /**
     * Get configuration value from system configuration
     *
     * @param string $field
     * @param null|int $storeId
     * @return mixed
     */
    public function getConfigValue($field, $storeId = null): mixed
    {
        return $this->scopeConfig->getValue(
            self::XML_PATH_MODULE . $field,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Retrieves the matching merchant ID based on checkout merchant ID and configured merchant IDs.
     *
     * @param array $adminConfiguredMerchantIds
     * @param string $matching_merchant_id_index
     * @return string|null
     */
    public function getMatchingMerchantId(array $adminConfiguredMerchantIds, string $matching_merchant_id_index = ''): array|string|null
    {
        $matchingMerchant = [];

        // If the matching index is available.
        if (!empty($matching_merchant_id_index)) {
            if (!empty($adminConfiguredMerchantIds[$matching_merchant_id_index])) {
                $matchingMerchant = (array)$adminConfiguredMerchantIds[$matching_merchant_id_index];
            }
        } else {
            if (!empty($adminConfiguredMerchantIds[0])) {
                $matchingMerchant = (array)$adminConfiguredMerchantIds[0];
            }
        }

        return $matchingMerchant;
    }

    /**
     * Generates a secured hash using HMAC SHA-256.
     *
     * @param array $hashFields
     * @param string $hashCalculationKey
     * @return string
     */
    public function getSecuredHash(array $hashFields, string $hashCalculationKey): string
    {
        // Sort the data by keys in alphabetic order.
        ksort($hashFields);

        $hashInput = '';

        // Iterate through the fields to prepare the hash string.
        foreach ($hashFields as $key => $value) {
            if (strlen($value ?? '') > 0) {
                $hashInput .= $value;
            }
        }

        /**
         * Calculate the HMAC SHA-256 signature.
         * Use the secret key corresponding to your merchant ID.
         */
        $signature = hash_hmac('sha256', $hashInput, $hashCalculationKey);

        return $signature;
    }

    /**
     * Get the callback URL for payment confirmation
     *
     * @param string $quote_id
     * @return string
     */
    public function getCallbackUrl(string $quote_id): string
    {
        $return_data = [
            'quote_id' => $quote_id,
            'customer_session_id' => $this->customerSession->getSessionId(),
            'checkout_session_id' => $this->checkoutSession->getSessionId(),
            'session_id' => $this->session->getSessionId(),
        ];

        // Serialize the data array
        $return_data = json_encode($return_data);
        $return_data = urlencode($this->encryptor->encrypt($return_data));
        return $this->_urlBuilder->getUrl('phipayment/payment/callback', ['_secure' => true, 'return_data' => $return_data]);
    }

    /**
     * Get Payment Logo Src
     *
     * @return string
     */
    public function getPaymentLogoSrc(): string
    {
        // Define the path to your image within the module
        $logoPath = 'Prysoms_PhiPayments::images/payment_logo.png';

        // Generate the URL
        return $this->assetRepository->getUrl($logoPath);
    }
}