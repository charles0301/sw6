<?php
/**
 * Novalnet payment plugin
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to Novalnet End User License Agreement
 *
 * DISCLAIMER
 *
 * If you wish to customize Novalnet payment extension for your needs,
 * please contact technic@novalnet.de for more information.
 *
 * @category    Novalnet
 * @package     NovalnetPayment
 * @copyright   Copyright (c) Novalnet
 * @license     https://www.novalnet.de/payment-plugins/kostenlos/lizenz
 */
declare(strict_types=1);

namespace Novalnet\NovalnetPayment\Helper;

use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\MailTemplate\Service\MailServiceInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Novalnet\NovalnetPayment\Content\PaymentTransaction\NovalnetPaymentTransactionEntity;
use Shopware\Core\Checkout\Cart\Exception\OrderNotFoundException;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextFactory;
use Shopware\Core\System\StateMachine\Exception\IllegalTransitionException;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;

class NovalnetOrderTransactionHelper
{
    /**
     * @var MailServiceInterface
     */
    private $mailService;

    /**
     * @var TranslatorInterface
     */
    private $translator;

    /**
     * @var NovalnetHelper
     */
    private $helper;

    /**
     * @var NovalnetValidator
     */
    private $validator;

    /**
     * @var OrderTransactionStateHandler
     */
    private $orderTransactionState;

    /**
     * @var string
     */
    public $newLine = '/ ';

    /**
     * @var EntityRepositoryInterface
     */
    public $orderRepository;

    /**
     * @var EntityRepositoryInterface
     */
    public $orderTransactionRepository;

    /**
     * @var EntityRepositoryInterface
     */
    public $paymentMethodRepository;

    /**
     * @var EntityRepositoryInterface
     */
    public $novalnetTransactionRepository;

    /** @var SalesChannelContextFactory */
    public $salesChannelContextFactory;

    /**
     * @var Environment
     */
    private $twig;

    public function __construct(
        NovalnetHelper $helper,
        NovalnetValidator $validator,
        OrderTransactionStateHandler $orderTransactionState,
        MailServiceInterface $mailService,
        TranslatorInterface $translator,
        EntityRepositoryInterface $orderRepository,
        EntityRepositoryInterface $orderTransactionRepository,
        EntityRepositoryInterface $paymentMethodRepository,
        EntityRepositoryInterface $novalnetTransactionRepository,
        SalesChannelContextFactory $salesChannelContextFactory,
        Environment $twig
    ) {
        $this->mailService                   = $mailService;
        $this->helper            	         = $helper;
        $this->translator                    = $translator;
        $this->validator                     = $validator;
        $this->orderTransactionState         = $orderTransactionState;
        $this->orderRepository               = $orderRepository;
        $this->orderTransactionRepository    = $orderTransactionRepository;
        $this->paymentMethodRepository       = $paymentMethodRepository;
        $this->novalnetTransactionRepository = $novalnetTransactionRepository;
        $this->salesChannelContextFactory	 = $salesChannelContextFactory;
        $this->twig                   		 = $twig;
    }

    /**
     * send novalnet order mail.
     *
     * @param SalesChannelContext $salesChannelContext
     * @param OrderEntity $order
     * @param string $note
     * @param boolean $instalmentRecurring
     */
    public function sendMail(SalesChannelContext $salesChannelContext, OrderEntity $order, string $note , bool $instalmentRecurring = false): void
    {
        $customer = $order->getOrderCustomer();
        if (null === $customer) {
            return;
        }

        $renderedTemplate = $this->twig->render('@NovalnetPayment/documents/mail.html.twig', ['note' => $note, 'order' => $order, 'salesChannel' => $salesChannelContext->getSalesChannel(), 'instalment' => $instalmentRecurring]);

        $data = new ParameterBag();
        $data->set(
            'recipients',
            [
                $customer->getEmail() => $customer->getFirstName().' '.$customer->getLastName(),
            ]
        );
        $data->set('senderName', $salesChannelContext->getSalesChannel()->getName());
        $data->set('salesChannelId', $order->getSalesChannelId());

        $data->set('contentHtml', $renderedTemplate);
        $data->set('contentPlain', $renderedTemplate);

        if($instalmentRecurring)
        {
			$data->set('subject', sprintf($this->translator->trans('NovalnetPayment.text.instalmentMailSubject'), $salesChannelContext->getSalesChannel()->getName(), $order->getOrderNumber()));
		} else {
			$data->set('subject', $this->translator->trans('NovalnetPayment.text.mailSubject'));
		}

        try {
            $this->mailService->send(
                $data->all(),
                $salesChannelContext->getContext(),
                [
                    'order' => $order,
                    'note' => $note,
                ]
            );
        } catch (\RuntimeException $e) {
            //Ignore
        }
    }

