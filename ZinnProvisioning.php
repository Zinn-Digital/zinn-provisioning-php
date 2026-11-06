<?php
/**
 * The panel-agnostic provisioning recipe — for a billing system we do not ship a module for.
 *
 * ⚖️ Owner, 2026-08-22: *"do 1 but then do hostibill and the generic webhook also for sure, we
 * need to make this as open and adatpable as possible"*. This is that third thing: everything
 * the WHMCS and HostBill modules do, with no panel in it at all. Blesta, an in-house billing
 * system, a Laravel app, a cron script — anything that can make an HTTP request can use it.
 *
 * ⛔⛔ THERE IS NO PRIVILEGED PATH HERE AND THERE IS NOTHING TO REGISTER WITH US. A reseller
 * does not "install a webhook"; they call five documented endpoints with their own API key.
 * That is deliberate: an integration that requires us to provision something for the reseller
 * first is an integration they cannot start on a Sunday, and every panel would need its own
 * onboarding. The whole contract is below, and the same sequence is written out as `curl` in
 * the reseller guide at https://zinndigital.com/kb/reseller-api-getting-started.
 *
 * ── The contract, in five calls ───────────────────────────────────────────────────────
 *
 *   ORDER PAID    POST /v1/orgs                              (once per CUSTOMER)
 *                 POST /v1/reseller/clients/{orgId}/plan     (once per CUSTOMER — NOT optional)
 *                 POST /v1/sites                             (once per SERVICE)
 *   NOT PAID      POST /v1/reseller/services/{id}/suspend
 *   PAID AGAIN    POST /v1/reseller/services/{id}/unsuspend
 *   CANCELLED     DELETE /v1/sites/{id}
 *   "LOG IN"      POST /v1/reseller/services/{id}/sso
 *
 * ⛔ THE TWO RULES THAT MATTER, and both of them cost real money when they are broken:
 *
 *   1. **Key the organisation on your CUSTOMER, and the site on your SERVICE.** A customer's
 *      second order must land in the account they already have. Key both on the service and
 *      one customer ends up with three unrelated accounts and three separate panels.
 *   2. **Send an `Idempotency-Key` on every POST, derived from YOUR OWN id for the thing.**
 *      Every billing system retries: a gateway callback arrives twice, an admin re-runs a
 *      failed provision, a customer double-clicks. Without the key the second attempt creates
 *      a second site and you are billed for it.
 *
 * @package Zinn\Generic
 * @license GPL-2.0-or-later
 * @author  Neil Lock — CEO, Zinn Digital® Ltd
 * @link    https://zinndigital.com
 */

declare(strict_types=1);

/**
 * A dependency-free reference implementation of the Zinn provisioning contract.
 */
class ZinnProvisioning
{
    /**
     * Seconds to wait for the API.
     */
    public const TIMEOUT = 20;

    /**
     * Seconds to wait on a call that carries an `Idempotency-Key`.
     *
     * ⛔⛔ IT MUST EXCEED THE PLATFORM'S IN-FLIGHT LOCK, AND 20 DID NOT (D18631, measured in
     * WHMCS 9.0.7 against production on 2026-09-02 — the same client code, one panel over).
     * `IdempotentCreateMixin.replay` reserves the key for **30 seconds** and answers a repeat
     * inside that window with `409 ... already in progress`, so a client that gives up at 20s
     * discards the answer carrying the new site's id AND cannot retry to recover it. The site
     * exists, the panel records nothing, and the account looks like a failed order.
     *
     * ⛔ Only keyed calls get the longer wait: a read that hangs should fail fast, and a keyed
     * write is safe to wait on precisely because a duplicate cannot be created.
     */
    public const PROVISION_TIMEOUT = 60;

    /**
     * API base URL.
     *
     * @var string
     */
    private $base;

    /**
     * API key.
     *
     * @var string
     */
    private $key;

    /**
     * @param string $key  Your Zinn API key (`zdk_live_…`).
     * @param string $base API base URL. Leave the default unless you were told otherwise.
     */
    public function __construct(string $key, string $base = 'https://api.zinndigital.com')
    {
        $base = trim($base);
        if ($base === '') {
            $base = 'https://api.zinndigital.com';
        }
        if (!preg_match('#^https?://#i', $base)) {
            $base = 'https://' . $base;
        }
        $this->base = rtrim($base, '/');
        $this->key = trim($key);
        if ($this->key === '') {
            throw new RuntimeException('A Zinn API key is required.');
        }
    }

