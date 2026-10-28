<?php

namespace App\Models\Finance;

enum SnapshotSource: string
{
    case Manual = 'manual';
    case Import = 'import';
}
