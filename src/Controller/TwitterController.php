<?php

namespace Drupal\twitter_api\Controller;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 *
 */
class TwitterController extends ControllerBase {

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
   * Loads nodes.
   *
   * @var \Drupal\node\NodeStorage
   */
  private $nodeStorage;

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
    $this->nodeStorage = $entity_type_manager->getStorage('node');
    $this->termStorage = $entity_type_manager->getStorage('taxonomy_term');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('file_system'),
      $container->get('logger.factory')->get('twitter_api')
    );
  }

  /**
   * Get all unpublished quote.
   */
  public function getUnpublishedApprovedQuotes() {
    return $this->nodeStorage->loadByProperties([
      'type' => 'book_quote',
      'status' => 0,
      'field_approved' => TRUE,
    ]);
  }

  /**
   * Get all unpublished quote.
   */
  public function getUnpublishedUnapprovedQuotes() {
    return $this->nodeStorage->loadByProperties([
      'type' => 'book_quote',
      'status' => 0,
      'field_approved' => FALSE,
    ]);
  }

  /**
   * Get all quotes.
   */
  public function getQuotes() {
    return $this->nodeStorage->loadByProperties(['type' => 'book_quote']);
  }

  /**
   * Post most recent quote.
   */
  public function postNext(): void {
    $next = $this->getNext();
    $this->postQuote($next);
  }

  /**
   * Get next quote to be posted.
   */
  public function getNext() {
    $unpublished = $this->getUnpublishedApprovedQuotes();
    $sorted = [];
    foreach ($unpublished as $quote) {
      $weight = (int) $quote->get('field_weight')->getString();
      $sorted[$weight] = $quote;
    }
    ksort($sorted);
    return array_shift($sorted);
  }

  /**
   *
   */
  public function showNext() {
    /** @var \Drupal\node\Entity\Node $next */
    $next = $this->getNext();
    if ($next) {
      return  $next->getTitle();
    }
    return "No Title";
  }

  /**
   * Get content by node id.
   */
  public function getQuote(string $node_id = NULL): ?EntityInterface {
    return $this->nodeStorage->load($node_id);
  }

  /**
   * Post quote to Twitter.
   */
  public function postQuote($quote = NULL): array {
    if (!$quote) {
      $node_id = \Drupal::request()->query->get('node_id');
      $quote = $this->getQuote($node_id);
    }

    $number = -200;

    $post_content = $quote->get('title')->getString();

    $exchange = new TwitterApiExchange();
    $response = $exchange->post($post_content);
    $quote->set('field_response', Json::encode($response));

    if (!empty($response['errors'])) {
      $msg = $response['errors'][0]['message'];
      if ($msg !== 'Status is a duplicate.') {
        $quote->set('status', FALSE);
      }
      $quote->save();
      $this->logger->alert('Post error: ' . json_encode($response));
      return ['#markup' => $msg];
    }

    $quote->set('field_date_posted', $this->formatTime($response['created_at']));
    $quote->set('field_twitter_id', $response['id']);
    $quote->set('status', TRUE);
    $quote->set('field_approved', TRUE);
    $quote->save();

    // Comment to give submission credit.
    $submitted_by = $quote->get('field_submitted_by')->getString();
    $submitted_by = trim($submitted_by);
    $restricted_handles = ['@', 'HyperionQuotes', '@HyperionQuotes'];
    if (!empty($submitted_by) && !in_array($submitted_by, $restricted_handles)) {
      $comment = "Thanks for the submission $submitted_by!";
      $response = $exchange->postComment($comment, $response['id']);
      $this->logger->info('Post comment response: ' . json_encode($response),);
    }

    return ['#markup' => 'success'];
  }

  /**
   * Approve all unpublished quotes.
   */
  public function approveAll() {
    $quotes = $this->getUnpublishedUnApprovedQuotes();
    foreach ($quotes as $quote) {
      $quote->set('field_approved', TRUE);
      $quote->save();
    }
  }

  /**
   * Format time.
   */
  protected function formatTime(string $time) {
    $timestamp = strtotime($time);
    $date_posted = date(DateTimeItemInterface::DATETIME_STORAGE_FORMAT, $timestamp);
    $date_posted = new DrupalDateTime($date_posted);
    $date_posted->setTimezone(new \DateTimeZone('PST'));
    return $date_posted->format(DateTimeItemInterface::DATETIME_STORAGE_FORMAT);
  }

  /**
   * Reset weights to node id.
   */
  public function resetWeights(): void {
    $quotes = $this->getQuotes();

    foreach ($quotes as $id => $quote) {
      $quote->set('field_weight', $id);
      $quote->save();
    }
  }

  /**
   * Delete all twitter data.
   */
  public function resetTwitterData(): void {
    $quotes = $this->getQuotes();

    foreach ($quotes as $id => $quote) {
      $quote->set('field_date_posted', NULL);
      $quote->set('field_response', NULL);
      $quote->set('field_twitter_id', NULL);
      $quote->set('status', FALSE);
      $quote->save();
    }
  }

}
