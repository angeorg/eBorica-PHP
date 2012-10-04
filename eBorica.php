<?php
/**
 * This file contains PHP classes that can be used
 * to interact with the eBorica's payment gateway API
 *
 * (C) 2012 Anton Georgiev. All rights reserved.
 * This work is licensed under a Creative Commons Attribution 3.0 Unported License.
 * To view a copy of this license, visit http://creativecommons.org/licenses/by/3.0/
 */

interface eBoricaActions
{
  public function set_terminal($terminal_id);
  public function set_language($language);
  public function set_currency($currency);
  public function add_transaction($amount, $transaction_id, $description);
  public function read_response($req);
  public function get_transaction_info($request);
  public function run();
}

/**
 * eBorica Payments PHP class
 *
 * @version 0.1.1-dev
 * @copyright Anton Georgiev (https://github.com/angeorg/eBorica-PHP)
 * @author Anton Georgiev
 * 
 */
class eBorica implements eBoricaActions
{
  // Set the default options that won't be changed often
  protected $default_options = array(
    'borica_url' => 'https://gate.borica.bg/boreps/',
    'private_key' => '/path/to/private_key.key',
    'private_key_pass' => '',
    'certificate' => '/path/to/certificate.cer',
    'terminal_id' => '10000000',
    'protocol_version' => '1.1',
    'language' => 'US',
    'currency' => 'USD',
    'my_pem_file' => '/path/to/my.pem',
    'server_pem_file' => '/path/to/server.pem',
    'pem_password' => ''
  );

  // Set the allowed transaction types
  public static $transaction_types = array(10, 21, 22, 23, 31, 32, 33, 34, 40);

  // Set the response codes
  public static $response_codes = array(
      '00' => 'OK',
      '13' => 'Expired card',
      '85' => 'Reversal already exists',
      '86' => 'Transaction already exists',
      '87' => 'Wrong protocol version',
      '88' => 'No BOReq parameter',
      '89' => 'Missing transaction',
      '90' => 'Invalid card',
      '91' => 'Timeout',
      '92' => 'Invalid eBorica request',
      '93' => 'Invalid 3D authentication',
      '94' => 'Canceled transaction',
  );

  // Set the allowed currencies (protocol version 1.1)
  public static $currency = array('USD', 'BGN');

  // Set the allowed eBorica interface's languages
  public static $language = array('US', 'BG');

  protected $request, $response, $signature, $transaction_code,
      $amount, $action, $transaction_id, $description;

  /**
   * Constructor
   *
   * @return void
   */
  public function __construct()
  {
    $this->options = $this->default_options;
  }

  /**
   * Set the terminal id
   *
   * @param integer $terminal_id Set terminal's id
   * @return void
   */
  public function set_terminal($terminal_id)
  {
    $this->options['terminal_id'] = $terminal_id;
  }

  /**
   * Set the eBorica gateway interface language
   *
   * @param string $language Language
   * @return void
   */
  public function set_language($language)
  {
    $this->options['language'] = $language;
  }

  /**
   * Set the transaction currency
   *
   * @param string $currency Currency
   * @return void
   */
  public function set_currency($currency)
  {
    $this->options['currency'] = $currency;
  }

  /**
   * Add a new transaction
   *
   * @param integer $amount Amount
   * @param string $transaction_id Transaction id
   * @param string $description Transaction description
   * @return void
   */
  public function add_transaction($amount, $transaction_id, $description)
  {
    $this->action = 'registerTransaction';
    $this->transaction_code = 10;
    $this->amount = $amount * 100;
    $this->transaction_id = $transaction_id;
    $this->description = $description;
  }

  /**
   * Set the transaction details
   *
   * @param string $transaction_id Transaction id
   * @return void
   */
  protected function transaction_info_request($transaction_id)
  {
    $this->action = 'transactionStatusReport';
    $this->transaction_code = 10;
    $this->transaction_id = $transaction_id;
  }

  /**
   * Get the transaction info
   *
   * @param string $transaction_id Transaction unique id
   * @return string
   */
  public function get_transaction_info($transaction_id)
  {
    $this->transaction_info_request($transaction_id);
    $request = $this->generate_request();
    $response = $this->get_response($request);
    return $this->read_response($response);
  }

  /**
   * Set the details for the transaction that will be canceled
   *
   * @param string $transaction_id Transaction id
   * @param integer $amount Amount
   * @param string $description Transaction description
   * @return void
   */
  public function set_cancel_transaction_info($transaction_id, $amount, $description)
  {
    $this->action = 'manageTransaction';
    $this->transaction_code = 40;
    $this->transaction_id = $transaction_id;
    $this->description = $description;
    $this->amount = $amount * 100;
  }

  /**
   * Cancel transaction (Reversal)
   *
   * @param string $transaction_id Transaction id
   * @param integer $amount Amount
   * @param string $description Transaction description
   * @return string
   */
  public function cancel_transaction($transaction_id, $amount,
      $description = 'Cancel transaction')
  {
    $this->set_cancel_transaction_info($transaction_id, $amount, $description);
    $request = $this->generate_request();
    $response = $this->get_response($request);
    return $this->read_response($response);
  }

