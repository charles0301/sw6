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

use DateTime;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Novalnet\NovalnetPayment\Components\NovalnetPaymentTokenRepository;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Plugin\PluginService;
use Shopware\Core\System\Currency\CurrencyFormatter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Intl\Countries;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * NovalnetHelper Class.
 */
class NovalnetHelper
{
    /**
     * @var string
     */
    protected $endpoint = 'https://payport.novalnet.de/v2/';

    /**
     * @var SystemConfigService
     */
    protected $systemConfigService;

    /**
     * @var SessionInterface
     */
    protected $sessionInterface;

    /**
     * @var TranslatorInterface
     */
    protected $translator;

    /**
     * @var ContainerInterface
     */
    protected $container;

    /**
     * @var string
     */
    protected $shopVersion;

    /**
     * @var string
     */
    protected $newLine = '/ ';

    /**
     * @var NovalnetPaymentTokenRepository
     */
    protected $paymentTokenRepository;

    /**
     * @var PluginService
     */
    protected $pluginService;

    /**
     * @var RequestStack
     */
    protected $requestStack;

    /**
     * @var EntityRepositoryInterface
     */
    protected $languageRepository;

    /**
     * @var CurrencyFormatter
     */
    protected $currencyFormatter;

    /**
     * @var array
     */
    protected $supports = [
        'authorize'        => [
            'novalnetcreditcard',
            'novalnetsepa',
            'novalnetpaypal',
            'novalnetinvoice',
            'novalnetsepaguarantee',
            'novalnetinvoiceguarantee',
            'novalnetinvoiceinstalment',
            'novalnetsepainstalment'
        ],
        'guarantee'        => [
            'novalnetsepaguarantee',
            'novalnetinvoiceguarantee'
        ],
        'instalment'	  => [
			'novalnetinvoiceinstalment',
            'novalnetsepainstalment'
        ],
        'payLater'        => [
            'novalnetinvoice',
            'novalnetprepayment',
            'novalnetcashpayment',
            'novalnetmultibanco',
        ]
    ];

    public function __construct(
        NovalnetPaymentTokenRepository $paymentTokenRepository,
        PluginService $pluginService,
        SessionInterface $sessionInterface,
        TranslatorInterface $translator,
        ContainerInterface $container,
        SystemConfigService $systemConfigService,
        RequestStack $requestStack,
        EntityRepositoryInterface $languageRepository,
        CurrencyFormatter $currencyFormatter,
        string $shopVersion
    ) {
        $this->container              = $container;
        $this->paymentTokenRepository = $paymentTokenRepository;
        $this->pluginService 		  = $pluginService;
        $this->sessionInterface 	  = $sessionInterface;
        $this->shopVersion 			  = $shopVersion;
        $this->systemConfigService    = $systemConfigService;
        $this->translator             = $translator;
        $this->requestStack           = $requestStack;
        $this->languageRepository     = $languageRepository;
        $this->currencyFormatter     = $currencyFormatter;
    }

    /**
     * Get Shopware & Novalnet version information
     * @param Context $context
     *
     * @return string
     */
    public function getVersionInfo(Context $context) : string
    {
        $plugin = $this->pluginService->getPluginByName('NovalnetPayment', $context);
        return $this->shopVersion . '-NN' . $plugin->getVersion();
    }

    /**
     * Returns the supported Novalnet payment based on process
     *
     * @param string $process
     * @param string $paymentType
     *
     * @return bool
     */
    public function getSupports(string $process, string $paymentType) : bool
    {
        if (! empty($this->supports[ $process ])) {
            if ('' !== $paymentType) {
                return in_array($paymentType, $this->supports[ $process ], true);
            }
        }
        return false;
    }

    /**
     * Format due_date.
     *
     * @param int $days
     *
     * @return string
     */
    public function formatDueDate(int $days) : string
    {
        return date('Y-m-d', mktime(0, 0, 0, (int) date('m'), (int) (date('d') + $days), (int) date('Y')));
    }

