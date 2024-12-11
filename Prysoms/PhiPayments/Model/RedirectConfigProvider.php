<?php
namespace Prysoms\PhiPayments\Model;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\UrlInterface;
use Prysoms\PhiPayments\Helper\Data as phiPaymentHelper;

class RedirectConfigProvider implements ConfigProviderInterface
{
    protected $checkoutSession;
    protected $_urlBuilder;
    protected phiPaymentHelper $phiPaymentHelper;

    public function __construct(
        CheckoutSession $checkoutSession,
        UrlInterface $urlBuilder,
        phiPaymentHelper $phiPaymentHelper,
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->_urlBuilder = $urlBuilder;
        $this->phiPaymentHelper = $phiPaymentHelper;
    }

    public function getConfig()
    {
        return [
            'payment' => [
                'phipayment' => [
                    'redirect_url' => $this->_urlBuilder->getUrl('phipayment/payment/start'),
                    'getPaymentLogoSrc' => $this->phiPaymentHelper->getPaymentLogoSrc()
                ]
            ]
        ];
    }
}
