<?php


namespace Prysoms\PhiPayments\Block\Adminhtml\Order\View;

use Magento\Backend\Block\Template\Context;
use Magento\Sales\Model\Order;

class PaymentInfo extends \Magento\Backend\Block\Template
{
    protected $order;
    // Your custom payment method code
    const PHI_PAYMENT_METHOD = 'phipayment';
    public function __construct(
        Context $context,
        Order   $order,
        array   $data = []
    )
    {
        $this->order = $order;
        parent::__construct($context, $data);
    }

    public function getAdditionalInformation(): ?array
    {
        $orderId = $this->getRequest()->getParam('order_id');
        $order = $this->order->load($orderId);

        if($order->getPayment()->getMethod() == self::PHI_PAYMENT_METHOD) {
            $additional_info = $order->getPayment()->getAdditionalInformation();
            unset($additional_info['redirect_url']);
            unset($additional_info['quote_id']);
            unset($additional_info['method_title']);
            return $additional_info;
        }

        return null;
    }
}
