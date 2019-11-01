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
 * @copyright  Copyright (c) 2014 Adyen BV (http://www.adyen.com)
 */
abstract class Adyen_Payment_Model_Adyen_Abstract extends Mage_Payment_Model_Method_Abstract
{

    /**
     * Zend_Log debug level
     * @var unknown_type
     */
    const DEBUG_LEVEL = 7;

    const VISIBLE_INTERNAL = 'backend';
    const VISIBLE_CHECKOUT = 'frontend';
    const VISIBLE_BOTH = 'both';

    protected $_isGateway = false;
    protected $_canAuthorize = true;
    protected $_canCapture = true;
    protected $_canCapturePartial = true;
    protected $_canRefund = true;
    protected $_canVoid = true;
    protected $_canUseInternal = true;
    protected $_canUseCheckout = true;
    protected $_canUseForMultishipping = false;
    protected $_canRefundInvoicePartial = true;

    /** @var Adyen_Payment_Helper_Pci */
    protected $_pciHelper;

    /**
     * Magento Order Object
     * @var unknown_type
     */
    protected $_order;

    /**
     * Module identifiers
     */
    protected $_code = 'adyen_abstract';
    protected $_paymentMethod = 'abstract';

    /**
     * Internal objects and arrays for SOAP communication
     */
    protected $_service = null;
    protected $_accountData = null;

    /**
     * Payment Modification Request
     * @var unknown_type
     */
    protected $_optionalData = null;
    protected $_testModificationUrl = 'https://pal-test.adyen.com/pal/adapter/httppost';
    protected $_liveModificationUrl = 'https://pal-live.adyen.com/pal/adapter/httppost';
    protected $_paymentMethodType = 'api';

    public function getPaymentMethodType()
    {
        return $this->_paymentMethodType;
    }

    public function __construct()
    {
        $visibleType = $this->getConfigData('visible_type');
        switch ($visibleType) {
            case self::VISIBLE_INTERNAL:
                $this->_canUseCheckout = false;
                $this->_canUseInternal = true;
                break;

            case self::VISIBLE_CHECKOUT:
                $this->_canUseCheckout = true;
                $this->_canUseInternal = false;
                break;

            case self::VISIBLE_BOTH:
                $this->_canUseCheckout = true;
                $this->_canUseInternal = true;
                break;
        }
    }


    /**
     * @param Varien_Object $payment
     * @param float $amount
     * @return $this
     */
    public function refund(Varien_Object $payment, $amount)
    {
        $this->writeLog('refund fx called');

        $order = $payment->getOrder();
        $pspReference = Mage::getModel('adyen/event')->getOriginalPspReference($order->getIncrementId());

        // if amount is a full refund send a refund/cancelled request so if it is not captured yet it will cancel the order
        $grandTotal = $order->getGrandTotal();
        $currency = $order->getOrderCurrencyCode();

        if ($payment->hasCreditmemo() && $currency != $order->getBaseCurrencyCode()) {
            $creditmemo = $payment->getCreditmemo();
            $amount = $creditmemo->getGrandTotal();
        }


        // check if payment was a split payment
        $orderPaymentCollection = Mage::getModel('adyen/order_payment')
            ->getCollection()
            ->addFieldToFilter('payment_id', $payment->getId());

        if ($grandTotal == $amount) {
            // Refund in ascending order
            $orderPaymentCollection->addPaymentFilterAscending($payment->getId());


            // full refund
            if ($orderPaymentCollection->getSize()) {
                // loop over payment methods and refund them all
                foreach ($orderPaymentCollection as $splitPayment) {
                    $order->getPayment()->getMethodInstance()->SendCancelOrRefund(
                        $payment,
                        $splitPayment->getPspreference()
                    );
                }
            } else {
                $order->getPayment()->getMethodInstance()->SendCancelOrRefund($payment, $pspReference);
            }
        } else {
            // partial refund if multiple payments check refund strategy
            if ($orderPaymentCollection->getSize() > 1) {
                // loop over payments and refund based on refund strategy
                $refundStrategy = $this->_getConfigData(
                    'split_payments_refund_strategy', 'adyen_abstract',
                    $order->getStoreId()
                );
                $ratio = null;

                if ($refundStrategy == "1") {
                    // Refund in ascending order
                    $orderPaymentCollection->addPaymentFilterAscending($payment->getId());
                } elseif ($refundStrategy == "2") {
                    // Refund in descending order
                    $orderPaymentCollection->addPaymentFilterDescending($payment->getId());
                } elseif ($refundStrategy == "3") {
                    // refund based on ratio
                    $ratio = $amount / $grandTotal;
                    $orderPaymentCollection->addPaymentFilterAscending($payment->getId());
                }

                // loop over payment methods and refund them all
                foreach ($orderPaymentCollection as $splitPayment) {
                    // could be that not all the split payments need a refund
                    if ($amount > 0) {
                        if ($ratio) {
                            // refund based on ratio calculate refund amount
                            $amount = $ratio * ($splitPayment->getAmount() - $splitPayment->getTotalRefunded());
                            $order->getPayment()->getMethodInstance()->sendRefundRequest(
                                $payment, $amount,
                                $splitPayment->getPspreference()
                            );
                        } else {
                            // total authorised amount of the split payment
                            $splitPaymentAmount = $splitPayment->getAmount() - $splitPayment->getTotalRefunded();

                            // if rest amount is zero go to next payment
                            if (!$splitPaymentAmount > 0) {
                                continue;
                            }

                            // if refunded amount is greather then split payment amount do a full refund
                            if ($amount >= $splitPaymentAmount) {
                                $order->getPayment()->getMethodInstance()->sendRefundRequest(
                                    $payment,
                                    $splitPaymentAmount, $splitPayment->getPspreference()
                                );
                            } else {
                                $order->getPayment()->getMethodInstance()->sendRefundRequest(
                                    $payment, $amount,
                                    $splitPayment->getPspreference()
                                );
                            }

                            // update amount with rest of the available amount
                            $amount = $amount - $splitPaymentAmount;
                        }
                    }
                }
            } else {
                $order->getPayment()->getMethodInstance()->sendRefundRequest($payment, $amount, $pspReference);
            }
        }

        return $this;
    }

    /**
     * In the backend it means Authorize only
     * @param Varien_Object $payment
     * @param               $amount
     * @return $this
     */
    public function authorize(Varien_Object $payment, $amount)
    {
        parent::authorize($payment, $amount);
        $payment->setLastTransId($this->getTransactionId())->setIsTransactionPending(true);

        /** @var Mage_Sales_Model_Order $order */
        $order = $payment->getOrder();
        $amount = $order->getGrandTotal();

        // check if a zero auth should be done for this order
        $useZeroAuth = (bool)Mage::helper('adyen')->getConfigData('use_zero_auth', null, $order->getStoreId());
        $zeroAuthDateField = Mage::helper('adyen')->getConfigData(
            'base_zero_auth_on_date', null,
            $order->getStoreId()
        );

        if ($useZeroAuth) { // zero auth should be used
            // only orders that are scheduled to be captured later than
            // the auth valid period use zero auth
            // the period is 7 days since this works for most payment methods
            $scheduledDate = strtotime($order->getData($zeroAuthDateField));
            if ($scheduledDate > strtotime("+7 days")) { // scheduled date is higher than now + 7 days
                $amount = 0; // set amount to 0 for zero auth
            }
        }

        /*
         * ReserveOrderId for this quote so payment failed notification
         * does not interfere with new successful orders
         */
        $incrementId = $order->getIncrementId();
        $quoteId = $order->getQuoteId();
        $quote = Mage::getModel('sales/quote')
            ->load($quoteId)
            ->setReservedOrderId($incrementId)
            ->save();

        // by zero authentication payment is authorised when api responds is succesfull
        if ($order->getGrandTotal() == 0) {
            $payment->setIsTransactionPending(false);
        }

        /*
         * Do not send a email notification when order is created.
         * Only do this on the AUHTORISATION notification.
         * For Boleto and Multibanco send it on order creation
         */
        if (!in_array($this->getCode(), array('adyen_boleto', 'adyen_multibanco'))) {
            $order->setCanSendNewEmailFlag(false);
        }

        if ($this->getCode() === 'adyen_cc' ||
            $this->getCode() === 'adyen_boleto' ||
            $this->getCode() === 'adyen_multibanco' ||
            $this->getCode() === 'adyen_sepa' ||
            $this->getCode() === 'adyen_apple_pay'
        ) {
            $result = $this->_api()->authorisePayment($payment, $amount, $this->_paymentMethod);
            $this->processJsonResponse($payment, $result);
        } elseif (substr($this->getCode(), 0, 14) === 'adyen_oneclick') {
            // set payment method to adyen_oneclick otherwise backend can not view the order
            $payment->setMethod("adyen_oneclick");

            $recurringDetailReference = $payment->getAdditionalInformation("recurring_detail_reference");

            // load agreement based on reference_id (option to add an index on reference_id in database)
            $agreement = Mage::getModel('sales/billing_agreement')->load($recurringDetailReference, 'reference_id');

            // agreement could be a empty object
            if ($agreement && $agreement->getAgreementId() > 0 && $agreement->isValid()) {
                $agreement->addOrderRelation($order);
                $agreement->setIsObjectChanged(true);
                $order->addRelatedObject($agreement);
                $message = Mage::helper('adyen')->__(
                    'Used existing billing agreement #%s.',
                    $agreement->getReferenceId()
                );

                $comment = $order->addStatusHistoryComment($message);
                $order->addRelatedObject($comment);
            }

            $result = $this->_api()->authorisePayment($payment, $amount, $this->_paymentMethod);
            $this->processJsonResponse($payment, $result);
        }

        return $this;
    }

    /**
     * In backend it means Authorize && Capture
     * @param $payment
     * @param $amount
     */
    public function capture(Varien_Object $payment, $amount)
    {
        parent::capture($payment, $amount);
        $payment->setStatus(self::STATUS_APPROVED)
            ->setTransactionId($this->getTransactionId())
            ->setIsTransactionClosed(0);
        $order = $payment->getOrder();
        $currency = $order->getOrderCurrencyCode();

        if ($payment->hasCurrentInvoice() && $currency != $order->getBaseCurrencyCode()) {
            $invoice = $payment->getCurrentInvoice();
            $amount = $invoice->getGrandTotal();
        }

        // do capture request to adyen
        $order = $payment->getOrder();
        $pspReference = Mage::getModel('adyen/event')->getOriginalPspReference($order->getIncrementId());
        $order->getPayment()->getMethodInstance()->sendCaptureRequest($payment, $amount, $pspReference);

        return $this;
    }

    public function authorise3d(Varien_Object $payment, $amount)
    {
        $response = $this->_api()->authorise3DPayment($payment);
        $responseCode = $response['resultCode'];
        return $responseCode;
    }

    public function sendCaptureRequest(Varien_Object $payment, $amount, $pspReference)
    {
        if (empty($pspReference)) {
            $this->writeLog('Missing pspReference');
            return $this;
        }

        $this->writeLog("sendCaptureRequest pspReference : $pspReference amount: $amount");
        return $this->_processRequest($payment, $amount, "capture", $pspReference);
    }

    public function sendRefundRequest(Varien_Object $payment, $amount, $pspReference)
    {
        if (empty($pspReference)) {
            $this->writeLog('Missing pspReference');
            return $this;
        }

        $this->writeLog("sendRefundRequest pspReference : $pspReference amount: $amount");
        return $this->_processRequest($payment, $amount, "refund", $pspReference);
    }

    public function SendCancelOrRefund(Varien_Object $payment, $pspReference)
    {
        if (empty($pspReference)) {
            $this->writeLog('Missing pspReference');
            return $this;
        }

        $this->writeLog("sendCancelOrRefundRequest pspReference : $pspReference");
        return $this->_processRequest($payment, null, "cancel_or_refund", $pspReference);
    }

    public function sendCancelRequest(Varien_Object $payment, $pspReference)
    {
        if (empty($pspReference)) {
            $this->writeLog('Missing pspReference');
            return $this;
        }

        $this->writeLog("sendCancelRequest pspReference : $pspReference");
        return $this->_processRequest($payment, null, "cancel", $pspReference);
    }

    /**
     * Process the request here
     * @param Varien_Object $payment
     * @param unknown_type $amount
     * @param unknown_type $request
     * @param unknown_type $responseData
     */
    protected function _processRequest(Varien_Object $payment, $amount, $request, $pspReference = null)
    {

        if (Mage::app()->getStore()->isAdmin()) {
            $storeId = $payment->getOrder()->getStoreId();
        } else {
            $storeId = null;
        }

        // retrieve configuration
        $merchantAccount = Mage::helper('adyen')->getAdyenMerchantAccount($this->_paymentMethod, $storeId);
        $recurringType = $this->_getConfigData('recurringtypes', 'adyen_abstract', $storeId);

        // initiate soap client
        $this->_initService($storeId);
        $modificationResult = Mage::getModel('adyen/adyen_data_modificationResult');
        $requestData = Mage::getModel('adyen/adyen_data_modificationRequest')
            ->create($payment, $amount, $merchantAccount, $pspReference);

        try {
            switch ($request) {
                case "capture":
                    $response = $this->_service->capture(
                        array(
                            'modificationRequest' => $requestData,
                            'modificationResult' => $modificationResult
                        )
                    );
                    break;
                case "refund":
                    $response = $this->_service->refund(
                        array(
                            'modificationRequest' => $requestData,
                            'modificationResult' => $modificationResult
                        )
                    );
                    break;
                case "cancel_or_refund":
                    $response = $this->_service->cancelorrefund(
                        array(
                            'modificationRequest' => $requestData,
                            'modificationResult' => $modificationResult
                        )
                    );
                    break;
                case "cancel":
                    $response = $this->_service->cancel(
                        array(
                            'modificationRequest' => $requestData,
                            'modificationResult' => $modificationResult
                        )
                    );
                    break;
            }
        } catch (SoapFault $e) {
            // log the request
            $this->_debugAdyen();
            Mage::log($this->_pci()->obscureSensitiveData($requestData), self::DEBUG_LEVEL, "$request.log", true);


            if (isset($response)) {
                Mage::getResourceModel('adyen/adyen_debug')->assignData($response);
                $this->_debugAdyen();
            }

            throw $e;
        }


        // log the request
        Mage::getResourceModel('adyen/adyen_debug')->assignData($response);
        $this->_debugAdyen();
        Mage::log($this->_pci()->obscureSensitiveData($requestData), self::DEBUG_LEVEL, "$request.log", true);


        if (!empty($response)) {
            // log the result
            Mage::log("Response from Adyen:", self::DEBUG_LEVEL, "$request.log", true);
            Mage::log($this->_pci()->obscureSensitiveData($response), self::DEBUG_LEVEL, "$request.log", true);

            $this->_processResponse($payment, $response, $request, $pspReference);
        }

        /*
         * clear the cache for recurring payments so new card will be added
         */
        $cacheKey = $merchantAccount . "|" . $payment->getOrder()->getCustomerId() . "|" . $recurringType;
        Mage::app()->getCache()->remove($cacheKey);

        return $response;
    }

    /**
     * @param Varien_Object $payment
     * @param $response
     * @param null $request
     * @param null $pspReference
     * @return $this|bool
     * @throws Adyen_Payment_Exception
     */
    protected function _processResponse(
        Varien_Object $payment,
        $response,
        $request = null,
        $originalPspReference = null
    ) {
        if (!($response instanceof stdClass)) {
            return false;
        }

        $pspReference = null;

        switch ($request) {
            case 'refund':
                $responseCode = $response->refundResult->response;
                $pspReference = $response->refundResult->pspReference;
                break;
            case 'cancel_or_refund':
                $responseCode = $response->cancelOrRefundResult->response;
                $pspReference = $response->cancelOrRefundResult->pspReference;
                break;
            case 'cancel':
                $responseCode = $response->cancelResult->response;
                $pspReference = $response->cancelResult->pspReference;
                break;
            case 'capture':
                $responseCode = $response->captureResult->response;
                $pspReference = $response->captureResult->pspReference;
                break;
            default:
                $responseCode = null;
                $this->writeLog("Unknown data type by Adyen");
                break;
        }

        switch ($responseCode) {
            case 'Cancelled':
            case 'Refused':
                $errorMsg = new Varien_Object(
                    array(
                        'error_message' => Mage::helper('adyen')->__('The payment is REFUSED.')
                    )
                );
                Mage::dispatchEvent(
                    'adyen_payment_authorize_refused_error',
                    array('responseResult' => $response->paymentResult, 'error' => $errorMsg)
                );
                $errorMsg = $errorMsg->getErrorMessage();

                $this->resetReservedOrderId();
                Adyen_Payment_Exception::throwException($errorMsg);
                break;
            case 'Authorised':
                $this->_addStatusHistory($payment, $responseCode, $pspReference, $this->_getConfigData('order_status'));
                break;
            case '[capture-received]':
            case '[refund-received]':
            case '[cancel-received]':
            case '[cancelOrRefund-received]':
                $this->_addStatusHistory($payment, $responseCode, $pspReference, false, null, $originalPspReference);
                break;
            case 'Error':
                $this->resetReservedOrderId();
                $errorMsg = Mage::helper('adyen')->__('System error, please try again later');
                Adyen_Payment_Exception::throwException($errorMsg);
                break;
            default:
                $this->resetReservedOrderId();
                $errorMsg = Mage::helper('adyen')->__('Unknown data type by Adyen');
                Adyen_Payment_Exception::throwException($errorMsg);
                break;
        }

        //save all response data for a pure duplicate detection
        Mage::getModel('adyen/event')
            ->setPspReference($pspReference)
            ->setAdyenEventCode($responseCode)
            ->setAdyenEventResult($responseCode)
            ->setIncrementId($payment->getOrder()->getIncrementId())
            ->setPaymentMethod($this->getInfoInstance()->getCcType())
            ->setCreatedAt(now())
            ->saveData();
        return $this;
    }


    protected function processJsonResponse(
        Varien_Object $payment,
        $response,
        $request = null,
        $originalPspReference = null
    ) {

        if (!empty($response['fraudResult']['accountScore'])) {
            $fraudResult = $response['fraudResult']['accountScore'];
            $payment->setAdyenTotalFraudScore($fraudResult);
        }

        $responseCode = $response['resultCode'];
        $pspReference = !empty($response['pspReference']) ? $response['pspReference'] : null;

        // save pspreference to match with notification
        $payment->setAdyenPspReference($pspReference);

        switch ($responseCode) {
            case 'RedirectShopper':
                $paRequest = $response['redirect']['data']['PaReq'];
                $md = $response['redirect']['data']['MD'];
                $issuerUrl = $response['redirect']['url'];
                $paymentData = $response['paymentData'];

                if (!empty($paRequest) && !empty($md) && !empty($issuerUrl) && !empty($paymentData)) {
                    $payment->setAdditionalInformation('paRequest', $paRequest);
                    $payment->setAdditionalInformation('md', $md);
                    $payment->setAdditionalInformation('issuerUrl', $issuerUrl);
                    $payment->setAdditionalInformation('paymentData', $paymentData);
                } else {
                    // log exception
                    $errorMsg = Mage::helper('adyen')->__('3D secure is not valid');
                    Adyen_Payment_Exception::throwException($errorMsg);
                }

                Mage::getSingleton('customer/session')->setRedirectUrl("adyen/process/validate3d");
                $this->_addStatusHistory($payment, $responseCode, $pspReference, $this->_getConfigData('order_status'));
                break;
            case 'Cancelled':
            case 'Refused':
                $errorMsg = new Varien_Object(array('error_message' => Mage::helper('adyen')->__('The payment is REFUSED.')));
                Mage::dispatchEvent(
                    'adyen_payment_authorize_refused_error',
                    array('responseResult' => $response->paymentResult, 'error' => $errorMsg)
                );
                $errorMsg = $errorMsg->getErrorMessage();

                $this->resetReservedOrderId();
                Adyen_Payment_Exception::throwException($errorMsg);
                break;
            case 'Authorised':
                $this->_addStatusHistory($payment, $responseCode, $pspReference, $this->_getConfigData('order_status'));
                break;
            case 'Received':
            case 'PresentToShopper': // boleto payment
                $pdfUrl = null;

                if (!empty($response['outputDetails']['boletobancario.url'])) {
                    $pdfUrl = $response['outputDetails']['boletobancario.url'];
                }

                foreach ($response['additionalData'] as $key => $value) {

                    // store all multibanco details
                    if (preg_match('/comprafacil/', $key)) {
                        $payment->setAdditionalInformation($key, $value);
                    }

                    // calculate the deadline date for payment for multibanco
                    if ($key == 'comprafacil.deadline') {
                        /** @var Mage_Sales_Model_Order $salesOrder */
                        $salesOrder = $payment->getOrder();

                        $deadlineDate = 'comprafacil.deadline_date';

                        if ($value > 0) {
                            $zendDate = new Zend_Date($salesOrder->getCreatedAtStoreDate());

                            $zendDate->addDay($value);

                            $payment->setAdditionalInformation(
                                $deadlineDate,
                                Mage::helper('core')->formatDate($zendDate)
                            );
                        } else {
                            $payment->setAdditionalInformation(
                                $deadlineDate,
                                Mage::helper('core')->formatDate($salesOrder->getCreatedAtStoreDate())
                            );
                        }
                    }
                }

                $this->_addStatusHistory($payment, $responseCode, $pspReference, false, $pdfUrl);
                break;
            case 'IdentifyShopper':
                if (!empty($response['resultCode']) && !empty($response['authentication']['threeds2.fingerprintToken']) && !empty($response['paymentData'])){
                    $payment->setAdditionalInformation('threeDS2Type', $response['resultCode']);
                    $payment->setAdditionalInformation('threeDS2Token',
                        $response['authentication']['threeds2.fingerprintToken']);
                    $payment->setAdditionalInformation('threeDS2PaymentData', $response['paymentData']);
                }
                Mage::getSingleton('customer/session')->setRedirectUrl("adyen/process/validate3ds2");
                break;
            case 'ChallengeShopper':
                if (!empty($response['resultCode']) && !empty($response['authentication']['threeds2.challengeToken']) && !empty($response['paymentData'])){
                    $payment->setAdditionalInformation('threeDS2Type', $response['resultCode']);
                    $payment->setAdditionalInformation('threeDS2Token',
                        $response['authentication']['threeds2.challengeToken']);
                    $payment->setAdditionalInformation('threeDS2PaymentData', $response['paymentData']);
                }
                Mage::getSingleton('customer/session')->setRedirectUrl("adyen/process/validate3ds2");
                  break;
            case "Error":
                $this->resetReservedOrderId();
                $errorMsg = Mage::helper('adyen')->__('System error, please try again later');
                Adyen_Payment_Exception::throwException($errorMsg);
                break;
            default:
                $this->resetReservedOrderId();
                $errorMsg = Mage::helper('adyen')->__('Unknown data type by Adyen');
                Adyen_Payment_Exception::throwException($errorMsg);
                break;
        }


        //save all response data for a pure duplicate detection
        Mage::getModel('adyen/event')
            ->setPspReference($pspReference)
            ->setAdyenEventCode($responseCode)
            ->setAdyenEventResult($responseCode)
            ->setIncrementId($payment->getOrder()->getIncrementId())
            ->setPaymentMethod($this->getInfoInstance()->getCcType())
            ->setCreatedAt(now())
            ->saveData();

    }

    /**
     * @desc Reset the reservedOrderId so Adyen notification will not interfere with
     * the next payment
     */
    protected function resetReservedOrderId()
    {
        Mage::getSingleton('checkout/session')->getQuote()->setReservedOrderId(null);
    }

    /**
     * @since 0.0.3
     * @param Varien_Object $payment
     * @param unknown_type $request
     * @param unknown_type $pspReference
     */
    protected function _addStatusHistory(
        Varien_Object $payment,
        $responseCode,
        $pspReference,
        $status = false,
        $boletoPDF = null,
        $originalPspReference = null
    ) {

        if ($boletoPDF) {
            $payment->getOrder()->setAdyenBoletoPdf($boletoPDF);
        }

        if ($originalPspReference) {
            $originalPspReferenceText = "originalPspReference: " . $originalPspReference;
        } else {
            $originalPspReferenceText = "";
        }

        $type = 'Adyen Result URL Notification(s):';
        $comment = Mage::helper('adyen')->__(
            '%s <br /> authResult: %s <br /> pspReference: %s <br /> %s', $type,
            $responseCode, $pspReference, $originalPspReferenceText
        );
        $payment->getOrder()->setAdyenEventCode($responseCode);
        $payment->getOrder()->addStatusHistoryComment($comment, $status);
        $payment->setAdyenEventCode($responseCode);
        return $this;
    }

    /**
     * Format price
     * @param unknown_type $amount
     * @param unknown_type $format
     */
    protected function _numberFormat($amount, $format = 2)
    {
        return (int)number_format($amount, $format, '', '');
    }

    /**
     * @desc Get SOAP client
     * @return Adyen_Payment_Model_Adyen_Abstract
     */
    protected function _initService($storeId = null)
    {
        $accountData = $this->getAccountData($storeId);
        $wsdl = $accountData['url']['wsdl'];
        $location = $accountData['url']['location'];
        $login = $accountData['login'];
        $password = $accountData['password'];
        $classmap = new Adyen_Payment_Model_Adyen_Data_Classmap();
        try {
            $this->_service = new SoapClient(
                $wsdl, array(
                    'login' => $login,
                    'password' => $password,
                    'soap_version' => SOAP_1_1,
                    'style' => SOAP_DOCUMENT,
                    'use' => SOAP_LITERAL,
                    'location' => $location,
                    'trace' => 1,
                    'classmap' => $classmap
                )
            );
        } catch (SoapFault $fault) {
            $this->writeLog("Adyen SOAP Fault: (faultcode: {$fault->faultcode}, faultstring: {$fault->faultstring})");
            Mage::throwException(Mage::helper('adyen')->__('Can not connect payment service. Please try again later.'));
        }

        return $this;
    }

    /**
     * @desc soap urls
     * @return string
     */
    protected function _getAdyenUrls($storeId = null)
    {
        $test = array(
            'location' => "https://pal-test.adyen.com/pal/servlet/soap/Payment",
            'wsdl' => Mage::getModuleDir('etc', 'Adyen_Payment') . DS . 'Payment.wsdl'
        );
        $live = array(
            'location' => "https://pal-live.adyen.com/pal/servlet/soap/Payment",
            'wsdl' => Mage::getModuleDir('etc', 'Adyen_Payment') . DS . 'Payment.wsdl'
        );
        if ($this->getConfigDataDemoMode($storeId)) {
            return $test;
        } else {
            return $live;
        }
    }

    /**
     * @desc Testing purposes only
     */
    protected function _debugAdyen()
    {
        $this->writeLog("Request Headers: ");
        $this->writeLog($this->_pci()->obscureSensitiveData($this->_service->__getLastRequestHeaders()));
        $this->writeLog("Request:");
        $this->writeLog($this->_pci()->obscureSensitiveData(($this->_service->__getLastRequest())));
        $this->writeLog("Response Headers");
        $this->writeLog($this->_pci()->obscureSensitiveData(($this->_service->__getLastResponseHeaders())));
        $this->writeLog("Response");
        $this->writeLog($this->_pci()->obscureSensitiveData(($this->_service->__getLastResponse())));
    }

    /**
     * @return Adyen_Payment_Helper_Pci
     */
    protected function _pci()
    {
        if (!isset($this->_pciHelper)) {
            $this->_pciHelper = Mage::helper('adyen/pci');
        }

        return $this->_pciHelper;
    }

    /**
     * Adyen User Account Data
     */
    public function getAccountData($storeId = null)
    {
        $url = $this->_getAdyenUrls($storeId);
        $wsUsername = $this->getConfigDataWsUserName($storeId);
        $wsPassword = $this->getConfigDataWsPassword($storeId);
        $account = array(
            'url' => $url,
            'login' => $wsUsername,
            'password' => $wsPassword
        );
        return $account;
    }

    /**
     * @desc Adyen log fx
     * @param type $str
     * @return type
     */
    public function writeLog($str)
    {
        Mage::log($this->_pci()->obscureSensitiveData($str), Zend_Log::DEBUG, "adyen_notification.log", true);
        return false;
    }

    /**
     * @status poor programming practises modification_result model not exist!
     * @param unknown_type $responseBody
     */
    public function getModificationResult($responseBody)
    {
        $result = new Varien_Object();
        $valArray = explode('&', $responseBody);
        foreach ($valArray as $val) {
            $valArray2 = explode('=', $val);
            $result->setData($valArray2[0], urldecode($valArray2[1]));
        }

        return $result;
    }

    public function getModificationUrl()
    {
        if ($this->getConfigDataDemoMode()) {
            return $this->_testModificationUrl;
        }

        return $this->_liveModificationUrl;
    }

    public function getConfigDataAutoCapture()
    {
        if (!$this->_getConfigData('auto_capture') || $this->_getConfigData('auto_capture') == 0) {
            return false;
        }

        return true;
    }

    public function getConfigDataAutoInvoice()
    {
        if (!$this->_getConfigData('auto_invoice') || $this->_getConfigData('auto_invoice') == 0) {
            return false;
        }

        return true;
    }

    public function getConfigDataAdyenCapture()
    {
        if ($this->_getConfigData('adyen_capture') && $this->_getConfigData('adyen_capture') == 1) {
            return true;
        }

        return false;
    }

    public function getConfigDataAdyenRefund()
    {
        if ($this->_getConfigData('adyen_refund') == 1) {
            return true;
        }

        return false;
    }

    /**
     * Return true if the method can be used at this time
     * @since 0.1.0.3r1
     * @return bool
     */
    public function isAvailable($quote = null)
    {
        if (!parent::isAvailable($quote)) {
            return false;
        }

        if (!is_null($quote)) {
            if ($this->_getConfigData('allowspecific', $this->_code)) {
                $country = $quote->getShippingAddress()->getCountry();
                $availableCountries = explode(',', $this->_getConfigData('specificcountry', $this->_code));
                if (!in_array($country, $availableCountries)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @desc Give Default settings
     * @example $this->_getConfigData('demoMode','adyen_abstract')
     * @since 0.0.2
     * @param string $code
     */
    protected function _getConfigData($code, $paymentMethodCode = null, $storeId = null)
    {
        return Mage::helper('adyen')->_getConfigData($code, $paymentMethodCode, $storeId);
    }

    /**
     * Used via Payment method.Notice via configuration ofcourse Y or N
     * @return boolean true on demo, else false
     */
    public function getConfigDataDemoMode($storeId = null)
    {
        if ($storeId == null && Mage::app()->getStore()->isAdmin()) {
            $storeId = $this->getInfoInstance()->getOrder()->getStoreId();
        }

        return Mage::helper('adyen')->getConfigDataDemoMode($storeId);
    }

    public function getConfigDataWsUserName($storeId = null)
    {
        return Mage::helper('adyen')->getConfigDataWsUserName($storeId);
    }

    public function getConfigDataWsPassword($storeId)
    {
        return Mage::helper('adyen')->getConfigDataWsPassword($storeId);
    }

    public function getAvailableBoletoTypes()
    {
        $types = Mage::helper('adyen')->getBoletoTypes();
        $availableTypes = $this->_getConfigData('boletotypes', 'adyen_boleto');
        if ($availableTypes) {
            $availableTypes = explode(',', $availableTypes);
            foreach ($types as $code => $name) {
                if (!in_array($code, $availableTypes)) {
                    unset($types[$code]);
                }
            }
        }

        return $types;
    }

    public function getConfigPaymentAction()
    {
        return Mage_Payment_Model_Method_Abstract::ACTION_AUTHORIZE;
    }

    protected function _initOrder()
    {
        if (!$this->_order) {
            $paymentInfo = $this->getInfoInstance();
            $this->_order = $paymentInfo->getOrder();
        }

        return $this;
    }

    public function canCreateBillingAgreement()
    {
        if (!$this->_canCreateBillingAgreement) {
            return false;
        }

        $recurringType = $this->_getConfigData('recurringtypes');
        if ($recurringType == "ONECLICK" || $recurringType == "ONECLICK,RECURRING") {
            return true;
        }

        return false;
    }


    public function getBillingAgreementCollection()
    {
        $customerId = $this->getInfoInstance()->getQuote()->getCustomerId();
        return Mage::getModel('adyen/billing_agreement')
            ->getAvailableCustomerBillingAgreements($customerId)
            ->addFieldToFilter('method_code', $this->getCode());
    }


    /**
     * @return Adyen_Payment_Model_Api
     */
    protected function _api()
    {
        return Mage::getSingleton('adyen/api');
    }

    /**
     * Create billing agreement by token specified in request
     *
     * @param Mage_Payment_Model_Billing_AgreementAbstract $agreement
     * @return Exception
     */
    public function placeBillingAgreement(Mage_Payment_Model_Billing_AgreementAbstract $agreement)
    {
        Mage::throwException('Not yet implemented.');
        return $this;
    }


    /**
     * Init billing agreement
     *
     * @param Mage_Payment_Model_Billing_AgreementAbstract $agreement
     * @return Exception
     */
    public function initBillingAgreementToken(Mage_Payment_Model_Billing_AgreementAbstract $agreement)
    {
        Mage::throwException('Not yet implemented.');
        return $this;
    }

    /**
     * Update billing agreement status
     *
     * @param Adyen_Payment_Model_Billing_Agreement|Mage_Payment_Model_Billing_AgreementAbstract $agreement
     *
     * @return $this
     * @throws Exception
     * @throws Mage_Core_Exception
     */
    public function updateBillingAgreementStatus(Mage_Payment_Model_Billing_AgreementAbstract $agreement)
    {
        Mage::dispatchEvent('adyen_payment_update_billing_agreement_status', array('agreement' => $agreement));

        $targetStatus = $agreement->getStatus();
        $adyenHelper = Mage::helper('adyen');

        if ($targetStatus == Mage_Sales_Model_Billing_Agreement::STATUS_CANCELED) {
            try {
                $this->_api()->disableRecurringContract(
                    $agreement->getReferenceId(),
                    $agreement->getCustomerReference(),
                    $agreement->getStoreId()
                );
            } catch (Adyen_Payment_Exception $e) {
                Mage::throwException(
                    $adyenHelper->__(
                        "Error while disabling Billing Agreement #%s: %s", $agreement->getReferenceId(),
                        $e->getMessage()
                    )
                );
            }
        } else {
            throw new Exception(
                Mage::helper('adyen')->__(
                    'Changing billing agreement status to "%s" not yet implemented.', $targetStatus
                )
            );
        }

        return $this;
    }


    /**
     * Retrieve billing agreement customer details by token
     *
     * @param Adyen_Payment_Model_Billing_Agreement|Mage_Payment_Model_Billing_AgreementAbstract $agreement
     * @return array
     */
    public function getBillingAgreementTokenInfo(Mage_Payment_Model_Billing_AgreementAbstract $agreement)
    {
        $recurringContractDetail = $this->_api()->getRecurringContractDetail(
            $agreement->getCustomerReference(),
            $agreement->getReferenceId()
        );

        if (!$recurringContractDetail) {
            Adyen_Payment_Exception::throwException(
                Mage::helper('adyen')->__(
                    'The recurring contract (%s) could not be retrieved', $agreement->getReferenceId()
                )
            );
        }

        $agreement->parseRecurringContractData($recurringContractDetail);

        return $recurringContractDetail;
    }

    public function originKeys()
    {
        // Gets the current store's id
        $storeId = Mage::app()->getStore()->getStoreId();

        return $this->_api()->originKeys($storeId);
    }
}