    /**
     * Create — or reuse — the client account for one of YOUR customers.
     *
     * @param string $customerReference Your own stable id for this customer.
     * @param string $name              What to call the account.
     * @return string The Zinn organisation id. Store it against your customer.
     */
    public function account(string $customerReference, string $name): string
    {
        $org = $this->post('/v1/orgs', [
            'type' => 'customer',
            'name' => $name !== '' ? $name : $customerReference,
        ], 'account-' . $customerReference);
        if (empty($org['id'])) {
            throw new RuntimeException('The hosting platform did not return a client account.');
        }
        return (string) $org['id'];
    }

    /**
     * Put a client account on the plan you are selling, and get its subscription id.
     *
     * ⛔⛔ CALL THIS BETWEEN `account()` AND `provision()`. IT IS NOT OPTIONAL AND SKIPPING IT
     * COSTS YOU THE WHOLE INVOICE. A client organisation `account()` has just created holds
     * no subscription, and `POST /v1/sites` carries no plan field — so a site provisioned
     * without one lands with no plan at all. Your wholesale statement is built from your
     * clients' live subscriptions, so there is no line for that service and Zinn® bills you
     * **nothing** while the hosting runs; your client inherits no allowances, so no quota is
     * applied to their box; and there is nothing for an upgrade to change. Nothing anywhere
     * reports it — the site provisions and serves perfectly (D17700).
     *
     * ⛔ Your client is never invoiced by Zinn®. The subscription is granted comped: it
     * exists to carry entitlements and to put a line on YOUR statement. You bill your client;
     * we bill you.
     *
     * ⛔ A second service for the same client on the **same** plan is a no-op and safe to
     * repeat — the plan's own site allowance covers the extra site. On a **different** plan it
     * is refused, because replacing the live plan would leave one of the two services
     * unbilled. Sell both on the same plan, or use a separate client account.
     *
     * @param string $orgId    The organisation id from `account()`.
     * @param string $planCode The Zinn® plan code. `GET /v1/reseller/prices` lists yours.
     * @param string $interval `monthly` (default) or `annual` — YOUR wholesale interval.
     * @return string The subscription id. Pass it to `provision()`.
     */
    public function plan(string $orgId, string $planCode, string $interval = 'monthly'): string
    {
        $planCode = trim($planCode);
        if ($planCode === '') {
            throw new RuntimeException(
                'A plan code is required. Provisioning without one creates hosting that '
                . 'carries no plan: you would not be invoiced for it and your client would '
                . 'get no allowances.'
            );
        }
        $plan = $this->post(
            '/v1/reseller/clients/' . rawurlencode($orgId) . '/plan',
            ['plan_code' => $planCode, 'interval' => trim($interval) ?: 'monthly'],
            'plan-' . $orgId . '-' . $planCode
        );
        $subscriptionId = trim((string) ($plan['subscription_id'] ?? ''));
        if ($subscriptionId === '') {
            throw new RuntimeException(
                'The hosting platform did not return a subscription for plan "' . $planCode
                . '", so nothing was provisioned.'
            );
        }
        return $subscriptionId;
    }

