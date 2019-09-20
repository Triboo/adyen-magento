<?php
/**
 *                       ######
 *                       ######
 * ############    ####( ######  #####. ######  ############   ############
 * #############  #####( ######  #####. ######  #############  #############
 *        ######  #####( ######  #####. ######  #####  ######  #####  ######
 * ###### ######  #####( ######  #####. ######  #####  #####   #####  ######
 * ###### ######  #####( ######  #####. ######  #####          #####  ######
 * #############  #############  #############  #############  #####  ######
 *  ############   ############  #############   ############  #####  ######
 *                                      ######
 *                               #############
 *                               ############
 *
 * Adyen Payment Module
 *
 * Copyright (c) 2019 Adyen B.V.
 * This file is open source and available under the MIT license.
 * See the LICENSE file for more info.
 *
 * Author: Adyen <magento@adyen.com>
 */

/**
 * @category   Payment Gateway
 * @package    Adyen_Payment
 * @author     Adyen
 * @property   Adyen B.V
 * @copyright  Copyright (c) 2015 Adyen BV (http://www.adyen.com)
 */
class Adyen_Payment_Model_Api extends Mage_Core_Model_Abstract
{
    const RECURRING_TYPE_ONECLICK = 'ONECLICK';
    const RECURRING_TYPE_RECURRING = 'RECURRING';
    const RECURRING_TYPE_ONECLICK_RECURRING = 'ONECLICK,RECURRING';
    const ENDPOINT_TEST = "https://pal-test.adyen.com/pal/adapter/httppost";
    const ENDPOINT_LIVE = "https://pal-live.adyen.com/pal/adapter/httppost";
    const ENDPOINT_TERMINAL_CLOUD_TEST = "https://terminal-api-test.adyen.com/sync";
    const ENDPOINT_TERMINAL_CLOUD_LIVE = "https://terminal-api-live.adyen.com/sync";
    const ENDPOINT_PROTOCOL = "https://";
    const CHECKOUT_ENDPOINT_LIVE_SUFFIX = "-checkout-live.adyenpayments.com/checkout";
    const ENDPOINT_CONNECTED_TERMINALS_TEST = "https://terminal-api-test.adyen.com/connectedTerminals";
    const ENDPOINT_CONNECTED_TERMINALS_LIVE = "https://terminal-api-live.adyen.com/connectedTerminals";
    const ENDPOINT_CHECKOUT_TEST = "https://checkout-test.adyen.com/checkout";
    const GUEST_ID = "customer_";

    protected $_recurringTypes = array(
        self::RECURRING_TYPE_ONECLICK,
        self::RECURRING_TYPE_RECURRING
    );

    protected $_paymentMethodMap;


    /**
     * @param string $shopperReference
     * @param string $recurringDetailReference
     * @param int|Mage_Core_model_Store|null $store
     * @return bool
     */
    public function getRecurringContractDetail($shopperReference, $recurringDetailReference, $store = null)
    {
        $recurringContracts = $this->listRecurringContracts($shopperReference, $store);
        foreach ($recurringContracts as $rc) {
            if (isset($rc['recurringDetailReference']) && $rc['recurringDetailReference'] == $recurringDetailReference) {
                return $rc;
            }
        }

        return false;
    }


