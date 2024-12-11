
# Prysoms_PhiPayments Payment Gateway for Magento 2  

Prysoms_PhiPayments is a custom payment gateway module designed for seamless integration with Magento 2. It enables secure payment processing and supports dynamic redirects, sandbox/live configurations, and custom logic tailored to merchant needs.

---

## Features  

- **Sandbox and Live Modes**: Separate configurations for testing and production environments.  
- **Redirect to Payment Gateway**: Automatically redirects customers to the payment gateway upon order placement.  
- **Customizable Payment Logic**: Includes additional payment logic and validations.  
- **Callback Handling**: Processes responses from the payment gateway and updates order statuses accordingly.  
- **Secure Quote Handling**: Manages quote lifecycle securely, ensuring no duplicate transactions.  
- **Admin Configuration Options**: Simple configuration of API keys, URLs, and environment modes from the Magento admin.  

---

## Installation  

### Manual Installation  
1. Download or clone this repository.  
2. Copy the contents into the `app/code/Prysoms/PhiPayments` directory.  
3. Run the following commands:  
   ```bash  
   bin/magento module:enable Prysoms_PhiPayments  
   bin/magento setup:upgrade  
   bin/magento setup:di:compile  
   bin/magento setup:static-content:deploy -f  
   bin/magento cache:clean  
   ```  

---

## Configuration  

1. Navigate to **Stores > Configuration > Sales > Payment Methods** in the Magento admin panel.  
2. Locate the "PhiPayments Payment Gateway" section.  
3. Configure the following settings:  
   - **Enable Payment Method**: Yes/No  
   - **Title**: Custom title displayed at checkout.  
   - **Environment**: Sandbox/Live  
   - **Sandbox/Live URLs**: URLs for the gateway's sandbox and live environments.  
   - **Merchant Information**: Enter the Merchant Information provided by the payment gateway.  
   - **Additional Settings**: Configure other settings.  

---

## File Structure  

- **Controller**  
  Handles redirection to and callback from the payment gateway.  
- **Model**  
  Contains core payment gateway logic, including `authorize` and `capture` methods.  
- **View**  
  Defines the payment method template displayed on the frontend.  
- **Etc**  
  - `system.xml`: Configuration settings for admin.  
  - `di.xml`: Dependency injection for model and helper classes.  
- **Helper**  
  Utility functions for generating URLs, managing sessions, and interacting with the payment gateway.  

---

## Development Notes  

- **Customization**: Extend the module via plugins and observers for additional functionality.  
- **Debugging**: Enable debug logs to monitor API requests and responses.  
  ```php  
  $this->logger->debug('Debug message', ['context' => $context]);  
  ```  
- **Direct SQL Operations**: Avoid direct SQL queries unless absolutely necessary. Use Magento's ResourceConnection instead.  

---

## Support  

For issues, feature requests, or contributions, contact us at [support@Prysoms.com](mailto:support@Prysoms.com).  

---

## License  

This module is licensed under the MIT License. See the `LICENSE` file for details.  