    /**
     * get the order reference details.
     *
     * @param string|null $orderId
     * @param string|null $customerId
     *
     * @return Criteria
     */
    public function getOrderCriteria(string $orderId = null, string $customerId = null): Criteria
    {
        if (!empty($orderId)) {
            $orderCriteria = new Criteria([$orderId]);
        } else {
            $orderCriteria = new Criteria([]);
        }

        if (!empty($customerId)) {
            $orderCriteria->addFilter(
                new EqualsFilter('order.orderCustomer.customerId', $customerId)
            );
        }

        $orderCriteria->addAssociation('orderCustomer.salutation');
        $orderCriteria->addAssociation('orderCustomer.customer');
        $orderCriteria->addAssociation('currency');
        $orderCriteria->addAssociation('stateMachineState');
        $orderCriteria->addAssociation('lineItems');
        $orderCriteria->addAssociation('transactions');
        $orderCriteria->addAssociation('transactions.paymentMethod');
        $orderCriteria->addAssociation('addresses');
        $orderCriteria->addAssociation('deliveries.shippingMethod');
        $orderCriteria->addAssociation('addresses.country');
        $orderCriteria->addAssociation('deliveries.shippingOrderAddress.country');
        $orderCriteria->addAssociation('salesChannel');
        $orderCriteria->addAssociation('price');
        $orderCriteria->addAssociation('taxStatus');

        return $orderCriteria;
    }

    /**
     * Finds a transaction by id.
     *
     * @param string $transactionId
     * @param Context|null $context
     *
     * @return OrderTransactionEntity|null
     */
    public function getTransactionById(string $transactionId, Context $context = null): ?OrderTransactionEntity
    {
        $transactionCriteria = new Criteria();
        $transactionCriteria->addFilter(new EqualsFilter('id', $transactionId));

        $transactionCriteria->addAssociation('order');

        $transactions = $this->orderTransactionRepository->search(
            $transactionCriteria,
            $context ?? Context::createDefaultContext()
        );

        if ($transactions->count() === 0) {
            return null;
        }

        return $transactions->first();
    }

    /**
     * Finds a payment method by id.
     *
     * @param string $paymentMethodId
     * @param Context|null $context
     *
     * @return PaymentMethodEntity|null
     */
    public function getPaymentMethodById(string $paymentMethodId, Context $context = null): ?PaymentMethodEntity
    {
        $paymentMethodCriteria = new Criteria();
        $paymentMethodCriteria->addFilter(new EqualsFilter('id', $paymentMethodId));

        $paymentMethod = $this->paymentMethodRepository->search(
            $paymentMethodCriteria,
            $context ?? Context::createDefaultContext()
        );

        if ($paymentMethod->count() === 0) {
            return null;
        }

        return $paymentMethod->first();
    }

    /**
     * send novalnet mail.
     *
     * @param OrderEntity $order
     * @param SalesChannelContext $salesChannelContext
     * @param string $note
     * @param boolean $instalmentRecurring
     *
     */
    public function prepareMailContent(OrderEntity $order, SalesChannelContext $salesChannelContext, string $note, $instalmentRecurring = false): void
    {
        if (!is_null($order->getOrderCustomer())) {
            $orderEntity = $this->getOrderCriteria($order->getId(), $order->getOrderCustomer()->getCustomerId());
            $orderReference = $this->orderRepository->search($orderEntity, $salesChannelContext->getContext())->first();
            try {
                $this->sendMail($salesChannelContext, $orderReference, $note, $instalmentRecurring);
            } catch (\RuntimeException $e) {
                //Ignore
            }
        }
    }