    /**
     * Create a payment request
     *
     * @param $payment
     * @param $amount
     * @param $paymentMethod
     * @return mixed
     */
    public function authorisePayment($payment, $amount, $paymentMethod)
    {
        // retrieve configurations
        if (Mage::app()->getStore()->isAdmin()) {
            $storeId = $payment->getOrder()->getStoreId();
        } else {
            $storeId = null;
        }

        // configurations
        $order = $payment->getOrder();
        $orderCurrencyCode = $order->getOrderCurrencyCode();
        $incrementId = $order->getIncrementId();
        $realOrderId = $order->getRealOrderId();
        $customerId = Mage::helper('adyen/payment')->getShopperReference($order->getCustomerId(), $realOrderId);
        $merchantAccount = Mage::helper('adyen')->getAdyenMerchantAccount($paymentMethod, $storeId);
        $customerEmail = $order->getCustomerEmail();
        $billingAddress = $order->getBillingAddress();
        $deliveryAddress = $order->getShippingAddress();

        if ($this->_helper()->getConfigDataDemoMode()) {
            $requestUrl = self::ENDPOINT_CHECKOUT_TEST . "/v41/payments";
        } else {
            $requestUrl = self::ENDPOINT_PROTOCOL .
                $this->_helper()->getConfigData("live_endpoint_url_prefix") .
                self::CHECKOUT_ENDPOINT_LIVE_SUFFIX . "/v41/payments";
        }

        $apiKey = $this->_helper()->getConfigDataApiKey($storeId);

        $request = array();

        $request['browserInfo'] = array(
            'userAgent' => $_SERVER['HTTP_USER_AGENT'],
            'acceptHeader' => $_SERVER['HTTP_ACCEPT']
        );
        $request['merchantAccount'] = $merchantAccount;
        $request['returnUrl'] = Mage::getUrl('adyen/process/success');
        $request['amount'] = array(
            'currency' => $orderCurrencyCode,
            'value' => Mage::helper('adyen')->formatAmount($amount, $orderCurrencyCode)
        );
        $request['reference'] = $incrementId;
        $request['fraudOffset'] = '0';
        $request['shopperEmail'] = $customerEmail;
        $request['shopperIP'] = $order->getXForwardedFor();
        $request['shopperReference'] = !empty($customerId) ? $customerId : self::GUEST_ID . $realOrderId;
        if (!Mage::app()->getStore()->isAdmin() && Mage::getStoreConfigFlag('payment/adyen_cc/enable_threeds2', $storeId)) {
            $request = $this->setThreeds2Data($request, $payment);
        }
        $request = $this->setApplicationInfo($request);
        $request = $this->buildAddressData($request, $billingAddress, $deliveryAddress);
        $request = $this->setRecurringMode($request, $paymentMethod, $payment, $storeId);
        $request = $this->setShopperInteraction($request, $paymentMethod, $payment, $storeId);
        $request = $this->setPaymentSpecificData($request, $paymentMethod, $payment);
        $response = $this->doRequestJson($request, $requestUrl, $apiKey, $storeId);
        return json_decode($response, true);
    }

    public function setThreeds2Data($request, $payment)
    {
        $session = Mage::helper('adyen')->getSession();
        $info = $payment->getMethodInstance();
        $request['additionalData']['allow3DS2'] = "true";
        $request['browserInfo']['language'] = $session->getData('language_' . $info->getCode());
        $request['browserInfo']['colorDepth'] = $session->getData('color_depth_' . $info->getCode());
        $request['browserInfo']['screenHeight'] = $session->getData('screen_height_' . $info->getCode());
        $request['browserInfo']['screenWidth'] = $session->getData('screen_width_' . $info->getCode());
        $request['browserInfo']['timeZoneOffset'] = $session->getData('time_zone_offset_' . $info->getCode());
        $request['browserInfo']['javaEnabled'] = $session->getData('java_enabled_' . $info->getCode());
        $request['channel'] = 'web';
        $request['origin'] = $this->getOrigin();
        return $request;
    }

    /**
     * Create a payment details request for 3DS 2.0
     *
     * @param $payload
     * @param $payment
     * @param $storeId
     * @return mixed
     * @throws Adyen_Payment_Exception
     */
    public function authoriseThreeDS2Payment($payload, $payment, $storeId)
    {
        $apiKey = $this->_helper()->getConfigDataApiKey($storeId);
        if ($this->_helper()->getConfigDataDemoMode()) {
            $requestUrl = "https://checkout-test.adyen.com/v41/payments/details";
        } else {
            $requestUrl = self::ENDPOINT_PROTOCOL . $this->_helper()->getConfigData("live_endpoint_url_prefix") . self::CHECKOUT_ENDPOINT_LIVE_SUFFIX . "/v41/payments/details";
        }

        $request = array();

        if ($paymentData = $payment->getAdditionalInformation('threeDS2PaymentData')) {
            // Add payment data into the request object
            $request['paymentData'] = $payment->getAdditionalInformation('threeDS2PaymentData');

            // unset payment data from additional information
            $payment->unsAdditionalInformation('threeDS2PaymentData');
        } else {
            Adyen_Payment_Exception::throwException('3D secure 2.0 failed, payment data not found');
        }

        // Depends on the component's response we send a fingerprint or the challenge result
        if (!empty($payload['details']['threeds2.fingerprint'])) {
            $request['details']['threeds2.fingerprint'] = $payload['details']['threeds2.fingerprint'];
        } elseif (!empty($payload['details']['threeds2.challengeResult'])) {
            $request['details']['threeds2.challengeResult'] = $payload['details']['threeds2.challengeResult'];
        }

        $response = $this->doRequestJson($request, $requestUrl, $apiKey, $storeId);
        return json_decode($response, true);
    }

