<?php

namespace App\Mcp\Prompts\Finance;

use App\Mcp\Tools\Finance\RuleSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Prompt;

#[Name('categorize_transactions')]
#[Description('The workflow for categorizing the user\'s uncategorized transactions with deterministic rules, including the rule condition and action format.')]
class CategorizeTransactionsPrompt extends Prompt
{
    public function handle(Request $request): Response
    {
        return Response::text(self::guide());
    }

    public static function guide(): string
    {
        $reference = RuleSchema::reference();

        return <<<TEXT
        Help me categorize my uncategorized transactions in casaOS. casaOS stays deterministic: prefer rules over one-off edits, and never change data without showing me what will change first.

        Amounts are integers in Rappen (1 CHF = 100 Rappen), negative for money out; every amount also comes as a `*_chf` string.

        Workflow:
        1. list_categories and list_payees, to know the ids; list_rules, to avoid duplicates.
        2. list_transactions with category "none" (and transfer "no"; transfers carry no category). Page through all pages.
        3. Group the rows by the merchant in their raw description (e.g. "MIGROS M ZUERICH HB" and "Migros Basel" are both Migros). Ignore groups of one unless they are obvious.
        4. For each group, propose a rule: conditions that catch the group and nothing else (usually description starts_with or contains), and actions (set_category, often set_payee with a clean name). Propose new categories only when none fits.
        5. preview_rule_matches with the conditions: check the count and the sample, and tighten the conditions until no unrelated rows match.
        6. Show me the proposals (pattern, category, payee, match count). After I agree, create_rule for each.
        7. preview_apply_rules (per rule_id, or without it for all rules), show me the changes, then apply_rules.
        8. list_transactions with category "none" again; use update_transaction only for one-off rows no rule should cover.

        Values I set by hand (source `manual`) are never changed by rules. Imports run the rules on new rows automatically, so good rules keep future imports categorized.

        {$reference}
        TEXT;
    }
}