    /**
     * Fetch Novalnet transaction data.
     *
     * @param string|null $orderNumber
     * @param Context|null $context
     * @param int|null $tid
     *
     * @return NovalnetPaymentTransactionEntity
     */
    public function fetchNovalnetTransactionData(string $orderNumber = null, Context $context = null, int $tid = null): ? NovalnetPaymentTransactionEntity
    {
        $criteria = new Criteria();

        if (!is_null($orderNumber)) {
            $criteria->addFilter(new EqualsFilter('novalnet_transaction_details.orderNo', $orderNumber));
        }

        if (!is_null($tid)) {
            $criteria->addFilter(new EqualsFilter('novalnet_transaction_details.tid', $tid));
        }
        
        $criteria->addSorting(
            new FieldSorting('createdAt', FieldSorting::DESCENDING)
        );
            
        return $this->novalnetTransactionRepository->search($criteria, $context ?? Context::createDefaultContext())->first();
    }

    /**
     * @throws OrderNotFoundException
     */
    private function getSalesChannelIdByOrderId(string $orderId, Context $context): string
    {
        $order = $this->orderRepository->search(new Criteria([$orderId]), $context)->first();

        if ($order === null) {
            throw new OrderNotFoundException($orderId);
        }

        return $order->getSalesChannelId();
    }

    /**
     * Manage transaction
     *
     * @param NovalnetPaymentTransactionEntity $transactionData
     * @param OrderTransactionEntity $transaction
     * @param Context $context
     * @param string $type
     * @param boolean $extensionProcess
     *
     * @return array
     */
    public function manageTransaction(NovalnetPaymentTransactionEntity $transactionData, OrderTransactionEntity $transaction, Context $context, string $type = 'transaction_capture', bool $extensionProcess = false): array
    {
        $response = [];
        if ($type) {
            $parameters = [
                'transaction' => [
                    'tid' => $transactionData->getTid()
                ],
                'custom' => [
					'shop_invoked' => 1
                ]
            ];

            $endPoint   = $this->helper->getActionEndpoint($type);
            $paymentSettings = $this->helper->getNovalnetPaymentSettings($this->getSalesChannelIdByOrderId($transaction->getOrderId(), $context));

            $response = $this->helper->sendPostRequest($parameters, $endPoint, $paymentSettings['NovalnetPayment.settings.accessKey']);

            if ($this->validator->isSuccessStatus($response)) {
                $message        = '';
                $appendComments = true;

                if (! empty($response['transaction']['status'])) {
                    $upsertData = [
                        'id'            => $transactionData->getId(),
                        'gatewayStatus' => $response['transaction']['status']
                    ];
                    if (in_array($response['transaction']['status'], ['CONFIRMED', 'PENDING'])) {
                        $message = sprintf($this->translator->trans('NovalnetPayment.text.confirmMessage'), date('d/m/Y H:i:s')) . $this->newLine;
                        if ($response['transaction']['status'] === 'CONFIRMED') {
                            $upsertData['paidAmount'] = $transactionData->getAmount();
                        }
                        if(!empty($transactionData->getAdditionalDetails()) && !empty($transactionData->getPaymentType()) && in_array($transactionData->getPaymentType(), ['novalnetinvoice', 'novalnetinvoiceguarantee', 'novalnetprepayment', 'novalnetinvoiceinstalment'])) {
							$appendComments = false;
                            $response['transaction']['bank_details'] = $this->helper->unserializeData($transactionData->getAdditionalDetails());
                            $message .=  $this->newLine . $this->helper->formBankDetails($response);
                        }

                        if(! empty($response['instalment']['cycles_executed']) && !empty($transactionData->getPaymentType()) && in_array($transactionData->getPaymentType(), ['novalnetsepainstalment', 'novalnetinvoiceinstalment']))
						{
							$response['transaction']['amount'] = $transactionData->getAmount();
							$upsertData['additionalDetails'] = $this->getInstalmentInformation($response);
							$upsertData['additionalDetails'] = $this->helper->serializeData($upsertData['additionalDetails']);
						}
                    } elseif ($response['transaction']['status'] === 'DEACTIVATED') {
                        $message = sprintf($this->translator->trans('NovalnetPayment.text.faliureMessage'), date('d/m/Y H:i:s')) . $this->newLine;
                    }

//                    if(!empty($transactionData->getPaymentType()) && $transactionData->getPaymentType() == 'novalnetpaypal' && !empty($response['transaction']['payment_data']['paypal_account']))
//                    {
//
//                        $this->helper->paymentTokenRepository->savePaymentToken(salesChannelContext, );
//                    }
//						$order = $this->getOrderEntity($transactionData->getOrderNo(), $context);
//						$options = [];
//						if ((string) $order->getOrderCustomer()->getCustomerId() !== '') {
//							$options[SalesChannelContextService::CUSTOMER_ID] = $order->getOrderCustomer()->getCustomerId();
//						}
//
//						$salesChannelContext	= $this->salesChannelContextFactory->create(Uuid::randomHex(),$this->getSalesChannelIdByOrderId($transaction->getOrderId(), $context), $options);
//						$paymentMethodObj->savePaymentToken($response, $transactionData->getPaymentType(), $salesChannelContext);
//					}
                    $this->postProcess($transaction, $context, $message, $upsertData, $appendComments);

					if(!empty($extensionProcess))
					{
						if ($response['transaction']['status'] === 'CONFIRMED') {
							$this->orderTransactionState->paid($transaction->getId(), $context);
						} elseif ($response['transaction']['status'] === 'PENDING') {
							$this->orderTransactionState->process($transaction->getId(), $context);
						} elseif ($response['transaction']['status'] !== 'PENDING') {
							$this->orderTransactionState->cancel($transaction->getId(), $context);
						}
					}
                }
            }
        }
        return $response;
    }