    /**
     * Define recurring mode for payment request
     *
     * @param $request
     * @param $paymentMethod
     * @param $storeId
     * @param $payment
     * @return mixed
     */
    public function setRecurringMode($request, $paymentMethod, $payment, $storeId)
    {
        $recurringType = $this->_helper()->getConfigData('recurringtypes', 'adyen_abstract', $storeId);

        if ($paymentMethod === 'apple_pay') {
            $request['enableRecurring'] = false;
            $request['enableOneClick'] = false;
            // if type want to store it as recurring do store it only as recurring
            // you do not want to have a oneclick card based on apple pay tx
            if (!empty($recurringType) && $recurringType !== self::RECURRING_TYPE_ONECLICK) {
                $request['enableRecurring'] = true;
            }
        } elseif ($paymentMethod === 'cc') {
            $request['enableRecurring'] = false;
            $request['enableOneClick'] = true;

            // if save card is disabled only shoot in as recurring if recurringType is set to ONECLICK,RECURRING
            if ($payment->getAdditionalInformation('store_cc') == '' &&
                $recurringType === self::RECURRING_TYPE_ONECLICK_RECURRING
            ) {
                $request['enableRecurring'] = true;
            } elseif ($payment->getAdditionalInformation('store_cc') == '1') {
                if ($recurringType == self::RECURRING_TYPE_ONECLICK || $recurringType == self::RECURRING_TYPE_ONECLICK_RECURRING) {
                    $request['paymentMethod']['storeDetails'] = true;
                }

                if ($recurringType == self::RECURRING_TYPE_ONECLICK_RECURRING || $recurringType == self::RECURRING_TYPE_RECURRING) {
                    $request['enableRecurring'] = true;
                }
            } elseif ($recurringType == self::RECURRING_TYPE_RECURRING) {
                $request['enableRecurring'] = true;
            }
        }

        return $request;
    }


    public function setShopperInteraction($request, $paymentMethod, $payment, $storeId)
    {
        $shopperInteraction = "Ecommerce";
        $enableMoto = (int)$this->_helper()->getConfigData('enable_moto', 'adyen_cc', $storeId);

        if ($paymentMethod == 'cc') {
            if ($paymentMethod == 'cc' && Mage::app()->getStore()->isAdmin() &&
                $enableMoto != null && $enableMoto == 1
            ) {
                $shopperInteraction = 'Moto';
            }
        } elseif ($paymentMethod === 'oneclick') {
            if (!$payment->getAdditionalInformation('customer_interaction')) {
                $shopperInteraction = "ContAuth";
            }
        }

        $request['shopperInteraction'] = $shopperInteraction;
        return $request;
    }

