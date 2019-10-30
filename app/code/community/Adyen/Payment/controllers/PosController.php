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
class Adyen_Payment_PosController extends Mage_Core_Controller_Front_Action
{
    const PAYMENT_SUCCESSFUL = "Ok"; //Payment on the terminal was successful
    const PAYMENT_ERROR = "Stop"; //Payment on the terminal was refused, or error with the terminal
    const PAYMENT_RETRY = "Retry"; //Timeout on the terminal, poll on the status call to retrieve the status of the payment
    const ORDER_SUCCESS = "SUCCESS"; //Order placed successfully
    const ORDER_ERROR = "ERROR"; //Error in order placement

    /**
     * Initiate controller for POS Cloud.
     * Returns:
     * - PAYMENT_SUCCESSFUL on authorised payment on the Terminal
     * - PAYMENT_ERROR on refused payment on the Terminal
     * - PAYMENT_RETRY on timeout, the frontend will call @see checkStatusAction
     * @return string
     */
    public function initiateAction()
    {
        $api = Mage::getSingleton('adyen/api');
        $quote = (Mage::getModel('checkout/type_onepage') !== false) ? Mage::getModel('checkout/type_onepage')->getQuote() : Mage::getModel('checkout/session')->getQuote();
        $storeId = Mage::app()->getStore()->getId();
        $adyenHelper = Mage::helper('adyen');

        $params = $this->getRequest()->getParams();
        $poiId = $params['terminalId'];

        $serviceID = date("dHis");
        $initiateDate = date("U");
        $timeStamper = date("Y-m-d") . "T" . date("H:i:s+00:00");
        $customerId = $quote->getCustomerId();

        # Always create new order increment ID, assuring payment transaction is linked to one order only
        $quote->unsReservedOrderId();
        $reference = $quote->reserveOrderId()->getReservedOrderId();

        $request = array(
            'SaleToPOIRequest' => array(
                'MessageHeader' => array(
                    'MessageType' => 'Request',
                    'MessageClass' => 'Service',
                    'MessageCategory' => 'Payment',
                    'SaleID' => 'Magento1Cloud',
                    'POIID' => $poiId,
                    'ProtocolVersion' => '3.0',
                    'ServiceID' => $serviceID
                ),
                'PaymentRequest' => array(
                    'SaleData' => array(
                        'TokenRequestedType' => 'Customer',
                        'SaleTransactionID' => array(
                            'TransactionID' => $reference,
                            'TimeStamp' => $timeStamper
                        ),
                    ),
                    'PaymentTransaction' => array(
                        'AmountsReq' => array(
                            'Currency' => $quote->getBaseCurrencyCode(),
                            'RequestedAmount' => doubleval($quote->getGrandTotal())
                        ),
                    ),
                    'PaymentData' => array(
                        'PaymentType' => 'Normal'
                    ),
                ),
            ),
        );

        // If customer exists add it into the request to store request
        if (!empty($customerId)) {
            $shopperEmail = $quote->getCustomerEmail();
            $recurringContract = $adyenHelper->getConfigData('recurring_type', 'adyen_pos_cloud', $storeId);

            if (!empty($recurringContract) && !empty($shopperEmail) && !empty($customerId)) {
                $recurringDetails = array(
                    'shopperEmail' => $shopperEmail,
                    'shopperReference' => strval($customerId),
                    'recurringContract' => $recurringContract
                );
                $request['SaleToPOIRequest']['PaymentRequest']['SaleData']['SaleToAcquirerData'] = http_build_query($recurringDetails);
            }
        }

        $quote->getPayment()->setAdditionalInformation('serviceID', $serviceID);
        $quote->getPayment()->setAdditionalInformation('initiateDate', $initiateDate);

        $result = self::PAYMENT_ERROR;
        $errorCondition = "Payment Error";

        // Continue only if success or timeout
        try {
            $response = $api->doRequestSync($request, $storeId);
            if (!empty($response['SaleToPOIResponse']['PaymentResponse']) && $response['SaleToPOIResponse']['PaymentResponse']['Response']['Result'] == 'Success') {
                $result = self::PAYMENT_SUCCESSFUL;
            } elseif (!empty($response['SaleToPOIResponse']['PaymentResponse']) && $response['SaleToPOIResponse']['PaymentResponse']['Response']['Result'] == 'Failure') {
                $errorCondition = $response['SaleToPOIResponse']['PaymentResponse']['Response']['ErrorCondition'];
            }
        } catch (Adyen_Payment_Exception $e) {
            if ($e->getCode() == CURLE_OPERATION_TIMEOUTED) {
                $result = self::PAYMENT_RETRY;
            }
        }

        if (!empty($response['SaleToPOIResponse']['PaymentResponse']['PaymentReceipt'])) {
            $formattedReceipt = $adyenHelper->formatTerminalAPIReceipt($response['SaleToPOIResponse']['PaymentResponse']['PaymentReceipt']);
            $quote->getPayment()->setAdditionalInformation('receipt', $formattedReceipt);
        }

        $terminalResponse = "";
        if (!empty($response['SaleToPOIResponse']['PaymentResponse'])) {
            $terminalResponse = $response['SaleToPOIResponse']['PaymentResponse'];
        }

        $quote->getPayment()->setAdditionalInformation(
            'terminalResponse',
            $terminalResponse
        );

        try {
            $quote->save();
        } catch (Exception $e) {
            $result = self::PAYMENT_RETRY;
            $errorCondition = $e->getMessage();
            Mage::logException($e);
        }

        $resultArray = array(
            'result' => $result,
            'error' => $errorCondition
        );

        $this->getResponse()->setHeader('Content-type', 'application/json');
        $this->getResponse()->setBody(json_encode($resultArray));
        return $result;
    }

