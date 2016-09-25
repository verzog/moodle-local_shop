<?php

$string['ipnfortest'] = 'Test the IPN backcall with this transaction';
$string['enablepaypal'] = 'Paypal payment';
$string['enablepaypal2'] = 'Paypal payment';
$string['enablepaypal3'] = 'You choosed to pay using Paypal...';
$string['paypal'] = 'Paypal';
$string['paypalmsg'] = 'Thanks using Paypal for payment';
$string['pluginname'] = 'Paypal Pay Mode';
$string['paypalpaymodeparams'] = 'Paypal configuration options';
$string['paypalsellername'] = 'Paypal seller account';
$string['paypalsellertestname'] = 'Paypal seller test account (paypal sandbox)';
$string['selleritemname'] = 'Sales Service';
$string['sellertestitemname'] = 'Sales Service (test mode)';
$string['configpaypalsellername'] = 'The account id of the reseller';
$string['configselleritemname'] = 'An arbitrary label that allows sorting transactions against multiple selling services';
$string['paypalpaymodeinvoiceinfo'] = 'You have choosen Paypal as payment method.';

$string['door_transfer_text_tpl'] = '
<p><b>Card payment over Paypal:</b> 
We will transfer you on the Paypal portal
';

$string['print_procedure_text_tpl'] = '
<p>Follow the Paypal process untill end and come back to our seller site. You will be provided an invoice on return.
';

$string['pending_followup_text_tpl'] = '
<p>Your transaction has been accepted by our paiement partner. Your order will be automatically processed
on reception of the validation notification from your account holder. You will ne sent a last activation email
when done. Thank you again for your purchase.</p>

<p>In case the activation has not occured in the next 48 hours, contact us to check your situation.</p>
';

$string['success_followup_text_tpl'] = '
<p>Your transaction has been accepted by our paiement partner. Your order will be automatically processed
on reception of the validation notification from your account holder. You will ne sent a last activation email
when done. Thank you again for your purchase.</p>

<p>In case the activation has not occured in the next 48 hours, contact our sales service <%%SUPPORT%%>.</p>
';