    /**
     * Set payment specific data into payment request
     *
     * @param $request
     * @param $paymentMethod
     * @param $payment
     * @return mixed
     */
    public function setPaymentSpecificData($request, $paymentMethod, $payment)
    {
        if ($paymentMethod == 'cc' || $paymentMethod == 'oneclick') {
            // encrypted card data
            $session = Mage::helper('adyen')->getSession();
            $info = $payment->getMethodInstance();
            $encryptedNumber = $session->getData('encrypted_number_' . $info->getCode());
            $encryptedExpiryMonth = $session->getData('encrypted_expiry_month_' . $info->getCode());
            $encryptedExpiryYear = $session->getData('encrypted_expiry_year_' . $info->getCode());
            $encryptedCvc = $session->getData('encrypted_cvc_' . $info->getCode());


            // installments
            if (Mage::helper('adyen/installments')->isInstallmentsEnabled() &&
                $payment->getAdditionalInformation('number_of_installments') > 0
            ) {
                $request['installments']['value'] = $payment->getAdditionalInformation('number_of_installments');
            }


            if (!empty($encryptedCvc) && $encryptedCvc != "false") {
                $request['paymentMethod']['encryptedSecurityCode'] = $encryptedCvc;
            }

            if ($paymentMethod == 'oneclick') {
                $request['paymentMethod']['type'] = $payment->getMethodInstance()->getRecurringDetails()['variant'];
                $request['paymentMethod']['recurringDetailReference'] =
                    $payment->getAdditionalInformation("recurring_detail_reference");
            } else {
                $request['paymentMethod']['type'] = 'scheme';
                if (!empty($payment->getCcOwner())) {
                    $request['paymentMethod']['holderName'] = $payment->getCcOwner();
                }

                if (!empty($encryptedNumber) && $encryptedNumber != "false") {
                    $request['paymentMethod']['encryptedCardNumber'] = $encryptedNumber;
                }

                if (!empty($encryptedExpiryMonth) && !empty($encryptedExpiryYear)) {
                    $request['paymentMethod']['encryptedExpiryMonth'] = $encryptedExpiryMonth;
                    $request['paymentMethod']['encryptedExpiryYear'] = $encryptedExpiryYear;
                }
            }
        } elseif ($paymentMethod === 'boleto') {
            $boleto = unserialize($payment->getPoNumber());
            $request['selectedBrand'] = $boleto['selected_brand'];
            $request['paymentMethod']['type'] = $boleto['selected_brand'];
            $request['socialSecurityNumber'] = $boleto['social_security_number'];
            $request['deliveryDate'] = $boleto['delivery_date'];
            $request['shopperName']['firstName'] = $boleto['firstname'];
            $request['shopperName']['lastName'] = $boleto['lastname'];
        } elseif ($paymentMethod === 'multibanco') {
            $request['paymentMethod']['type'] = $paymentMethod;
            $request['deliveryDate'] = $payment->getAdditionalInformation('delivery_date');
        } elseif ($paymentMethod === 'sepa') {
            $request['paymentMethod']['type'] = 'sepadirectdebit';
            // Additional data for sepa direct debit
            if (!empty($payment->getAdditionalInformation('account_name'))) {
                $request['paymentMethod']['sepa.ownerName'] = $payment->getAdditionalInformation('account_name');
            }

            if (!empty($payment->getAdditionalInformation('iban'))) {
                $request['paymentMethod']['sepa.ibanNumber'] = $payment->getAdditionalInformation('iban');
            }
        } elseif ($paymentMethod === 'apple_pay') {
            $token = $payment->getAdditionalInformation("token");
            if (!$token) {
                Mage::throwException(Mage::helper('adyen')->__('Missing token'));
            }

            $request['paymentMethod']['type'] = 'applepay';
            $request['paymentMethod']['applepay.token'] = base64_encode($token);
        }

        return $request;
    }


    /**
     * Create a 3D secure payment request
     *
     * @param $payment
     * @return mixed
     */
    public function authorise3DPayment($payment)
    {
        $apiKey = $this->_helper()->getConfigDataApiKey(null);
        if ($this->_helper()->getConfigDataDemoMode()) {
            $requestUrl = self::ENDPOINT_CHECKOUT_TEST . "/v41/payments/details";
        } else {
            $requestUrl = self::ENDPOINT_PROTOCOL . $this->_helper()->getConfigData("live_endpoint_url_prefix") . self::CHECKOUT_ENDPOINT_LIVE_SUFFIX . "/v41/payments/details";
        }

        $storeId = $payment->getOrder()->getStoreId();
        $paymentData = $payment->getAdditionalInformation('paymentData');
        $md = $payment->getAdditionalInformation('md');
        $paResponse = $payment->getAdditionalInformation('paResponse');


        $request = array(
            "paymentData" => $paymentData,
            "details" => array(
                "MD" => $md,
                "PaRes" => $paResponse
            )
        );

        $payment->unsAdditionalInformation('paymentData');
        $payment->unsAdditionalInformation('paRequest');
        $payment->unsAdditionalInformation('md');
        $payment->unsAdditionalInformation('paResponse');


        $resultJson = $this->doRequestJson($request, $requestUrl, $apiKey, $storeId);
        return json_decode($resultJson, true);
    }

