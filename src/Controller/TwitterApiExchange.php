<?php

namespace Drupal\twitter_api\Controller;

use Drupal\Component\Serialization\Json;
use Exception;

/**
 * Adapted from:.
 *
 * Twitter-API-PHP : Simple PHP wrapper for the v1.1 API.
 *
 * PHP version 5.3.10
 *
 * @category Awesomeness
 * @package Twitter-API-PHP
 * @author James Mallison <me@j7mbo.co.uk>
 * @license MIT License
 * @version 1.0.4
 * @link http://github.com/j7mbo/twitter-api-php
 */
class TwitterApiExchange {

  /**
   * Oauth Access Token.
   *
   * @var string
   */
  private $oauthAccessToken;

  /**
   * Oauth access token secret.
   *
   * @var string
   */
  private $oauthAccessTokenSecret;

  /**
   * Consumer key.
   *
   * @var string
   */
  private $consumerKey;


  /**
   * Consumer secret.
   *
   * @var string
   */
  private $consumerSecret;

  /**
   * POST fields.
   *
   * @var array
   */
  private $postFields;

  /**
   * GET fields.
   *
   * @var string
   */
  private $getField;

  /**
   * Oauth.
   *
   * @var mixed
   */
  protected $oauth;

  /**
   * Url.
   *
   * @var string
   */
  public $url;


  /**
   * Request Method.
   *
   * @var string
   */
  public $requestMethod;

  /**
   * The HTTP status code from the previous request.
   *
   * @var int
   */
  protected $httpStatusCode;

  /**
   * Create the API access object.
   *
   * Get creds from dev.twitter.com.
   */
  public function __construct() {
    $this->consumerKey = \Drupal::config('twitter_api.settings')->get('consumerKey');
    $this->consumerSecret = \Drupal::config('twitter_api.settings')->get('consumerSecret');
    $this->oauthAccessToken = \Drupal::config('twitter_api.settings')->get('oauth_access_token');
    $this->oauthAccessTokenSecret = \Drupal::config('twitter_api.settings')->get('oauth_access_token_secret');

    $this->getField = '';
    $this->postFields = [];
  }

  /**
   * Post to Twitter.
   */
  public function post(string $post) {
    $this->setPostfields(['status' => $post]);
    $this->buildOauth('https://api.twitter.com/1.1/statuses/update.json', 'POST');
    return $this->performRequest();
  }
  /**
   * Post to Twitter.
   */
  public function postComment(string $post, string $id) {
    $this->setPostfields(['status' => $post, 'in_reply_to_status_id' => $id]);
    $this->buildOauth('https://api.twitter.com/1.1/statuses/update.json', 'POST');
    return $this->performRequest();
  }
  /**
   * Set postFields array, example: array('screen_name' => 'J7mbo')
   */
  public function setPostfields(array $fields) {
    // Encode '@' char.
    if (!empty($fields['status']) && strpos($fields['status'], '@') === 0) {
      $fields['status'] = sprintf("\0%s", $fields['status']);
    }

    // Convert bool to string.
    foreach ($fields as $key => &$value) {
      if (is_bool($value)) {
        $value = ($value === TRUE) ? 'true' : 'false';
      }
    }
    unset($value);

    $this->postFields = $fields;

    // Rebuild oAuth.
    if (!empty($this->oauth['oauth_signature'])) {
      $this->buildOauth($this->url, $this->requestMethod);
    }
  }

  /**
   * Set getfield string, example: '?screen_name=J7mbo'.
   *
   * @param string $string
   *   Get key and value pairs as string.
   *
   * @throws \Exception
   */
  public function setGetField(string $string) {
    if (!is_null($this->getPostfields())) {
      throw new Exception('You can only choose get OR post / post fields.');
    }

    $get_fields = preg_replace('/^\?/', '', explode('&', $string));
    $params = [];

    foreach ($get_fields as $field) {
      if ($field !== '') {
        [$key, $value] = explode('=', $field);
        $params[$key] = $value;
      }
    }

    $this->getField = '?' . http_build_query($params, '', '&');

    return $this;
  }

  /**
   * Get getfield string (simple getter)
   */
  public function getGetField(): string {
    return $this->getField;
  }

  /**
   * Get postfields array (simple getter)
   */
  public function getPostfields(): array {
    return $this->postFields;
  }

