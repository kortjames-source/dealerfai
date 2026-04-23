<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/security_headers.php';
require_once __DIR__ . '/includes/theme_head.php';
include 'db.php';
require_once __DIR__ . '/accessory_helpers.php';
require_once __DIR__ . '/helpers/billing_helpers.php';
require_once __DIR__ . '/protection_helpers.php';
require_once __DIR__ . '/helpers/organization_question_config.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/helpers/deal_access.php';

if (!function_exists('accessory_is_luxury_vehicle_make')) {
    function accessory_is_luxury_vehicle_make(?string $make): bool
    {
        $normalized = strtolower(trim((string)$make));
        if ($normalized === '') {
            return false;
        }
        $luxuryMakes = [
            'land rover', 'range rover', 'mercedes', 'mercedes-benz', 'bmw', 'lexus',
            'audi', 'porsche', 'cadillac', 'infiniti', 'jaguar',
        ];
        return in_array($normalized, $luxuryMakes, true);
    }
}

$deal_id = $_GET['deal_id'] ?? $_SESSION['deal_id'] ?? null;
if (!$deal_id) {
    die('Missing deal ID.');
}
$deal_id = (int)$deal_id;

$stmt = $db->prepare("SELECT * FROM deals WHERE id = ?");
$stmt->execute([$deal_id]);
$deal = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$deal) {
    die('Deal not found.');
}
if (!dealerfai_session_can_access_deal($deal)) {
    http_response_code(403);
    die('Access denied.');
}

$org_id = (int)($deal['organization'] ?? 0);
if ($org_id > 0 && !is_accessories_enabled($db, $org_id)) {
    header("Location: recommendations_loading.php?deal_id=" . urlencode((string)$deal_id) . "&prescored=1");
    exit;
}

if ($org_id > 0) {
    $usageUserId = $_SESSION['user_id'] ?? null;
    try {
        log_usage_event(
            $db,
            $org_id,
            $deal_id,
            'accessory_presentation',
            $usageUserId ? (int)$usageUserId : null,
            'accessory'
        );
    } catch (Throwable $e) {
        error_log("log_usage_event failed: " . $e->getMessage());
    }
}

$theme = ['logo' => '', 'color' => '#0066cc'];
if ($org_id) {
    $hasLeaseCapPercentColumn = organization_column_exists($db, 'lease_msrp_cap_percent');
    $hasFinanceCapPercentColumn = organization_column_exists($db, 'finance_msrp_cap_percent');
    $selectFields = 'logo_url, theme_variant';
    if ($hasLeaseCapPercentColumn) {
        $selectFields .= ', lease_msrp_cap_percent';
    }
    if ($hasFinanceCapPercentColumn) {
        $selectFields .= ', finance_msrp_cap_percent';
    }
    $orgStmt = $db->prepare("SELECT {$selectFields} FROM organizations WHERE id = ?");
    $orgStmt->execute([$org_id]);
    $org = $orgStmt->fetch(PDO::FETCH_ASSOC);
    if ($org) {
        $theme['logo'] = $org['logo_url'] ?? '';
        $theme['color'] = $org['theme_variant'] === 'jlr' ? '#4a4a4a' : (in_array($org['theme_variant'], ['land_rover', 'jaguar'], true) ? '#1A1A1A' : '#0066cc');
    }
}

