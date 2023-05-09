<?php

namespace Drupal\green_money_exchange\Form;

use Drupal\Core\Ajax\MessageCommand;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\green_money_exchange\GreenExchangeService;

/**
 * Creates a configuration form for a GreenExchange block.
 */
class CustomConfigForm extends ConfigFormBase {

  /**
   * Custom exchange service.
   *
   * @var \Drupal\green_money_exchange\GreenExchangeService
   */
  protected $exchangeService;

  /**
   * Create config form from Green Exchange Block.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   Config Factory.
   * @param \Drupal\green_money_exchange\GreenExchangeService $exchangeService
   *   Exchange service.
   */
  public function __construct(ConfigFactoryInterface $config_factory, GreenExchangeService $exchangeService) {
    parent::__construct($config_factory);
    $this->exchangeService = $exchangeService;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('green_money_exchange.exchange')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'green_money_exchange.customconfig',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'green_money_exchange_config_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('green_money_exchange.customconfig');
    $currencyList = $this->exchangeService->getCurrencyList($form_state->getValue('uri'));

    $form['api_messages'] = [
      '#markup' => '<div id="form-api-messages"></div>',
    ];
    $form['settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Money Exchange'),
      '#open' => TRUE,
    ];
    $form['settings']['request'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Activate request to server'),
      '#default_value' => $config->get('request') ?? FALSE,
    ];
    $form['settings']['range'] = [
      '#type' => 'number',
      '#title' => $this->t('Exchange rate for the period in days'),
      '#default_value' => $config->get('range') ?? 1,
    ];
    $form['settings']['uri'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Enter Money Exchange service URI'),
      '#default_value' => $config->get('uri') ?? ' ',
      '#ajax' => [
        'callback' => '::ajaxCheckUri',
        'event' => 'change',
        'progress' => [
          'type' => 'throbber',
        ],
      ],
      '#maxlength' => NULL,
    ];
    $form['check_button'] = [
      '#type' => 'button',
      '#value' => $this->t('Check API'),
      '#ajax' => [
        'callback' => '::ajaxCheckApi',
        'event' => 'click',
        'progress' => [
          'type' => 'throbber',
        ],
      ],
    ];

    $form['get_checkboxes_api'] = [
      '#type' => 'button',
      '#value' => $this->t('Get Currency'),
      '#ajax' => [
        'callback' => '::ajaxGetCurrency',
        'wrapper' => 'cur-list',
        'event' => 'click',
        'progress' => [
          'type' => 'throbber',
        ],
      ],
    ];

    $form['currency-list'] = [
      '#type' => 'details',
      '#title' => $this->t('Select currencies to display'),
      '#open' => TRUE,
      '#attributes' => [
        'id' => 'cur-list',
      ],

    ];
    $form['currency-list']['currency-item'] = [
      '#type' => 'checkboxes',
      '#options' => $currencyList,
      '#title' => $this->t('The list of currencies is not available'),
      '#default_value' => $config->get('currency-item') ?? [],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * Show server returned data in MessageCommand.
   *
   * @param array $form
   *   Form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   Show API data thet server return.
   *
   * @throws \Exception
   */
  public function ajaxCheckApi(array &$form, FormStateInterface $form_state) {
    $ajax_response = new AjaxResponse();

    $data = $this->exchangeService->fetchData(trim($form_state->getValue('uri')));
    $showData = '';

    foreach ($data as $item) {
      $str = '';
      foreach ($item as $property => $value) {
        $str .= "<b>{$property} : </b>{$value}; ";
      }

      $showData .= "<div>" . "{ " . $str . " }," . "</div>";
    }

    $ajax_response->addCommand(new MessageCommand($showData));

    return $ajax_response;
  }

  /**
   * Show message is URI valid.
   *
   * @param array $form
   *   Form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   Return Ajax MessageCommand.
   */
  public function ajaxCheckUri(array &$form, FormStateInterface $form_state) {
    $ajax_response = new AjaxResponse();
    $uri = trim($form_state->getValue('uri'));

    if ($uri == '') {
      return $ajax_response;
    }

    try {
      $data = $this->exchangeService->fetchData(trim($form_state->getValue('uri')));
      if (count($data) > 0) {
        $ajax_response->addCommand(new MessageCommand($this->t("API uri is valid.")));
      }

    }
    catch (\Exception $e) {
      $ajax_response->addCommand(new MessageCommand($this->t('Exchange service is ERROR!!!'), NULL, ['type' => 'error']));

    }
    return $ajax_response;
  }

  /**
   * Rerender checkboxes ajax.
   *
   * @param array $form
   *   Form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   Return $form['currency-list'].
   */
  public function ajaxGetCurrency(array &$form, FormStateInterface $form_state) {
    return $form['currency-list'];
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);

    $isRemovedCurrency = $this->exchangeService->checkCurrencyList();

    $this->config('green_money_exchange.customconfig')
      ->set('request', $form_state->getValue('request'))
      ->set('range', $form_state->getValue('range'))
      ->set('uri', trim($form_state->getValue('uri')))
      ->set('currency-item', $form_state->getValue('currency-item'))
      ->save();

    $this->exchangeService->clearCurrencyState();

    if (count($isRemovedCurrency) > 0) {
      foreach ($isRemovedCurrency as $deletedCurrency) {
        $this->messenger->addWarning("Currency is deleted {$deletedCurrency}");
      }
    }

  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {

    $isUriError = $this->exchangeService->isValidUri($form_state->getValue('uri'));

    if (!$isUriError['isValid']) {
      $form_state
        ->setErrorByName('uri', $this
          ->t((string) $isUriError['error']));
    }

    if ($form_state->getValue('range') < 0) {
      $form_state->setErrorByName('range', $this
        ->t('Exchange rate for the period in
        days must be greater than or equal to 1'));
    }
  }

}