  /**
   * Build the Oauth object using params set in construct and additionals
   * passed to this method. For v1.1, see:
   * https://dev.twitter.com/docs/api/1.1.
   *
   * @param string $url
   *   The API url to use. Example:
   *   https://api.twitter.com/1.1/search/tweets.json.
   *
   * @throws \Exception
   */
  public function buildOauth(string $url, string $requestMethod) {
    $requestMethod = strtoupper($requestMethod);
    if (!in_array($requestMethod, ['DELETE', 'GET', 'POST', 'PUT'])) {
      throw new \RuntimeException('Request method must be either POST, GET or PUT or DELETE');
    }

    $oauth = [
      'oauth_consumer_key' => $this->consumerKey,
      'oauth_nonce' => time(),
      'oauth_signature_method' => 'HMAC-SHA1',
      'oauth_timestamp' => time(),
      'oauth_token' => $this->oauthAccessToken,
      'oauth_version' => '1.0',
    ];

    $get_field = $this->getGetField();

    if (!empty($get_field)) {
      $get_fields = str_replace('?', '', explode('&', $get_field));

      foreach ($get_fields as $g) {
        $split = explode('=', $g);

        // In case a null is passed through.
        if (isset($split[1])) {
          $oauth[$split[0]] = urldecode($split[1]);
        }
      }
    }

    $post_fields = $this->getPostfields();
    foreach ($post_fields as $key => $value) {
      $oauth[$key] = $value;
    }

    $base_info = $this->buildBaseString($url, $requestMethod, $oauth);
    $composite_key = rawurlencode($this->consumerSecret) . '&' . rawurlencode($this->oauthAccessTokenSecret);
    $oauth_signature = base64_encode(hash_hmac('sha1', $base_info, $composite_key, TRUE));
    $oauth['oauth_signature'] = $oauth_signature;

    $this->url = $url;
    $this->requestMethod = $requestMethod;
    $this->oauth = $oauth;
  }

  /**
   * Perform the actual data retrieval from the API.
   *
   * @throws \Exception
   */
  public function performRequest(array $curl_options = []): array {
    $header = [$this->buildAuthorizationHeader($this->oauth), 'Expect:'];

    $get_field = $this->getGetField();
    $post_fields = $this->getPostfields();

    if (in_array(strtolower($this->requestMethod), ['put', 'delete'])) {
      $curl_options[CURLOPT_CUSTOMREQUEST] = $this->requestMethod;
    }

    $options = $curl_options + [
      CURLOPT_HTTPHEADER => $header,
      CURLOPT_HEADER => FALSE,
      CURLOPT_URL => $this->url,
      CURLOPT_RETURNTRANSFER => TRUE,
      CURLOPT_TIMEOUT => 10,
    ];

    if (!is_null($post_fields)) {
      $options[CURLOPT_POSTFIELDS] = http_build_query($post_fields, '', '&');
    }
    else {
      if ($get_field !== '') {
        $options[CURLOPT_URL] .= $get_field;
      }
    }

    $feed = curl_init();
    curl_setopt_array($feed, $options);
    $json = curl_exec($feed);

    $this->httpStatusCode = curl_getinfo($feed, CURLINFO_HTTP_CODE);

    if (!empty($error = curl_error($feed))) {
      curl_close($feed);
      throw new \RuntimeException($error);
    }

    curl_close($feed);
    return Json::decode($json);
  }

  /**
   * Private method to generate the base string used by cURL.
   */
  private function buildBaseString(string $base_uri, string $method, array $params): string {
    $encoded_params = [];
    ksort($params);

    foreach ($params as $key => $value) {
      $encoded_params[] = rawurlencode($key) . '=' . rawurlencode($value);
    }

    return $method . "&" . rawurlencode($base_uri) . '&' . rawurlencode(implode('&', $encoded_params));
  }

  /**
   * Private method to generate authorization header used by cURL.
   */
  private function buildAuthorizationHeader(array $oauth): string {
    $acceptable_keys = [
      'oauth_consumer_key',
      'oauth_nonce',
      'oauth_signature',
      'oauth_signature_method',
      'oauth_timestamp',
      'oauth_token',
      'oauth_version',
    ];

    foreach ($oauth as $key => $value) {
      if (in_array($key, $acceptable_keys, TRUE)) {
        $values[] = "$key=\"" . rawurlencode($value) . "\"";
      }
    }

    return 'Authorization: OAuth ' . implode(', ', $values ?? []);
  }

  /**
   * Helper method to perform our request.
   */
  public function request(string $url, string $method = 'get', string $data = NULL, array $curlOptions = []): string {
    if (strtolower($method) === 'get') {
      $this->setGetField($data);
    }
    else {
      $this->setPostfields($data);
    }

    return $this->buildOauth($url, $method)->performRequest(TRUE, $curlOptions);
  }

  /**
   * Get the HTTP status code for the previous request.
   */
  public function getHttpStatusCode(): int {
    return $this->httpStatusCode;
  }

}