    /**
     * Get the system IP address.
     *
     * @param string $type
     * @return string
     */
    public function getIp(string $type = 'REMOTE') : string
    {
        $ipAddress = '';

        // Check to determine the IP address type
        if ($type === 'REMOTE') {
            if (!is_null($this->requestStack->getCurrentRequest())) {
                $ipAddress = $this->requestStack->getCurrentRequest()->getClientIp();
            }
        } else {
            if (empty($_SERVER['SERVER_ADDR']) && !empty($_SERVER['SERVER_NAME'])) {
                // Handled for IIS server
                $ipAddress = gethostbyname($_SERVER['SERVER_NAME']);
            } elseif (!empty($_SERVER['SERVER_ADDR'])) {
                $ipAddress = $_SERVER['SERVER_ADDR'];
            }
        }
        return $ipAddress;
    }

    /**
     * Request to payment gateway action.
     *
     * @param array  $parameters
     * @param string $url
     * @param string $accessKey
     *
     * @return array
     */
    public function sendPostRequest(array $parameters, string $url, string $accessKey) : array
    {
		file_put_contents('request.txt', print_r($url, true), FILE_APPEND);
		file_put_contents('request.txt', print_r($parameters, true), FILE_APPEND);
        $client = new Client([
            'headers'  => [

                // The charset should be "utf-8"
                'charset' => 'utf-8',

                // Optional
                'Accept' => 'application/json',

                // The formed authenticate value (case-sensitive)
                'X-NN-Access-Key' => base64_encode(str_replace(' ', '', $accessKey)),
            ],
            'json' => $parameters
        ]);

        try {
            $response = $client->post($url)->getBody()->getContents();
        } catch (RequestException $requestException) {
            return [
                'result' => [
                    'status'      => 'FAILURE',
                    'status_code' => '106',
                    'status_text' => $requestException->getMessage(),
                ],
            ];
        }
        file_put_contents('response.txt', print_r($this->unserializeData($response), true), FILE_APPEND);
        return $this->unserializeData($response);
    }

    /**
     * Returns the Novalnet backend configuration.
     *
     * @param string $salesChannelId
     *
     * @return array
     */
    public function getNovalnetPaymentSettings(string $salesChannelId): array
    {
        return $this->systemConfigService->getDomain(
            'NovalnetPayment.settings',
            $salesChannelId,
            true
        );
    }

    /**
     * Format the given string.
     *
     * @param string $string
     * @param string $find
     * @param string $replace
     *
     * @return string
     */
    public function formatString(string $string, string $find = 'novalnet', string $replace = '') : string
    {
        return str_ireplace($find, $replace, $string);
    }

    /**
     * To fetch the shop language from context.
     *
     * @param Context $context
     *
     * @return string
     */
    public function getLocaleCodeFromContext(Context $context): string
    {
        $languageId = $context->getLanguageId();
        $criteria = new Criteria([$languageId]);
        $criteria->addAssociation('locale');
        $languageCollection = $this->languageRepository->search($criteria, $context)->getEntities();

        $language = $languageCollection->get($languageId);
        if (null === $language) {
            return 'EN';
        }

        $locale = $language->getLocale();
        if (!$locale) {
            return 'EN';
        }
        $lang = explode('-', $locale->getCode());
        return strtoupper($lang[0]);
    }

    /**
     * Get last charaters from the string
     *
     * @param string $input
     *
     * @return string
     */
    public function getLastCharacters(string $input) : string
    {
        return substr($input, -4);
    }

    /**
     * Get formatted Novalnet payment
     *
     * @param PaymentMethodEntity $paymentMethodEntity
     *
     * @return string
     */
    public function getPaymentMethodName(PaymentMethodEntity $paymentMethodEntity): ?string
    {
		$paymentMethodName = '';
		if (method_exists($paymentMethodEntity, 'getShortName') && $paymentMethodEntity->getShortName() !== null) {
			$paymentMethodName = (new CamelCaseToSnakeCaseNameConverter())->denormalize((string) $paymentMethodEntity->getShortName());
			$paymentMethodName = strtolower($paymentMethodName);
		} elseif (!empty($paymentMethodEntity->getCustomFields()['novalnet_payment_method_name'])) {
			$paymentMethodName = $paymentMethodEntity->getCustomFields()['novalnet_payment_method_name'];
		}
        return $paymentMethodName;
    }