    /**
     * @param array $request
     * @param $billingAddress
     * @param $shippingAddress
     * @return array
     */
    public function buildAddressData($request, $billingAddress, $shippingAddress)
    {
        if ($billingAddress) {
            // Billing address defaults
            $requestBillingDefaults = array(
                "street" => "N/A",
                "postalCode" => '',
                "city" => "N/A",
                "houseNumberOrName" => '',
                "country" => "ZZ"
            );

            // Save the defaults for later to compare if anything has changed
            $requestBilling = $requestBillingDefaults;


            if (!empty($billingAddress->getStreet(1))) {
                $requestBilling["street"] = $billingAddress->getStreet(1);
            }

            if (!empty($billingAddress->getPostcode())) {
                $requestBilling["postalCode"] = $billingAddress->getPostcode();
            }

            if (!empty($billingAddress->getCity())) {
                $requestBilling["city"] = $billingAddress->getCity();
            }

            if (!empty($billingAddress->getRegionCode())) {
                $requestBilling["stateOrProvince"] = $billingAddress->getRegionCode();
            }

            if (!empty($billingAddress->getCountryId())) {
                $requestBilling["country"] = $billingAddress->getCountryId();
            }

            // If nothing is changed which means delivery address is not filled
            if ($requestBilling !== $requestBillingDefaults) {
                $request['billingAddress'] = $requestBilling;
            }
        }

        if ($shippingAddress) {
            // Delivery address defaults
            $requestDeliveryDefaults = array(
                "street" => "N/A",
                "postalCode" => '',
                "city" => "N/A",
                "houseNumberOrName" => '',
                "country" => "ZZ"
            );

            // Save the defaults for later to compare if anything has changed
            $requestDelivery = $requestDeliveryDefaults;


            if (!empty($shippingAddress->getStreet(1))) {
                $requestDelivery["street"] = $shippingAddress->getStreet(1);
            }

            if (!empty($shippingAddress->getPostcode())) {
                $requestDelivery["postalCode"] = $shippingAddress->getPostcode();
            }

            if (!empty($shippingAddress->getCity())) {
                $requestDelivery["city"] = $shippingAddress->getCity();
            }

            if (!empty($shippingAddress->getRegionCode())) {
                $requestDelivery["stateOrProvince"] = $shippingAddress->getRegionCode();
            }

            if (!empty($shippingAddress->getCountryId())) {
                $requestDelivery["country"] = $shippingAddress->getCountryId();
            }

            // If nothing is changed which means delivery address is not filled
            if ($requestDelivery !== $requestDeliveryDefaults) {
                $request['deliveryAddress'] = $requestDelivery;
            }
        }
        return $request;
    }