$isCashDeal = strcasecmp((string)($deal['deal_type'] ?? ''), 'Cash') === 0;
$isLeaseDeal = strcasecmp((string)($deal['deal_type'] ?? ''), 'Lease') === 0;
$isFinanceDeal = strcasecmp((string)($deal['deal_type'] ?? ''), 'Finance') === 0;
$province = strtoupper(trim((string)($deal['province'] ?? 'ON')));
$taxRates = [
    'MB' => ['gst' => 0.05, 'pst' => 0.07],
    'SK' => ['gst' => 0.05, 'pst' => 0.06],
    'ON' => ['hst' => 0.13],
    'QC' => ['gst' => 0.05, 'pst' => 0.09975],
    'NS' => ['hst' => 0.15], 'NB' => ['hst' => 0.15], 'PE' => ['hst' => 0.15],
    'NL' => ['hst' => 0.15], 'BC' => ['gst' => 0.05, 'pst' => 0.07],
    'AB' => ['gst' => 0.05], 'NT' => ['gst' => 0.05], 'NU' => ['gst' => 0.05], 'YT' => ['gst' => 0.05],
];
$tax = $taxRates[$province] ?? ['hst' => 0.13];
$gstRate = (float)($tax['gst'] ?? 0.0);
$pstRate = (float)($tax['pst'] ?? 0.0);
$hstRate = (float)($tax['hst'] ?? 0.0);
$vehicleMakeForLuxury = (string)($deal['vehicle_make'] ?? '');
if ($vehicleMakeForLuxury === '' && !empty($deal['vehicle_make_id'])) {
    try {
        $makeStmt = $db->prepare("SELECT make_name FROM vehicle_makes WHERE id = ?");
        $makeStmt->execute([(int)$deal['vehicle_make_id']]);
        $vehicleMakeForLuxury = (string)($makeStmt->fetchColumn() ?: '');
    } catch (Throwable $e) {
        $vehicleMakeForLuxury = '';
    }
}
$isLuxuryVehicle = accessory_is_luxury_vehicle_make($vehicleMakeForLuxury);
$fltOverrideAmount = array_key_exists('flt_override_amount', $deal) && $deal['flt_override_amount'] !== null
    ? (float)$deal['flt_override_amount']
    : null;
$vehicleSalePrice = (float)($deal['sale_price'] ?? 0);
$leaseCapPercent = isset($org) && is_array($org) ? ($org['lease_msrp_cap_percent'] ?? null) : null;
$financeCapPercent = isset($org) && is_array($org) ? ($org['finance_msrp_cap_percent'] ?? null) : null;
$capPercent = $isLeaseDeal ? $leaseCapPercent : ($isFinanceDeal ? $financeCapPercent : null);
$capPercent = ($capPercent !== null && $capPercent !== '' && is_numeric($capPercent)) ? (float)$capPercent : null;
$msrpValue = (float)($deal['msrp'] ?? 0);
$includedProtections = function_exists('parse_included_protections')
    ? parse_included_protections($deal['included_protections'] ?? null)
    : [];
$includedSummary = function_exists('summarize_included_protections')
    ? summarize_included_protections($includedProtections)
    : ['total' => 0];
