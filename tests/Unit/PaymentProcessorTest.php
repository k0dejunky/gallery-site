<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Models\PaymentProcessor;
use PHPUnit\Framework\TestCase;

final class PaymentProcessorTest extends TestCase
{
    public function testMaskSecret(): void
    {
        $this->assertSame('', PaymentProcessor::maskSecret(''));
        $this->assertSame('*********', PaymentProcessor::maskSecret('short-key'));
        $masked = PaymentProcessor::maskSecret('031nicksdf+q433*72381lj');
        $this->assertStringStartsWith('031nic', $masked);
        $this->assertStringEndsWith('81lj', $masked);
        $this->assertStringContainsString('********', $masked);
        $this->assertStringNotContainsString('q433', $masked);
    }

    public function testBuildConfigMergesKeepSavedValues(): void
    {
        $existing = [
            'config_json' => json_encode(['merchant_id' => 'mid-1', 'public_key' => 'pk', 'private_key' => 'sk', 'plan_id' => 'gold_monthly']),
        ];

        // Change only the plan id; blank secrets must not wipe the saved ones.
        $built = PaymentProcessor::buildConfig('braintree', [
            'merchant_id' => '',
            'public_key'  => '',
            'private_key' => '',
            'plan_id'     => 'platinum_monthly',
        ], $existing);

        $json  = json_decode((string) $built, true);
        $this->assertIsArray($json);
        $this->assertSame('mid-1', $json['merchant_id']);
        $this->assertSame('sk', $json['private_key']);
        $this->assertSame('platinum_monthly', $json['plan_id']);
    }

    public function testBuildConfigIgnoresMaskedPlaceholder(): void
    {
        $existing = ['config_json' => json_encode(['private_key' => 'realsecret123456'])];

        $built = PaymentProcessor::buildConfig('braintree', [
            'private_key' => '**..**56123456',
            'public_key'  => 'pk-new',
        ], $existing);

        $json = json_decode((string) $built, true);
        $this->assertSame('realsecret123456', $json['private_key']);
        $this->assertSame('pk-new', $json['public_key']);
    }

    public function testLabelsAndMode(): void
    {
        $this->assertSame('Braintree', PaymentProcessor::providerLabel('braintree'));
        $this->assertSame('PayPal', PaymentProcessor::providerLabel('paypal'));
        $this->assertSame('Live', PaymentProcessor::modeLabel('live'));
        $this->assertSame('Test', PaymentProcessor::modeLabel('sandbox'));
        $this->assertSame(['merchant_id', 'public_key', 'private_key', 'plan_id'], array_keys(PaymentProcessor::configFields('braintree')));
    }
}