    /**
     * Provision a site under a client account.
     *
     * ⛔⛔ `$stackType` IS REQUIRED BY THE PLATFORM AND HAS A DEFAULT HERE RATHER THAN BEING
     * OPTIONAL. `POST /v1/sites` answers `422 stack_type: "Required when blueprint_id is
     * omitted."`, so this reference could not provision anything until 2026-08-31 (D17360).
     * It is the fifth parameter, after `$productLine`, so an existing caller keeps working
     * and starts succeeding rather than having to be edited.
     *
     * @param string $serviceReference Your own stable id for this service/subscription.
     * @param string $orgId            The organisation id from `account()`.
     * @param string $domain           The hostname the customer bought.
     * @param string $productLine      The Zinn product line, e.g. `mainstream`.
     * @param string $stackType        What to build: one of `dotnet`, `headless_cms`,
     *                                 `nextcloud`, `node`, `one_click`, `owncloud`, `php`,
     *                                 `php_app`, `python`, `ruby`, `static`, `woocommerce`,
     *                                 `wordpress`.
     * @param string $application      For `one_click`/`headless_cms`/`php_app` only. Empty to
     *                                 omit. For `php_app` it names the application to install,
     *                                 e.g. `joomla`.
     * @param string $phpVersion       e.g. `8.3`. Empty for the product line's default.
     * @param string $subscriptionId   REQUIRED — the id from `plan()`. See below.
     * @return string The Zinn site id. Store it against your service.
     */
    public function provision(
        string $serviceReference,
        string $orgId,
        string $domain,
        string $productLine = 'mainstream',
        string $stackType = 'wordpress',
        string $application = '',
        string $phpVersion = '',
        string $subscriptionId = ''
    ): string {
        $domain = strtolower(trim($domain));
        if ($domain === '') {
            throw new RuntimeException('A domain is required to provision a site.');
        }
        // ⛔⛔ REFUSED, NOT DEFAULTED, AND THIS DELIBERATELY BREAKS A PRE-1.1.0 CALLER. It has
        // a default only so the signature stays additive; an empty value is rejected here.
        // The alternative — provisioning anyway — is the D17700 state: hosting that runs
        // perfectly, carries no plan, raises no line on your wholesale statement and grants
        // your client no allowances, with nothing at either end reporting it. A caller that
        // stops working and says why is strictly better than one that keeps working and
        // silently costs you every invoice for that service.
        if (trim($subscriptionId) === '') {
            throw new RuntimeException(
                'No subscription id. Call plan($orgId, $planCode) first and pass what it '
                . 'returns as the $subscriptionId argument — a site provisioned without one '
                . 'carries no plan, so you are never invoiced for it and your client gets no '
                . 'allowances.'
            );
        }
        $body = [
            'org_id' => $orgId,
            'product_line' => $productLine,
            'primary_domain' => $domain,
            'name' => $domain,
            'stack_type' => $stackType,
        ];
        // ⛔ Omitted rather than sent empty: an empty `php_version` or an `application` on a
        // stack that does not ask for one are each a 422, so sending the blank would refuse
        // the order instead of taking the line's default.
        if (trim($application) !== '') {
            $body['application'] = trim($application);
        }
        if (trim($phpVersion) !== '') {
            $body['php_version'] = trim($phpVersion);
        }
        $body['subscription_id'] = trim($subscriptionId);
        $site = $this->post('/v1/sites', $body, 'service-' . $serviceReference);
        if (empty($site['id'])) {
            throw new RuntimeException('The hosting platform did not return a site.');
        }
        return (string) $site['id'];
    }

    /**
     * Put a site on a non-payment hold.
     *
     * @param string $siteId Zinn site id.
     * @param string $reason Your own note, typically the unpaid invoice number.
     * The answer is `202`: the hold is applied on the site's server within a few minutes.
     * Until then `serving_change` on the row shows it (`status` still reads `active`).
     *
     * @return array<string,mixed> The service row, with the hold being applied.
     */
    public function suspend(string $siteId, string $reason = ''): array
    {
        return $this->post(
            '/v1/reseller/services/' . rawurlencode($siteId) . '/suspend',
            $reason === '' ? [] : ['reason' => $reason]
        );
    }

    /**
     * Release a hold YOU placed.
     *
     * ⛔ A `409` here means the site was suspended by our abuse team, not by you, and no
     * billing system may release that. Show the message; do not retry it.
     *
     * @param string $siteId Zinn site id.
     * The answer is `202`: the release is applied on the server within a few minutes, and
     * `serving_change` on the row shows it until then.
     *
     * @return array<string,mixed> The service row, with the release being applied.
     */
    public function unsuspend(string $siteId): array
    {
        return $this->post('/v1/reseller/services/' . rawurlencode($siteId) . '/unsuspend', []);
    }

    /**
     * Schedule a site's deletion.
     *
     * ⚠️ Scheduled, not immediate. That is a deliberate customer protection: the date comes
     * back on the service row as `pending_deletion_at`, and telling your customer their data
     * is already gone when it is not is worse than telling them nothing.
     *
     * @param string $siteId Zinn site id.
     * @return array<string,mixed>
     */
    public function terminate(string $siteId): array
    {
        // ⛔⛔ THE CONFIRMATION IS MANDATORY. A bare DELETE is `422 confirm_domain:
        // "This field is required."`, so a caller written from the old signature of this
        // method cancelled nothing and kept being billed (D17363).
        // ⛔ Read back from the platform, never taken from the caller: it is compared
        // against `SiteDeletion.primary_domain` and never against an alias.
        $row = $this->usage($siteId);
        $domain = trim((string) ($row['primary_domain'] ?? ''));
        if ($domain === '') {
            throw new RuntimeException(
                'The hosting platform did not report this site\'s domain, so the deletion '
                . 'could not be confirmed. Nothing has been changed.'
            );
        }
        return $this->request(
            'DELETE',
            '/v1/sites/' . rawurlencode($siteId),
            ['confirm_domain' => $domain],
            ''
        );
    }

