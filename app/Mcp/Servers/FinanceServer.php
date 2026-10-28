<?php

namespace App\Mcp\Servers;

use App\Mcp\Prompts\Finance\CategorizeTransactionsPrompt;
use App\Mcp\Tools\Finance;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

/**
 * The finance module's MCP server at /mcp/finance. Each casaOS module gets its own server.
 */
#[Name('casaOS Finance')]
#[Version('1.0.0')]
#[Instructions(<<<'TEXT'
Access to the user's personal finances in casaOS: accounts and balances, bank transactions,
categories, payees, categorization rules, transfers between accounts and CSV imports. Everything
belongs to the user of the API token. Amounts are integers in Rappen (1 CHF = 100 Rappen, negative =
money out), each with a formatted `*_chf` string. casaOS is deterministic: categorize with rules
(create_rule, preview_apply_rules, apply_rules) rather than many one-off edits, and show the user
what will change before writing. For the categorization workflow and the rule format, get the
`categorize_transactions` prompt or read the create_rule description. Tools that change data need
a token with the finance:write ability; a read-only token can only use read tools.
TEXT)]
class FinanceServer extends Server
{
    /**
     * List every tool on one page: some clients do not follow `nextCursor`.
     */
    public int $defaultPaginationLength = 50;

    protected array $tools = [
        Finance\ListAccountsTool::class,
        Finance\GetAccountTool::class,
        Finance\AddBalanceSnapshotTool::class,

        Finance\ListTransactionsTool::class,
        Finance\GetTransactionTool::class,
        Finance\UpdateTransactionTool::class,

        Finance\ListCategoriesTool::class,
        Finance\CreateCategoryTool::class,
        Finance\UpdateCategoryTool::class,
        Finance\DeleteCategoryTool::class,

        Finance\ListPayeesTool::class,
        Finance\CreatePayeeTool::class,
        Finance\RenamePayeeTool::class,
        Finance\MergePayeesTool::class,
        Finance\DeletePayeeTool::class,

        Finance\ListRulesTool::class,
        Finance\CreateRuleTool::class,
        Finance\UpdateRuleTool::class,
        Finance\DeleteRuleTool::class,
        Finance\SetRuleEnabledTool::class,
        Finance\ReorderRulesTool::class,
        Finance\PreviewRuleMatchesTool::class,
        Finance\PreviewApplyRulesTool::class,
        Finance\ApplyRulesTool::class,

        Finance\FindTransfersTool::class,
        Finance\ListTransferSuggestionsTool::class,
        Finance\ConfirmTransferSuggestionTool::class,
        Finance\DismissTransferSuggestionTool::class,
        Finance\PairTransferTool::class,
        Finance\UnpairTransferTool::class,
        Finance\MarkTransferTool::class,
        Finance\UnmarkTransferTool::class,
        Finance\TransferCandidatesTool::class,

        Finance\ListImportBatchesTool::class,
        Finance\GetImportBatchTool::class,
        Finance\UndoImportBatchTool::class,
        Finance\ImportCsvTool::class,
    ];

    protected array $prompts = [
        CategorizeTransactionsPrompt::class,
    ];
}
