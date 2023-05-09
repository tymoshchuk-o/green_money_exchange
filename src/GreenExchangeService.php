<?php

namespace Drupal\green_money_exchange;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;

/**
 * Receives exchange rate data from Rest API.
 *
 * @package Drupal\green_money_exchange
 */
class GreenExchangeService {

  use StringTranslationTrait;

  /**
   * The valid keys in HTTP client response.
   *
   * @var string
   */
  protected $validResponseData = ['txt', 'rate', 'cc'];

  /**
   * The HTTP client to fetch the feed data with.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The LoggerChannelFactoryInterface.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $errorLog;

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * Constructor.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The Drupal http client.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $errorLog
   *   The Logger interface.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The Entity type manager.
   * @param \Drupal\Core\State\StateInterface $state
   *   The Drupal state.
   */
  public function __construct(ClientInterface $http_client, ConfigFactoryInterface $configFactory, LoggerChannelFactoryInterface $errorLog, EntityTypeManagerInterface $entity_type_manager, StateInterface $state) {
    $this->httpClient = $http_client;
    $this->configFactory = $configFactory;
    $this->errorLog = $errorLog;
    $this->entityTypeManager = $entity_type_manager;
    $this->state = $state;
  }

  /**
   * Log error.
   *
   * @param string $message
   *   Error Message.
   */
  public function logError(string $message): void {
    $this->errorLog->get('green_exchange')->error($this->t($message));
  }

  /**
   * Log notice.
   *
   * @param string $message
   *   Error Message.
   */
  public function logNotice(string $message): void {
    $this->errorLog->get('green_exchange')->notice($this->t($message));
  }

  /**
   * Clear currency entity state.
   */
  public function clearCurrencyState() {
    $this->state->delete('green_exchange_date');
  }

  /**
   * Return EntityStorageInterface.
   *
   * @return \Drupal\Core\Entity\EntityStorageInterface
   *   A currency entity storage.
   */
  public function getStorage() {
    $currencyStorage = $this->entityTypeManager->getStorage('green_exchange_currency');

    return $currencyStorage;

  }

  /**
   * Selects currency data from currency entity type.
   *
   * @return array
   *   An array with of currency exchange.
   */
  public function getCurrencyByRange() {
    $currencyArr = [];
    $range = $this->getExchangeSetting()['range'] ?? 0;
    $currencyStorage = $this->getStorage();
    $dateFormat = 'd.m.Y';
    $days = 0;

    $requestTime = $this->state->get('green_exchange_date');

    if (!$requestTime || $requestTime < strtotime("-4 hours")) {
      $this->setCurrencyEntity();
      $this->state->set('green_exchange_date', date('Y-m-d H:i:s'));
    }

    while (!($days > $range)) {
      $query = $currencyStorage->getQuery();
      $day = date($dateFormat, strtotime("-{$days} days"));
      $query->condition('exchangedate', $day);
      $data = $query->execute();
      $currencyList = $currencyStorage->loadMultiple($data);

      foreach ($currencyList as $item) {
        $currencyArr[] = [
          'exchangedate' => $item->get('exchangedate')->getValue()[0]['value'],
          'cc' => $item->get('cc')->getValue()[0]['value'],
          'txt' => $item->get('txt')->getValue()[0]['value'],
          'rate' => $item->get('rate')->getValue()[0]['value'],
        ];
      }
      $days++;
    }

    return $currencyArr;

  }

  /**
   * Save currency to Currency entity.
   */
  public function setCurrencyEntity() {
    $currencyStorage = $this->getStorage();
    $currencyData = $this->getExchange();
    if ($currencyData && $currencyStorage) {
      foreach ($currencyData as $item) {
        if (!$this->checkCurrencyStorage($item->exchangedate, $item->cc)) {
          $currency = $currencyStorage->create([
            "exchangedate" => $item->exchangedate,
            "cc" => $item->cc,
            "txt" => $item->txt,
            "rate" => $item->rate,
          ]);
          $currency->save();
        }
      }
    }
  }

  /**
   * Сhecks for the availability of saved exchange rates for the given date.
   *
   * @param string $date
   *   A currency exchange date.
   * @param string $cc
   *   A currency cc field.
   *
   * @return bool
   *   True if data is found in currency entity type for a given date false.
   */
  public function checkCurrencyStorage(string $date, string $cc): bool {
    $check = FALSE;
    $currencyStorage = $this->getStorage();
    $dateFormat = 'd.m.Y';
    $day = date($dateFormat, strtotime($date));

    $query = $currencyStorage->getQuery();
    $query->condition('exchangedate', $day);
    $data = $query->execute();
    $currencyList = $currencyStorage->loadMultiple($data);

    if (count($currencyList) > 0) {
      foreach ($currencyList as $cr) {
        $ccValue = $cr->get('cc')->getValue()[0]['value'];
        if ($ccValue == $cc) {
          $check = TRUE;

          return $check;
        }

      }
    }

    return $check;
  }

  /**
   * Gets exchange setting.
   */
  public function getExchangeSetting() {

    $config = $this->configFactory->get('green_money_exchange.customconfig');

    return [
      'request' => $config->get('request'),
      'range' => $config->get('range'),
      'uri' => $config->get('uri'),
      'currency' => $config->get('currency-item'),
    ];
  }