    /**
     * A one-time sign-in link into the panel for this site's client.
     *
     * ⛔ Call this when your customer CLICKS the button, never when you render the page. The
     * link is single-use and expires in minutes, so a link rendered into a page is burned on
     * a page view and left in every cache between you and the browser.
     *
     * @param string $siteId Zinn site id.
     * @return string An absolute URL to redirect the customer to.
     */
    public function signInLink(string $siteId): string
    {
        $response = $this->post('/v1/reseller/services/' . rawurlencode($siteId) . '/sso', []);
        if (empty($response['url'])) {
            throw new RuntimeException('No sign-in link was returned.');
        }
        return (string) $response['url'];
    }

    /**
     * Every service you have provisioned, one page at a time.
     *
     * ⛔ No usage on this row, deliberately — reading usage is a round trip to the hosting
     * box, so carrying it here would be one network call per row. Call `usage()` per service
     * for the ones you actually need.
     *
     * @param string $cursor Cursor from the previous page, or an empty string.
     * @return array<string,mixed> `{data: [...], page: {next_cursor, has_more}}`
     */
    public function services(string $cursor = ''): array
    {
        return $this->request(
            'GET',
            '/v1/reseller/services' . ($cursor === '' ? '' : '?cursor=' . rawurlencode($cursor)),
            null,
            ''
        );
    }

    /**
     * One service, with its current disk and bandwidth.
     *
     * ⛔ `disk_used_bytes` and `bandwidth_used_bytes` may be `null`, and a null is NOT a
     * zero: it means we could not measure, not that nothing was used. Skip a null; do not
     * write it into your own records as 0, or you will show a customer a green usage bar for
     * a site you have no reading from.
     *
     * @param string $siteId Zinn site id.
     * @return array<string,mixed>
     */
    public function usage(string $siteId): array
    {
        return $this->request('GET', '/v1/reseller/services/' . rawurlencode($siteId), null, '');
    }

    /**
     * POST a JSON body with an idempotency key.
     *
     * @param string              $path           API path.
     * @param array<string,mixed> $body           JSON body.
     * @param string              $idempotencyKey Your own stable key for this operation.
     * @return array<string,mixed>
     */
    private function post(string $path, array $body, string $idempotencyKey = ''): array
    {
        return $this->request('POST', $path, $body, $idempotencyKey);
    }

    /**
     * The headers one request carries.
     *
     * @param bool   $hasBody        Whether a JSON body is being sent.
     * @param string $idempotencyKey Idempotency key, or an empty string.
     * @return array<int,string>
     */

    /**
     * The `Idempotency-Key` a request actually carries.
     *
     * ⛔⛔ THE CALLER'S KEY NAMES THE OPERATION; THE BODY HASH SAYS WHICH ONE (D18635).
     * The module's keys are stable per service (`whmcs-site-42`), per client (`whmcs-org-7`)
     * and per plan — which is right for a retry and wrong for everything else, because the
     * platform fingerprints the BODY and answers a key reused with a different one:
     *
     *     {"result":"error","message":"Idempotency-Key reused with a different request body."}
     *
     * Measured inside WHMCS 9.0.7 on 2026-09-02: terminate a service, re-order it on a new
     * domain, and the create is refused — for the full 24-hour replay window, on the one
     * button that sells anything. The same trap fires when a client's company name is edited
     * (the org body changes) or a product's billing interval is switched.
     *
     * ⭐ Hashing the body REMOVES the class rather than patching three call sites, and it
     * keeps the property the keys exist for: a genuine retry sends the same bytes, so it
     * still replays; a different request is a different operation and is allowed to proceed.
     * Fixing the three call sites individually would have been an enumeration of the cases
     * somebody thought of (§2.24).
     *
     * @param string                   $logicalKey The caller's key, or an empty string.
     * @param array<string,mixed>|null $body       The JSON body, or null.
     * @return string
     */