  /**
   * Check the transaction request for errors
   *
   * @return void
   */
  protected function check_request_for_errors()
  {
    if (!in_array($this->transaction_code, self::$transaction_types))
    {
      throw new eBoricaException('Invalid transaction code!');
    }
    if ($this->amount
        && (!is_numeric($this->amount) || strlen($this->amount) > 12))
    {
      throw new eBoricaException('Invalid amount!');
    }
    if (!is_numeric($this->options['terminal_id'])
        || strlen($this->options['terminal_id']) != 8)
    {
      throw new eBoricaException('Invalid terminal id!');
    }
    if (strlen($this->transaction_id) > 15)
    {
      throw new eBoricaException('Invalid transaction id!');
    }
    if (strlen($this->description) > 125)
    {
      throw new eBoricaException('Invalid description!');
    }
    if (!ctype_alpha($this->options['language'])
        || strlen($this->options['language']) != 2)
    {
      throw new eBoricaException('Invalid language!');
    }
    if (strlen($this->options['protocol_version']) != 3)
    {
      throw new eBoricaException('Invalid protocol!');
    }
    if (!in_array($this->options['currency'], self::$currency))
    {
      throw new eBoricaException('Invalid currency!');
    }
    if (!in_array($this->options['language'], self::$language))
    {
      throw new eBoricaException('Invalid language!');
    }
    if (strlen($this->signature) != 128)
    {
      throw new eBoricaException('Invalid signature!');
    }
  }

  /**
   * Sign the transaction request with the private key
   *
   * @param string $request Transaction's request
   * @return void
   */
  protected function sign_request($request)
  {
    if (!file_exists($this->options['certificate'])
        || !is_readable($this->options['certificate']))
    {
      throw eBoricaException('Invalid certificate file!');
    }
    $priv_key = file_get_contents($this->options['private_key']);
    $private_key_id = openssl_get_privatekey($priv_key,
        $this->options['private_key_pass']);
    openssl_sign($request, $this->signature, $private_key_id);
    openssl_free_key($private_key_id);
  }

  /**
   * Generate the transaction request and check for input errors
   *
   * @return string
   */
  protected function generate_request()
  {
    $request = $this->transaction_code;
    $request .= date('YmdHis', mktime());
    $request .= str_pad($this->amount, 12, 0, STR_PAD_LEFT);
    $request .= $this->options['terminal_id'];
    $request .= str_pad($this->transaction_id, 15);
    $request .= str_pad($this->description, 125);
    $request .= $this->options['language'];
    $request .= $this->options['protocol_version'];
    $request .= $this->options['currency'];
    $this->sign_request($request);
    $request .= $this->signature;
    $request = urlencode(base64_encode($request));
    $this->check_request_for_errors();
    return $this->options['borica_url'] . $this->action
      . '?eBorica=' . $request;
  }

  /**
   * Get the generated request and redirect to the eBorica's payment gateway
   *
   * @return void
   */
  public function run()
  {
    $request = $this->generate_request();
    header('Location: ' . $request);
    exit();
  }

  /**
   * Sign the eBorica response with the generated .cer file
   *
   * @param string $message eBorica GET response
   * @return string
   */
  protected function sign_response($message)
  {
    if (!file_exists($this->options['certificate'])
        || !is_readable($this->options['certificate']))
    {
      throw new eBoricaException('Invalid certification file!');
    }
    $cert = file_get_contents($this->options['certificate']);
    $public_key_id = openssl_get_publickey($cert);
    $response = openssl_verify(substr($message, 0, strlen($message)-128),
        substr($message, 56, 128), $public_key_id);
    openssl_free_key($public_key_id);
    return $response;
  }

  /**
   * Read the eBorica response
   *
   * @param string $message eBorica GET response
   * @return array
   */
  public function read_response($message)
  {
    $message = base64_decode($message);
    return array('transaction_code' => substr($message, 0, 2),
        'transaction_time' => substr($message, 2, 14),
        'amount' => substr($message, 16, 12),
        'terminal_id' => substr($message, 28, 8),
        'transaction_id' => substr($message, 36, 15),
        'response_code' => substr($message, 51, 2),
        'protocol_version' => substr($message, 53, 3),
        'signature' => substr($message, 56, 128),
        'signature_status' => $this->sign_response($message),
    );
  }

  /**
   * Fetch the generated request and return the response from eBorica
   *
   * @param string $url Generated HTTP request
   * @return string
   */
  protected function get_response($url)
  {
    $ch = curl_init ($url);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_CAINFO, $this->options['server_pem_file']);
    curl_setopt($ch, CURLOPT_SSLCERT, $this->options['my_pem_file']);
    curl_setopt($ch, CURLOPT_SSLCERTPASSWD, $this->options['pem_password']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
    $data = curl_exec($ch);
    if (curl_error($ch))
    {
      throw new eBoricaException('CURL authentication failed!');
    }
    curl_close ($ch);
    return $data;
  }

}

/**
 * eBorica Payments Exception Class
 *
 */
class eBoricaException extends Exception
{
  public function __construct($message)
  {
    echo $message;
  }
}