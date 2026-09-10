<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth;

use PDO;

interface SecurityClock
{
    public function capture(PDO $pdo): SecurityTime;
}