    /**
     * The bytes a JSON body is sent as.
     *
     * ⛔⛔ AN EMPTY PHP ARRAY IS A JSON **LIST**, AND THE PLATFORM REFUSES ONE (D18636).
     * `json_encode([])` is `[]`, not `{}`, so every call this module makes with no fields —
     * unsuspend, single sign-on, take backup, purge cache — sent a list where an object was
     * required and came back:
     *
     *     422 non_field_errors: Invalid data. Expected a dictionary, but got list.
     *
     * Measured through a real WHMCS on 2026-09-02: **Purge Cache had never worked**, from the
     * admin page or the client area, and neither had the other three. It is D17360's shape
     * exactly — a body the platform will not accept — and it survived because the suite
     * asserts the body as a PHP array, where `[]` and "an empty object" are the same value.
     * Only the wire tells them apart.
     *
     * ⭐ Fixed here rather than at the four call sites, and the difference matters: passing
     * `new stdClass()` at each one is an enumeration of the calls somebody remembered, and the
     * fifth would be wrong again (§2.24).
     *
     * @param array<string,mixed>|null $body The JSON body, or null for none.
     * @return string|null
     */
    public static function encodeBody(?array $body): ?string
    {
        if ($body === null) {
            return null;
        }
        // An empty array is the only ambiguous case: PHP cannot tell "no fields" from
        // "an empty list", and JSON must.
        return $body === [] ? '{}' : json_encode($body, JSON_THROW_ON_ERROR);
    }

    public static function idempotencyKeyFor(string $logicalKey, ?array $body): string
    {
        if ($logicalKey === '') {
            return '';
        }
        $encoded = $body === null ? '' : (string) json_encode($body);
        return $logicalKey . '-' . substr(sha1($encoded), 0, 10);
    }

    public function headers(bool $hasBody, string $idempotencyKey): array
    {
        $headers = [
            'Authorization: Bearer ' . $this->key,
            'Accept: application/json',
            'User-Agent: ZinnProvisioning/1.0',
        ];
        if ($hasBody) {
            $headers[] = 'Content-Type: application/json';
        }
        if ($idempotencyKey !== '') {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }
        return $headers;
    }

    /**
     * Turn a status + raw body into decoded data, or throw with the API's own message.
     *
     * @param int    $status HTTP status.
     * @param string $raw    Raw response body.
     * @return array<string,mixed>
     */
    public function interpret(int $status, string $raw): array
    {
        $decoded = json_decode($raw, true);
        if ($status >= 200 && $status < 300) {
            return is_array($decoded) ? $decoded : [];
        }
        $message = '';
        if (is_array($decoded) && isset($decoded['error']['message'])) {
            $message = (string) $decoded['error']['message'];
        }
        if ($message === '') {
            // ⛔⛔ A 5xx IS OUR FAULT AND THE OLD SENTENCE READ LIKE THE RESELLER'S (D18637).
            // "The hosting platform answered with status 500." names no cause and no next
            // step, so a reseller re-checks their key, their product and their plan — none of
            // which is wrong. Measured on 2026-09-02: three buttons answered 500 on a site
            // whose vendor account was missing, and the platform's own log carried a perfect
            // sentence saying exactly that, which the response body did not.
            $message = $status >= 500
                ? 'The hosting platform had a fault of its own (HTTP ' . $status . '), so '
                    . 'nothing here is wrong with your settings. It is safe to try again in a '
                    . 'few minutes; if it keeps happening, send Zinn support this service and '
                    . 'the time you pressed it.'
                : 'The hosting platform answered with status ' . $status . '.';
        }
        // ⛔⛔ THE `details` ARRAY IS THE HALF THAT SAYS WHAT TO DO, AND IT USED TO BE
        // DISCARDED. A validation failure's top-level message is deliberately generic —
        // "The request was well-formed but failed validation." — because the platform puts
        // the actionable half in `details`: which field, and why. Dropping it left a
        // reseller's admin staring at a sentence that names nothing, on the one screen
        // where they are trying to work out what to change (D17362).
        $message .= self::detailSuffix($decoded);
        // ⛔⛔ THE STATUS TRAVELS WITH THE MESSAGE, AND WITHOUT IT A TERMINATION DELETED
        // NOTHING (D18633). A caller that has to decide *"is this gone, or did I fail to
        // ask?"* cannot read a sentence — and reading every failure as "gone" is the
        // reassuring direction (§2.44). Measured live: a transient `502` on the pre-delete
        // lookup made WHMCS mark a service Terminated while the site kept running and the
        // reseller kept being invoiced for it.
        //
        // ⭐ Carried as the exception CODE rather than a new exception class: every existing
        // `catch (RuntimeException)` and every message assertion is unchanged, and a caller
        // that does not care never sees it. A transport failure keeps code 0 — deliberately
        // NOT a status, because "we never got an answer" is a different fact from any status.
        throw new RuntimeException($message, $status);
    }

