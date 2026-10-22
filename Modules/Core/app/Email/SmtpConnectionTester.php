<?php

declare(strict_types=1);

namespace Modules\Core\Email;

interface SmtpConnectionTester
{
    /** @throws SmtpConnectionFailed with a message safe to show the tenant admin */
    public function test(SmtpSettings $settings): void;
}
