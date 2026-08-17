# receipt.php
Change:
    if (!defined('CURRENCY_SYMBOL')) {
        define('CURRENCY_SYMBOL', '₦');
    }
To:
    if (!defined('CURRENCY_SYMBOL')) {
        define('CURRENCY_SYMBOL', '£');
    }

(number_format((float)$r['amount_paid'], 2) already gives 2 decimal places, correct for GBP pounds — no other change needed here.)

# receipt_pdf.php
Change:
    if (!defined('CURRENCY_SYMBOL')) {
        define('CURRENCY_SYMBOL', 'NGN ');
    }
To:
    if (!defined('CURRENCY_SYMBOL')) {
        define('CURRENCY_SYMBOL', 'GBP ');
    }
    // (FPDF's core Helvetica font can't render "£" either — same reason as
    // the original NGN workaround — so spell out "GBP" rather than "£".)

Also update:
    "Amount Paid: " . CURRENCY_SYMBOL . number_format((float)$row['amount_paid'])
To:
    "Amount Paid: " . CURRENCY_SYMBOL . number_format((float)$row['amount_paid'], 2)
(add 2 decimal places — Paystack's kobo-based whole-number style isn't the convention for GBP)
