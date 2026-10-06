<?php

namespace Database\Factories;

use App\Models\PaymentMethodInvoiceSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentMethodInvoiceSetting>
 */
class PaymentMethodInvoiceSettingFactory extends Factory
{
    protected $model = PaymentMethodInvoiceSetting::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['closing_day' => 10, 'due_day' => 15, 'effective_from' => today(), 'retired_at' => null];
    }
}