    /**
     * Get address
     *
     * @param CustomerEntity|null $customerEntity
     * @param string $type
     *
     * @return array
     */
    public function getAddress(CustomerEntity $customerEntity = null, string $type = 'billing'): array
    {
        $address  = [];
        $customer = [];
        if (!is_null($customerEntity)) {
            if ($type === 'shipping') {
                $addressData = $customerEntity->getActiveShippingAddress();
            } else {
                $addressData = $customerEntity->getActiveBillingAddress();
            }
            if (!is_null($addressData) && !is_null($addressData->getCountry())) {
                list($customer ['first_name'], $customer ['last_name']) = $this->retrieveName(
                    [
                        $addressData->getFirstName(),
                        $addressData->getLastName(),
                    ]
                );

                $customer['email'] = $customerEntity->getEmail();
                if(!is_null($addressData->getCompany())) {
					$address['company'] = $addressData->getCompany();
				}

                $address['street'] = $addressData->getStreet().' '.$addressData->getAdditionalAddressLine1().' '.$addressData->getAdditionalAddressLine2();
                $address['city'] = $addressData->getCity();
                $address['zip'] = $addressData->getZipCode();
                $address['country_code'] = $addressData->getCountry()->getIso();
            }
        }

        return [$customer, $address];
    }

    /**
     * Retrieve the name of the end user.
     *
     * @param array $name
     *
     * @return array
     */
    public function retrieveName(array $name) : array
    {

        // Retrieve first name and last name from order objects.
        if (empty($name['0'])) {
            $name['0'] = $name['1'];
        }
        if (empty($name['1'])) {
            $name['1'] = $name['0'];
        }
        return $name;
    }

    /**
     * Converting given amount into smaller unit
     *
     * @param float|null $amount
     *
     * @return float
     */
    public function amountInLowerCurrencyUnit(float $amount = null): ?float
    {
        return  $amount * 100;
    }

    /**
     * Converting given amount into bigger unit
     *
     * @param int $amount
     * @param string $currency
     * @param Context|null $context
     *
     * @return string
     */
    public function amountInBiggerCurrencyUnit(int $amount, string $currency = '', Context $context = null) : ?string
    {
        $formatedAmount = (float) sprintf('%.2f', $amount / 100);
        if (! empty($currency)) {
            if (is_null($context)) {
                $context        = Context::createDefaultContext();
            }
            $formatedAmount = $this->currencyFormatter->formatCurrencyByLanguage($formatedAmount, $currency, $context->getLanguageId(), $context);
        }
        return (string) $formatedAmount;
    }



    /**
     * Get action URL
     *
     * @param string $action
     *
     * @return string
     */
    public function getActionEndpoint(string $action = '') : string
    {
        return $this->endpoint . str_replace('_', '/', $action);
    }

    /**
     * Form payment comments.
     *
     * @param array   $input
     * @param boolean $isError
     *
     * @return string
     */
    public function formBasicComments(array $input, bool $isError = false) : string
    {
        $comments = '';

        if (! empty($input ['transaction']['tid'])) {
            $comments = sprintf($this->translator->trans('NovalnetPayment.text.transactionId'), $input ['transaction']['tid']);
            if (! empty($input ['transaction'] ['test_mode'])) {
                $comments .= $this->newLine . $this->translator->trans('NovalnetPayment.text.testOrder') . $this->newLine;
            }

            if ($input['transaction']['status'] === 'PENDING' && in_array($input['transaction']['payment_type'],['GUARANTEED_DIRECT_DEBIT_SEPA', 'INSTALMENT_DIRECT_DEBIT_SEPA'])) {
                $comments .= $this->newLine . $this->translator->trans('NovalnetPayment.text.sepaGuaranteePendingMsg');
            }
        }
        if ($isError) {
            $comments .= $this->newLine . $this->getResponseText($input);
        }
        return $comments;
    }

