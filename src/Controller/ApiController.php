<?php

namespace Drupal\twitter_api\Controller;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\File\FileSystemInterface;
use DateTimeZone;
use Drupal\Core\Url;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
use Psr\Log\LoggerInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Holds processors for API response.
 */
class ApiController extends ControllerBase {

  /**
   * File system.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  private $fileSystem;

  /**
   * Logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;



  /**
   * SMPostsSettingsForm constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   Service: 'entity_type.manager'.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   Service: 'file_system'.
   * @param \Psr\Log\LoggerInterface $logger
   *   Service: 'logger.factor'.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, FileSystemInterface $file_system, LoggerInterface $logger) {
    $this->entityManager = $entity_type_manager;
    $this->fileSystem = $file_system;
    $this->logger = $logger;
    $this->termStorage = $entity_type_manager->getStorage('taxonomy_term');

  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('file_system'),
      $container->get('logger.factory')->get('action')
    );
  }

  /**
   * Get access token.
   */
  public function getAccessToken() : string {
    $config = self::config('twitter_api.settings');
    return $config->get('access_token') ?? '';
  }


  /**
   * Queries Instagram API.
   */
  private function queryApi(string $access_token) : array {
    $client = new Client();
    $api_url = Url::fromUri('https://api.instagram.com/v1/users/self/media/recent/', [
      'query' => ['access_token' => $access_token],
    ]);

    $this->logger->notice(Link::fromTextAndUrl(
      'Instagram Catalogue queried: ' . $api_url->toString(),
      $api_url
    )->toString());

    try {
      $api_response = $client->get($api_url->toString(), ['headers' => ['Accept' => 'application/json']]);
      $response_body = $api_response->getBody();
    }
    catch (ClientException $e) {
      $this->messenger()->addError($e->getMessage());
    }

    return Json::decode($response_body ?? '') ?? [];
  }

  /**
   * Process and save data retrieved from Instagram API.
   */
  public function processApiResponse(array $api_response) {

  }

  /**
   * Convert Unix timestamp to time in hopefully the right timezone.
   */
  public function convertTimestampToDate(int $timestamp) : DrupalDateTime {
    $date_posted = date(DateTimeItemInterface::DATETIME_STORAGE_FORMAT, $timestamp);
    $date_posted = new DrupalDateTime($date_posted);
    $date_posted->setTimezone(new DateTimeZone('PST'));
    return $date_posted;
  }

  /**
   * Deletes all data saved from Instagram.
   */
  public function deleteAllPostData() {

  }

}
