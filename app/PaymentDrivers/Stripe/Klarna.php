<?php

/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2023. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace App\PaymentDrivers\Stripe;

use App\Exceptions\PaymentFailed;
use App\Jobs\Util\SystemLogger;
use App\Models\GatewayType;
use App\Models\Payment;
use App\Models\PaymentType;
use App\Models\SystemLog;
use App\PaymentDrivers\StripePaymentDriver;
use App\Utils\Number;
use Illuminate\Support\Facades\Cache;

class Klarna
{
    /** @var StripePaymentDriver */
    public StripePaymentDriver $stripe;

    public function __construct(StripePaymentDriver $stripe)
    {
        $this->stripe = $stripe;
    }

    public function authorizeView($data)
    {
        return render('gateways.stripe.klarna.authorize', $data);
    }

    public function paymentView(array $data)
    {
        $this->stripe->init();

        $data['gateway'] = $this->stripe;
        $data['return_url'] = $this->buildReturnUrl();
        $data['stripe_amount'] = $this->stripe->convertToStripeAmount($data['total']['amount_with_fee'], $this->stripe->client->currency()->precision, $this->stripe->client->currency());
        $data['client'] = $this->stripe->client;
        $data['customer'] = $this->stripe->findOrCreateCustomer()->id;
        $data['country'] = $this->stripe->client->country->iso_3166_2;

        $amount = $data['total']['amount_with_fee'];

        $invoice_numbers = collect($data['invoices'])->pluck('invoice_number');

        if ($invoice_numbers->count() > 0) {
            $description = ctrans('texts.stripe_payment_text', ['invoicenumber' => $invoice_numbers->implode(', '), 'amount' => Number::formatMoney($amount, $this->stripe->client), 'client' => $this->stripe->client->present()->name()], $this->stripe->client->company->locale());
        } else {
            $description = ctrans('texts.stripe_payment_text_without_invoice', ['amount' => Number::formatMoney($amount, $this->stripe->client), 'client' => $this->stripe->client->present()->name()], $this->stripe->client->company->locale());
        }

        $intent = \Stripe\PaymentIntent::create([
            'amount' => $data['stripe_amount'],
            'currency' => $this->stripe->client->getCurrencyCode(),
            'payment_method_types' => ['klarna'],
            'customer' => $this->stripe->findOrCreateCustomer(),
            'description' => $description,
            'metadata' => [
                'payment_hash' => $this->stripe->payment_hash->hash,
                'gateway_type_id' => GatewayType::KLARNA,
            ],
        ], array_merge($this->stripe->stripe_connect_auth, ['idempotency_key' => uniqid("st",true)]));

        $data['pi_client_secret'] = $intent->client_secret;

        $this->stripe->payment_hash->data = array_merge((array) $this->stripe->payment_hash->data, ['stripe_amount' => $data['stripe_amount']]);
        $this->stripe->payment_hash->save();

        return render('gateways.stripe.klarna.pay', $data);
    }

    private function buildReturnUrl(): string
    {
        return route('client.payments.response', [
            'company_gateway_id' => $this->stripe->company_gateway->id,
            'payment_hash' => $this->stripe->payment_hash->hash,
            'payment_method_id' => GatewayType::KLARNA,
        ]);
    }

    public function paymentResponse($request)
    {
        $this->stripe->payment_hash->data = array_merge((array) $this->stripe->payment_hash->data, $request->all());
        $this->stripe->payment_hash->save();

        if (in_array($request->redirect_status,  ['succeeded','pending'])) {
            return $this->processSuccessfulPayment($request->payment_intent);
        }

        return $this->processUnsuccessfulPayment();
    }

    public function processSuccessfulPayment(string $payment_intent)
    {

        $this->stripe->init();

        //catch duplicate submissions.
        if (Payment::where('transaction_reference', $payment_intent)->exists()) {
            return redirect()->route('client.payments.index');
        }

        $data = [
            'payment_method' => $payment_intent,
            'payment_type' => PaymentType::KLARNA,
            'amount' => $this->stripe->convertFromStripeAmount($this->stripe->payment_hash->data->stripe_amount, $this->stripe->client->currency()->precision, $this->stripe->client->currency()),
            'transaction_reference' => $payment_intent,
            'gateway_type_id' => GatewayType::KLARNA,
        ];

        $this->stripe->createPayment($data, Payment::STATUS_PENDING);

        SystemLogger::dispatch(
            ['response' => $this->stripe->payment_hash->data, 'data' => $data],
            SystemLog::CATEGORY_GATEWAY_RESPONSE,
            SystemLog::EVENT_GATEWAY_SUCCESS,
            SystemLog::TYPE_STRIPE,
            $this->stripe->client,
            $this->stripe->client->company,
        );

        return redirect()->route('client.payments.index');
    }

    public function processUnsuccessfulPayment()
    {
        $server_response = $this->stripe->payment_hash->data;

        $this->stripe->sendFailureMail($server_response);

        $message = [
            'server_response' => $server_response,
            'data' => $this->stripe->payment_hash->data,
        ];

        SystemLogger::dispatch(
            $message,
            SystemLog::CATEGORY_GATEWAY_RESPONSE,
            SystemLog::EVENT_GATEWAY_FAILURE,
            SystemLog::TYPE_STRIPE,
            $this->stripe->client,
            $this->stripe->client->company,
        );

        throw new PaymentFailed(ctrans('texts.gateway_error'), 500);
    }
}