    /**
     * Checkstatus controller for POS Cloud.
     * Returns:
     * - PAYMENT_SUCCESSFUL on successful Status call, with authorised PaymentResponse
     * - PAYMENT_ERROR on successful Status call, with refused/cancelled PaymentResponse, or if two minutes are passed since the @see initiateAction call
     * - PAYMENT_RETRY on timeout, the frontend will poll on @see checkStatusAction
     * @return string
     */
    public function checkStatusAction()
    {
        $api = Mage::getSingleton('adyen/api');
        $quote = (Mage::getModel('checkout/type_onepage') !== false) ? Mage::getModel('checkout/type_onepage')->getQuote() : Mage::getModel('checkout/session')->getQuote();
        $storeId = Mage::app()->getStore()->getId();

        $params = $this->getRequest()->getParams();
        $poiId = $params['terminalId'];

        $adyenHelper = Mage::helper('adyen');
        $totalTimeout = $adyenHelper->getConfigData('total_timeout', 'adyen_pos_cloud', $storeId);

        $paymentResponse = $quote->getPayment()->getAdditionalInformation('terminalResponse');
        $serviceID = $quote->getPayment()->getAdditionalInformation('serviceID');
        $initiateDate = $quote->getPayment()->getAdditionalInformation('initiateDate');

        $newServiceID = date("dHis");

        $statusDate = date("U");
        $timeDiff = (int)$statusDate - (int)$initiateDate;


        $result = self::PAYMENT_ERROR;
        $errorCondition = "Payment Error";

        if ($timeDiff > $totalTimeout) {
            $errorCondition = "The Terminal timed out after " . $totalTimeout . " seconds.";
        } elseif (empty($paymentResponse)) {
            $request = array(
                'SaleToPOIRequest' => array(
                    'MessageHeader' => array(
                        'ProtocolVersion' => '3.0',
                        'MessageClass' => 'Service',
                        'MessageCategory' => 'TransactionStatus',
                        'MessageType' => 'Request',
                        'ServiceID' => $newServiceID,
                        'SaleID' => 'Magento1CloudStatus',
                        'POIID' => $poiId
                    ),
                    'TransactionStatusRequest' => array(
                        'MessageReference' => array(
                            'MessageCategory' => 'Payment',
                            'SaleID' => 'Magento1Cloud',
                            'ServiceID' => $serviceID
                        ),
                        'DocumentQualifier' => array(
                            "CashierReceipt",
                            "CustomerReceipt"
                        ),

                        'ReceiptReprintFlag' => true
                    )
                )
            );

            try {
                $response = $api->doRequestSync($request, $storeId);
                if (!empty($response['SaleToPOIResponse']['TransactionStatusResponse'])) {
                    $statusResponse = $response['SaleToPOIResponse']['TransactionStatusResponse'];
                    if ($statusResponse['Response']['Result'] == 'Failure') {
                        $result = self::PAYMENT_RETRY;
                    } else {
                        $paymentResponse = $statusResponse['RepeatedMessageResponse']['RepeatedResponseMessageBody']['PaymentResponse'];
                    }
                }
            } catch (Adyen_Payment_Exception $e) {
                if ($e->getCode() == CURLE_OPERATION_TIMEOUTED) {
                    $result = self::PAYMENT_RETRY;
                }
            }
        }

        //If we are in a final state, update the quote
        if (!empty($paymentResponse)) {
            if (!empty($paymentResponse['PaymentReceipt'])) {
                $formattedReceipt = $adyenHelper->formatTerminalAPIReceipt($paymentResponse['PaymentReceipt']);
                $quote->getPayment()->setAdditionalInformation('receipt', $formattedReceipt);
            }

            $quote->getPayment()->setAdditionalInformation('terminalResponse', $paymentResponse);
            $quote->save();
            if ($paymentResponse['Response']['Result'] == 'Success') {
                $result = self::PAYMENT_SUCCESSFUL;
            } elseif ($paymentResponse['Response']['Result'] == 'Failure') {
                $errorCondition = $paymentResponse['Response']['ErrorCondition'];
            }
        }

        $resultArray = array(
            'result' => $result,
            'error' => $errorCondition
        );
        $this->getResponse()->setHeader('Content-type', 'application/json');
        $this->getResponse()->setBody(json_encode($resultArray));
        return $result;
    }

