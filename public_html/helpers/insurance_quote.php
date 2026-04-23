<?php
/**
 * Quote adapter layer (insurance + warranty).
 *
 * Goal: allow adding a partner integration by provider code (ex: SAL) without
 * rewriting core recommendation logic.
 *
 * How it works:
 * - Products already have `provider`, `category`, and `requires_quote` fields.
 * - `recommendations.php` can call insurance_quote_try_quote(...) for products
 *   that require a quote.
 * - Provider integrations are configured via environment variables.
 *
 * Environment variable convention (provider code is uppercased):
 * - QUOTE_<PROVIDER>_TYPE=stub|http_json
 * - QUOTE_<PROVIDER>_BASE_URL=https://...
 * - QUOTE_<PROVIDER>_API_KEY=...
 * - QUOTE_<PROVIDER>_TIMEOUT_MS=4000 (optional)
 *
 * Backward compatible:
 * - INS_QUOTE_<PROVIDER>_... is also supported.
 *
 * The `stub` provider is safe for local wiring and returns a deterministic
 * premium based on amount financed + term (for UI/testing only).
 */

if (!function_exists('insurance_quote_env')) {
    function insurance_quote_env(string $key): ?string {
        $val = $_ENV[$key] ?? getenv($key);
        if ($val === false || $val === null) {
            return null;
        }
        $val = trim((string)$val);
        return $val === '' ? null : $val;
    }
}

if (!function_exists('insurance_quote_provider_env')) {
    function insurance_quote_provider_env(string $providerCode, string $suffix): ?string {
        $providerCode = insurance_quote_normalize_provider_code($providerCode);
        if ($providerCode === '') {
            return null;
        }
        $suffix = strtoupper(trim($suffix));
        $suffix = preg_replace('/[^A-Z0-9_]/', '', $suffix);
        if ($suffix === '') {
            return null;
        }
        // Prefer the generic QUOTE_ prefix; allow legacy INS_QUOTE_ prefix.
        return insurance_quote_env("QUOTE_{$providerCode}_{$suffix}")
            ?? insurance_quote_env("INS_QUOTE_{$providerCode}_{$suffix}");
    }
}

if (!function_exists('insurance_quote_normalize_provider_code')) {
    function insurance_quote_normalize_provider_code(?string $provider): string {
        $provider = strtoupper(trim((string)$provider));
        // Keep it alnum/underscore to avoid env var surprises.
        $provider = preg_replace('/[^A-Z0-9_]/', '', $provider);
        return $provider ?: '';
    }
}

if (!function_exists('insurance_quote_get_provider_config')) {
    function insurance_quote_get_provider_config(string $providerCode): ?array {
        $providerCode = insurance_quote_normalize_provider_code($providerCode);
        if ($providerCode === '') {
            return null;
        }

        $type = strtolower((string)(insurance_quote_provider_env($providerCode, 'TYPE') ?? ''));
        if ($type === '') {
            return null;
        }

        $timeoutMs = (int)(insurance_quote_provider_env($providerCode, 'TIMEOUT_MS') ?? 4000);
        $timeoutMs = max(500, min(15000, $timeoutMs));

        if ($type === 'stub') {
            return [
                'provider' => $providerCode,
                'type' => 'stub',
                'timeout_ms' => $timeoutMs,
            ];
        }

        if ($type === 'http_json') {
            $baseUrl = insurance_quote_provider_env($providerCode, 'BASE_URL');
            if ($baseUrl === null) {
                return null;
            }
            return [
                'provider' => $providerCode,
                'type' => 'http_json',
                'base_url' => $baseUrl,
                'api_key' => insurance_quote_provider_env($providerCode, 'API_KEY'),
                'timeout_ms' => $timeoutMs,
            ];
        }

        return null;
    }
}

if (!function_exists('insurance_quote_load_applicant_dobs')) {
    function insurance_quote_load_applicant_dobs(PDO $db, int $dealId): array {
        $stmt = $db->prepare("
            SELECT is_co_applicant, dob
            FROM credit_applications
            WHERE deal_id = ?
            ORDER BY is_co_applicant ASC
        ");
        $stmt->execute([$dealId]);

        $primary = null;
        $co = null;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $dob = $row['dob'] ?? null;
            if ($dob === null || $dob === '0000-00-00' || $dob === '') {
                continue;
            }
            $isCo = !empty($row['is_co_applicant']);
            if ($isCo) {
                $co = (string)$dob;
            } else {
                $primary = (string)$dob;
            }
        }
        return ['primary' => $primary, 'co' => $co];
    }
}

