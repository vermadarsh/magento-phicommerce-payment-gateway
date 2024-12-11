<?php
namespace Prysoms\PhiPayments\Model\Config\Source;

use Magento\Framework\Option\ArrayInterface;

class Environment implements ArrayInterface
{
    public function toOptionArray()
    {
        return [
            ['value' => 'sandbox', 'label' => __('Sandbox')],
            ['value' => 'live', 'label' => __('Live')]
        ];
    }
}