    /**
     * Get all the stored Credit Cards and other billing agreements stored with Adyen.
     *
     * @param string $shopperReference
     * @param int|Mage_Core_model_Store|null $store
     * @return array
     */
    public function listRecurringContracts($shopperReference, $store = null)
    {

        $recurringContracts = array();
        foreach ($this->_recurringTypes as $recurringType) {
            try {
                // merge ONECLICK and RECURRING into one record with recurringType ONECLICK,RECURRING
                $listRecurringContractByType = $this->listRecurringContractByType(
                    $shopperReference, $store,
                    $recurringType
                );

                foreach ($listRecurringContractByType as $recurringContract) {
                    if (isset($recurringContract['recurringDetailReference'])) {
                        $recurringDetailReference = $recurringContract['recurringDetailReference'];
                        // check if recurring reference is already in array
                        if (isset($recurringContracts[$recurringDetailReference])) {
                            // recurring reference already exists so recurringType is possible for ONECLICK and RECURRING
                            $recurringContracts[$recurringDetailReference]['recurring_type'] = self::RECURRING_TYPE_ONECLICK_RECURRING;
                        } else {
                            $recurringContracts[$recurringDetailReference] = $recurringContract;
                        }
                    }
                }
            } catch (Adyen_Payment_Exception $e) {
                Adyen_Payment_Exception::throwException(
                    Mage::helper('adyen')->__(
                        "Error retrieving the Billing Agreement for shopperReference %s with recurringType #%s Error: %s",
                        $shopperReference, $recurringType, $e->getMessage()
                    )
                );
            }
        }

        return $recurringContracts;
    }


    /**
     * @param $shopperReference
     * @param $store
     * @param $recurringType
     *
     * @return array
     */
    public function listRecurringContractByType($shopperReference, $store, $recurringType)
    {
        // rest call to get list of recurring details
        $request = array(
            "action" => "Recurring.listRecurringDetails",
            "recurringDetailsRequest.merchantAccount" => $this->_helper()->getConfigData(
                'merchantAccount', null,
                $store
            ),
            "recurringDetailsRequest.shopperReference" => $shopperReference,
            "recurringDetailsRequest.recurring.contract" => $recurringType,
        );

        $result = $this->_doRequest($request, $store);

        // convert result to utf8 characters
        $result = utf8_encode(urldecode($result));

        // The $result contains a JSON array containing the available payment methods for the merchant account.
        parse_str($result, $resultArr);

        $recurringContracts = array();
        $recurringContractExtra = array();
        foreach ($resultArr as $key => $value) {
            // strip the key
            $key = str_replace("recurringDetailsResult_details_", "", $key);
            $key2 = strstr($key, '_');
            $keyNumber = str_replace($key2, "", $key);
            $keyAttribute = substr($key2, 1);

            // set ideal to sepadirectdebit because it is and we want to show sepadirectdebit logo
            if ($keyAttribute == "variant" && $value == "ideal") {
                $value = 'sepadirectdebit';
            }

            if ($keyAttribute == 'variant') {
                $recurringContracts[$keyNumber]['recurring_type'] = $recurringType;
                $recurringContracts[$keyNumber]['payment_method'] = $this->_mapToPaymentMethod($value);
            }

            $recurringContracts[$keyNumber][$keyAttribute] = $value;

            if ($keyNumber == 'recurringDetailsResult') {
                $recurringContractExtra[$keyAttribute] = $value;
            }
        }

        // unset the recurringDetailsResult because this is not a card
        unset($recurringContracts["recurringDetailsResult"]);

        foreach ($recurringContracts as $key => $recurringContract) {
            $recurringContracts[$key] = $recurringContracts[$key] + $recurringContractExtra;
        }

        return $recurringContracts;
    }

    /**
     * Map the recurring variant to a Magento payment method.
     * @param $variant
     * @return mixed
     */
    protected function _mapToPaymentMethod($variant)
    {
        if (is_null($this->_paymentMethodMap)) {
            //@todo abstract this away to some config?
            $this->_paymentMethodMap = array(
                'sepadirectdebit' => 'adyen_sepa'
            );


            $ccTypes = Mage::helper('adyen')->getCcTypes();
            $ccTypes = array_keys(array_change_key_case($ccTypes, CASE_LOWER));
            foreach ($ccTypes as $ccType) {
                $this->_paymentMethodMap[$ccType] = 'adyen_cc';
            }
        }

        return isset($this->_paymentMethodMap[$variant]) ? $this->_paymentMethodMap[$variant] : $variant;
    }


