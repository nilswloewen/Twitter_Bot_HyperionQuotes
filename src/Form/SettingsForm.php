<?php

namespace Drupal\twitter_api\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\twitter_api\Controller\TwitterController;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Creates a text input and checkbox form for module settings.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The HTTP client to fetch the feed data with.
   *
   * @var \GuzzleHttp\Client
   */
  protected $httpClient;

  /**
   * Shortcuts for entity manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityManager;

  /**
   * Handles dir creation.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * Handles api queries.
   *
   * @var \Drupal\twitter_api\Controller\ApiController
   */
  protected $controller;

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['twitter_api.settings'];
  }

  /**
   * SettingsForm constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   Service 'config.factory'.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   Service: 'entity_type.manager'.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   Service: 'file_system'.
   */
  public function __construct(ConfigFactoryInterface $config_factory, EntityTypeManagerInterface $entity_type_manager, FileSystemInterface $file_system) {
    parent::__construct($config_factory);
    $this->entityManager = $entity_type_manager;
    $this->fileSystem = $file_system;
    $this->controller = new TwitterController($this->entityManager, $this->fileSystem, $this->logger('twitter_api'));
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
      $container->get('file_system')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'twitter_api_settings_form';
  }

  /**
   * Build elements for access token and upload personal data file.
   */
  public function buildForm(array $form, FormStateInterface $form_state) : array {
    $config = $this->config('twitter_api.settings');
    $form = parent::buildForm($form, $form_state);

    $form['post_next'] = [
      '#type' => 'submit',
      '#value' => 'Post Next',
      '#name' => 'post_next',
    ];
    $form['show_next'] = [
      '#type' => 'submit',
      '#value' => 'Show Next',
      '#name' => 'show_next',
    ];


    $form['approve_all'] = [
      '#type' => 'submit',
      '#value' => 'Approve All non-published',
      '#name' => 'approve_all',
    ];
    $form['reset_weights'] = [
      '#type' => 'submit',
      '#value' => 'Reset weights',
      '#name' => 'reset_weights',
    ];

    $form['reset_twitter_data'] = [
      '#type' => 'submit',
      '#value' => 'Reset Twitter Data',
      '#name' => 'reset_twitter_data',
    ];

    $form['next'] = [
      '#markup' => $this->controller->showNext(),
    ];

    $form['keys'] = [
      'consumerKey' => [
        '#type' => 'textfield',
        '#title' => 'Consumer Key',
        '#default_value' => $config->get('consumerKey'),
      ],
      'consumerSecret' => [
        '#type' => 'textfield',
        '#title' => 'Consumer Secret',
        '#default_value' => $config->get('consumerSecret'),
      ],
      'oauth_access_token' => [
        '#type' => 'textfield',
        '#title' => 'Oauth Access Token',
        '#default_value' => $config->get('oauth_access_token'),
      ],
      'oauth_access_token_secret' => [
        '#type' => 'textfield',
        '#title' => 'Oauth Access Token Secret',
        '#default_value' => $config->get('oauth_access_token_secret'),
      ],
    ];


    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) : void {
    $trigger = $form_state->getTriggeringElement();
    switch ($trigger['#name']) {
      case 'reset_weights':
        $this->controller->resetWeights();
        break;

      case 'post_next':
        $this->controller->postNext();
        break;

      case 'reset_twitter_data':
        $this->controller->resetTwitterData();
        break;

      case 'approve_all':
        $this->controller->approveAll();
        break;

      case 'show_next':
        $this->controller->showNext();
        break;

      default:
        $config = $this->config('twitter_api.settings');
        $config->set('consumerKey', $form_state->getValue('consumerKey'));
        $config->set('consumerSecret', $form_state->getValue('consumerSecret'));
        $config->set('oauth_access_token', $form_state->getValue('oauth_access_token'));
        $config->set('oauth_access_token_secret', $form_state->getValue('oauth_access_token_secret'));
        $config->save();
    }

    parent::submitForm($form, $form_state);
  }

}