  /**
   * Returns only active currencies.
   *
   * @return array
   *   An active currency.
   */
  public function activeCurrency(): array {
    $allCurrency = $this->getExchangeSetting()['currency'];
    if ($allCurrency && count($allCurrency) > 0) {
      $filteredActiveCurrency = array_filter($allCurrency, function ($item) {
        return $item !== 0;
      });
    }
    else {
      return [];
    }

    return $filteredActiveCurrency;
  }

  /**
   * If currency list changed Returns an array of deleted currencies.
   *
   * @return array
   *   An associative array with deleted or added currency.
   */
  public function checkCurrencyList(): array {
    $logMessage = 'There are no currency data on the server: ';
    $filteredActiveCurrency = $this->activeCurrency();
    $serverCurrency = $this->getCurrencyList();

    $returnArr = array_filter($filteredActiveCurrency, function ($item) use ($serverCurrency) {
      foreach ($serverCurrency as $currency => $value) {
        if ($currency == $item) {
          return FALSE;
        }
      }

      return TRUE;
    }
    );

    if (count($returnArr) > 0) {
      foreach ($returnArr as $currency) {
        $logMessage .= $currency . '; ';
      }

      $this->logError($this->t($logMessage));

    }
    return $returnArr;

  }

  /**
   * Returns only active currencies in the config form.
   *
   * @param array $currencyData
   *   An array with of currency exchange.
   *
   * @return array
   *   A filtered array with of currency exchange.
   */
  public function filterCurrency(array $currencyData): array {

    $filteredActiveCurrency = $this->activeCurrency();

    if ($currencyData && count($currencyData) > 0) {
      $returnCurrencyData = array_filter($currencyData, function ($item) use ($filteredActiveCurrency) {
        foreach ($filteredActiveCurrency as $active) {
          if ($item['cc'] === $active) {
            return TRUE;
          }

        }

        return FALSE;

      }
      );
    }
    else {
      return [];
    }

    return $returnCurrencyData;

  }

  /**
   * Send GET request to currency server.
   *
   * @return array
   *   An array with of currency exchange.
   */
  public function fetchData($uri, ?int $range = 0) {
    $uriTail = 'sort=exchangedate&order=desc&json';
    $dateFormat = 'Ymd';
    $today = date($dateFormat);
    $startDate = date($dateFormat, strtotime("-{$range} days"));

    if ($range && $range > 0) {
      $uriTail = "start=" . $startDate . "&end=" . $today . "&" . $uriTail;
    }

    $uri .= "?" . $uriTail;

    try {
      $response = $this->httpClient->get($uri)->getBody();
      $data = json_decode($response);
    }
    catch (\Exception $e) {
      throw new \Exception('Server not found');
    }

    return $data;

  }

  /**
   * Send GET request to currency server.
   *
   * @return array
   *   An array with of currency exchange.
   */
  public function getExchange(string $apiUri = NULL): array {
    $settings = $this->getExchangeSetting();
    $request = $settings['request'];
    $uri = $apiUri ? $apiUri : $settings['uri'];
    $range = $settings['range'] ?? 0;

    if (!$request || !$uri) {
      return [];
    }

    try {
      $data = $this->fetchData($uri, $range);
    }
    catch (\Exception $e) {
      return [];
    }
    return $data ?? [];

  }

  /**
   * Send GET request to currency server.
   *
   * @return array
   *   An array with of currency name.
   */
  public function getCurrencyList(string $uri = NULL) {
    $currencyData = $this->getExchange($uri);
    $currencyList = [];
    if ($currencyData && count($currencyData) > 0) {
      foreach ($currencyData as $item) {
        $currencyList[$item->cc] = $this->t((string) $item->txt);
      }
    }

    return $currencyList;

  }

  /**
   * Check is valid URI.
   *
   * @param string $uri
   *   Exchange API URI.
   *
   * @return array
   *   An associative array with two keys isValid:boolean, error:string|null.
   */
  public function isValidUri(string $uri) {

    $returnArr = [
      "isValid" => FALSE,
      "error" => NULL,
    ];

    if (trim($uri) == '') {
      $returnArr["error"] = $this->t('The server URI field is empty.');
      $this->logNotice($returnArr["error"]);
    }
    if (trim($uri) !== '') {
      try {
        $exchangeData = $this->fetchData($uri);
      }
      catch (\Exception $e) {
        $returnArr['error'] = $this->t('Server request error.');
        $this->logError($returnArr["error"]);
        return $returnArr;
      }

      if (!$exchangeData) {
        $returnArr["error"] = $this->t('The exchange server not found.');
        $this->logError($returnArr["error"]);
        return $returnArr;
      }

      foreach ($exchangeData as $item) {
        foreach ($this->validResponseData as $key) {
          if (!$item->$key) {
            $returnArr['error'] = $this->t('Server response data is invalid');
            $this->logError($returnArr["error"]);
            return $returnArr;
          }
        }
      }
    }

    $returnArr['isValid'] = TRUE;

    return $returnArr;
  }

}