    /**
     * Disable a recurring contract
     *
     * @param string $recurringDetailReference
     * @param string $shopperReference
     * @param int|Mage_Core_model_Store|null $store
     *
     * @throws Adyen_Payment_Exception
     * @return bool
     */
    public function disableRecurringContract($recurringDetailReference, $shopperReference, $store = null)
    {
        $merchantAccount = $this->_helper()->getConfigData('merchantAccount', null, $store);

        $request = array(
            "action" => "Recurring.disable",
            "disableRequest.merchantAccount" => $merchantAccount,
            "disableRequest.shopperReference" => $shopperReference,
            "disableRequest.recurringDetailReference" => $recurringDetailReference
        );

        $result = $this->_doRequest($request, $store);

        // convert result to utf8 characters
        $result = utf8_encode(urldecode($result));

        if ($result != "disableResult.response=[detail-successfully-disabled]") {
            Adyen_Payment_Exception::throwException(Mage::helper('adyen')->__($result));
        }

        return true;
    }

    public function originKeys($store)
    {
        $cacheId = "adyen_origin_keys_" . $store;

        $domain = $this->getOrigin();

        $request = array(
            "originDomains" => array($domain)
        );

        if ($cacheData = Mage::app()->getCache()->load($cacheId)) {
            $originKey = $cacheData;
        } else {
            try {
                $resultJson = $this->doRequestOriginKey($request, $store);
                $result = json_decode($resultJson, true);
                if (!empty($originKey = $result['originKeys'][$domain])) {
                    Mage::app()->getCache()->save(
                        $originKey, $cacheId,
                        array(Mage_Core_Model_Config::CACHE_TAG), 60 * 60 * 24
                    );
                }
            } catch (Exception $e) {
                return '';
            }
        }

        return $originKey;
    }

    public function getOrigin()
    {
        $originUrl = Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_WEB);
        $parsed = parse_url($originUrl);
        $origin = $parsed['scheme'] . "://" . $parsed['host'];
        if (!empty($parsed['port'])) {
            $origin .= ":" . $parsed['port'];
        }

