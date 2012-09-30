<?php
require('eBorica.php');

$eBorica = new eBorica();

$amount = 111;
$transaction_id = uniqid(true);
$description = 'Example transaction';

if (isset($_GET['eBorica']))
{
  $response_data = $eBorica->read_response($_GET['eBorica']);
  $transaction_id = $response_data['transaction_id'];
  $transaction_info = $eBorica->get_transaction_info($transaction_id);
  print_r($transaction_info);
}
else
{
  $eBorica->add_transaction($amount, $transaction_id, $description);
  $eBorica->run();
}

