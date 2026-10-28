<?php

namespace App\Mcp\Tools\Finance;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

/**
 * The subscription fields shared by create_subscription and update_subscription.
 */
final class SubscriptionSchema
{
    /**
     * @return array<string, Type>
     */
    public static function fields(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->max(100)->description('What the user calls it, e.g. "Hetzner".'),
            'payee_id' => $schema->integer()->nullable()->description('Match charges with this payee (from list_payees). Preferred over description_match.'),
            'description_match' => $schema->string()->nullable()->max(200)->description('Without a payee: match charges whose description contains these words (compared lowercased, letters only, e.g. "ente io").'),
            'account_id' => $schema->integer()->nullable()->description('Only match charges in this account. Null for any account.'),
            'amount' => $schema->integer()->min(1)->description('The expected charge in positive Rappen, e.g. 1032 for CHF 10.32. Charges within ±10% match.'),
            'interval' => $schema->string()->enum(['weekly', 'monthly', 'quarterly', 'yearly']),
            'next_date' => $schema->string()->format('date')->description('The day the next charge is expected, Y-m-d. Linking a newer charge moves it.'),
        ];
    }

    /**
     * The fields the caller sent, as ManageSubscriptions takes them.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function only(array $input): array
    {
        return array_intersect_key($input, array_flip(['name', 'payee_id', 'description_match', 'account_id', 'amount', 'interval', 'next_date', 'status']));
    }
}