    /**
     * Refund transaction
     *
     * @param NovalnetPaymentTransactionEntity $transactionData
     * @param OrderTransactionEntity $transaction
     * @param Context $context
     * @param int $refundAmount
     * @param Request|null $request
     * @param boolean $extensionProcess
     *
     * @return array
     */
    public function refundTransaction(NovalnetPaymentTransactionEntity $transactionData, OrderTransactionEntity $transaction, Context $context, int $refundAmount = 0, Request $request = null, bool $extensionProcess = false) : array
    {
		
		$endPoint   = $this->helper->getActionEndpoint('transaction_refund');
		
		if(!empty($request->get('instalmentCancel')))
		{
			$parameters = [
				'instalment' => [
					'tid'    => $transactionData->getTid()
				],
				'custom' => [
					'shop_invoked' => 1
				]
			];
			$endPoint   = $this->helper->getActionEndpoint('instalment_cancel');
		} else {
			$parameters = [
				'transaction' => [
					'tid'    => !empty($request->get('instalmentCycleTid')) ? $request->get('instalmentCycleTid') : $transactionData->getTid()
				],
				'custom' => [
					'shop_invoked' => 1
				]
			];
			
			if ($request->get('reason')) {
				$parameters['transaction']['reason'] = $request->get('reason');
			}
        
			if (!empty($refundAmount)) {
				$parameters['transaction']['amount'] = $refundAmount;
			}
		}
        
        $paymentSettings = $this->helper->getNovalnetPaymentSettings($this->getSalesChannelIdByOrderId($transaction->getOrderId(), $context));

        $response = $this->helper->sendPostRequest($parameters, $endPoint, $paymentSettings['NovalnetPayment.settings.accessKey']);

        if ($this->validator->isSuccessStatus($response)) {
            if (! empty($response['transaction']['status'])) {
                if (empty($refundAmount)) {
                    if (! empty($response['transaction']['refund']['amount'])) {
                        $refundAmount = $response['transaction']['refund']['amount'];
                    } else {
                        $refundAmount = (int) $transactionData->getAmount() - (int) $transactionData->getRefundedAmount();
                    }
                }
                $currency = !empty($response['transaction'] ['currency']) ? $response['transaction'] ['currency'] : $response ['transaction'] ['refund'] ['currency'];
                $refundedAmountInBiggerUnit = $this->helper->amountInBiggerCurrencyUnit($refundAmount, $currency, $context);
                $message = sprintf($this->translator->trans('NovalnetPayment.text.refundComment'), $transactionData->getTid(), $refundedAmountInBiggerUnit) . $this->newLine;

                if (! empty($response['transaction']['refund']['tid'])) {
                    $message .= sprintf($this->translator->trans('NovalnetPayment.text.refundCommentForNewTid'), $response ['transaction']['refund']['tid']) . $this->newLine;
                }

                $totalRefundedAmount = (int) $transactionData->getRefundedAmount() + (int) $refundAmount;

                $this->postProcess($transaction, $context, $message, [
                    'id'             => $transactionData->getId(),
                    'refundedAmount' => $totalRefundedAmount,
                    'gatewayStatus'  => $response['transaction']['status'],
                ]);

                if (($totalRefundedAmount >= $transactionData->getAmount() && !empty($extensionProcess)) || !empty($request->get('instalmentCancel'))) {
                    try {
                        
                        if(!empty($request->get('instalmentCancel')))
                        {
							$this->orderTransactionState->cancel($transaction->getId(), $context);
						} else {
							$this->orderTransactionState->refund($transaction->getId(), $context);
						}
                    } catch (IllegalTransitionException $exception) {
                        // we can not ensure that the refund or refund partially status change is allowed
                        $this->orderTransactionState->cancel($transaction->getId(), $context);
                    }
                }
            }
        }
        return $response;
    }