    /**
     * Form Bank details comments.
     *
     * @param array   $input
     *
     * @return string
     */
    public function formBankDetails(array $input) : string
    {
        $comments = $this->formBasicComments($input);

        if ($input['transaction']['status'] === 'PENDING' && in_array($input['transaction']['payment_type'],['GUARANTEED_INVOICE', 'INSTALMENT_INVOICE'])) {
            $comments .= $this->newLine . $this->translator->trans('NovalnetPayment.text.invoiceGuaranteePendingMsg');
        } elseif (!empty($input ['transaction']['bank_details'])) {

			if ( ! empty( $input['instalment']['cycle_amount'] ) ) {
				$amountInBiggerCurrencyUnit = $this->amountInBiggerCurrencyUnit( $input['instalment']['cycle_amount'], $input ['transaction']['currency'] );
			} elseif ( $input['transaction']['payment_type'] !== 'INSTALMENT_INVOICE' ) {
				$amountInBiggerCurrencyUnit = $this->amountInBiggerCurrencyUnit( $input ['transaction']['amount'], $input ['transaction']['currency'] );
			}

			if(!empty($amountInBiggerCurrencyUnit))
			{
				if (in_array($input['transaction']['status'], [ 'CONFIRMED', 'PENDING' ], true) && ! empty($input ['transaction']['due_date'])) {
					$comments .= $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.amountTransaferNoteWithDueDate'), $amountInBiggerCurrencyUnit, date('d/m/Y', strtotime($input ['transaction']['due_date']))) . $this->newLine . $this->newLine;
				} else {
					$comments .= $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.amountTransaferNote'), $amountInBiggerCurrencyUnit) . $this->newLine . $this->newLine;
				}
			}

            foreach ([
                'account_holder' => $this->translator->trans('NovalnetPayment.text.accountHolder'),
                'bank_name'      => $this->translator->trans('NovalnetPayment.text.bank'),
                'bank_place'     => $this->translator->trans('NovalnetPayment.text.bankPlace'),
                'iban'           => $this->translator->trans('NovalnetPayment.text.iban'),
                'bic'            => $this->translator->trans('NovalnetPayment.text.bic'),
            ] as $key => $text) {
                if (! empty($input ['transaction']['bank_details'][ $key ])) {
                    $comments .= sprintf($text, $input ['transaction']['bank_details'][ $key ]) . $this->newLine;
                }
            }

            // Form reference comments.
            $comments .= $this->newLine . $this->translator->trans('NovalnetPayment.text.paymentReferenceNote');
            /* translators: %s:  TID */
            $comments .= $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.paymentReference'), '1', $input ['transaction']['tid']) . $this->newLine;

            if (! empty($input ['transaction']['invoice_ref'])) {
                /* translators: %s: invoice_ref */
                $comments .=  sprintf($this->translator->trans('NovalnetPayment.text.paymentReference'), '2', $input ['transaction']['invoice_ref']) . $this->newLine;
            }
        }

        return $comments;
    }

    /**
     * Form Barzahlen/Slip details comments.
     *
     * @param array $input
     *
     * @return string
     */
    public function formSlipDetails(array $input) : string
    {
        $comments = $this->formBasicComments($input);

        if (! empty($input ['transaction']['due_date'])) {
            /* translators: %1$s: amount, %2$s: due date */
            $comments .= $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.slipExpiryDate'), date('d/m/Y', strtotime($input ['transaction']['due_date']))) . $this->newLine . $this->newLine;
        }

        $comments .= $this->translator->trans('NovalnetPayment.text.cashpaymentStore') . $this->newLine;

        foreach ($input['transaction']['nearest_stores'] as $nearestStore) {
            foreach ([
                'store_name',
                'street',
                'city',
                'zip',
            ] as $addressData) {
                if (! empty($nearestStore[$addressData])) {
                    $comments .= $nearestStore[$addressData] . $this->newLine;
                }
            }
            if (! empty($nearestStore['country_code'])) {
                $comments .= Countries::getName($nearestStore['country_code']) . $this->newLine . $this->newLine;
            }
        }

        return $comments;
    }