if (!function_exists('insurance_quote_build_request')) {
    function insurance_quote_build_request(PDO $db, array $deal, array $productRow, float $includedTotal, float $accessoryTotalRolledIn): array {
        $dealId = (int)($deal['id'] ?? 0);
        $dobs = $dealId > 0 ? insurance_quote_load_applicant_dobs($db, $dealId) : ['primary' => null, 'co' => null];

        $amountFinanced = null;
        if (function_exists('estimate_amount_financed')) {
            $amountFinanced = estimate_amount_financed($deal, $includedTotal + $accessoryTotalRolledIn);
        } else {
            // Fallback: approximate (finance/lease) vs cash.
            $sale = (float)($deal['sale_price'] ?? 0.0);
            $doc = (float)($deal['documentation_fee'] ?? 0.0);
            $ppsa = (float)($deal['ppsa_fee'] ?? 0.0);
            $down = (float)($deal['down_payment'] ?? 0.0);
            $trade = (float)($deal['trade_value'] ?? 0.0);
            $lien = (float)($deal['lien_amount'] ?? 0.0);
            $amountFinanced = max(0.0, $sale + $doc + $ppsa + $includedTotal + $accessoryTotalRolledIn - $down - $trade + $lien);
        }

        $dealType = strtolower(trim((string)($deal['deal_type'] ?? '')));
        $paymentFrequency = strtolower(trim((string)($deal['payment_frequency'] ?? 'monthly')));

        $vehicleKms = isset($deal['vehicle_kms']) ? (int)$deal['vehicle_kms'] : null;
        $vehicleKms = $vehicleKms && $vehicleKms > 0 ? $vehicleKms : null;
        $inServiceDate = $deal['in_service_date'] ?? null;
        $inServiceDate = ($inServiceDate === null || $inServiceDate === '' || $inServiceDate === '0000-00-00') ? null : (string)$inServiceDate;
        $vehicleYear = isset($deal['vehicle_year']) ? (int)$deal['vehicle_year'] : null;
        $vehicleYear = $vehicleYear && $vehicleYear > 0 ? $vehicleYear : null;
        $vehicleCondition = trim((string)($deal['vehicle_condition'] ?? ''));
        $vehicleCondition = $vehicleCondition !== '' ? strtolower($vehicleCondition) : null;

        // Coverage term hint for quote APIs (especially warranty). Prefer explicit product term if present.
        $coverageTerm = isset($productRow['coverage_term']) ? (int)$productRow['coverage_term'] : 0;
        if ($coverageTerm <= 0) {
            $coverageTerm = isset($productRow['default_term']) ? (int)$productRow['default_term'] : 0;
        }
        if ($coverageTerm <= 0) {
            $coverageTerm = (int)($deal['term'] ?? 0);
        }
        $coverageTerm = $coverageTerm > 0 ? $coverageTerm : null;

        return [
            'deal_id' => $dealId,
            'product_code' => (string)($productRow['code'] ?? ''),
            'product_name' => (string)($productRow['name'] ?? ''),
            'provider_code' => (string)($productRow['provider'] ?? ''),
            'category' => (string)($productRow['category'] ?? ''),
            'vin' => (string)($deal['vin'] ?? ''),
            'province' => strtoupper(trim((string)($deal['province'] ?? ''))),
            'deal_type' => $dealType, // finance|lease|cash
            'payment_frequency' => $paymentFrequency, // monthly|bi-weekly|...
            'loan_term_months' => (int)($deal['term'] ?? 0),
            'interest_rate' => (float)($deal['interest_rate'] ?? 0.0),
            'amount_financed' => $amountFinanced,
            'coverage_term_months' => $coverageTerm,
            // Vehicle info (important for warranty).
            'vehicle_kms' => $vehicleKms,
            'in_service_date' => $inServiceDate,
            'vehicle_year' => $vehicleYear,
            'vehicle_make' => (string)($deal['vehicle_make'] ?? ''),
            'vehicle_model' => (string)($deal['vehicle_model'] ?? ''),
            'vehicle_condition' => $vehicleCondition,
            'sale_price' => isset($deal['sale_price']) ? (float)$deal['sale_price'] : null,
            'msrp' => isset($deal['msrp']) ? (float)$deal['msrp'] : null,
            // Applicant info
            'dob' => $dobs['primary'],
            'co_dob' => $dobs['co'],
            // Optional; can be populated later when you add UI for it.
            'coverage_type' => null, // 'individual' | 'joint' | null
        ];
    }
}