    /**
     * The ` (field: reason)` tail appended to a refusal, or an empty string.
     *
     * ⛔ Deduplicated and capped. A bulk refusal can carry a `details` entry per row, and a
     * WHMCS module's returned string is rendered into an admin page — an unbounded paste of
     * fifty identical reasons is a message nobody reads, which is the same outcome as
     * having no message at all.
     *
     * ⭐ `code: "none"` entries carry a bare VALUE rather than a sentence — the platform
     * uses them to attach numbers to an error (`max_sites: 0`, `sites_in_use: 0` on
     * `PLAN_REQUIRED`) — so they are rendered as `field: value`, which is what they mean.
     *
     * @param mixed $decoded The decoded response body.
     * @return string
     */
    private static function detailSuffix($decoded): string
    {
        if (!is_array($decoded) || !isset($decoded['error']['details'])) {
            return '';
        }
        $details = $decoded['error']['details'];
        if (!is_array($details)) {
            return '';
        }
        $parts = [];
        foreach ($details as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $field = trim((string) ($entry['field'] ?? ''));
            $reason = trim((string) ($entry['message'] ?? ''));
            if ($field === '' && $reason === '') {
                continue;
            }
            $part = $field === '' ? $reason : $field . ': ' . $reason;
            if (!in_array($part, $parts, true)) {
                $parts[] = $part;
            }
            if (count($parts) === 5) {
                break;
            }
        }
        return $parts === [] ? '' : ' (' . implode('; ', $parts) . ')';
    }

    /**
     * Perform the call.
     *
     * @param string                   $method         HTTP method.
     * @param string                   $path           API path.
     * @param array<string,mixed>|null $body           JSON body, or null.
     * @param string                   $idempotencyKey Idempotency key, or an empty string.
     * @return array<string,mixed>
     */
    private function request(string $method, string $path, ?array $body, string $idempotencyKey): array
    {
        $idempotencyKey = self::idempotencyKeyFor($idempotencyKey, $body);
        self::requireCurl();
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $this->headers($body !== null, $idempotencyKey),
            CURLOPT_TIMEOUT => $idempotencyKey === '' ? self::TIMEOUT : self::PROVISION_TIMEOUT,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, self::encodeBody($body));
        }
        $raw = curl_exec($ch); // php-fn-guard-ok: requireCurl() above refuses unless canCall('curl_exec').
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $transportError = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            // ⛔⛔ A KEYED WRITE THAT DID NOT ANSWER IS NOT A WRITE THAT DID NOT HAPPEN
            // (D18631). "Could not reach" reads as *nothing happened*, and the reseller's
            // natural response to that is to cancel an order whose hosting is already live.
            if ($idempotencyKey !== '') {
                throw new RuntimeException(
                    'The hosting platform did not answer in time (' . $transportError . '). '
                    . 'The operation may still have completed — wait a minute and press the '
                    . 'same button again. It carries an idempotency key, so a repeat returns '
                    . 'the same account rather than creating a second one.'
                );
            }
            throw new RuntimeException('Could not reach the hosting platform: ' . $transportError);
        }
        return $this->interpret($status, (string) $raw);
    }

    /**
     * Whether this server's PHP lets us call `$fn`: it exists AND it is not in the host's
     * `disable_functions`.
     *
     * ⛔⛔ On PHP 8 a disabled function is UNDEFINED, so calling it is a fatal error that `@`
     * does not stop (V1 ticket 13692, docs/72 D35246). A billing panel on a hardened host
     * would show the admin a blank page instead of a reason. PHP 7 keeps a disabled function
     * "existing", which is why the list is read as well.
     *
     * @param string $fn Function name.
     * @return bool
     */
    private static function canCall(string $fn): bool
    {
        if (!function_exists($fn)) {
            return false;
        }
        $off = array_map('trim', explode(',', strtolower((string) ini_get('disable_functions'))));
        return !in_array(strtolower($fn), $off, true);
    }

    /**
     * Refuse, with the reason, when this server cannot make an HTTPS call at all. Nothing was
     * sent, so it is safe to fix the host and press the same button again.
     *
     * @return void
     */
    private static function requireCurl(): void
    {
        if (!self::canCall('curl_init') || !self::canCall('curl_exec')) {
            throw new RuntimeException(
                'This server\'s PHP cannot make HTTPS calls: the curl extension is missing or '
                . 'curl_init/curl_exec is listed in disable_functions, so the request was not sent. '
                . 'Ask your host to enable curl, then press the same button again.'
            );
        }
    }
}