    /**
     * Places the order after a PAYMENT_SUCCESSFUL from
     * @see initiateAction or
     * @see checkStatusAction
     * @return string
     */
    public function placeOrderAction()
    {
        //If checkout is onepage call savePayment/saveOrder, otherwise fallback to previous
        if (Mage::getModel('checkout/type_onepage') !== false) {

            $onePage = Mage::getSingleton('checkout/type_onepage');

            $quote = Mage::getModel('checkout/type_onepage')->getQuote();
            $quote->getPayment()->setMethod('adyen_pos_cloud');
            $quote->collectTotals()->save();

            try {
                $onePage->savePayment(array("method" => 'adyen_pos_cloud'));
                $onePage->saveOrder();
                $result = self::ORDER_SUCCESS;
                $onePage->getQuote()->save();
            } catch (Exception $e) {
                Mage::logException($e);
                $result = self::ORDER_ERROR;
            }

        } else {
            $quote = Mage::getModel('checkout/session')->getQuote();
            $quote->getPayment()->setMethod('adyen_pos_cloud');
            $quote->collectTotals()->save();

            try {
                $service = Mage::getModel('sales/service_quote', $quote);
                $service->submitAll();
                $order = $service->getOrder();
                $order->save();
                $result = self::ORDER_SUCCESS;

                // add order information to the session
                $session = Mage::getSingleton('checkout/session');
                $session->setLastOrderId($order->getId());
                $session->setLastRealOrderId($order->getIncrementId());
                $session->setLastSuccessQuoteId($order->getQuoteId());
                $session->setLastQuoteId($order->getQuoteId());
                $session->unsAdyenRealOrderId();
                $session->setQuoteId($session->getAdyenQuoteId(true));
                $session->getQuote()->setIsActive(false)->save();
            } catch (Exception $e) {
                Mage::logException($e);
                $result = self::ORDER_ERROR;
            }
        }

        $this->getResponse()->setHeader('Content-type', 'application/json');
        $this->getResponse()->setBody($result);
        return $result;
    }
}