    /**
     * Post payment process
     *
     * @param OrderTransactionEntity $transaction
     * @param Context $context
     * @param string $comments
     * @param array $upsertData
     * @param bool $append
     */
    public function postProcess(OrderTransactionEntity $transaction, Context $context, string $comments, array $upsertData = [], bool $append = true) : void
    {
		
		file_put_contents('asjdbs.txt', print_r($transaction, true), FILE_APPEND);
		if (!empty($upsertData)) {
            $this->novalnetTransactionRepository->update([$upsertData], $context);
        }

        if (!is_null($transaction->getCustomFields()) && !empty($comments)) {
            $oldComments = $transaction->getCustomFields()['novalnet_comments'];

            if (!empty($oldComments) && ! empty($append)) {
                $comments = $oldComments . $this->newLine . $comments;
            }

            $data = [
                'id' => $transaction->getId(),
                'customFields' => [
                    'novalnet_comments' => $comments,
                ],
            ];
            $this->orderTransactionRepository->update([$data], $context);
        }
    }

    /**
     * Get order.
     *
     * @param string $orderNumber
     * @param Context $context
     *
     * @return OrderTransactionEntity|null
     */
    public function getOrder(string $orderNumber, Context $context): ?OrderTransactionEntity
    {
        $order = $this->getOrderEntity($orderNumber, $context);

        if (null === $order) {
            return null;
        }

        $transactionCollection = $order->getTransactions();

        if (null === $transactionCollection) {
            return null;
        }

        $transaction = $transactionCollection->last();

        if (null === $transaction) {
            return null;
        }

        return $transaction;
    }

    /**
     * Get order.
     *
     * @param string $orderNumber
     * @param Context $context
     *
     * @return OrderEntity|null
     */
    public function getOrderEntity(string $orderNumber, Context $context): ?OrderEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('orderNumber', $orderNumber));
        $criteria->addAssociation('transactions');
        $order = $this->orderRepository->search($criteria, $context)->first();

        if (null === $order) {
            return null;
        }

        return $order;
    }

    /**
     * Form instalment information.
     *
     * @param array $response
     *
     * @return array
     */
    public function getInstalmentInformation(array $response): array
    {
		$instalmentData = $response['instalment'];
		$additionalDetails = [];
		sort($instalmentData['cycle_dates']);
		foreach ($instalmentData['cycle_dates'] as $cycle => $futureInstalmentDate) {
			
			$cycle = $cycle + 1;
			$additionalDetails['InstalmentDetails'][$cycle] = [
				'amount'		=> $instalmentData['cycle_amount'],
				'cycleDate'		=> $futureInstalmentDate ? date('Y-m-d', strtotime($futureInstalmentDate)) : '',
				'cycleExecuted'	=> '',
				'dueCycles'		=> '',
				'paidDate'		=> '',
				'status'		=> 'Pending',
				'reference'		=> ''
			];
			
			if($cycle == count($instalmentData['cycle_dates']))
			{
				$amount = $response['transaction']['amount'] - ($instalmentData['cycle_amount'] * ($cycle - 1));
				$additionalDetails['InstalmentDetails'][$cycle] = array_merge($additionalDetails['InstalmentDetails'][$cycle], [
						'amount'	=> $amount
				]);
			}

			if($cycle == 1)
			{
				$additionalDetails['InstalmentDetails'][$cycle] = array_merge($additionalDetails['InstalmentDetails'][$cycle], [
						'cycleExecuted'	=> !empty($instalmentData['cycles_executed']) ? $instalmentData['cycles_executed'] : '',
						'dueCycles'		=> !empty($instalmentData['pending_cycles']) ? $instalmentData['pending_cycles'] : '',
						'paidDate'		=> date('Y-m-d'),
						'status'		=> 'Paid',
						'reference'		=> (string) $response['transaction']['tid']
				]);
			}
		}
		return $additionalDetails;
	}
}