$includedTotal = (float)($includedSummary['total'] ?? 0);
$baseCapCost = 0.0;
$leaseCapLimit = 0.0;
$leaseCapEnabled = false;
if (($isLeaseDeal || $isFinanceDeal) && $capPercent !== null && $capPercent > 0 && $msrpValue > 0) {
    $leaseCapEnabled = true;
    $leaseCapLimit = $msrpValue * ($capPercent / 100);
    $baseCapCost = (float)($deal['sale_price'] ?? 0)
        + (float)($deal['documentation_fee'] ?? 0)
        + $includedTotal
        + (float)($deal['ppsa_fee'] ?? 0)
        - (float)($deal['down_payment'] ?? 0)
        - (float)($deal['trade_value'] ?? 0)
        + (float)($deal['lien_amount'] ?? 0);
    $baseCapCost = max(0, $baseCapCost);
}
$loadingUrl = "recommendations_loading.php?deal_id=" . urlencode((string)$deal_id) . "&prescored=1";
$csrfToken = dealerfai_csrf_get_token();
?>
<!DOCTYPE html>
<html>
<head>
  <?php include __DIR__ . "/includes/head_favicon.php"; ?>
  <meta charset="UTF-8">
  <title>Accessories</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php dealerfai_theme_head($theme ?? null); ?>
  <style nonce="<?= dealerfai_csp_nonce() ?>">
    body { font-family: "Segoe UI", sans-serif; background: #f4f6f8; margin: 0; padding: 0; color: #111; }
    header { background: <?= htmlspecialchars($theme['color']) ?>; color: white; padding: 20px; text-align: center; }
    header img { max-width: 480px; max-height: 180px; height: auto; display: block; margin: 0 auto 10px; }
    main { padding: 30px 40px; }
    .card { background: white; padding: 24px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); max-width: 900px; margin: 0 auto; }
    h2 { margin-top: 0; }
    .muted { color: #64748b; font-size: 0.95rem; }
	    .accessories-list { display: grid; gap: 14px; margin-top: 16px; }
	    .accessory-card { border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px; background: #fff; }
	    .accessory-header { display:flex; justify-content: space-between; gap: 12px; align-items: flex-start; }
	    .accessory-left { display: flex; gap: 12px; align-items: flex-start; min-width: 0; }
	    .accessory-thumb { width: 72px; height: 72px; border-radius: 10px; object-fit: cover; border: 1px solid #e2e8f0; background: #f1f5f9; flex: 0 0 auto; }
	    .accessory-text { min-width: 0; }
	    .accessory-title { font-weight: 600; color: #1a2443; }
	    .accessory-meta { color: #4b5563; font-size: 0.9rem; margin-top: 4px; }
	    .accessory-controls { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-top: 8px; }
	    .accessory-controls select { padding: 6px; border-radius: 6px; border: 1px solid #cbd5f5; }
	    .badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 12px; background: #e0f2fe; color: #075985; margin-left: 8px; }
    .actions { display: flex; gap: 12px; justify-content: flex-end; margin-top: 20px; }
    .btn { background: <?= htmlspecialchars($theme['color']) ?>; color: white; padding: 12px 18px; border: none; border-radius: 6px; font-size: 16px; cursor: pointer; }
    .btn.secondary { background: #94a3b8; }
    .status { margin-top: 12px; font-size: 0.95rem; color: #334155; }
    .reason-loading { color: #475569; font-style: italic; }
  </style>
</head>
<body>
<header>
  <?php if (!empty($theme['logo'])): ?>
    <img src="<?= htmlspecialchars($theme['logo']) ?>" alt="Dealer Logo">
  <?php else: ?>
    <h1>DealerFAI Accessories</h1>
  <?php endif; ?>
</header>

<main>
  <div class="card">
    <h2>Accessories</h2>
    <p class="muted">Select any accessories you’d like to include before we finalize your protection recommendations.</p>

    <div id="accessories-list" class="accessories-list">
      <p class="muted">Loading accessories...</p>
    </div>

    <div class="status" id="lease-cap-note" class="d-none"></div>

    <div class="actions">
      <button class="btn secondary" id="skip-accessories">Skip Accessories</button>
      <button class="btn" id="continue-btn">Continue to Recommendations</button>
    </div>
  </div>
</main>

<script nonce="<?= dealerfai_csp_nonce() ?>">
  const dealId = <?= json_encode($deal_id) ?>;
  const csrfToken = <?= json_encode($csrfToken) ?>;
  const isCashDeal = <?= json_encode($isCashDeal) ?>;
  const loadingUrl = <?= json_encode($loadingUrl) ?>;
  const listEl = document.getElementById('accessories-list');
  const continueBtn = document.getElementById('continue-btn');
  const skipBtn = document.getElementById('skip-accessories');
  const leaseCapNote = document.getElementById('lease-cap-note');
  const leaseCapEnabled = <?= json_encode($leaseCapEnabled && $leaseCapLimit > 0) ?>;
  const leaseCapLimit = <?= json_encode($leaseCapLimit) ?>;
  const baseCapCost = <?= json_encode($baseCapCost) ?>;
  const capDealType = <?= json_encode($isLeaseDeal ? 'lease' : ($isFinanceDeal ? 'finance' : '')) ?>;
  const gstRate = <?= json_encode($gstRate) ?>;
  const pstRate = <?= json_encode($pstRate) ?>;
  const hstRate = <?= json_encode($hstRate) ?>;
  const vehicleSalePrice = <?= json_encode($vehicleSalePrice) ?>;
  const isLuxuryVehicle = <?= json_encode($isLuxuryVehicle) ?>;
  const fltOverrideAmount = <?= json_encode($fltOverrideAmount) ?>;
  let accessoriesLoaded = false;

  function setNavigationEnabled(enabled) {
    continueBtn.disabled = !enabled;
    skipBtn.disabled = !enabled;
  }

  function formatCurrency(value) {
    return `$${value.toFixed(2)}`;
  }

  function calculateFltAmount(base) {
    if (!isLuxuryVehicle || !Number.isFinite(base) || base <= 0) {
      return 0;
    }
    if (base > 100000 && base < 200000) {
      return (base - 100000) * 0.20;
    }
    if (base >= 200000) {
      return base * 0.10;
    }
    return 0;
  }

  function currentLuxuryAccessoryBase(excludeAccessoryId) {
    let total = vehicleSalePrice;
    document.querySelectorAll('.accessory-checkbox').forEach((cb) => {
      if (!cb.checked) return;
      if ((cb.dataset.contributesLuxuryTax || '1') !== '1') return;
      if (excludeAccessoryId && String(cb.dataset.id || '') === String(excludeAccessoryId)) return;
      const itemPrice = parseFloat(cb.dataset.price || '0');
      if (Number.isFinite(itemPrice) && itemPrice > 0) {
        total += itemPrice;
      }
    });
    return total;
  }

  function calculateAccessoryUpfrontTotal(price, contributesToLuxuryTax, accessoryId) {
    const basePrice = Number.isFinite(price) ? price : 0;
    if (basePrice <= 0) return 0;

    let fltAmount = 0;
    if (contributesToLuxuryTax) {
      const baseWithoutCurrent = currentLuxuryAccessoryBase(accessoryId);
      const withAccessoryBase = baseWithoutCurrent + basePrice;
      fltAmount = Math.max(0, calculateFltAmount(withAccessoryBase) - calculateFltAmount(baseWithoutCurrent));
      if (Number.isFinite(fltOverrideAmount) && fltOverrideAmount >= 0) {
        fltAmount = Math.min(fltAmount, fltOverrideAmount);
      }
    }

    const taxableBase = basePrice + fltAmount;
    const salesTax = hstRate > 0
      ? taxableBase * hstRate
      : (taxableBase * gstRate) + (taxableBase * pstRate);
    return basePrice + fltAmount + salesTax;
  }

  async function updateAccessorySelection(accessoryId, selected, payMethod) {
    try {
      const resp = await fetch('api/accessories_select.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrfToken
        },
        body: JSON.stringify({
          deal_id: dealId,
          accessory_id: accessoryId,
          selected: selected,
          pay_method: payMethod || null,
          csrf_token: csrfToken
        })
      });
      const data = await resp.json().catch(() => null);
      if (data && data.pay_method) {
        const card = document.querySelector(`.accessory-checkbox[data-id="${accessoryId}"]`)?.closest('.accessory-card');
        if (card) {
          const checkbox = card.querySelector('.accessory-checkbox');
          const select = card.querySelector('select');
          if (checkbox) checkbox.dataset.payMethod = data.pay_method;
          if (select) select.value = data.pay_method;
          updateActionText(card);
        }
      }
    } catch (error) {
      console.warn('Accessory update failed', error);
    }
  }

  async function loadAccessoryReasons() {
    try {
      const resp = await fetch(`api/accessories_reasons.php?deal_id=${encodeURIComponent(dealId)}`, { cache: 'no-store' });
      const data = await resp.json();
      const reasons = data && data.reasons && typeof data.reasons === 'object' ? data.reasons : {};
      document.querySelectorAll('[data-reason-id]').forEach((el) => {
        const id = String(el.getAttribute('data-reason-id') || '');
        const reason = typeof reasons[id] === 'string' ? reasons[id].trim() : '';
        if (reason !== '') {
          el.textContent = reason;
          el.classList.remove('reason-loading');
        } else {
          el.textContent = 'Why it fits: tailored to your profile and ownership needs.';
          el.classList.remove('reason-loading');
        }
      });
    } catch (error) {
      document.querySelectorAll('[data-reason-id]').forEach((el) => {
        el.textContent = 'Why it fits: tailored to your profile and ownership needs.';
        el.classList.remove('reason-loading');
      });
    }
  }

  function updateActionText(card) {
    const checkbox = card.querySelector('.accessory-checkbox');
    const actionText = card.querySelector('.accessory-action-text');
    if (!checkbox || !actionText) return;
    if (isCashDeal) {
      const price = parseFloat(checkbox.dataset.price || '0');
      const contributesToLuxuryTax = (checkbox.dataset.contributesLuxuryTax || '1') === '1';
      const upfrontTotal = calculateAccessoryUpfrontTotal(price, contributesToLuxuryTax, checkbox.dataset.id || '');
      actionText.textContent = price > 0 ? `Add to total ${formatCurrency(upfrontTotal)} (inclusive of taxes)` : 'Request a quote';
      return;
    }
    const payMethod = (checkbox.dataset.payMethod || '').toLowerCase();
    const price = parseFloat(checkbox.dataset.price || '0');
    if (payMethod === 'upfront') {
      const contributesToLuxuryTax = (checkbox.dataset.contributesLuxuryTax || '1') === '1';
      const upfrontTotal = calculateAccessoryUpfrontTotal(price, contributesToLuxuryTax, checkbox.dataset.id || '');
      actionText.textContent = price > 0 ? `Pay upfront ${formatCurrency(upfrontTotal)} (inclusive of taxes)` : 'Request a quote';
      return;
    }
    const monthly = parseFloat(checkbox.dataset.monthly || '0');
    actionText.textContent = monthly > 0 ? `Include in payment ${formatCurrency(monthly)}/mo` : 'Request a quote';
  }

  function enforceCapLimit() {
    if (!leaseCapEnabled) return;
    let capCost = baseCapCost;
    let capped = false;
    document.querySelectorAll('.accessory-card').forEach(card => {
      const checkbox = card.querySelector('.accessory-checkbox');
      if (!checkbox || !checkbox.checked) return;
      const payMethod = (checkbox.dataset.payMethod || '').toLowerCase();
      if ((capDealType === 'lease' && payMethod !== 'cap_cost') || (capDealType === 'finance' && payMethod !== 'finance')) {
        return;
      }
      const price = parseFloat(checkbox.dataset.price || '0') || 0;
      if (!checkbox.disabled && capCost + price > leaseCapLimit) {
        const select = card.querySelector('select');
        checkbox.dataset.payMethod = 'upfront';
        if (select) select.value = 'upfront';
        updateActionText(card);
        updateAccessorySelection(checkbox.dataset.id, true, 'upfront');
        capped = true;
        return;
      }
      capCost += price;
    });
    if (leaseCapNote) {
      if (capped || capCost > leaseCapLimit) {
        const excess = Math.max(0, capCost - leaseCapLimit);
        leaseCapNote.textContent = excess > 0
          ? `MSRP cap reached. ${formatCurrency(excess)} moved to cash down.`
          : 'MSRP cap reached. Additional accessories will be treated as cash down.';
        leaseCapNote.style.display = '';
      } else {
        leaseCapNote.textContent = '';
        leaseCapNote.style.display = 'none';
      }
    }
  }

	  function buildAccessoryCard(item) {
	    const card = document.createElement('div');
	    card.className = 'accessory-card';

	    const header = document.createElement('div');
	    header.className = 'accessory-header';

	    const left = document.createElement('div');
	    left.className = 'accessory-left';

	    if (item.photo_url) {
	      const img = document.createElement('img');
	      img.className = 'accessory-thumb';
	      img.src = item.photo_url;
	      img.alt = `${item.name || 'Accessory'} photo`;
	      img.loading = 'lazy';
	      left.appendChild(img);
	    }

	    const titleWrap = document.createElement('div');
	    titleWrap.className = 'accessory-text';
	    const title = document.createElement('div');
	    title.className = 'accessory-title';
	    title.textContent = item.name || 'Accessory';
	    if (item.recommended) {
      const badge = document.createElement('span');
      badge.className = 'badge';
      badge.textContent = 'Recommended';
      title.appendChild(badge);
    }
    titleWrap.appendChild(title);

    const meta = document.createElement('div');
    meta.className = 'accessory-meta';
    meta.textContent = item.base_price > 0 ? `Price: ${formatCurrency(item.base_price)}` : 'Price on request';
	    titleWrap.appendChild(meta);

	    left.appendChild(titleWrap);
	    header.appendChild(left);

	    const checkbox = document.createElement('input');
	    checkbox.type = 'checkbox';
	    checkbox.className = 'accessory-checkbox';
    checkbox.dataset.id = item.id;
    checkbox.dataset.name = item.name || '';
    checkbox.dataset.monthly = (item.payment_value || 0).toFixed(2);
    checkbox.dataset.payMethod = item.pay_method || '';
    checkbox.dataset.price = (item.base_price || 0).toFixed(2);
    checkbox.dataset.contributesLuxuryTax = item.contributes_to_luxury_tax ? '1' : '0';
    if (item.selected) checkbox.checked = true;
    if (item.included) {
      checkbox.checked = true;
      checkbox.disabled = true;
    }
    header.appendChild(checkbox);

    card.appendChild(header);

    const controls = document.createElement('div');
    controls.className = 'accessory-controls';

    if (!isCashDeal) {
      const select = document.createElement('select');
      const options = item.is_lease
        ? [
            { value: 'upfront', label: 'Pay upfront' },
            { value: 'cap_cost', label: 'Add to cap cost' }
          ]
        : [
            { value: 'upfront', label: 'Pay upfront' },
            { value: 'finance', label: 'Finance' }
          ];
      options.forEach(opt => {
        const option = document.createElement('option');
        option.value = opt.value;
        option.textContent = opt.label;
        if ((item.pay_method || '').toLowerCase() === opt.value) {
          option.selected = true;
        }
        select.appendChild(option);
      });
      select.addEventListener('change', () => {
        checkbox.dataset.payMethod = select.value;
        if (checkbox.checked && !checkbox.disabled) {
          updateAccessorySelection(item.id, true, select.value);
        }
        updateActionText(card);
        enforceCapLimit();
      });
      if (item.included) {
        select.disabled = true;
      }
      controls.appendChild(select);
    }

    const actionText = document.createElement('span');
    actionText.className = 'accessory-action-text';
    if (isCashDeal) {
      const upfrontTotal = calculateAccessoryUpfrontTotal(item.base_price || 0, !!item.contributes_to_luxury_tax, item.id);
      actionText.textContent = item.base_price > 0 ? `Add to total ${formatCurrency(upfrontTotal)} (inclusive of taxes)` : 'Request a quote';
    } else {
      const payMethod = (item.pay_method || '').toLowerCase();
      if (payMethod === 'upfront') {
        const upfrontTotal = calculateAccessoryUpfrontTotal(item.base_price || 0, !!item.contributes_to_luxury_tax, item.id);
        actionText.textContent = item.base_price > 0 ? `Pay upfront ${formatCurrency(upfrontTotal)} (inclusive of taxes)` : 'Request a quote';
      } else {
        const monthly = parseFloat(checkbox.dataset.monthly || '0');
        actionText.textContent = monthly > 0 ? `Include in payment ${formatCurrency(monthly)}/mo` : 'Request a quote';
      }
    }
    controls.appendChild(actionText);
    card.appendChild(controls);

    if (item.description) {
      const desc = document.createElement('p');
      desc.className = 'muted';
      desc.style.marginTop = '8px';
      desc.textContent = item.description;
      card.appendChild(desc);
    }

    const why = document.createElement('p');
    why.className = 'muted reason-loading';
    why.style.marginTop = '8px';
    why.style.color = '#334155';
    why.setAttribute('data-reason-id', String(item.id || ''));
    why.textContent = 'Why it fits: generating personalized insight...';
    card.appendChild(why);

    checkbox.addEventListener('change', () => {
      if (checkbox.disabled) return;
      updateAccessorySelection(item.id, checkbox.checked, checkbox.dataset.payMethod || null);
      updateActionText(card);
      enforceCapLimit();
    });

    return card;
  }

  async function loadAccessories() {
    setNavigationEnabled(false);
    accessoriesLoaded = false;
    try {
      const resp = await fetch(`api/accessories_list.php?deal_id=${encodeURIComponent(dealId)}`, { cache: 'no-store' });
      const data = await resp.json();
      listEl.innerHTML = '';
      if (data && Array.isArray(data.accessories) && data.accessories.length) {
        const dealType = <?= json_encode($deal['deal_type'] ?? '') ?>;
        data.accessories.forEach(item => {
          item.is_lease = (dealType || '').toLowerCase() === 'lease';
          if (!item.pay_method) {
            if (isCashDeal) {
              item.pay_method = 'upfront';
            } else if (item.is_lease) {
              item.pay_method = item.residualizable ? 'cap_cost' : 'upfront';
            } else {
              item.pay_method = 'finance';
            }
          }
          listEl.appendChild(buildAccessoryCard(item));
        });
        enforceCapLimit();
        loadAccessoryReasons().catch(() => {});
      } else {
        listEl.innerHTML = '<p class="muted">No accessories available for this vehicle.</p>';
      }
      accessoriesLoaded = true;
      setNavigationEnabled(true);
    } catch (error) {
      listEl.innerHTML = '<p class="muted">Accessories are not available right now.</p>';
      accessoriesLoaded = true;
      setNavigationEnabled(true);
    }
  }

  continueBtn.addEventListener('click', () => {
    if (!accessoriesLoaded) return;
    window.location.href = loadingUrl;
  });
  skipBtn.addEventListener('click', () => {
    if (!accessoriesLoaded) return;
    window.location.href = loadingUrl;
  });

  setNavigationEnabled(false);
  loadAccessories();
  fetch('api/start_recommendations.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': csrfToken
    },
    body: JSON.stringify({ deal_id: dealId, csrf_token: csrfToken }),
    cache: 'no-store'
  }).catch(() => {});
</script>
</body>
</html>