if (!function_exists('insurance_quote_cache_get')) {
    function insurance_quote_cache_get(string $cacheKey, int $ttlSeconds): ?array {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }
        $bucket = $_SESSION['insurance_quote_cache'] ?? null;
        if (!is_array($bucket) || empty($bucket[$cacheKey]) || !is_array($bucket[$cacheKey])) {
            return null;
        }
        $entry = $bucket[$cacheKey];
        $ts = (int)($entry['ts'] ?? 0);
        if ($ts <= 0 || (time() - $ts) > $ttlSeconds) {
            return null;
        }
        return $entry['value'] ?? null;
    }
}

if (!function_exists('insurance_quote_cache_set')) {
    function insurance_quote_cache_set(string $cacheKey, array $value): void {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        if (!isset($_SESSION['insurance_quote_cache']) || !is_array($_SESSION['insurance_quote_cache'])) {
            $_SESSION['insurance_quote_cache'] = [];
        }
        $_SESSION['insurance_quote_cache'][$cacheKey] = [
            'ts' => time(),
            'value' => $value,
        ];
    }
}

if (!function_exists('insurance_quote_provider_stub')) {
    function insurance_quote_provider_stub(array $request): array {
        $amount = (float)($request['amount_financed'] ?? 0.0);
        $term = (int)($request['loan_term_months'] ?? 0);
        $rate = (float)($request['interest_rate'] ?? 0.0);

        // Simple deterministic formula: base + term factor + amount factor + tiny rate factor.
        $base = 250.0;
        $amountFactor = max(0.0, $amount) * 0.012; // 1.2%
        $termFactor = max(0, $term) * 7.5; // $7.50 per month
        $rateFactor = max(0.0, $rate) * 35.0;
        $premium = round($base + $amountFactor + $termFactor + $rateFactor, 2);

        return [
            'ok' => true,
            'premium' => $premium,
            'currency' => 'CAD',
            'raw' => ['type' => 'stub'],
        ];
    }
}

if (!function_exists('insurance_quote_provider_http_json')) {
    function insurance_quote_provider_http_json(array $config, array $request): array {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'error' => 'curl_not_available'];
        }

        $payload = [
            // Keep the payload stable and explicit for insurer partners.
            'vin' => $request['vin'] ?? null,
            'dob' => $request['dob'] ?? null,
            'co_dob' => $request['co_dob'] ?? null,
            'province' => $request['province'] ?? null,
            'deal_type' => $request['deal_type'] ?? null,
            'payment_frequency' => $request['payment_frequency'] ?? null,
            'amount_financed' => $request['amount_financed'] ?? null,
            'loan_term_months' => $request['loan_term_months'] ?? null,
            'interest_rate' => $request['interest_rate'] ?? null,
            'product_code' => $request['product_code'] ?? null,
            'coverage_term_months' => $request['coverage_term_months'] ?? null,
            'vehicle_kms' => $request['vehicle_kms'] ?? null,
            'in_service_date' => $request['in_service_date'] ?? null,
            'vehicle_year' => $request['vehicle_year'] ?? null,
            'vehicle_make' => $request['vehicle_make'] ?? null,
            'vehicle_model' => $request['vehicle_model'] ?? null,
            'vehicle_condition' => $request['vehicle_condition'] ?? null,
            'coverage_type' => $request['coverage_type'] ?? null,
        ];

        $ch = curl_init();
        $headers = ['Content-Type: application/json'];
        if (!empty($config['api_key'])) {
            $headers[] = 'Authorization: Bearer ' . $config['api_key'];
        }

        $timeoutMs = (int)($config['timeout_ms'] ?? 4000);
        curl_setopt_array($ch, [
            CURLOPT_URL => (string)$config['base_url'],
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => min($timeoutMs, 2000),
            CURLOPT_TIMEOUT_MS => $timeoutMs,
        ]);

        $resp = curl_exec($ch);
        $err = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false) {
            return ['ok' => false, 'error' => 'http_error', 'detail' => $err ?: null];
        }
        if ($status < 200 || $status >= 300) {
            return ['ok' => false, 'error' => 'http_status_' . $status];
        }

        $decoded = json_decode((string)$resp, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'error' => 'invalid_json'];
        }

        // Expected insurer response (recommended):
        // { "premium": 123.45, "currency": "CAD", "id": "...", ... }
        $premium = $decoded['premium'] ?? null;
        if ($premium === null || $premium === '' || !is_numeric($premium)) {
            return ['ok' => false, 'error' => 'missing_premium'];
        }

        return [
            'ok' => true,
            'premium' => round((float)$premium, 2),
            'currency' => (string)($decoded['currency'] ?? 'CAD'),
            'raw' => $decoded,
        ];
    }
}