    /**
     * Form Multibanco details comments.
     *
     * @param array   $input
     *
     * @return string
     */
    public function formMultibancoDetails(array $input) : string
    {
        $comments = $this->formBasicComments($input);

        if (! empty($input['transaction']['partner_payment_reference'])) {
            $comments .= $this->newLine . $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.multibancoReference'), $input ['transaction']['amount'], $input['transaction']['partner_payment_reference']);
        }

        return $comments;
    }

    /**
     * Retrieves messages from server response.
     *
     * @param array $data
     *
     * @return string
     */
    public function getResponseText(array $data) : string
    {
        if (! empty($data ['result']['status_text'])) {
            return $data ['result']['status_text'];
        }
        if (! empty($data ['status_text'])) {
            return $data ['status_text'];
        }
        return $this->translator->trans('NovalnetPayment.text.paymentError');
    }

    /**
     * Get stored payment token.
     *
     * @param SalesChannelContext $salesChannelContext
     * @param string $paymentMethod
     * @param bool $default
     *
     * @return array
     */
    public function getStoredData(SalesChannelContext $salesChannelContext, string $paymentMethod, bool $default = false) : array
    {
        $additionalFilter = [];
        $tokens =[];

        $additionalFilter['paymentType'] = $paymentMethod;

        if ($this->getSupports('guarantee', $paymentMethod)) {
            $additionalFilter['paymentType'] = $this->formatString($paymentMethod, 'guarantee');
        }

        if ($this->getSupports('instalment', $paymentMethod)) {
            $additionalFilter['paymentType'] = $this->formatString($paymentMethod, 'instalment');
        }

        if ($default && $this->sessionInterface->has($paymentMethod . 'FormData') && ! empty($paymentMethod)) {
            $sessionData = $this->sessionInterface->get($paymentMethod . 'FormData');
            if (! empty($sessionData['paymentToken'])) {
                $additionalFilter ['token'] = $sessionData['paymentToken'];
            }
        }

        if ($default) {
            $storedData [] = $this->paymentTokenRepository->getLastPaymentToken($salesChannelContext, $additionalFilter);
        } else {
            $storedData = $this->paymentTokenRepository->getPaymentTokens($salesChannelContext, $additionalFilter);
        }

        if (! empty($storedData)) {
            foreach ($storedData as $data) {
                if (! empty($data)) {
                    if (! empty($data->getToken())) {
                        $tokens[$data->getToken()]['token'] = $data->getToken();
                        if (! empty($data->getAccountData() && $paymentMethod === 'novalnetpaypal')) {
                            $tokens[$data->getToken()]['accountData'] = $data->getAccountData();
                        } else {
                            $tokens[$data->getToken()]['accountData'] = substr($data->getAccountData(), -4);
                        }

                        if (! empty($data->getExpiryDate())) {
                            $tokens[$data->getToken()]['expiryDate'] = date('m/y', strtotime($data->getExpiryDate()->format(DateTime::ATOM)));
                        }
                        if (! empty($data->getType())) {
                            $tokens[$data->getToken()]['type'] = $data->getType();
                        }
                    }
                }
            }
        }

        return $tokens;
    }

    /**
     * Perform serialize data.
     *
     * @param array $data
     *
     * @return string
     */
    public function serializeData(array $data): string
    {
        $result = '{}';

        if (! empty($data)) {
            $result = json_encode($data, JSON_UNESCAPED_SLASHES);
        }
        return $result;
    }

    /**
     * Perform unserialize data.
     *
     * @param string $data
     * @param bool $needAsArray
     *
     * @return array
     */
    public function unserializeData(string $data, bool $needAsArray = true): ?array
    {
        $result = json_decode($data, $needAsArray, 512, JSON_BIGINT_AS_STRING);

        if (json_last_error() === 0) {
            return $result;
        }

        return $result;
    }

    /**
     * Get error messagwwe from session.
     *
     * @return string
     */
    public function getNovalnetErrorMessage() : string
    {
        $errorMessage = '';
        if ($this->sessionInterface->has('novalnetErrorMessage')) {
            $errorMessage = $this->sessionInterface->get('novalnetErrorMessage');
            $this->sessionInterface->remove('novalnetErrorMessage');
        }
        return $errorMessage;
    }
}
