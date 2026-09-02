# Any other billing system

One file, no dependencies, no panel. Drop
[`ZinnProvisioning.php`](ZinnProvisioning.php) into your own codebase.

```php
require_once 'ZinnProvisioning.php';

$zinn = new ZinnProvisioning(getenv('ZINN_API_KEY'));

// When an order is paid:
$orgId  = $zinn->account('customer-4211', 'Acme Ltd');            // once per CUSTOMER

// ⛔⛔ NOT OPTIONAL, AND SKIPPING IT COSTS YOU THE WHOLE INVOICE. A client account has no
// plan until you set one, and a site provisioned without one carries no plan at all: your
// wholesale statement is built from your clients' live subscriptions, so no line is raised
// and Zinn bills you NOTHING while the hosting runs; your client gets no allowances; and
// there is nothing for an upgrade to change. The site provisions and serves perfectly, and
// nothing at either end reports any of it (D17700). `provision()` refuses without it.
$subId  = $zinn->plan($orgId, 'wp_basic');                        // once per CUSTOMER

// ⛔ The 5th argument is the STACK and the platform requires one. It defaults to
// 'wordpress'; pass 'woocommerce', 'php', 'static', 'node', 'one_click', 'headless_cms',
// 'nextcloud' or 'owncloud' for anything else. An omitted stack is a 422 and no order can
// succeed without it (D17360).
$siteId = $zinn->provision(
    'service-9915', $orgId, 'acme.com', 'mainstream', 'wordpress', '', '', $subId
);
// Store $orgId against your customer and $siteId against your service.

// When an invoice goes unpaid:
$zinn->suspend($siteId, 'INV-2026-114 unpaid');

// When they pay:
$zinn->unsuspend($siteId);

// When they cancel:
// Reads the site's own domain back and sends it as the typed confirmation the platform
// requires. A bare DELETE is a 422 and cancels nothing (D17363).
$zinn->terminate($siteId);   // schedules a deletion; read pending_deletion_at

// When they click "log in to my hosting" — mint it on the CLICK, never on page render:
header('Location: ' . $zinn->signInLink($siteId));
```

A second service for the same customer on the **same** plan needs no second `plan()` call —
it is a no-op and safe to repeat, because that plan's own site allowance covers the extra
site. On a **different** plan it is refused: replacing the live plan would leave one of the
two services unbilled. Sell both on the same plan, or use a separate client account.

Before your first order you need a reseller plan with site allowance — sites your clients
order count against it, and an order placed without one is refused with _"This organization
has no hosting plan"_. That is not an integration fault.

Not writing PHP? The same six calls as `curl` are in the reseller guide at
<https://zinndigital.com/kb/reseller-api-getting-started>, and every endpoint is in the reference at
<https://zinndigital.com/developers/api>.

Read [`../README.md`](../README.md) before you start — the two idempotency rules and the three
answers you must not flatten into two are there, and each of them costs real money.

## Licence

GPL-2.0-or-later. Author: Neil Lock — CEO, Zinn Digital® Ltd — <https://zinndigital.com>