if (!function_exists('insurance_quote_try_quote')) {
    /**
     * Attempt to quote premium for a product.
     * Returns:
     * - ['ok' => true, 'premium' => float, 'currency' => 'CAD', ...]
     * - ['ok' => false, 'error' => 'missing_inputs'|'no_provider_config'|...]
     */
    function insurance_quote_try_quote(PDO $db, array $deal, array $productRow, float $includedTotal, float $accessoryTotalRolledIn): array {
        $providerCode = (string)($productRow['provider'] ?? '');
        $providerCodeNorm = insurance_quote_normalize_provider_code($providerCode);
        if ($providerCodeNorm === '') {
            return ['ok' => false, 'error' => 'missing_provider_code'];
        }

        $config = insurance_quote_get_provider_config($providerCodeNorm);
        if ($config === null) {
            return ['ok' => false, 'error' => 'no_provider_config'];
        }

        $request = insurance_quote_build_request($db, $deal, $productRow, $includedTotal, $accessoryTotalRolledIn);

        $category = strtolower(trim((string)($request['category'] ?? '')));
        // Require minimal inputs based on category.
        // Note: partner APIs vary; this is a baseline so we can safely attempt quotes.
        $requiredKeys = [];
        if ($category === 'warranty') {
            // Warranty quoting typically needs vehicle age + kms; some providers also require term.
            // Keep term optional so we can still quote for cash deals / missing term setups.
            $requiredKeys = ['vin', 'province', 'vehicle_kms', 'in_service_date'];
        } else {
            // Default to insurance-style requirements.
            $requiredKeys = ['vin', 'dob', 'province', 'amount_financed', 'loan_term_months', 'payment_frequency', 'interest_rate'];
        }

        $missing = [];
        foreach ($requiredKeys as $k) {
            $v = $request[$k] ?? null;
            if ($v === null || $v === '') {
                $missing[] = $k;
                continue;
            }
            if (in_array($k, ['loan_term_months', 'coverage_term_months'], true) && (int)$v <= 0) {
                $missing[] = $k;
                continue;
            }
            if ($k === 'amount_financed' && !is_numeric($v)) {
                $missing[] = $k;
                continue;
            }
        }
        if (!empty($missing)) {
            return ['ok' => false, 'error' => 'missing_inputs', 'missing' => $missing];
        }

        $cacheKey = hash('sha256', json_encode([
            'provider' => $providerCodeNorm,
            'type' => $config['type'] ?? '',
            'req' => $request,
        ]));
        $cached = insurance_quote_cache_get($cacheKey, 15 * 60);
        if (is_array($cached)) {
            return $cached;
        }

        if (($config['type'] ?? '') === 'stub') {
            $out = insurance_quote_provider_stub($request);
            insurance_quote_cache_set($cacheKey, $out);
            return $out;
        }

        if (($config['type'] ?? '') === 'http_json') {
            $out = insurance_quote_provider_http_json($config, $request);
            // Cache only successful quotes to avoid “sticky” transient failures.
            if (!empty($out['ok'])) {
                insurance_quote_cache_set($cacheKey, $out);
            }
            return $out;
        }

        return ['ok' => false, 'error' => 'unsupported_provider_type'];
    }
}