        return $origin;
    }

    /**
     * Do the actual API request
     *
     * @param array $request
     * @param int|Mage_Core_model_Store $storeId
     *
     * @throws Adyen_Payment_Exception
     * @return mixed
     */
    protected function _doRequest(array $request, $storeId)
    {
        if ($storeId instanceof Mage_Core_model_Store) {
            $storeId = $storeId->getId();
        }

        $requestUrl = self::ENDPOINT_LIVE;
        if ($this->_helper()->getConfigDataDemoMode($storeId)) {
            $requestUrl = self::ENDPOINT_TEST;
        }

        $username = $this->_helper()->getConfigDataWsUserName($storeId);
        $password = $this->_helper()->getConfigDataWsPassword($storeId);

        $logRequest = $request;
        $logRequest['additionalData'] = '';
        Mage::log($logRequest, null, 'adyen_api.log');

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $requestUrl);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $username . ":" . $password);
        curl_setopt($ch, CURLOPT_POST, count($request));
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($request));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $result = curl_exec($ch);
        $error = curl_error($ch);

        $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($result === false) {
            Adyen_Payment_Exception::throwException($error);
        }

        if ($httpStatus != 200) {
            Adyen_Payment_Exception::throwException(
                Mage::helper('adyen')->__(
                    'HTTP Status code %s received, data %s',
                    $httpStatus, $result
                )
            );
        }

        return $result;
    }

    protected function doRequestOriginKey(array $request, $storeId)
    {
        if ($storeId instanceof Mage_Core_model_Store) {
            $storeId = $storeId->getId();
        }

        if ($this->_helper()->getConfigDataDemoMode()) {
            $requestUrl = "https://checkout-test.adyen.com/v1/originKeys";
        } else {
            $requestUrl = self::ENDPOINT_PROTOCOL . $this->_helper()->getConfigData("live_endpoint_url_prefix") . self::CHECKOUT_ENDPOINT_LIVE_SUFFIX . "/v1/originKeys";
        }

        $apiKey = $this->_helper()->getConfigDataApiKey($storeId);

        return $this->doRequestJson($request, $requestUrl, $apiKey, $storeId);
    }

    /**
     * @return Adyen_Payment_Helper_Data
     */
    protected function _helper()
    {
        return Mage::helper('adyen');
    }

    /**
     * Do the API request in json format
     *
     * @param array $request
     * @param $requestUrl
     * @param $apiKey
     * @param $storeId
     * @param null $timeout
     * @return mixed
     */
    protected function doRequestJson(array $request, $requestUrl, $apiKey, $storeId, $timeout = null)
    {
        $ch = curl_init();
        $headers = array(
            'Content-Type: application/json'
        );

        if (empty($apiKey)) {
            $username = $this->_helper()->getConfigDataWsUserName($storeId);
            $password = $this->_helper()->getConfigDataWsPassword($storeId);
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_USERPWD, $username . ":" . $password);
        } else {
            $headers[] = 'x-api-key: ' . $apiKey;
        }

        if (!empty($timeout)) {
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        }

        Mage::log($request, null, 'adyen_api.log');

        curl_setopt($ch, CURLOPT_URL, $requestUrl);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($request));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $result = curl_exec($ch);
        $error = curl_error($ch);
        $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errorCode = curl_errno($ch);
        curl_close($ch);
        if ($result === false) {
            Adyen_Payment_Exception::throwCurlException($error, $errorCode);
        }

        if ($httpStatus == 401 || $httpStatus == 403) {
            Adyen_Payment_Exception::throwException(
                Mage::helper('adyen')->__(
                    'Received Status code %s, please make sure your Checkout API key is correct.',
                    $httpStatus
                )
            );
        } elseif ($httpStatus != 200) {
            Adyen_Payment_Exception::throwException(
                Mage::helper('adyen')->__(
                    'HTTP Status code %s received, data %s',
                    $httpStatus, $result
                )
            );
        }

        Mage::log($result, null, 'adyen_api.log');

        return $result;
    }

    /**
     * Set the timeout and do a sync request to the Terminal API endpoint
     *
     * @param array $request
     * @param int $storeId
     * @return mixed
     */
    public function doRequestSync(array $request, $storeId)
    {
        $requestUrl = self::ENDPOINT_TERMINAL_CLOUD_LIVE;
        if ($this->_helper()->getConfigDataDemoMode($storeId)) {
            $requestUrl = self::ENDPOINT_TERMINAL_CLOUD_TEST;
        }

        $apiKey = $this->_helper()->getPosApiKey($storeId);
        $timeout = $this->_helper()->getConfigData('timeout', 'adyen_pos_cloud', $storeId);
        $response = $this->doRequestJson($request, $requestUrl, $apiKey, $storeId, $timeout);
        return json_decode($response, true);
    }

    /**
     * Do a synchronous request to retrieve the connected terminals
     *
     * @param $storeId
     * @return mixed
     */
    public function retrieveConnectedTerminals($storeId)
    {
        $requestUrl = self::ENDPOINT_CONNECTED_TERMINALS_LIVE;
        if ($this->_helper()->getConfigDataDemoMode($storeId)) {
            $requestUrl = self::ENDPOINT_CONNECTED_TERMINALS_TEST;
        }

        $apiKey = $this->_helper()->getPosApiKey($storeId);
        $merchantAccount = $this->_helper()->getAdyenMerchantAccount("pos_cloud", $storeId);
        $request = array("merchantAccount" => $merchantAccount);

        //If store_code is configured, retrieve only terminals connected to that store
        $storeCode = $this->_helper()->getConfigData('store_code', 'adyen_pos_cloud', $storeId);
        if ($storeCode) {
            $request["store"] = $storeCode;
        }
        $response = $this->doRequestJson($request, $requestUrl, $apiKey, $storeId);
        return $response;
    }

    /**
     * Set ApplicationInfo on /payments request
     *
     * @param $request
     * @return mixed
     */
    public function setApplicationInfo($request)
    {
        $request['applicationInfo']['externalPlatform']['version'] = Mage::getVersion();
        $request['applicationInfo']['externalPlatform']['name'] = "Magento";
        $request['applicationInfo']['adyenPaymentSource']['version'] = Mage::helper('adyen')->getExtensionVersion();
        $request['applicationInfo']['adyenPaymentSource']['name'] = "adyen-magento";
        return $request;
    }
}
