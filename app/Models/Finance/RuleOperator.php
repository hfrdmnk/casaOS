<?php

namespace App\Models\Finance;

enum RuleOperator: string
{
    case Contains = 'contains';
    case Equals = 'equals';
    case StartsWith = 'starts_with';
    case Regex = 'regex';
    case Is = 'is';
    case GreaterThan = 'greater_than';
    case LessThan = 'less_than';
    case Between = 'between';
}
